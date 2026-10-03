<?php

declare(strict_types=1);

namespace App\Service\IoT;

/**
 * Typed, single-source access to the MQTT_* environment variables.
 *
 * Every value is bound once in config/services.yaml from an env var with an
 * explicit type, so a malformed value fails at container build/boot time
 * instead of deep inside a publish. Nothing here is secret except the broker
 * password: it is redacted from __debugInfo() so a var_dump / profiler /
 * log-context dump can never leak it.
 *
 * MQTT_ENABLED is the master switch:
 *   false (default) -> MqttPublisherInterface resolves to the in-memory
 *                      BufferingMqttPublisher: no socket, no broker, tests
 *                      and broker-less development behave exactly as before.
 *   true            -> BrokerMqttPublisher talks to the real broker, and
 *                      `app:mqtt:consume` may be started.
 */
final class MqttConfig
{
    public function __construct(
        private readonly bool $enabled = false,
        private readonly string $host = 'localhost',
        private readonly int $port = 1883,
        #[\SensitiveParameter]
        private readonly string $username = '',
        #[\SensitiveParameter]
        private readonly string $password = '',
        private readonly string $clientId = '3awedlou-backend',
        private readonly bool $tls = false,
        private readonly string $prefix = '3awedlou',
        private readonly int $qos = 1,
        private readonly int $commandTtlSeconds = 30,
        private readonly int $maxPayloadBytes = 4096,
        private readonly int $telemetryMaxPerSecond = 2,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function getHost(): string
    {
        return $this->host;
    }

    public function getPort(): int
    {
        return $this->port;
    }

    public function getUsername(): string
    {
        return $this->username;
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    public function getClientId(): string
    {
        return $this->clientId;
    }

    public function isTlsEnabled(): bool
    {
        return $this->tls;
    }

    public function getPrefix(): string
    {
        return $this->prefix;
    }

    /** QoS used for outbound publishes and inbound subscriptions (0|1|2). */
    public function getQos(): int
    {
        return $this->qos;
    }

    /**
     * Lifetime of a machine command on the broker: expiresAt = issuedAt +
     * this many seconds. A command that has not been consumed within the
     * TTL must be treated as stale by the firmware.
     */
    public function getCommandTtlSeconds(): int
    {
        return $this->commandTtlSeconds;
    }

    /** Hard cap on an inbound MQTT payload; larger messages are dropped. */
    public function getMaxPayloadBytes(): int
    {
        return $this->maxPayloadBytes;
    }

    /** Per-machine ceiling on accepted telemetry messages per second. */
    public function getTelemetryMaxPerSecond(): int
    {
        return $this->telemetryMaxPerSecond;
    }

    /**
     * Client id used for a real connection.
     *
     * MQTT allows only ONE live connection per client id: the HTTP publisher
     * and `app:mqtt:consume` run in different processes, so the configured id
     * is a prefix and every connection appends a short random suffix. Without
     * it the two would continuously kick each other off the broker.
     */
    public function createConnectionClientId(): string
    {
        return sprintf('%s-%s', $this->clientId, bin2hex(random_bytes(4)));
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return [
            'enabled' => $this->enabled,
            'host' => $this->host,
            'port' => $this->port,
            'username' => $this->username,
            'password' => '[redacted]',
            'clientId' => $this->clientId,
            'tls' => $this->tls,
            'prefix' => $this->prefix,
            'qos' => $this->qos,
            'commandTtlSeconds' => $this->commandTtlSeconds,
            'maxPayloadBytes' => $this->maxPayloadBytes,
            'telemetryMaxPerSecond' => $this->telemetryMaxPerSecond,
        ];
    }
}
