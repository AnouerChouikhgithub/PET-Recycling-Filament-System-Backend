<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Machine;
use App\Entity\MachineSession;
use App\Entity\SessionStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MachineSession>
 */
class MachineSessionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MachineSession::class);
    }

    /**
     * Paginated session history for a machine, newest first.
     *
     * @return list<MachineSession>
     */
    public function findHistory(Machine $machine, int $limit = 50, int $offset = 0, ?SessionStatus $status = null): array
    {
        $qb = $this->createQueryBuilder('s')
            ->andWhere('s.machine = :machine')
            ->setParameter('machine', $machine)
            ->orderBy('s.startedAt', 'DESC')
            ->setMaxResults(max(1, min($limit, 200)))
            ->setFirstResult(max(0, $offset));

        if (null !== $status) {
            $qb->andWhere('s.status = :status')
                ->setParameter('status', $status);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * A session still running on this machine, if any.
     */
    public function findActiveSession(Machine $machine): ?MachineSession
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.machine = :machine')
            ->andWhere('s.status IN (:active)')
            ->setParameter('machine', $machine)
            ->setParameter('active', [SessionStatus::InProgress, SessionStatus::Paused])
            ->orderBy('s.startedAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
