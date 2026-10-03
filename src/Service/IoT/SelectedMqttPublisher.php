<?php

declare(strict_types=1);

namespace App\Service\IoT;

/**
 * Picks the outbound transport from MQTT_ENABLED.
 *
 *   false (default) -> BufferingMqttPublisher: the historical in-memory
 *                      buffer + debug log. No socket is opened, so tests and
 *                      broker-less development behave exactly as they did
 *                      before phase 3.
 *   true            -> BrokerMqttPublisher, which reaches the real broker and
 *                      raises MqttUnavailableException when it cannot.
 *
 * Both collaborators are real MqttPublisherInterface instances injected by
 * config/services.yaml, so the whole choice is observable in a unit test.
 */
final class SelectedMqttPublisher implements MqttPublisherInterface
{
    public function __construct(
        private readonly bool $enabled,
        private readonly MqttPublisherInterface $buffered,
        private readonly MqttPublisherInterface $broker,
    ) {
    }

    public function publish(string $topic, string $payload, int $qos = 1): void
    {
        if ($this->enabled) {
            $this->broker->publish($topic, $payload, $qos);

            return;
        }

        $this->buffered->publish($topic, $payload, $qos);
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }
}
