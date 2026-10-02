<?php

declare(strict_types=1);

namespace App\Dto\Transformer;

use App\Entity\Machine;
use App\Entity\MachineSession;
use App\Entity\MachineTelemetry;
use App\Service\Machine\MachineConnectivityService;

/**
 * Builds the JSON representation of machines for the API.
 *
 * `status` is the EFFECTIVE status: the reported lifecycle state while the
 * machine is reachable, `offline` once lastSeenAt lapsed the configured
 * threshold — the same rule for web, mobile and every other client.
 */
final class MachineViewFactory
{
    public function __construct(
        private readonly MachineConnectivityService $connectivity,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function create(Machine $machine): array
    {
        $lastSeenAt = $machine->getLastSeenAt()?->format(\DateTimeInterface::ATOM);

        return [
            'id' => $machine->getId()->toRfc4122(),
            'name' => $machine->getName(),
            'identifier' => $machine->getIdentifier(),
            'status' => $this->connectivity->effectiveStatus($machine)->value,
            'reportedStatus' => $machine->getStatus()->value,
            'lastSeenAt' => $lastSeenAt,
            'secondsSinceLastSeen' => $this->connectivity->secondsSinceLastSeen($machine),
            'createdAt' => $machine->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'updatedAt' => $machine->getUpdatedAt()->format(\DateTimeInterface::ATOM),
            'owner' => $machine->getOwner()?->getId()->toRfc4122(),
        ];
    }

    /**
     * Detailed view: machine + latest telemetry + running session, matching
     * what the frontends render on their machine dashboards.
     *
     * @return array<string, mixed>
     */
    public function createDetailed(Machine $machine, ?MachineTelemetry $latestTelemetry, ?MachineSession $activeSession): array
    {
        $view = $this->create($machine);
        $view['telemetry'] = null !== $latestTelemetry ? $this->telemetry($latestTelemetry) : null;
        $view['activeSessionId'] = $activeSession?->getId()->toRfc4122();

        return $view;
    }

    /**
     * @return array<string, mixed>
     */
    public function telemetry(MachineTelemetry $t): array
    {
        return [
            'id' => $t->getId()->toRfc4122(),
            'machineId' => $t->getMachine()->getId()->toRfc4122(),
            'temperature' => $t->getTemperature(),
            'targetTemperature' => $t->getTargetTemperature(),
            'heaterState' => $t->getHeaterState(),
            'motorState' => $t->getMotorState(),
            'motorSpeed' => $t->getMotorSpeed(),
            'fanState' => $t->getFanState(),
            'filamentSpeed' => $t->getFilamentSpeed(),
            'filamentDiameter' => $t->getFilamentDiameter(),
            'energyConsumption' => $t->getEnergyConsumption(),
            'extra' => $t->getExtra(),
            'recordedAt' => $t->getRecordedAt()->format(\DateTimeInterface::ATOM),
        ];
    }

    /**
     * JSON view of one projected telemetry row (no entity — the list endpoint
     * stays cheap on large histories). `machineId` is appended by the caller.
     *
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    public function telemetryRow(array $row): array
    {
        return [
            'id' => (string) $row['id'],
            'temperature' => $row['temperature'],
            'targetTemperature' => $row['targetTemperature'],
            'heaterState' => $row['heaterState'],
            'motorState' => $row['motorState'],
            'motorSpeed' => $row['motorSpeed'],
            'fanState' => $row['fanState'],
            'filamentSpeed' => $row['filamentSpeed'],
            'filamentDiameter' => $row['filamentDiameter'],
            'energyConsumption' => $row['energyConsumption'],
            'extra' => $row['extra'],
            'recordedAt' => $row['recordedAt'] instanceof \DateTimeInterface
                ? $row['recordedAt']->format(\DateTimeInterface::ATOM)
                : (string) $row['recordedAt'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function session(MachineSession $session): array
    {
        return [
            'id' => $session->getId()->toRfc4122(),
            'machineId' => $session->getMachine()->getId()->toRfc4122(),
            'operator' => $session->getOperator()?->getId()->toRfc4122(),
            'startedAt' => $session->getStartedAt()->format(\DateTimeInterface::ATOM),
            'endedAt' => $session->getEndedAt()?->format(\DateTimeInterface::ATOM),
            'status' => $session->getStatus()->value,
            'materialInput' => $session->getMaterialInput(),
            'materialOutput' => $session->getMaterialOutput(),
            'durationMinutes' => $session->getDurationMinutes(),
            'notes' => $session->getNotes(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function production(\App\Entity\FilamentProduction $production): array
    {
        return [
            'id' => $production->getId()->toRfc4122(),
            'sessionId' => $production->getSession()->getId()->toRfc4122(),
            'batchCode' => $production->getBatchCode(),
            'diameterTarget' => $production->getDiameterTarget(),
            'diameterActual' => $production->getDiameterActual(),
            'diameterSamples' => $production->getDiameterSamples(),
            'weightGrams' => $production->getWeightGrams(),
            'lengthMeters' => $production->getLengthMeters(),
            'durationMinutes' => $production->getDurationMinutes(),
            'material' => $production->getMaterial(),
            'color' => $production->getColorName(),
            'colorHex' => $production->getColorHex(),
            'quality' => $production->getQuality()->value,
            'notes' => $production->getNotes(),
            'producedAt' => $production->getProducedAt()->format(\DateTimeInterface::ATOM),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function recycling(\App\Entity\RecyclingSession $recycling): array
    {
        return [
            'id' => $recycling->getId()->toRfc4122(),
            'sessionId' => $recycling->getSession()->getId()->toRfc4122(),
            'machineId' => $recycling->getMachine()->getId()->toRfc4122(),
            'inputMaterial' => $recycling->getInputMaterial(),
            'inputMassGrams' => $recycling->getInputMassGrams(),
            'outputMaterial' => $recycling->getOutputMaterial(),
            'outputMassGrams' => $recycling->getOutputMassGrams(),
            'durationMinutes' => $recycling->getDurationMinutes(),
            'avgTemperature' => $recycling->getAvgTemperature(),
            'notes' => $recycling->getNotes(),
            'recycledAt' => $recycling->getRecycledAt()->format(\DateTimeInterface::ATOM),
        ];
    }
}
