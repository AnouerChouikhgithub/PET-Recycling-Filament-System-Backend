<?php

declare(strict_types=1);

namespace App\Service\Machine;

use App\Entity\Machine;
use App\Entity\MachineStatus;
use Doctrine\ORM\EntityManagerInterface;

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
 *
 * This service is also the SINGLE writer of the two connectivity signals —
 * nothing else may issue its own liveness SQL:
 *
 *   markSeen()    — the race-safe targeted UPDATE that bumps lastSeenAt
 *                   (used by TelemetryProcessor and by the MQTT status topic);
 *   markOffline() — the Last Will path: forces the derived OFFLINE state to
 *                   take effect immediately instead of waiting out the
 *                   threshold.
 *
 * Both exist because `offline` is DERIVED (see isOffline()) and is never
 * stored as a status value, so there is exactly one implementation of
 * "how a machine becomes online/offline" — here.
 */
final class MachineConnectivityService
{
    public const ENV_THRESHOLD = 'MACHINE_OFFLINE_AFTER_MINUTES';

    public function __construct(
        private readonly EntityManagerInterface $em,
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

    /**
     * Record that the machine is alive NOW.
     *
     * A single targeted UPDATE — never a read-modify-write of the whole row —
     * so concurrent ingests (HTTP telemetry, several MQTT devices) can never
     * resurrect stale data. lastSeenAt only ever moves forward. Optionally
     * applies the device-reported lifecycle status in the same statement.
     *
     * @param MachineStatus|null $status already-validated reported status (never `offline`)
     */
    public function markSeen(Machine $machine, ?MachineStatus $status = null): void
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $parameters = [
            'now' => $now,
            'id' => $machine->getId()->toRfc4122(),
        ];

        $sql = 'UPDATE machine SET last_seen_at = CASE WHEN last_seen_at IS NULL OR last_seen_at < :now THEN :now ELSE last_seen_at END,'
            .' updated_at = :now WHERE id = :id';

        if (null !== $status) {
            $sql = 'UPDATE machine SET last_seen_at = CASE WHEN last_seen_at IS NULL OR last_seen_at < :now THEN :now ELSE last_seen_at END,'
                .' status = :status, updated_at = :now WHERE id = :id';
            $parameters['status'] = $status->value;
        }

        $this->em->getConnection()->executeStatement($sql, $parameters);
    }

    /**
     * A device told us (or its Last Will told us) that it is GONE: make the
     * derived OFFLINE state take effect immediately instead of waiting out
     * MACHINE_OFFLINE_AFTER_MINUTES.
     *
     * `offline` is never stored as a status value, so this works by moving
     * lastSeenAt out of the online window — and only ever OUT of it:
     *   * LEAST() keeps an already-older timestamp (never moves it forward);
     *   * a NULL lastSeenAt (never seen) is preserved, since it is already
     *     offline by definition.
     */
    public function markOffline(Machine $machine, ?\DateTimeImmutable $at = null): void
    {
        $now = new \DateTimeImmutable();
        $cutoff = ($at ?? $now)->sub(new \DateInterval(sprintf('PT%dS', $this->offlineAfterMinutes * 60 + 1)));

        $this->em->getConnection()->executeStatement(
            'UPDATE machine'
            .' SET last_seen_at = CASE WHEN last_seen_at IS NULL THEN NULL ELSE LEAST(last_seen_at, :cutoff) END,'
            .' updated_at = :now WHERE id = :id',
            [
                'cutoff' => $cutoff->format('Y-m-d H:i:s'),
                'now' => $now->format('Y-m-d H:i:s'),
                'id' => $machine->getId()->toRfc4122(),
            ],
        );
    }
}
