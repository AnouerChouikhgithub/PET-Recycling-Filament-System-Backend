<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AgentConversation;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AgentConversation>
 */
final class AgentConversationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AgentConversation::class);
    }

    /**
     * The conversation IF it belongs to the user and is not soft-deleted;
     * anything else behaves exactly like an unknown id (caller renders 404).
     */
    public function findOwnedOrExcluded(string $id, User $user): ?AgentConversation
    {
        $conversation = $this->createQueryBuilder('c')
            ->andWhere('c.id = :id')
            ->andWhere('c.user = :user')
            ->andWhere('c.deletedAt IS NULL')
            ->setParameter('id', $id, 'uuid')
            ->setParameter('user', $user)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $conversation instanceof AgentConversation ? $conversation : null;
    }

    /**
     * @return list<AgentConversation>
     */
    public function findActiveByUser(User $user, int $limit = 50): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.user = :user')
            ->andWhere('c.deletedAt IS NULL')
            ->setParameter('user', $user)
            ->orderBy('c.updatedAt', 'DESC')
            ->setMaxResults(max(1, $limit))
            ->getQuery()
            ->getResult();
    }

    /** Soft-delete every active conversation of the user. Returns rows touched. */
    public function softDeleteAllFor(User $user): int
    {
        return (int) $this->createQueryBuilder('c')
            ->update()
            ->set('c.deletedAt', ':now')
            ->where('c.user = :user')
            ->andWhere('c.deletedAt IS NULL')
            ->setParameter('now', new \DateTimeImmutable(), 'datetime_immutable')
            ->setParameter('user', $user)
            ->getQuery()
            ->execute();
    }
}
