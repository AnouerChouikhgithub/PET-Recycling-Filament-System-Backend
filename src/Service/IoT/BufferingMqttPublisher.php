<?php

declare(strict_types=1);

namespace App\Service\IoT;

use Psr\Log\LoggerInterface;

/**
 * Default MqttPublisherInterface implementation used until the real broker
 * client lands. It buffers the last messages in memory (handy in the dev
 * profiler / tests) and logs every publish at debug level.
 *
 * Swapping in the real client later is a one-line services.yaml change — no
 * consumer code changes.
 */
final class BufferingMqttPublisher implements MqttPublisherInterface
{
    /** @var list<array{topic: string, payload: string, qos: int, at: string}> */
    private array $published = [];

    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    public function publish(string $topic, string $payload, int $qos = 1): void
    {
        $this->published[] = [
            'topic' => $topic,
            'payload' => $payload,
            'qos' => $qos,
            'at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ];

        // Ring buffer so a long-running worker cannot leak memory.
        if (\count($this->published) > 100) {
            array_shift($this->published);
        }

        $this->logger->debug('MQTT publish (buffered — no broker configured).', [
            'topic' => $topic,
            'qos' => $qos,
        ]);
    }

    /** @return list<array{topic: string, payload: string, qos: int, at: string}> */
    public function published(): array
    {
        return $this->published;
    }
}
