<?php

declare(strict_types=1);

namespace App\Tests\IoT;

use App\Api\Exception\MqttUnavailableException;
use App\Service\IoT\MqttPublisherInterface;

/**
 * Test double for MqttPublisherInterface used where the test replaces the
 * transport itself (SelectedMqttPublisher is made public precisely so this
 * can be injected into a real HTTP request).
 *
 * It records what the pipeline tried to send and can simulate an unreachable
 * broker, which is how the 503 MQTT_UNAVAILABLE path is exercised end to end.
 */
final class FakeMqttPublisher implements MqttPublisherInterface
{
    /** @var list<array{topic: string, payload: string, qos: int}> */
    public array $published = [];

    /** When set, publish() throws it — a broker that is down. */
    public ?MqttUnavailableException $failure = null;

    public function publish(string $topic, string $payload, int $qos = 1): void
    {
        if (null !== $this->failure) {
            throw $this->failure;
        }

        $this->published[] = ['topic' => $topic, 'payload' => $payload, 'qos' => $qos];
    }
}
