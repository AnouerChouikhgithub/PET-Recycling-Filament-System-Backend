<?php

declare(strict_types=1);

namespace App\Tests\IoT;

use App\Api\Exception\MqttUnavailableException;
use App\Service\IoT\MqttConnectionInterface;

/**
 * Test double for MqttConnectionInterface — the only way the suite talks to
 * "a broker", so no test ever opens a socket.
 *
 * Records every publish (topic, payload, qos, retain) so tests can assert the
 * exact wire message, and can be armed with $failure to simulate an
 * unreachable broker at any point of the command pipeline.
 */
final class FakeMqttConnection implements MqttConnectionInterface
{
    /** @var list<array{topic: string, payload: string, qos: int, retain: bool}> */
    public array $published = [];

    /** When set, publish() throws it — a broker that is down. */
    public ?MqttUnavailableException $failure = null;

    private bool $connected = true;

    public function publish(string $topic, string $payload, int $qos, bool $retain): void
    {
        if (null !== $this->failure) {
            throw $this->failure;
        }

        $this->published[] = [
            'topic' => $topic,
            'payload' => $payload,
            'qos' => $qos,
            'retain' => $retain,
        ];
    }

    public function isConnected(): bool
    {
        return $this->connected;
    }

    public function close(): void
    {
        $this->connected = false;
    }

    /** @return list<array{topic: string, payload: string, qos: int, retain: bool}> */
    public function published(): array
    {
        return $this->published;
    }

    /**
     * First recorded publish, decoded as JSON.
     *
     * @return array<string, mixed>
     */
    public function firstPayload(): array
    {
        $first = $this->published[0] ?? throw new \LogicException('Nothing was published.');
        $decoded = json_decode($first['payload'], true);

        return \is_array($decoded) ? $decoded : throw new \LogicException('Published payload is not a JSON object.');
    }
}
