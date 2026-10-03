<?php

declare(strict_types=1);

namespace App\Service\IoT;

/**
 * Contract for publishing messages to the machine fleet over MQTT.
 *
 * Two implementations, selected by MQTT_ENABLED (see SelectedMqttPublisher):
 *   - BufferingMqttPublisher — default: buffers + logs, opens no socket, so
 *     tests and broker-less development are unchanged;
 *   - BrokerMqttPublisher — real broker via MqttConnectionInterface; throws
 *     MqttUnavailableException instead of pretending a publish succeeded.
 *
 * Consumers (the command service, the realtime fan-out) depend on this
 * abstraction only, never on a concrete broker client — which is also what
 * lets the whole suite run without a broker.
 */
interface MqttPublisherInterface
{
    /**
     * Publish $payload (already-serialized JSON) to a full topic, e.g.
     * `{prefix}/machines/{identifier}/commands`.
     *
     * Implementations MUST publish with retain = false — a retained command
     * would be replayed to the device on every reconnect.
     *
     * @throws \App\Api\Exception\MqttUnavailableException when the real broker
     *                                  cannot be reached (implementations that
     *                                  do not talk to a broker never throw)
     */
    public function publish(string $topic, string $payload, int $qos = 1): void;
}
