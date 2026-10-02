<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Machine;
use App\Entity\MachineStatus;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Machine>
 */
class MachineRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Machine::class);
    }

    public function findByIdentifier(string $identifier): ?Machine
    {
        return $this->findOneBy(['identifier' => strtolower(trim($identifier))]);
    }

    /**
     * @return list<Machine>
     */
    public function findByOwner(User $owner): array
    {
        return $this->createQueryBuilder('m')
            ->andWhere('m.owner = :owner')
            ->setParameter('owner', $owner)
            ->orderBy('m.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return list<Machine>
     */
    public function findByStatus(MachineStatus $status): array
    {
        return $this->createQueryBuilder('m')
            ->andWhere('m.status = :status')
            ->setParameter('status', $status)
            ->orderBy('m.name', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
