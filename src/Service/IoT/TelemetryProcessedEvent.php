<?php

declare(strict_types=1);

namespace App\Service\IoT;

use App\Entity\Machine;
use App\Entity\MachineTelemetry;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Dispatched on the Symfony event dispatcher after a telemetry sample is
 * persisted. This is the seam where the realtime layer attaches: a future
 * WebSocket/SSE broadcaster or MQTT status publisher listens for
 * TelemetryProcessor::EVENT_NAME and fans the sample out to clients.
 */
final class TelemetryProcessedEvent extends Event
{
    public function __construct(
        public readonly Machine $machine,
        public readonly MachineTelemetry $telemetry,
        /** @var array<string, mixed> JSON view identical to the API telemetry shape */
        public readonly array $view,
    ) {
    }
}
