<?php

declare(strict_types=1);

namespace App\Service\Agent;

use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\ClockInterface;

/** Content-free retention operations for agent conversations. */
final class AgentRetention
{
    public function __construct(
        private readonly Connection $connection,
        private readonly ClockInterface $clock,
        private readonly int $retentionDays = 30,
        private readonly int $softDeleteGraceDays = 7,
    ) {
    }

    /** @return array{inactive: int, soft_deleted: int, conversations: int, messages: int} */
    public function candidateCounts(): array
    {
        $now = $this->clock->now();
        $inactiveBefore = $now->modify(sprintf('-%d days', max(1, $this->retentionDays)));
        $deletedBefore = $now->modify(sprintf('-%d days', max(1, $this->softDeleteGraceDays)));

        return $this->countCandidates($inactiveBefore, $deletedBefore);
    }

    /**
     * Hard-delete eligible conversations and cascading messages.
     *
     * @return array{inactive: int, soft_deleted: int, conversations: int, messages: int}
     */
    public function purge(): array
    {
        $now = $this->clock->now();
        $inactiveBefore = $now->modify(sprintf('-%d days', max(1, $this->retentionDays)));
        $deletedBefore = $now->modify(sprintf('-%d days', max(1, $this->softDeleteGraceDays)));
        $counts = $this->countCandidates($inactiveBefore, $deletedBefore);

        $this->connection->executeStatement(
            'DELETE FROM agent_conversation
             WHERE (deleted_at IS NULL AND updated_at < :inactive_before)
                OR (deleted_at IS NOT NULL AND deleted_at < :deleted_before)',
            [
                'inactive_before' => $inactiveBefore,
                'deleted_before' => $deletedBefore,
            ],
            [
                'inactive_before' => 'datetime_immutable',
                'deleted_before' => 'datetime_immutable',
            ],
        );

        return $counts;
    }

    /** @return array{inactive: int, soft_deleted: int, conversations: int, messages: int} */
    private function countCandidates(\DateTimeImmutable $inactiveBefore, \DateTimeImmutable $deletedBefore): array
    {
        $rows = $this->connection->fetchAssociative(
            'SELECT
                count(*) FILTER (WHERE deleted_at IS NULL AND updated_at < :inactive_before) AS inactive,
                count(*) FILTER (WHERE deleted_at IS NOT NULL AND deleted_at < :deleted_before) AS soft_deleted,
                count(*) AS conversations,
                coalesce(sum(message_count), 0) AS messages
             FROM (
                SELECT c.id, c.deleted_at, c.updated_at, count(m.id) AS message_count
                FROM agent_conversation c
                LEFT JOIN agent_message m ON m.conversation_id = c.id
                WHERE (c.deleted_at IS NULL AND c.updated_at < :inactive_before)
                   OR (c.deleted_at IS NOT NULL AND c.deleted_at < :deleted_before)
                GROUP BY c.id
             ) eligible',
            [
                'inactive_before' => $inactiveBefore,
                'deleted_before' => $deletedBefore,
            ],
            [
                'inactive_before' => 'datetime_immutable',
                'deleted_before' => 'datetime_immutable',
            ],
        );

        return [
            'inactive' => (int) ($rows['inactive'] ?? 0),
            'soft_deleted' => (int) ($rows['soft_deleted'] ?? 0),
            'conversations' => (int) ($rows['conversations'] ?? 0),
            'messages' => (int) ($rows['messages'] ?? 0),
        ];
    }
}
