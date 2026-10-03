<?php

declare(strict_types=1);

namespace App\Service\IoT;

/**
 * MqttPublisherInterface implementation that talks to a REAL broker, selected
 * when MQTT_ENABLED=true.
 *
 * It is deliberately thin: topic, payload and QoS arrive from the caller
 * (MachineCommandService) and the only policy this class owns is the one that
 * must never be forgotten — retain is ALWAYS false.
 *
 * WHY retain must stay false: a retained "start heater" would be stored by the
 * broker and replayed to the device on every reconnect, long after the
 * operator's intent expired. Commands carry their own expiresAt instead.
 */
final class BrokerMqttPublisher implements MqttPublisherInterface
{
    public function __construct(
        private readonly MqttConnectionInterface $connection,
    ) {
    }

    public function publish(string $topic, string $payload, int $qos = 1): void
    {
        // retain = false, hard-coded. See the class docblock.
        $this->connection->publish($topic, $payload, $qos, false);
    }
}
