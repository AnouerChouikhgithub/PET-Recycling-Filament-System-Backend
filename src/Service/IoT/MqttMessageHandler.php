<?php

declare(strict_types=1);

namespace App\Service\IoT;

use App\Api\Exception\ValidationException;
use App\Entity\Machine;
use App\Repository\MachineRepository;
use App\Service\Machine\MachineConnectivityService;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * ALL inbound MQTT message logic, isolated from the socket so it can be unit
 * tested without a broker: `app:mqtt:consume` only wires client → handler.
 *
 * Rules enforced here, in order:
 *   1. the topic must be {prefix}/machines/{identifier}/{telemetry|status|events};
 *   2. the payload must fit MQTT_MAX_PAYLOAD_BYTES;
 *   3. it must be a JSON OBJECT (not an array, scalar or malformed);
 *   4. the machine must already exist — MQTT never creates machines, so an
 *      unknown identifier is logged and dropped;
 *   5. telemetry goes through the SAME TelemetryProcessor (and therefore the
 *      same validation) as HTTP ingest — no duplicated validation logic;
 *   6. a per-machine rate guard keeps a chatty/broken device from flooding
 *      the database.
 *
 * Nothing here may crash the loop: every message is handled inside a catch-all
 * that logs without dumping payloads (a payload could contain anything), and
 * the EntityManager is cleared afterwards so a long-running worker cannot grow
 * its identity map without bound.
 */
final class MqttMessageHandler
{
    private const BRANCH_TELEMETRY = 'telemetry';
    private const BRANCH_STATUS = 'status';
    private const BRANCH_EVENTS = 'events';

    /** @var array<string, list<float>> machine identifier => timestamps of recent telemetry attempts within the last second */
    private array $recentSamples = [];

    /** @var array<string, int> counter name => occurrences (for the worker summary) */
    private array $counters = [];

    public function __construct(
        private readonly MqttConfig $config,
        private readonly MqttTopicBuilder $topics,
        private readonly MachineRepository $machines,
        private readonly TelemetryProcessor $telemetryProcessor,
        private readonly MachineConnectivityService $connectivity,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Handle one broker message. Never throws — a bad message must not take
     * the consumer down with it.
     */
    public function handle(string $topic, string $payload): void
    {
        try {
            $this->process($topic, $payload);
        } catch (\Throwable $exception) {
            $this->count('failed');
            $this->logger->error('MQTT message dropped after an unexpected failure.', [
                'topic' => $topic,
                'reason' => $exception->getMessage(),
                'class' => $exception::class,
                // Deliberately no payload: it is untrusted input and may hold
                // anything. Metadata only.
            ]);

            if ($exception instanceof DbalException) {
                // Drop the (now unusable) handle so ensureDatabase() below
                // reconnects instead of retrying on a dead socket.
                $this->em->getConnection()->close();
            }
        } finally {
            // Long-running worker hygiene: detach everything between messages
            // and make sure the next one starts from a usable DB connection.
            $this->em->clear();
            $this->ensureDatabase();
        }
    }

    /** @return array<string, int> */
    public function counters(): array
    {
        return $this->counters;
    }

    private function process(string $topic, string $payload): void
    {
        $this->count('received');

        $parsed = $this->topics->parse($topic);
        if (null === $parsed) {
            $this->count('dropped');
            $this->logger->notice('MQTT message on an unknown topic dropped.', ['topic' => $topic]);

            return;
        }

        $identifier = $parsed['machineIdentifier'];
        $branch = $parsed['branch'];

        if (strlen($payload) > $this->config->getMaxPayloadBytes()) {
            $this->count('dropped');
            $this->logger->warning('MQTT payload exceeds MQTT_MAX_PAYLOAD_BYTES; dropped.', [
                'machine' => $identifier,
                'bytes' => strlen($payload),
                'limit' => $this->config->getMaxPayloadBytes(),
            ]);

            return;
        }

        $data = $this->decodeObject($payload, $identifier);
        if (null === $data) {
            return;
        }

        // Identity comes from the TOPIC, and only from the topic.
        $machine = $this->machines->findByIdentifier($identifier);
        if (null === $machine) {
            $this->count('dropped');
            $this->logger->warning('MQTT message for an unknown machine dropped (MQTT never creates machines).', [
                'machine' => $identifier,
                'topic' => $topic,
            ]);

            return;
        }

        match ($branch) {
            self::BRANCH_TELEMETRY => $this->handleTelemetry($machine, $identifier, $data),
            self::BRANCH_STATUS => $this->handleStatus($machine, $identifier, $data),
            self::BRANCH_EVENTS => $this->handleEvents($machine, $identifier, $data),
            default => $this->drop(sprintf('Unsupported topic branch "%s".', $branch), ['machine' => $identifier]),
        };
    }

    /**
     * Telemetry: the SAME pipeline as POST /api/machines/{id}/telemetry —
     * same validation, same storage, same liveness refresh, no copies.
     *
     * @param array<string, mixed> $data
     */
    private function handleTelemetry(Machine $machine, string $identifier, array $data): void
    {
        if ($this->isRateLimited($identifier)) {
            $this->count('rate_limited');
            $this->logger->notice('Telemetry dropped by the per-machine rate guard.', [
                'machine' => $identifier,
                'maxPerSecond' => $this->config->getTelemetryMaxPerSecond(),
            ]);

            return;
        }

        try {
            $this->telemetryProcessor->process($machine, $data);
            $this->count('telemetry');
        } catch (ValidationException $exception) {
            // Field names + messages only — the shared validator explains
            // itself without us echoing the payload back into the logs.
            $this->count('dropped');
            $this->logger->warning('Telemetry rejected by the shared validator.', [
                'machine' => $identifier,
                'violations' => $exception->violations,
            ]);
        }
    }

    /**
     * Status: {"online": true|false}.
     *
     * online=true  → the device is alive: refresh lastSeenAt (same atomic
     *                UPDATE as telemetry).
     * online=false → Last Will / graceful disconnect: make the DERIVED
     *                offline state take effect now. Both go through
     *                MachineConnectivityService so there is one source of
     *                truth for connectivity.
     *
     * @param array<string, mixed> $data
     */
    private function handleStatus(Machine $machine, string $identifier, array $data): void
    {
        $unknown = array_diff(array_keys($data), ['online']);
        if ([] !== $unknown || !\array_key_exists('online', $data) || !\is_bool($data['online'])) {
            $this->count('dropped');
            $this->logger->warning('Status payload must be exactly {"online": true|false}.', [
                'machine' => $identifier,
                'fields' => array_keys($data),
            ]);

            return;
        }

        if ($data['online']) {
            $this->connectivity->markSeen($machine);
            $this->count('status_online');
            $this->logger->info('Machine reported online over MQTT.', ['machine' => $identifier]);

            return;
        }

        $this->connectivity->markOffline($machine);
        $this->count('status_offline');
        $this->logger->info('Machine reported offline over MQTT (Last Will).', ['machine' => $identifier]);
    }

    /**
     * Events: PREPARED, not acted on. Validated and logged only — no side
     * effects anywhere in the system yet, so a firmware that starts emitting
     * events today changes nothing but a log line.
     *
     * @param array<string, mixed> $data
     */
    private function handleEvents(Machine $machine, string $identifier, array $data): void
    {
        if ([] === $data) {
            $this->count('dropped');
            $this->logger->warning('Event payload must be a non-empty JSON object.', ['machine' => $identifier]);

            return;
        }

        if (\array_key_exists('type', $data) && !\is_string($data['type'])) {
            $this->count('dropped');
            $this->logger->warning('Event "type" must be a string when present.', ['machine' => $identifier]);

            return;
        }

        $this->count('event');
        $this->logger->info('MQTT event received (prepared, not acted on).', [
            'machine' => $identifier,
            'type' => $data['type'] ?? null,
            // Field names, not values: an event is free-form device input.
            'fields' => array_keys($data),
        ]);
    }

    /**
     * Decode a payload that must be a JSON OBJECT (an array, number, string,
     * true/false or null are all refused).
     *
     * @return array<string, mixed>|null null when the payload is not usable
     */
    private function decodeObject(string $payload, string $identifier): ?array
    {
        try {
            // First pass: objects only, so `[]` and `{}` stay distinguishable.
            $object = json_decode($payload, false, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $this->count('dropped');
            $this->logger->warning('MQTT payload is not valid JSON; dropped.', ['machine' => $identifier]);

            return null;
        }

        if (!$object instanceof \stdClass) {
            $this->count('dropped');
            $this->logger->warning('MQTT payload must be a JSON object; dropped.', ['machine' => $identifier]);

            return null;
        }

        $data = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        if (!\is_array($data)) {
            // Unreachable for valid JSON objects; keeps the type honest.
            $this->count('dropped');

            return null;
        }

        /** @var array<string, mixed> $data */
        return $data;
    }

    /**
     * In-memory per-machine guard: accept at most MQTT_TELEMETRY_MAX_PER_SECOND
     * telemetry messages per rolling second, so one broken firmware cannot
     * hammer the database. A sliding window (not fixed buckets) so a burst can
     * never smuggle in 2× the limit across a second boundary. Nothing is
     * persisted — a restart resets it, by design.
     */
    private function isRateLimited(string $identifier): bool
    {
        $maxPerSecond = $this->config->getTelemetryMaxPerSecond();
        if ($maxPerSecond <= 0) {
            return false;
        }

        $now = microtime(true);
        $windowStart = $now - 1.0;
        $recent = array_values(array_filter(
            $this->recentSamples[$identifier] ?? [],
            static fn (float $timestamp): bool => $timestamp > $windowStart,
        ));

        if (\count($recent) >= $maxPerSecond) {
            $this->recentSamples[$identifier] = $recent;

            return true;
        }

        $recent[] = $now;
        $this->recentSamples[$identifier] = $recent;

        // Bound the map so a fleet of thousands cannot grow it forever.
        if (\count($this->recentSamples) > 512) {
            $this->evictLeastRecentlyActive();
        }

        return false;
    }

    /** Drop the machine whose newest sample is the oldest (entries are kept chronological). */
    private function evictLeastRecentlyActive(): void
    {
        $oldestIdentifier = null;
        $oldestTimestamp = \PHP_FLOAT_MAX;

        foreach ($this->recentSamples as $identifier => $timestamps) {
            $newest = $timestamps[\count($timestamps) - 1] ?? -\PHP_FLOAT_MAX;
            if ($newest < $oldestTimestamp) {
                $oldestTimestamp = $newest;
                $oldestIdentifier = $identifier;
            }
        }

        if (null !== $oldestIdentifier) {
            unset($this->recentSamples[$oldestIdentifier]);
        }
    }

    /**
     * @param array<string, mixed> $context
     */
    private function drop(string $reason, array $context = []): void
    {
        $this->count('dropped');
        $this->logger->warning($reason, $context);
    }

    private function count(string $key): void
    {
        $this->counters[$key] = ($this->counters[$key] ?? 0) + 1;
    }

    /**
     * A dead PostgreSQL connection must not poison the following messages:
     * closing it makes DBAL reconnect lazily on the next statement, and a
     * failed reconnect is logged instead of being allowed to loop forever.
     */
    private function ensureDatabase(): void
    {
        $connection = $this->em->getConnection();

        try {
            if ($connection->isConnected()) {
                return;
            }

            $connection->executeQuery('SELECT 1');
            $this->logger->info('Database connection re-established.');
        } catch (\Throwable $exception) {
            $connection->close();
            $this->logger->error('Database unreachable; connection dropped so the next message can reconnect.', [
                'reason' => $exception->getMessage(),
            ]);
        }
    }
}
