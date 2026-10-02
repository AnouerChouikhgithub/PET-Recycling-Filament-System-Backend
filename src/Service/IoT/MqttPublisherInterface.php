<?php

declare(strict_types=1);

namespace App\Service\IoT;

/**
 * Contract for publishing messages to the machine fleet over MQTT.
 *
 * Phase 2 will ship a real implementation (symfony/messenger + php-mqtt/client
 * or similar) behind this interface; until then a buffering no-op keeps the
 * command pipeline testable and the API contract stable. Consumers — the
 * command service, the telemetry processor's future status fan-out — depend on
 * this abstraction, never on a concrete broker client.
 */
interface MqttPublisherInterface
{
    /**
     * Publish $payload (already-serialized JSON) to a full topic, e.g.
     * `3awedlou/machines/3awedlou-001/commands`.
     */
    public function publish(string $topic, string $payload, int $qos = 1): void;
}
