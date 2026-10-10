<?php

declare(strict_types=1);

namespace App\Service\Agent\Tools;

use App\Service\Agent\AgentTool;
use App\Service\Machine\MachineQueryService;

/**
 * Latest telemetry for ONE owned machine. Ownership is re-checked in CODE
 * (MachineQueryService -> MachineAccess::getOwnedMachine): a machine the
 * user does not own behaves exactly like an unknown id (MACHINE_NOT_FOUND).
 *
 * Honesty contract (docs/ai-agent-test-plan.md T5):
 *   - unreported channels arrive as the literal string "Not reported";
 *   - recordedAt + dataAgeSeconds always present;
 *   - staleness label: >120 s "stale", >600 s "very stale, telemetry missing".
 */
final class GetLatestTelemetryTool implements AgentTool
{
    private const STALE_AFTER_SECONDS = 120;
    private const VERY_STALE_AFTER_SECONDS = 600;

    public function __construct(
        private readonly MachineQueryService $queryService,
    ) {
    }

    public function name(): string
    {
        return 'get_latest_telemetry';
    }

    public function description(): string
    {
        return 'Latest telemetry sample of one of the user\'s machines, with data age and staleness label.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'machineId' => ['type' => 'string'],
            ],
            'required' => ['machineId'],
            'additionalProperties' => false,
        ];
    }

    public function execute(array $input): array
    {
        $machineId = $input['machineId'] ?? null;
        if (!\is_string($machineId) || '' === $machineId) {
            return ['ok' => false, 'code' => 'VALIDATION_FAILED'];
        }

        // Ownership gate FIRST - throws ResourceNotFoundException for
        // unknown OR not-owned ids (same envelope, no enumeration).
        try {
            $machine = $this->queryService->getMachine($machineId);
        } catch (\Throwable) {
            return ['ok' => false, 'code' => 'MACHINE_NOT_FOUND'];
        }

        $telemetry = $this->queryService->latestTelemetry($machine);
        if (null === $telemetry) {
            return ['ok' => true, 'data' => ['machineId' => $machineId, 'sample' => null, 'staleness' => 'no telemetry ever reported']];
        }

        $now = new \DateTimeImmutable();
        $ageSeconds = max(0, $now->getTimestamp() - $telemetry->getRecordedAt()->getTimestamp());
        $staleness = $ageSeconds > self::VERY_STALE_AFTER_SECONDS
            ? 'very stale, telemetry missing'
            : ($ageSeconds > self::STALE_AFTER_SECONDS ? 'stale' : 'fresh');

        $notReported = 'Not reported';

        return ['ok' => true, 'data' => [
            'machineId' => $machineId,
            'temperature' => $telemetry->getTemperature(),
            'targetTemperature' => $telemetry->getTargetTemperature(),
            'heaterState' => $telemetry->getHeaterState(),
            'motorState' => $telemetry->getMotorState(),
            'motorSpeed' => $telemetry->getMotorSpeed(),
            // fanState/filamentSpeed/filamentDiameter/energyConsumption have no
            // hardware source today - null must NEVER reach the model as 0/false.
            'fanState' => $telemetry->getFanState() ?? $notReported,
            'filamentSpeed' => $telemetry->getFilamentSpeed() ?? $notReported,
            'filamentDiameter' => $telemetry->getFilamentDiameter() ?? $notReported,
            'energyConsumption' => $telemetry->getEnergyConsumption() ?? $notReported,
            'recordedAt' => $telemetry->getRecordedAt()->format(\DateTimeInterface::ATOM),
            'dataAgeSeconds' => $ageSeconds,
            'staleness' => $staleness,
        ]];
    }
}
