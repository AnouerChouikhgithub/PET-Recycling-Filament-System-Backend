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

    private function base(string $machineIdentifier): string
    {
        return sprintf('%s/machines/%s', $this->prefix, $machineIdentifier);
    }
}
