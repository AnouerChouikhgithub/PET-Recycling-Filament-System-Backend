<?php

declare(strict_types=1);

namespace App\Service\IoT;

use App\Api\Exception\MqttUnavailableException;
use App\Api\Exception\ValidationException;
use App\Entity\Machine;
use App\Entity\MachineCommandAudit;
use App\Entity\MachineCommandType;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Machine command pipeline:
 *
 *     POST /api/machines/{id}/commands
 *        → ownership check (MachineQueryService / MachineAccess)
 *        → MachineCommandGuard (safety rules, config-driven ranges)
 *        → MachineCommandAudit row (WHO sent WHAT, WHEN) — written FIRST so
 *          its id becomes the correlation id of the wire message
 *        → MqttPublisherInterface ({prefix}/machines/{id}/commands)
 *        → MachineCommandEvent (realtime/fan-out seam)
 *
 * HONEST STATUS — acceptance is never execution:
 *   * MQTT_ENABLED=false → the message is buffered in memory and never leaves
 *     the process. `delivery: buffered_not_sent`.
 *   * MQTT_ENABLED=true  → `delivery: published_to_broker` means the BROKER
 *     acknowledged a QoS 1 publish. It does NOT mean a device received or
 *     executed anything: no ESP32 exists yet and no acknowledgement channel
 *     is implemented (`deviceAcknowledged` stays false).
 *   * broker unreachable → HTTP 503 MQTT_UNAVAILABLE, the failure is recorded
 *     on the audit row, and success is never reported.
 *
 * Order matters for safety: guard → audit → publish. A command rejected by
 * the guard writes no audit row and publishes nothing; a command that passes
 * the guard is always audited, even when the broker is down.
 */
final class MachineCommandService
{
    public const EVENT_NAME = 'app.machine_command_dispatched';

    /** Wire format of the command envelope (see docs/mqtt-contract.md). */
    public const PAYLOAD_VERSION = 1;

    /** Audit transport recorded when the broker acknowledged the publish. */
    public const TRANSPORT_MQTT = 'mqtt';

    /** Audit transport recorded when no broker is configured. */
    public const TRANSPORT_BUFFERED = 'buffered-log';

    /** Audit transport recorded when the broker could not be reached. */
    public const TRANSPORT_UNAVAILABLE = 'mqtt-unavailable';

    public function __construct(
        private readonly MachineCommandGuard $guard,
        private readonly MqttPublisherInterface $mqtt,
        private readonly MqttTopicBuilder $topics,
        private readonly EventDispatcherInterface $dispatcher,
        private readonly EntityManagerInterface $em,
        private readonly TokenStorageInterface $tokenStorage,
        private readonly MqttConfig $mqttConfig,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<string,mixed> $payload command + optional value/params
     *
     * @throws ValidationException unsafe/invalid command
     * @throws MqttUnavailableException validated + audited, but the broker
     *                                  could not be reached (HTTP 503)
     *
     * @return array<string, mixed> accepted-command view (API response data)
     */
    public function dispatch(Machine $machine, array $payload): array
    {
        if (!\array_key_exists('command', $payload) || !\is_string($payload['command'])) {
            throw new ValidationException('Command rejected.', [
                'command' => ['A command name is required.'],
            ]);
        }

        $type = MachineCommandType::tryFrom($payload['command']);
        if (null === $type) {
            throw new ValidationException('Command rejected.', [
                'command' => ['Unknown command. Accepted: '.implode(', ', MachineCommandType::values()).'.'],
            ]);
        }

        // 1. Safety gate. Runs BEFORE the audit row and before any publish:
        //    a rejected command must leave no trace and reach no topic.
        $value = $this->guard->guard($machine, $type, $payload);

        // 2. Audit row first — its id is the correlation id of the message,
        //    so a future device acknowledgement can be matched to this exact
        //    dispatch. Uuid::v7 is generated in the constructor, so the id is
        //    known before the first flush.
        $caller = null;
        $tokenUser = $this->tokenStorage->getToken()?->getUser();
        if ($tokenUser instanceof \App\Entity\User) {
            $caller = $tokenUser;
        }

        $audit = new MachineCommandAudit($machine, $caller, $type, null === $value ? null : (float) $value);
        $this->em->persist($audit);
        $this->em->flush();

        $issuedAt = $audit->getCreatedAt();
        $expiresAt = $issuedAt->add(new \DateInterval(sprintf('PT%dS', $this->mqttConfig->getCommandTtlSeconds())));

        // 3. Versioned wire envelope. No user PII: the caller's identity
        //    stays in the audit row, never on the broker.
        $message = [
            'v' => self::PAYLOAD_VERSION,
            'commandId' => $audit->getId()->toRfc4122(),
            'type' => $type->value,
            'value' => $value,
            'issuedAt' => $issuedAt->format(\DateTimeInterface::ATOM),
            'expiresAt' => $expiresAt->format(\DateTimeInterface::ATOM),
        ];

        $topic = $this->topics->commands($machine->getIdentifier());
        $qos = $this->mqttConfig->getQos();

        try {
            // retain is always false (BrokerMqttPublisher enforces it).
            $this->mqtt->publish($topic, (string) json_encode($message, JSON_THROW_ON_ERROR), $qos);
        } catch (MqttUnavailableException $exception) {
            // Record the failure on the audit row, then let the API answer
            // 503 MQTT_UNAVAILABLE. Reporting "accepted" here would be a lie.
            $audit->setTransport(self::TRANSPORT_UNAVAILABLE);
            $this->em->flush();

            $this->logger->error('Machine command audited but NOT published — broker unavailable.', [
                'commandId' => $audit->getId()->toRfc4122(),
                'machine' => $machine->getIdentifier(),
                'command' => $type->value,
                'topic' => $topic,
                'reason' => $exception->getMessage(),
            ]);

            throw $exception;
        }

        $transport = $this->mqttConfig->isEnabled() ? self::TRANSPORT_MQTT : self::TRANSPORT_BUFFERED;
        $audit->setTransport($transport);
        $this->em->flush();

        $view = [
            'machineId' => $machine->getId()->toRfc4122(),
            'identifier' => $machine->getIdentifier(),
            'command' => $type->value,
            'value' => $value,
            'topic' => $topic,
            'accepted' => true,
            // Correlation id of the wire message — same value as the audit row.
            'commandId' => $audit->getId()->toRfc4122(),
            // Honesty marker: clients must NOT treat this as machine execution.
            'delivery' => $this->mqttConfig->isEnabled() ? 'published_to_broker' : 'buffered_not_sent',
            'deviceAcknowledged' => false,
            'sentAt' => $issuedAt->format(\DateTimeInterface::ATOM),
            // The instant after which the command is stale (sentAt + TTL).
            'expiresAt' => $expiresAt->format(\DateTimeInterface::ATOM),
        ];

        $this->dispatcher->dispatch(new MachineCommandEvent($machine, $type, $message, $view), self::EVENT_NAME);

        return $view;
    }
}
