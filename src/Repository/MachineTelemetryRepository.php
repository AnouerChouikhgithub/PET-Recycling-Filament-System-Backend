<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Machine;
use App\Entity\MachineTelemetry;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MachineTelemetry>
 */
class MachineTelemetryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MachineTelemetry::class);
    }

    /**
     * Paginated telemetry history for a machine, newest first.
     *
     * @return list<MachineTelemetry>
     */
    public function findHistory(Machine $machine, int $limit = 100, int $offset = 0, ?\DateTimeImmutable $since = null): array
    {
        return $this->historyQueryBuilder($machine, $since)
            ->setMaxResults(max(1, min($limit, self::HARD_MAX_LIMIT)))
            ->setFirstResult(max(0, $offset))
            ->getQuery()
            ->getResult();
    }

    /** Global hard cap for any page size a client may ask for. */
    public const HARD_MAX_LIMIT = 200;

    /**
     * Paginated history as scalar rows (no entity hydration, no lazy loads).
     * Same shape as MachineViewFactory::telemetry() minus machineId, which the
     * caller already knows — used by the list endpoint so 200 rows stay cheap.
     *
     * @return list<array<string, mixed>>
     */
    public function findHistoryRows(Machine $machine, int $limit = 100, int $offset = 0, ?\DateTimeImmutable $since = null): array
    {
        $rows = $this->historyQueryBuilder($machine, $since)
            ->select(
                't.id', 't.temperature', 't.targetTemperature', 't.heaterState',
                't.motorState', 't.motorSpeed', 't.fanState', 't.filamentSpeed',
                't.filamentDiameter', 't.energyConsumption', 't.extra', 't.recordedAt'
            )
            ->setMaxResults(max(1, min($limit, self::HARD_MAX_LIMIT)))
            ->setFirstResult(max(0, $offset))
            ->getQuery()
            ->getArrayResult();

        return array_map(static function (array $r): array {
            return [
                'id' => $r['id'],
                'temperature' => $r['temperature'],
                'targetTemperature' => $r['targetTemperature'],
                'heaterState' => $r['heaterState'],
                'motorState' => $r['motorState'],
                'motorSpeed' => $r['motorSpeed'],
                'fanState' => $r['fanState'],
                'filamentSpeed' => $r['filamentSpeed'],
                'filamentDiameter' => $r['filamentDiameter'],
                'energyConsumption' => $r['energyConsumption'],
                'extra' => $r['extra'],
                'recordedAt' => $r['recordedAt'],
            ];
        }, $rows);
    }

    /**
     * The most recent telemetry sample of a machine (its "current" reading).
     */
    public function findLatest(Machine $machine): ?MachineTelemetry
    {
        return $this->historyQueryBuilder($machine)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** Retention: how many samples predate the cutoff (prune --dry-run). */
    public function countOlderThan(\DateTimeImmutable $cutoff): int
    {
        return (int) $this->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->andWhere('t.recordedAt < :cutoff')
            ->setParameter('cutoff', $cutoff)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Retention: delete samples predating the cutoff, optionally batched.
     * Returns the number of rows deleted in this pass.
     */
    public function deleteOlderThan(\DateTimeImmutable $cutoff, ?int $limit = null): int
    {
        $qb = $this->getEntityManager()->createQueryBuilder()
            ->delete(MachineTelemetry::class, 't')
            ->andWhere('t.recordedAt < :cutoff')
            ->setParameter('cutoff', $cutoff);

        if (null !== $limit) {
            $qb->setMaxResults($limit);
        }

        return (int) $qb->getQuery()->execute();
    }

    private function historyQueryBuilder(Machine $machine, ?\DateTimeImmutable $since = null): QueryBuilder
    {
        $qb = $this->createQueryBuilder('t')
            ->andWhere('t.machine = :machine')
            ->setParameter('machine', $machine)
            // id is a UUIDv7 (time-ordered) → stable tie-break for samples
            // recorded within the same second.
            ->orderBy('t.recordedAt', 'DESC')
            ->addOrderBy('t.id', 'DESC');

        if (null !== $since) {
            $qb->andWhere('t.recordedAt >= :since')
                ->setParameter('since', $since);
        }

        return $qb;
    }
}
