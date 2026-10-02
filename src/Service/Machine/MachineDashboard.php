<?php

declare(strict_types=1);

namespace App\Service\Machine;

use App\Entity\Machine;
use App\Entity\MachineSession;
use App\Entity\MachineTelemetry;

/**
 * Read model for the frontend dashboards: one query that returns everything
 * the "home"/"machine" screens need, so both apps render identical data from
 * identical responses.
 */
final class MachineDashboard
{
    public function __construct(
        public readonly Machine $machine,
        public readonly ?MachineTelemetry $latestTelemetry,
        public readonly ?MachineSession $activeSession,
        /** @var list<MachineTelemetry> oldest → newest, for charts */
        public readonly array $recentTelemetry,
        /** @var array{records: int, inputGrams: float|null, outputGrams: float|null} */
        public readonly array $recyclingTotals,
    ) {
    }
}
