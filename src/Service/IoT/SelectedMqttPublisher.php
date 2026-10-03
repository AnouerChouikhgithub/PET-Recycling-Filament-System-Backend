<?php

declare(strict_types=1);

namespace App\Service\IoT;

/**
 * Picks the outbound transport from MQTT_ENABLED (via MqttConfig).
 *
 *   false (default) -> BufferingMqttPublisher: the historical in-memory
 *                      buffer + debug log. No socket is opened, so tests and
 *                      broker-less development behave exactly as they did
 *                      before phase 3.
 *   true            -> BrokerMqttPublisher, which reaches the real broker and
 *                      raises MqttUnavailableException when it cannot.
 *
 * Taking MqttConfig (rather than a raw bool) means the switch and the payload
 * settings share ONE source of truth — and lets a test flip the transport the
 * same way production does.
 */
final class SelectedMqttPublisher implements MqttPublisherInterface
{
    public function __construct(
        private readonly MqttConfig $config,
        private readonly MqttPublisherInterface $buffered,
        private readonly MqttPublisherInterface $broker,
    ) {
    }

    public function publish(string $topic, string $payload, int $qos = 1): void
    {
        if ($this->config->isEnabled()) {
            $this->broker->publish($topic, $payload, $qos);

            return;
        }

        $this->buffered->publish($topic, $payload, $qos);
    }

    public function isEnabled(): bool
    {
        return $this->config->isEnabled();
    }
}
