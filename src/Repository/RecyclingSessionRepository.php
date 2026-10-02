<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Machine;
use App\Entity\RecyclingSession;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<RecyclingSession>
 */
class RecyclingSessionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RecyclingSession::class);
    }

    /**
     * Recycling records for all sessions of a machine, newest first.
     *
     * @return list<RecyclingSession>
     */
    public function findByMachine(Machine $machine, int $limit = 50, int $offset = 0): array
    {
        return $this->createQueryBuilder('r')
            ->join('r.session', 's')
            ->andWhere('s.machine = :machine')
            ->setParameter('machine', $machine)
            ->orderBy('r.recycledAt', 'DESC')
            ->setMaxResults(max(1, min($limit, 200)))
            ->setFirstResult(max(0, $offset))
            ->getQuery()
            ->getResult();
    }

    /**
     * Aggregate impact numbers for a machine (totals over its recycling records).
     *
     * @return array{records: int, inputGrams: float|null, outputGrams: float|null}
     */
    public function totalsForMachine(Machine $machine): array
    {
        $row = $this->createQueryBuilder('r')
            ->select('COUNT(r.id) AS records, SUM(r.inputMassGrams) AS inputGrams, SUM(r.outputMassGrams) AS outputGrams')
            ->join('r.session', 's')
            ->andWhere('s.machine = :machine')
            ->setParameter('machine', $machine)
            ->getQuery()
            ->getSingleResult();

        return [
            'records' => (int) $row['records'],
            'inputGrams' => null !== $row['inputGrams'] ? (float) $row['inputGrams'] : null,
            'outputGrams' => null !== $row['outputGrams'] ? (float) $row['outputGrams'] : null,
        ];
    }
}
