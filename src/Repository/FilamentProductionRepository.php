<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\FilamentProduction;
use App\Entity\Machine;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<FilamentProduction>
 */
class FilamentProductionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, FilamentProduction::class);
    }

    /**
     * Filament production records for all sessions of a machine, newest first.
     *
     * @return list<FilamentProduction>
     */
    public function findByMachine(Machine $machine, int $limit = 50, int $offset = 0): array
    {
        return $this->createQueryBuilder('p')
            ->join('p.session', 's')
            ->andWhere('s.machine = :machine')
            ->setParameter('machine', $machine)
            ->orderBy('p.producedAt', 'DESC')
            ->setMaxResults(max(1, min($limit, 200)))
            ->setFirstResult(max(0, $offset))
            ->getQuery()
            ->getResult();
    }

    public function findByBatchCode(string $batchCode): ?FilamentProduction
    {
        return $this->findOneBy(['batchCode' => $batchCode]);
    }
}
