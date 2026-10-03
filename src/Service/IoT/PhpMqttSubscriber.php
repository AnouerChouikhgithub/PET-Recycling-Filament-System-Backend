<?php

declare(strict_types=1);

namespace App\Service\IoT;

use App\Api\Exception\MqttUnavailableException;
use PhpMqtt\Client\ConnectionSettings;
use PhpMqtt\Client\MqttClient;
use Psr\Log\LoggerInterface;

/**
 * Real MqttSubscriberInterface implementation backed by php-mqtt/client.
 *
 * Used by `app:mqtt:consume`. It owns the socket, the topic filters and the
 * loop only — every inbound message is handed straight to the caller's
 * callback, so all message semantics live in MqttMessageHandler (unit-tested
 * without a broker).
 *
 * Reconnection with backoff is deliberately NOT done here: the console
 * command owns the run loop, so it can also watch --max-runtime, memory and
 * exit codes while it decides when to reconnect.
 */
final class PhpMqttSubscriber implements MqttSubscriberInterface
{
    private const CONNECT_TIMEOUT_SECONDS = 10;
    private const SOCKET_TIMEOUT_SECONDS = 5;
    private const KEEP_ALIVE_SECONDS = 10;

    private ?MqttClient $client = null;

    /** @var list<string> */
    private array $filters = [];

    public function __construct(
        private readonly MqttConfig $config,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function subscribe(array $filters, int $qos, callable $onMessage): void
    {
        $client = $this->client();
        $this->filters = [];

        try {
            foreach ($filters as $filter) {
                $client->subscribe(
                    $filter,
                    static function (string $topic, string $message, bool $retained) use ($onMessage): void {
                        $onMessage($topic, $message, $retained);
                    },
                    $qos,
                );
                $this->filters[] = $filter;
            }
        } catch (\Throwable $exception) {
            $this->close();
            throw new MqttUnavailableException('The MQTT broker rejected the subscription.', $exception);
        }

        $this->logger->info('Subscribed to MQTT topics.', [
            'filters' => $this->filters,
            'qos' => $qos,
        ]);
    }

    public function loopFor(float $seconds): void
    {
        // Deliberately does NOT reconnect: the console command owns the
        // reconnect/backoff policy (together with --max-runtime and the exit
        // code), so a lost connection must surface as an exception here.
        if (null === $this->client || !$this->client->isConnected()) {
            throw new MqttUnavailableException('Not connected to the MQTT broker.');
        }

        $client = $this->client;
        $timeout = max(0.0, $seconds);

        // The library's loop() only returns when interrupt() is called, so the
        // wall-clock budget is enforced by a loop hook that interrupts itself
        // at $timeout. A SIGINT/SIGTERM handler calls interrupt() the very same
        // way (pcntl only — it does not exist on Windows, where --max-runtime
        // is what bounds the worker).
        $stopAt = static function (MqttClient $mqtt, float $elapsedTime) use ($timeout): void {
            if ($elapsedTime >= $timeout) {
                $mqtt->interrupt();
            }
        };
        $client->registerLoopEventHandler($stopAt);

        try {
            // allowSleep = true (idle waits ~100ms per pass),
            // exitWhenQueuesEmpty = false (only interrupt() ends this).
            $client->loop(true, false);
        } catch (\Throwable $exception) {
            $this->close();
            throw new MqttUnavailableException('The connection to the MQTT broker was lost.', $exception);
        } finally {
            $client->unregisterLoopEventHandler($stopAt);
        }
    }

    public function interrupt(): void
    {
        if (null === $this->client) {
            return;
        }

        try {
            $this->client->interrupt();
        } catch (\Throwable) {
            // Best effort: --max-runtime still bounds the worker.
        }
    }

    public function isConnected(): bool
    {
        return null !== $this->client && $this->client->isConnected();
    }

    public function close(): void
    {
        if (null === $this->client) {
            return;
        }

        try {
            if ($this->client->isConnected()) {
                $this->client->disconnect();
            }
        } catch (\Throwable) {
            // The socket is already gone — nothing to clean up.
        }

        $this->client = null;
        $this->filters = [];
    }

    /** @throws MqttUnavailableException */
    private function client(): MqttClient
    {
        if (null !== $this->client && $this->client->isConnected()) {
            return $this->client;
        }

        $this->close();

        $settings = (new ConnectionSettings())
            ->setConnectTimeout(self::CONNECT_TIMEOUT_SECONDS)
            ->setSocketTimeout(self::SOCKET_TIMEOUT_SECONDS)
            ->setKeepAliveInterval(self::KEEP_ALIVE_SECONDS);

        if ('' !== $this->config->getUsername()) {
            $settings = $settings
                ->setUsername($this->config->getUsername())
                ->setPassword($this->config->getPassword());
        }

        if ($this->config->isTlsEnabled()) {
            $settings = $settings->setUseTls(true);
        }

        $client = new MqttClient(
            $this->config->getHost(),
            $this->config->getPort(),
            $this->config->createConnectionClientId(),
            MqttClient::MQTT_3_1_1,
            null,
            $this->logger,
        );

        try {
            $client->connect($settings, true);
        } catch (\Throwable $exception) {
            throw new MqttUnavailableException('The MQTT broker could not be reached.', $exception);
        }

        $this->client = $client;

        return $client;
    }
}
