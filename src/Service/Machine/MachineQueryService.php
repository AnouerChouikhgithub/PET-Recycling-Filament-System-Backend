<?php

declare(strict_types=1);

namespace App\Service\Machine;

use App\Api\Exception\ResourceNotFoundException;
use App\Entity\Machine;
use App\Entity\MachineSession;
use App\Entity\MachineTelemetry;
use App\Repository\FilamentProductionRepository;
use App\Repository\MachineSessionRepository;
use App\Repository\MachineTelemetryRepository;
use App\Repository\RecyclingSessionRepository;
use App\Security\MachineAccess;

/**
 * Read-side use cases for machines. Controllers stay thin; queries live here.
 *
 * OWNERSHIP: every machine read goes through MachineAccess — a user sees
 * only their own machines (admins see all); anything else is the same
 * 404 an unknown id produces, so machine ids cannot be enumerated.
 */
final class MachineQueryService
{
    public function __construct(
        private readonly MachineAccess $access,
        private readonly MachineTelemetryRepository $telemetry,
        private readonly MachineSessionRepository $sessions,
        private readonly FilamentProductionRepository $productions,
        private readonly RecyclingSessionRepository $recycling,
    ) {
    }

    /**
     * Machines visible to the current user.
     *
     * @return list<Machine>
     */
    public function listMachines(): array
    {
        return $this->access->visibleMachines();
    }

    /**
     * @throws ResourceNotFoundException unknown id OR not owned (same 404)
     */
    public function getMachine(string $id): Machine
    {
        return $this->access->getOwnedMachine($id);
    }

    /**
     * Telemetry ingest accepts TWO principals, each strictly bound to the
     * URL's machine id:
     *   - a DEVICE (DeviceUser): the machine its token was issued for;
     *   - a USER: only a machine they own (admins included).
     * Anything else is the standard 404 envelope.
     *
     * @throws ResourceNotFoundException
     */
    public function getMachineForIngest(string $id): Machine
    {
        return $this->access->getMachineForDeviceOrOwner($id);
    }

    public function latestTelemetry(Machine $machine): ?MachineTelemetry
    {
        return $this->telemetry->findLatest($machine);
    }

    public function activeSession(Machine $machine): ?MachineSession
    {
        return $this->sessions->findActiveSession($machine);
    }

    /**
     * @return list<MachineTelemetry>
     */
    public function telemetryHistory(Machine $machine, int $limit, int $offset, ?\DateTimeImmutable $since = null): array
    {
        return $this->telemetry->findHistory($machine, $limit, $offset, $since);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function telemetryHistoryRows(Machine $machine, int $limit, int $offset, ?\DateTimeImmutable $since = null): array
    {
        return $this->telemetry->findHistoryRows($machine, $limit, $offset, $since);
    }

    /**
     * @return list<MachineSession>
     */
    public function sessionHistory(Machine $machine, int $limit, int $offset, ?string $status = null): array
    {
        $statusEnum = null !== $status ? \App\Entity\SessionStatus::tryFrom($status) : null;

        return $this->sessions->findHistory($machine, $limit, $offset, $statusEnum);
    }

    /**
     * @return list<\App\Entity\FilamentProduction>
     */
    public function productionHistory(Machine $machine, int $limit, int $offset): array
    {
        return $this->productions->findByMachine($machine, $limit, $offset);
    }

    /**
     * @return list<\App\Entity\RecyclingSession>
     */
    public function recyclingHistory(Machine $machine, int $limit, int $offset): array
    {
        return $this->recycling->findByMachine($machine, $limit, $offset);
    }

    /**
     * @return array{records: int, inputGrams: float|null, outputGrams: float|null}
     */
    public function recyclingTotals(Machine $machine): array
    {
        return $this->recycling->totalsForMachine($machine);
    }

    /**
     * Everything the home/machine dashboards render in one round trip, so
     * web + mobile always show identical data from identical responses.
     *
     * @return MachineDashboard
     */
    public function buildDashboard(Machine $machine, int $telemetryPoints = 48): MachineDashboard
    {
        // findHistory returns newest-first; charts want oldest → newest.
        $recent = array_reverse($this->telemetry->findHistory($machine, $telemetryPoints));

        return new MachineDashboard(
            machine: $machine,
            latestTelemetry: [] === $recent ? null : $recent[array_key_last($recent)],
            activeSession: $this->sessions->findActiveSession($machine),
            recentTelemetry: $recent,
            recyclingTotals: $this->recycling->totalsForMachine($machine),
        );
    }
}
