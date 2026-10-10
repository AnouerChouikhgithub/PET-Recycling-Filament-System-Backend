<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AgentTokenUsage;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AgentTokenUsage>
 */
final class AgentTokenUsageRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AgentTokenUsage::class);
    }

    /**
     * Load-or-create today's row FOR UPDATE so a flush at the end of the turn
     * settles atomically. Calling code MUST split a transaction across turns:
     * reserve inside the transaction, settle right after the LLM call.
     */
    public function todayRow(User $user, \DateTimeImmutable $now, EntityManagerInterface $em): AgentTokenUsage
    {
        $day = $now->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d');

        $row = $this->createQueryBuilder('u')
            ->andWhere('u.user = :user')
            ->andWhere('u.day = :day')
            ->setParameter('user', $user)
            ->setParameter('day', $day)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        if (!$row instanceof AgentTokenUsage) {
            $row = new AgentTokenUsage($user, $day, 0);
            $em->persist($row);
            $em->flush(); // row exists now - later inserts become updates
        }

        return $row;
    }
}
