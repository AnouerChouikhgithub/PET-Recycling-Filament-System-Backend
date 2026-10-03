<?php

declare(strict_types=1);

namespace App\Service\IoT;

use App\Api\Exception\MqttUnavailableException;

/**
 * OUR thin seam over the MQTT client library (php-mqtt/client).
 *
 * Business logic (publishers, services, the command pipeline) depends on this
 * interface only, so every rule can be unit-tested against a fake and the
 * library can be swapped or stubbed without touching a single caller. Nothing
 * outside App\Service\IoT\PhpMqtt* may reference PhpMqtt\Client directly.
 */
interface MqttConnectionInterface
{
    /**
     * Publish one already-serialized payload to a full topic.
     *
     * $retain MUST stay false for machine commands: a retained "start
     * heater" would be replayed to the device on every reconnect.
     *
     * Implementations must only return once the broker has acknowledged a
     * QoS > 0 publish — "we wrote bytes to a socket" is not "published".
     *
     * @throws MqttUnavailableException when the broker is unreachable or does
     *                                  not acknowledge in time
     */
    public function publish(string $topic, string $payload, int $qos, bool $retain): void;

    public function isConnected(): bool;

    /** Drop the connection (and any queued state) so the next call reconnects. */
    public function close(): void;
}
