<?php

declare(strict_types=1);

namespace App\Tests\Agent;

use App\Entity\User;
use App\Service\Agent\AgentRetention;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Console\Tester\CommandTester;
use App\Command\PurgeAgentConversationsCommand;

final class AgentRetentionTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private User $owner;
    private User $other;
    private string $suffix;
    private MockClock $clock;
    private AgentRetention $retention;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        // This functional test owns the test database's conversations; clear
        // fixtures left by interrupted prior runs before exercising global purge.
        $this->em->getConnection()->executeStatement('DELETE FROM agent_conversation');
        $this->suffix = strtolower(Uuid::v7()->toBase32());
        $this->owner = $this->createUser('retention');
        $this->other = $this->createUser('other');
        $this->clock = new MockClock(new \DateTimeImmutable('2026-11-11T12:00:00+00:00'));
        $this->retention = new AgentRetention($this->em->getConnection(), $this->clock, 30, 7);
    }

    public function testDryRunReportsCountsWithoutDeletingAndDoesNotPrintContent(): void
    {
        $expired = $this->createConversation($this->owner, 'expired-message-secret', '-40 days', null);
        $this->createConversation($this->owner, 'recent-message-secret', '-1 day', null);

        $command = new PurgeAgentConversationsCommand($this->retention);
        $tester = new CommandTester($command);
        $tester->execute(['--dry-run' => true]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('DRY RUN conversations=1 inactive=1 soft_deleted=0 messages=1', trim($tester->getDisplay()));
        self::assertStringNotContainsString('expired-message-secret', $tester->getDisplay());
        self::assertSame(2, $this->conversationCount());
        self::assertSame(1, $this->messageCount($expired));
    }

    public function testPurgeRemovesExpiredInactiveAndGraceExpiredSoftDeletedButKeepsRecentAndUsage(): void
    {
        $inactive = $this->createConversation($this->owner, 'old-content', '-40 days', null);
        $softDeleted = $this->createConversation($this->owner, 'deleted-content', '-40 days', '-8 days');
        $recentOtherUser = $this->createConversation($this->other, 'other-recent-content', '-1 day', null);
        $recentSoftDeleted = $this->createConversation($this->other, 'other-deleted-recent', '-10 days', '-2 days');
        $usageId = Uuid::v7()->toRfc4122();
        $this->em->getConnection()->executeStatement(
            'INSERT INTO agent_token_usage (id, day, user_id, tokens_used, created_at, updated_at) VALUES (CAST(:id AS uuid), :day, CAST(:user_id AS uuid), 123, :now, :now)',
            ['id' => $usageId, 'day' => '2026-11-11', 'user_id' => $this->owner->getId()->toRfc4122(), 'now' => $this->clock->now()->format('Y-m-d H:i:s')],
        );

        $counts = $this->retention->purge();

        self::assertSame(['inactive' => 1, 'soft_deleted' => 1, 'conversations' => 2, 'messages' => 2], $counts);
        self::assertFalse($this->findConversation($inactive));
        self::assertFalse($this->findConversation($softDeleted));
        self::assertNotFalse($this->findConversation($recentOtherUser), 'an unrelated recent conversation owned by another user remains');
        self::assertNotFalse($this->findConversation($recentSoftDeleted), 'soft-delete grace period is honored');
        self::assertSame(0, $this->messageCount($inactive), 'FK cascade purges conversation messages');
        self::assertSame(0, $this->messageCount($softDeleted), 'FK cascade purges soft-deleted conversation messages');
        self::assertSame(123, (int) $this->em->getConnection()->fetchOne('SELECT tokens_used FROM agent_token_usage WHERE id = CAST(:id AS uuid)', ['id' => $usageId]), 'aggregate token usage is retained');
    }

    private function createUser(string $prefix): User
    {
        $user = new User();
        $user->setEmail($prefix.'-'.$this->suffix.'@3awedlou.test')->setName('Retention test');
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user->setPassword($hasher->hashPassword($user, 'retention-test-password'));
        $this->em->persist($user);
        $this->em->flush();
        return $user;
    }

    private function createConversation(User $user, string $content, string $updatedOffset, ?string $deletedOffset): string
    {
        $id = Uuid::v7()->toRfc4122();
        $messageId = Uuid::v7()->toRfc4122();
        $created = $this->clock->now()->modify($updatedOffset);
        $updated = $created;
        $deleted = null === $deletedOffset ? null : $this->clock->now()->modify($deletedOffset);
        $this->em->getConnection()->executeStatement(
            'INSERT INTO agent_conversation (id, user_id, title, created_at, updated_at, deleted_at) VALUES (CAST(:id AS uuid), CAST(:user_id AS uuid), NULL, :created, :updated, :deleted)',
            ['id' => $id, 'user_id' => $user->getId()->toRfc4122(), 'created' => $created->format('Y-m-d H:i:s'), 'updated' => $updated->format('Y-m-d H:i:s'), 'deleted' => null === $deleted ? null : $deleted->format('Y-m-d H:i:s')],
        );
        $this->em->getConnection()->executeStatement(
            'INSERT INTO agent_message (id, conversation_id, role, content, tool_calls, usage_input, usage_output, created_at) VALUES (CAST(:id AS uuid), CAST(:conversation_id AS uuid), :role, :content, NULL, 1, 1, :created)',
            ['id' => $messageId, 'conversation_id' => $id, 'role' => 'user', 'content' => $content, 'created' => $created->format('Y-m-d H:i:s')],
        );
        return $id;
    }

    private function conversationCount(): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT count(*) FROM agent_conversation');
    }

    private function messageCount(string $conversationId): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT count(*) FROM agent_message WHERE conversation_id = CAST(:id AS uuid)', ['id' => $conversationId]);
    }

    private function findConversation(string $id): mixed
    {
        return $this->em->getConnection()->fetchOne('SELECT id FROM agent_conversation WHERE id = CAST(:id AS uuid)', ['id' => $id]);
    }
}
