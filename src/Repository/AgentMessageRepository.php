<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AgentConversation;
use App\Entity\AgentMessage;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AgentMessage>
 */
final class AgentMessageRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AgentMessage::class);
    }

    /**
     * The LLM context window: last N messages oldest-first (only for the
     * owning conversation - the caller must have resolved ownership first).
     *
     * @return list<AgentMessage>
     */
    public function findContext(AgentConversation $conversation, int $limit): array
    {
        // count available rows first so the page starts at the right offset
        $total = (int) $this->countFor($conversation);

        /** @var list<AgentMessage> $rows */
        $rows = $this->createQueryBuilder('m')
            ->andWhere('m.conversation = :conv')
            ->setParameter('conv', $conversation)
            ->orderBy('m.createdAt', 'DESC')
            ->setFirstResult(max(0, $total - max(1, $limit)))
            ->setMaxResults(max(1, $limit))
            ->getQuery()
            ->getResult();

        // fetch is DESC; the model wants oldest-first
        return array_reverse($rows);
    }

    private function countFor(AgentConversation $conversation): int
    {
        return (int) $this->createQueryBuilder('m')
            ->select('COUNT(m.id)')
            ->andWhere('m.conversation = :conv')
            ->setParameter('conv', $conversation)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
