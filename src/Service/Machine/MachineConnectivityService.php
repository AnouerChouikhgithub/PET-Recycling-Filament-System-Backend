<?php

declare(strict_types=1);

namespace App\Service\Machine;

use App\Entity\Machine;
use App\Entity\MachineStatus;

/**
 * Decides whether a machine is currently ONLINE or OFFLINE.
 *
 * A stored status alone (e.g. "extruding") says nothing about connectivity:
 * the ESP32 may have dropped off Wi-Fi hours ago. The rule is time-based:
 *
 *     machine is ONLINE  ⇔  lastSeenAt is within OFFLINE_AFTER_MINUTES
 *     machine is OFFLINE ⇔  otherwise (including lastSeenAt = null)
 *
 * The threshold is configurable via the MACHINE_OFFLINE_AFTER_MINUTES env var
 * so it can be tuned for the real hardware reporting cadence without a redeploy.
 */
final class MachineConnectivityService
{
    public const ENV_THRESHOLD = 'MACHINE_OFFLINE_AFTER_MINUTES';

    public function __construct(
        private readonly int $offlineAfterMinutes = 10,
    ) {
    }

    public function isOnline(Machine $machine, ?\DateTimeImmutable $now = null): bool
    {
        return !$this->isOffline($machine, $now);
    }

    public function isOffline(Machine $machine, ?\DateTimeImmutable $now = null): bool
    {
        $lastSeen = $machine->getLastSeenAt();
        if (null === $lastSeen) {
            return true;
        }

        $now ??= new \DateTimeImmutable('now');

        return $now->getTimestamp() - $lastSeen->getTimestamp() > $this->offlineAfterMinutes * 60;
    }

    /**
     * The status clients should display: the reported lifecycle state when the
     * machine is reachable, `offline` when connectivity has lapsed.
     */
    public function effectiveStatus(Machine $machine, ?\DateTimeImmutable $now = null): MachineStatus
    {
        return $this->isOffline($machine, $now) ? MachineStatus::Offline : $machine->getStatus();
    }

    /**
     * Seconds since the machine was last seen (null when never seen).
     */
    public function secondsSinceLastSeen(Machine $machine, ?\DateTimeImmutable $now = null): ?int
    {
        $lastSeen = $machine->getLastSeenAt();
        if (null === $lastSeen) {
            return null;
        }

        $now ??= new \DateTimeImmutable('now');

        return max(0, $now->getTimestamp() - $lastSeen->getTimestamp());
    }
}
