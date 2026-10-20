<?php

declare(strict_types=1);

namespace App\Tests\Agent;

use App\Entity\AgentMessage;
use App\Entity\AgentTokenUsage;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Provider-failure lifecycle, tool input validation and result bounding
 * (session 2 phases B/C/E/F): the scripted stub drives with
 * addFailure()/addRawResponse() - no network, no key.
 */
final class AgentFailureAndValidationTest extends AgentApiTestCase
{
    public function testProviderFailureYields503AndNeverACompletedAnswer(): void
    {
        $owner = $this->createUser('f1', 'fail-pass-123');
        $this->login((string) $owner['email'], (string) $owner['password']);

        $this->stub()->addFailure(); // next LLM call explodes deterministically

        $this->client->request('POST', '/api/agent/chat', [], [], ['CONTENT_TYPE' => 'application/json'], (string) json_encode(['message' => 'hello']));
        self::assertResponseStatusCodeSame(503, 'provider failure maps to the documented 503');
        self::assertSame('AGENT_LLM_UNAVAILABLE', $this->json()['error']['code']);

        // Accounting: the reservation was reconciled at the conservative
        // floor - charged, never silently refunded away.
        $row = $this->em->getRepository(AgentTokenUsage::class)->findOneBy(['user' => $owner['user']]);
        self::assertNotNull($row, 'the failed turn is still accounted (durable table)');
        self::assertGreaterThan(0, $row->getTokensUsed());

        // The USER message survives (it was persisted before the call); no
        // assistant message may exist (no misleading completed answer).
        $messages = $this->em->createQueryBuilder()
            ->select('m')
            ->from(AgentMessage::class, 'm')
            ->join('m.conversation', 'c')
            ->where('c.user = :user')
            ->setParameter('user', $owner['user'])
            ->getQuery()
            ->getArrayResult();
        $roles = array_map(fn ($m) => $m['role'] ?? $m->getRole(), $messages);
        self::assertContains('user', $roles);
        self::assertNotContains('assistant', $roles, 'a provider failure never creates an assistant answer');
    }

    public function testMissingProviderUsageIsBilledTheConservativeFloor(): void
    {
        $owner = $this->createUser('f2', 'fail-pass-123');
        $this->login((string) $owner['email'], (string) $owner['password']);

        // Raw response without any usage object: the provider contract
        // (session 2 B2) must bill the documented floor instead of zero.
        $this->stub()->addRawResponse(['role' => 'assistant', 'content' => [['type' => 'text', 'text' => 'done']]]);

        $this->client->request('POST', '/api/agent/chat', [], [], ['CONTENT_TYPE' => 'application/json'], (string) json_encode(['message' => 'hello']));
        self::assertResponseStatusCodeSame(200);

        $row = $this->em->getRepository(AgentTokenUsage::class)->findOneBy(['user' => $owner['user']]);
        self::assertNotNull($row);
        self::assertSame(\App\Service\Agent\TurnGuard::MISSING_USAGE_ESTIMATE, $row->getTokensUsed(), 'T3.3 missing usage -> 1000-token floor, never zero');
    }

    /**
     * F4: a PRE-FLIGHT refusal (no credentials, so no request was ever sent)
     * must refund the reservation completely. The provider cannot have
     * generated a token, so the conservative floor would be a pure loss.
     */
    public function testPreflightRefusalRefundsTheWholeReservation(): void
    {
        $owner = $this->createUser('f7', 'fail-pass-123');
        $this->login((string) $owner['email'], (string) $owner['password']);

        $this->stub()->addNotAttemptedFailure();

        $this->client->request('POST', '/api/agent/chat', [], [], ['CONTENT_TYPE' => 'application/json'], (string) json_encode(['message' => 'hello']));
        self::assertResponseStatusCodeSame(503, 'same controlled 503 the client always sees');
        self::assertSame('AGENT_LLM_UNAVAILABLE', $this->json()['error']['code']);

        $row = $this->em->getRepository(AgentTokenUsage::class)->findOneBy(['user' => $owner['user']]);
        self::assertNotNull($row, 'the attempt is still accounted - the row exists, refunded to zero');
        self::assertSame(
            0,
            $row->getTokensUsed(),
            'F4: nothing was sent, so nothing (not even the 1000-token floor) may be billed',
        );
    }

    public function testModelGeneratedToolInputIsSchemaValidatedBeforeExecution(): void
    {
        $owner = $this->createUser('f3', 'fail-pass-123');
        $machine = $this->createMachine('M', 'fv-'.substr($this->suffix, 0, 8), $owner['user']);

        $this->login((string) $owner['email'], (string) $owner['password']);

        // get_recent_events requires machineId; limit must be 1..20. The
        // model sends limit=999 (out of range) + bogus extra property.
        $this->stub()->addResponse([['type' => 'tool_use', 'id' => 't1', 'name' => 'get_recent_events', 'input' => ['machineId' => $machine->getId()->toRfc4122(), 'limit' => 999, 'evil' => 1]]]);
        $this->stub()->addResponse([['type' => 'text', 'text' => 'ok']]);

        $this->client->request('POST', '/api/agent/chat', [], [], ['CONTENT_TYPE' => 'application/json'], (string) json_encode(['message' => 'go']));
        self::assertResponseStatusCodeSame(200);

        self::assertSame(2, $this->stub()->calls, 'the turn must make exactly two model calls');
        $received = (string) json_encode($this->stub()->receivedMessages);
        self::assertStringContainsString('VALIDATION_FAILED', $received, 'schema violations are refused as tool_result DATA, never executed');

        // The tool MUST NOT have executed despite the valid machineId.
        self::assertSame(0, \count($this->toolExecutedRowsFor($machine)), 'invalid input never reaches tool execution');
    }

    public function testDeleteOneAndInvalidConversationIdSanity(): void
    {
        $owner = $this->createUser('f4', 'fail-pass-123');
        $this->login((string) $owner['email'], (string) $owner['password']);

        // DELETE a conversation that does not exist at all: stable 404.
        $this->client->request('DELETE', '/api/agent/conversations/00000000-0000-0000-0000-000000000000');
        self::assertResponseStatusCodeSame(404, 'unknown conversation delete = standard 404');

        // Conversation POST + DELETE lifecycle end-to-end (T7.1 owner path).
        $this->stub()->addResponse([['type' => 'text', 'text' => 'hi']]);
        $this->client->request('POST', '/api/agent/chat', [], [], ['CONTENT_TYPE' => 'application/json'], (string) json_encode(['message' => 'first']));
        self::assertResponseStatusCodeSame(200);
        $conversationId = (string) $this->json()['data']['conversationId'];

        $this->client->request('DELETE', '/api/agent/conversations/'.$conversationId);
        self::assertResponseStatusCodeSame(200);

        // A soft-deleted conversation can NEVER be resumed.
        $this->client->request('POST', '/api/agent/chat', [], [], ['CONTENT_TYPE' => 'application/json'], (string) json_encode(['message' => 'resume', 'conversationId' => $conversationId]));
        self::assertResponseStatusCodeSame(404, 'soft-deleted conversations behave as not found');
        self::assertSame('AGENT_NOT_FOUND', $this->json()['error']['code']);
    }

    public function testForeignConversationDeleteIs404(): void
    {
        $alice = $this->createUser('f5a', 'fail-pass-123');
        $bob = $this->createUser('f5b', 'fail-pass-123');

        $this->login((string) $alice['email'], (string) $alice['password']);
        $this->stub()->addResponse([['type' => 'text', 'text' => 'a']]);
        $this->client->request('POST', '/api/agent/chat', [], [], ['CONTENT_TYPE' => 'application/json'], (string) json_encode(['message' => 'chat a']));
        $aliceConversationId = (string) $this->json()['data']['conversationId'];

        $this->login((string) $bob['email'], (string) $bob['password']);
        $this->client->request('DELETE', '/api/agent/conversations/'.$aliceConversationId);
        self::assertResponseStatusCodeSame(404, 'delete is OWNER-SCOPED: a foreign id behaves as not found');

        // Alice's conversation still exists, untouched and resumable by her.
        $aliceRepo = $this->em->getRepository(\App\Entity\AgentConversation::class);
        $aliceConversation = $aliceRepo->find($aliceConversationId);
        self::assertNotNull($aliceConversation, 'the foreign delete attempt must not touch the owner row');
        self::assertNull($aliceConversation->getDeletedAt(), 'the owner conversation was not soft-deleted by someone else');
    }

    public function testLogHygieneMarkerNeverReachesCapturedLogs(): void
    {
        $owner = $this->createUser('f6', 'fail-pass-123');
        // Machine name carries the unique marker: ANY leak to logs fails.
        $this->createMachine('MARKER-CANARY-9f2c-not-in-logs', 'cn-'.substr($this->suffix, 0, 8), $owner['user']);

        $this->login((string) $owner['email'], (string) $owner['password']);
        $this->stub()->addResponse([['type' => 'tool_use', 'id' => 't1', 'name' => 'list_my_machines', 'input' => []]]);
        $this->stub()->addResponse([['type' => 'text', 'text' => 'ok']]);

        $logFile = $this->logFilePath();
        $before = file_exists($logFile) ? (string) file_get_contents($logFile) : '';

        $this->client->request('POST', '/api/agent/chat', [], [], ['CONTENT_TYPE' => 'application/json'], (string) json_encode(['message' => 'log hygiene']));

        $after = file_exists($logFile) ? (string) file_get_contents($logFile) : $before;
        self::assertStringNotContainsString('MARKER-CANARY-9f2c', $after.''.$before, 'T7.3 the agent log channel is metadata-only: no chat or tool content');
    }

    // ------------------------------------------------------------ helpers

    /**
     * @return list<array<string, mixed>> audit/event rows for the machine
     */
    private function toolExecutedRowsFor(\App\Entity\Machine $machine): array
    {
        return $this->em->getConnection()->fetchAllAssociative(
            'SELECT id FROM machine_command_audit WHERE machine_id = CAST(:m AS uuid)',
            ['m' => $machine->getId()->toRfc4122()],
        );
    }

    private function logFilePath(): string
    {
        $kernel = $this->client->getKernel();

        return $kernel->getLogDir().'/test.log';
    }
}
