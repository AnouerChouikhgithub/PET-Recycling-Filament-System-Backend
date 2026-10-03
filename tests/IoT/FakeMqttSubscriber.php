<?php

declare(strict_types=1);

namespace App\Tests\IoT;

use App\Api\Exception\MqttUnavailableException;
use App\Service\IoT\MqttSubscriberInterface;

/**
 * Test double for MqttSubscriberInterface: messages are queued with push()
 * and delivered when loopFor() runs, exactly like a broker would deliver
 * them. No socket, no timing, no sleeping.
 */
final class FakeMqttSubscriber implements MqttSubscriberInterface
{
    /** @var list<string> */
    public array $filters = [];

    public int $qos = 0;

    public int $loops = 0;

    public int $interrupts = 0;

    /** When set, subscribe()/loopFor() throw it — a broker that is down. */
    public ?MqttUnavailableException $failure = null;

    private bool $connected = false;

    private bool $interrupted = false;

    /** @var callable(string, string, bool): void|null */
    private $onMessage = null;

    /** @var list<array{topic: string, payload: string}> */
    private array $queued = [];

    public function subscribe(array $filters, int $qos, callable $onMessage): void
    {
        if (null !== $this->failure) {
            throw $this->failure;
        }

        $this->filters = $filters;
        $this->qos = $qos;
        $this->onMessage = $onMessage;
        $this->connected = true;
    }

    public function loopFor(float $seconds): void
    {
        if (!$this->connected) {
            throw new MqttUnavailableException('Not connected to the MQTT broker.');
        }

        $this->loops++;

        $pending = $this->queued;
        $this->queued = [];

        foreach ($pending as $message) {
            if ($this->interrupted) {
                break;
            }
            if (null !== $this->onMessage) {
                ($this->onMessage)($message['topic'], $message['payload'], false);
            }
        }
    }

    public function interrupt(): void
    {
        $this->interrupted = true;
        $this->interrupts++;
    }

    public function isConnected(): bool
    {
        return $this->connected;
    }

    public function close(): void
    {
        $this->connected = false;
        $this->onMessage = null;
    }

    /** Queue a broker message for the next loopFor() call. */
    public function push(string $topic, string $payload): void
    {
        $this->queued[] = ['topic' => $topic, 'payload' => $payload];
    }
}
