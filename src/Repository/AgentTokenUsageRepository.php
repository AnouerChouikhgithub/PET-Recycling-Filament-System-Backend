<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AgentTokenUsage;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

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
     * Legacy helper kept for tests/reads: load-or-create today's row. The
     * ATOMIC paths (ensureRow/reserveBudget/settleBudget) don't use it.
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

    /**
     * Creates today's row when missing WITHOUT a read-then-write race: the
     * insert is idempotent (ON CONFLICT DO NOTHING), so two concurrent turns
     * coexist and both then serialize on the row's lock for the atomic
     * budget updates below.
     */
    public function ensureRow(User $user, \DateTimeImmutable $now): void
    {
        $this->connection()->executeStatement(
            'INSERT INTO agent_token_usage (id, day, user_id, tokens_used, created_at, updated_at)
             VALUES (CAST(:id AS uuid), :day, CAST(:user_id AS uuid), 0, :now, :now)
             ON CONFLICT (user_id, day) DO NOTHING',
            [
                'id' => Uuid::v7()->toRfc4122(),
                'day' => $this->dayOf($now),
                'user_id' => $user->getId()->toRfc4122(),
                'now' => $now,
            ],
            ['now' => 'datetime_immutable'],
        );
    }

    /**
     * ATOMIC reservation: the budget is taken inside ONE conditional update,
     * so two concurrent turns cannot read-then-write the same remaining
     * budget (no in-memory mutex, no check-then-write sequence).
     *
     * Returns true when the estimate was accepted under the cap; false means
     * the remaining budget is insufficient and NOTHING was charged.
     */
    public function reserveBudget(User $user, int $estimate, int $cap, \DateTimeImmutable $now): bool
    {
        $this->ensureRow($user, $now);

        $result = $this->connection()->fetchOne(
            'UPDATE agent_token_usage
             SET tokens_used = tokens_used + :estimate, updated_at = :now
             WHERE user_id = CAST(:user_id AS uuid) AND day = :day
               AND tokens_used + :estimate <= :cap
             RETURNING tokens_used',
            [
                'estimate' => $estimate,
                'cap' => $cap,
                'day' => $this->dayOf($now),
                'user_id' => $user->getId()->toRfc4122(),
                'now' => $now,
            ],
            ['now' => 'datetime_immutable'],
        );

        return false !== $result;
    }

    /**
     * Replaces this turn's reservation with the provider-reported usage
     * (or the conservative floor when usage is missing). The delta may be
     * negative; the row is clamped at zero so a refund can never push the
     * day below what was genuinely spent.
     */
    public function settleBudget(User $user, int $delta, \DateTimeImmutable $now): void
    {
        $this->connection()->executeStatement(
            'UPDATE agent_token_usage
             SET tokens_used = GREATEST(0, tokens_used + :delta), updated_at = :now
             WHERE user_id = CAST(:user_id AS uuid) AND day = :day',
            [
                'delta' => $delta,
                'day' => $this->dayOf($now),
                'user_id' => $user->getId()->toRfc4122(),
                'now' => $now,
            ],
            ['now' => 'datetime_immutable'],
        );
    }

    private function dayOf(\DateTimeImmutable $now): string
    {
        return $now->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d');
    }

    private function connection(): Connection
    {
        return $this->getEntityManager()->getConnection();
    }
}
