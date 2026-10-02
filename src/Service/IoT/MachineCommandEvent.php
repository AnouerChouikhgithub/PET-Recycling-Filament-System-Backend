<?php

declare(strict_types=1);

namespace App\Service\IoT;

use App\Entity\Machine;
use App\Entity\MachineCommandType;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Dispatched after a command passed the safety guard and was handed to the
 * MQTT transport. Future realtime layer listeners can use this to notify
 * dashboards that a command was accepted.
 */
final class MachineCommandEvent extends Event
{
    /**
     * @param array<string, mixed> $message payload published to the broker
     * @param array<string, mixed> $view    API response shape
     */
    public function __construct(
        public readonly Machine $machine,
        public readonly MachineCommandType $type,
        public readonly array $message,
        public readonly array $view,
    ) {
    }
}
