<?php

declare(strict_types=1);

namespace App\Service\IoT;

/**
 * Builds the canonical MQTT topic tree for a machine. The prefix is
 * configurable via the MQTT_PREFIX env var (default `3awedlou`).
 *
 *     {prefix}/machines/{identifier}/telemetry   ESP32 → backend (samples)
 *     {prefix}/machines/{identifier}/status      ESP32 → backend (lifecycle)
 *     {prefix}/machines/{identifier}/events      ESP32 → backend (log-worthy events)
 *     {prefix}/machines/{identifier}/commands    backend → ESP32 (remote control)
 *
 * The frontend NEVER publishes here — Web/Mobile → Symfony → MQTT → ESP32.
 */
final class MqttTopicBuilder
{
    public function __construct(
        private readonly string $prefix = '3awedlou',
    ) {
    }

    public function telemetry(string $machineIdentifier): string
    {
        return $this->base($machineIdentifier).'/telemetry';
    }

    public function status(string $machineIdentifier): string
    {
        return $this->base($machineIdentifier).'/status';
    }

    public function events(string $machineIdentifier): string
    {
        return $this->base($machineIdentifier).'/events';
    }

    public function commands(string $machineIdentifier): string
    {
        return $this->base($machineIdentifier).'/commands';
    }

    /** Subscribe pattern that receives everything from one machine. */
    public function machineWildcard(string $machineIdentifier): string
    {
        return $this->base($machineIdentifier).'/#';
    }

    /** Subscribe pattern that receives the whole fleet. */
    public function fleetWildcard(): string
    {
        return sprintf('%s/machines/+', $this->prefix).'/#';
    }

    /**
     * The topics `app:mqtt:consume` subscribes to: everything a device may
     * say, and nothing else (commands are published here, never consumed).
     *
     * @return list<string>
     */
    public function inboundFilters(): array
    {
        return [
            sprintf('%s/machines/+/telemetry', $this->prefix),
            sprintf('%s/machines/+/status', $this->prefix),
            sprintf('%s/machines/+/events', $this->prefix),
        ];
    }

    /**
     * Parse a full topic back into the machine identifier and branch it
     * addresses. The inverse of the builders above.
     *
     * This is how the consumer decides WHICH machine a message belongs to:
     * identity comes from the topic, never from the payload, so one device
     * can never write another machine's data — it would have to publish on
     * the other machine's topic, which the broker ACL refuses.
     *
     * @return array{machineIdentifier: string, branch: string}|null null when the
     *         topic is outside {prefix}/machines/{identifier}/{branch}
     */
    public function parse(string $topic): ?array
    {
        $base = sprintf('%s/machines/', $this->prefix);
        if (!str_starts_with($topic, $base)) {
            return null;
        }

        // $base already consumed "{prefix}/machines/", so what is left is
        // "{identifier}/{branch}" — exactly two segments.
        $segments = explode('/', substr($topic, \strlen($base)));
        if (2 !== \count($segments)) {
            return null;
        }

        [$identifier, $branch] = [$segments[0], $segments[1]];
        if ('' === $identifier || '' === $branch) {
            return null;
        }

        return ['machineIdentifier' => $identifier, 'branch' => $branch];
    }

    private function base(string $machineIdentifier): string
    {
        return sprintf('%s/machines/%s', $this->prefix, $machineIdentifier);
    }
}
