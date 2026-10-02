<?php

declare(strict_types=1);

namespace App\Service\Realtime;

use App\Service\IoT\MachineCommandEvent;
use App\Service\IoT\TelemetryProcessedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Bridges in-process domain events to the realtime layer. Keeps producers
 * (telemetry processor, command service) transport-agnostic: they dispatch
 * events, this subscriber decides how clients hear about them.
 */
final class RealtimeListener implements EventSubscriberInterface
{
    public function __construct(private readonly RealtimeBroadcaster $broadcaster)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            TelemetryProcessedEvent::class => 'onTelemetry',
            MachineCommandEvent::class => 'onCommand',
        ];
    }

    public function onTelemetry(TelemetryProcessedEvent $event): void
    {
        $this->broadcaster->broadcast([
            'type' => 'machine.telemetry',
            'topic' => sprintf('machines/%s/telemetry', $event->machine->getIdentifier()),
            'payload' => $event->view,
            'occurredAt' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ]);
    }

    public function onCommand(MachineCommandEvent $event): void
    {
        $this->broadcaster->broadcast([
            'type' => 'machine.command',
            'topic' => sprintf('machines/%s/commands', $event->machine->getIdentifier()),
            'payload' => $event->view,
            'occurredAt' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ]);
    }
}
