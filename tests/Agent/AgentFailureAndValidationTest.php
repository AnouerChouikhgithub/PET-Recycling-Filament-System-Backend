<?php

declare(strict_types=1);

namespace App\Tests\Agent;

use App\Controller\Api\AgentController;
use App\Entity\AgentMessage;
use App\Entity\AgentTokenUsage;
use App\Entity\MachineCommandAudit;
use App\Entity\MachineCommandType;
use App\Service\Agent\AgentOrchestrator;
use App\Service\Agent\AgentTool;
use App\Service\Agent\Tools\GetAuditLogTool;
use App\Service\Agent\Tools\GetLatestTelemetryTool;
use App\Service\Agent\Tools\GetRecentEventsTool;
use App\Service\Agent\UtcDayReset;
use App\Service\Agent\ToolRegistry;
use Doctrine\ORM\EntityManagerInterface;
use Monolog\Handler\TestHandler;
use ReflectionProperty;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
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

    public function testEmptyModelAnswerUsesAndPersistsFallback(): void
    {
        $owner = $this->createUser('empty-answer', 'empty-answer-pass-123');
        static::getContainer()->get(TokenStorageInterface::class)->setToken(
            new UsernamePasswordToken($owner['user'], 'main', $owner['user']->getRoles()),
        );
        $stub = $this->stub();
        $stub->reset();
        $controller = static::getContainer()->get(AgentController::class);
        $controllerOrchestrator = (new ReflectionProperty(AgentController::class, 'orchestrator'))->getValue($controller);
        self::assertSame($stub, (new ReflectionProperty(AgentOrchestrator::class, 'llm'))->getValue($controllerOrchestrator));
        $stub->addResponse([['type' => 'text', 'text' => '']]);

        $request = Request::create('/api/agent/chat', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], (string) json_encode(['message' => 'empty answer']));
        $response = $controller->chat($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(1, $stub->calls);
        $fallback = "I couldn't complete that lookup. Please try again.";
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame($fallback, $body['data']['message']['content']);
        $persisted = $this->em->getConnection()->fetchOne(
            "SELECT content FROM agent_message WHERE conversation_id = CAST(:id AS uuid) AND role = 'assistant'",
            ['id' => $body['data']['conversationId']],
        );
        self::assertSame($fallback, $persisted);
    }

    public function testToolRoundCapWithoutTextUsesAndPersistsFallback(): void
    {
        $owner = $this->createUser('round-cap', 'round-cap-pass-123');
        $machine = $this->createMachine('Round cap machine', 'rc-'.substr($this->suffix, 0, 8), $owner['user']);
        static::getContainer()->get(TokenStorageInterface::class)->setToken(
            new UsernamePasswordToken($owner['user'], 'main', $owner['user']->getRoles()),
        );
        $stub = $this->stub();
        $stub->reset();
        $controller = static::getContainer()->get(AgentController::class);
        $controllerOrchestrator = (new ReflectionProperty(AgentController::class, 'orchestrator'))->getValue($controller);
        self::assertSame($stub, (new ReflectionProperty(AgentOrchestrator::class, 'llm'))->getValue($controllerOrchestrator));

        for ($i = 1; $i <= 5; ++$i) {
            $stub->addResponse([['type' => 'tool_use', 'id' => 'cap-'.$i, 'name' => 'get_recent_events', 'input' => ['machineId' => $machine->getId()->toRfc4122()]]]);
        }

        $request = Request::create('/api/agent/chat', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], (string) json_encode(['message' => 'keep checking events']));
        $response = $controller->chat($request);

        self::assertSame(200, $response->getStatusCode());
        $fallback = "I couldn't complete that lookup. Please try again.";
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame($fallback, $body['data']['message']['content']);
        self::assertSame(5, $stub->calls);
        self::assertSame('tool round limit reached', $body['data']['message']['toolCalls'][4]['summary']);
        $persisted = $this->em->getConnection()->fetchOne(
            "SELECT content FROM agent_message WHERE conversation_id = CAST(:id AS uuid) AND role = 'assistant'",
            ['id' => $body['data']['conversationId']],
        );
        self::assertSame($fallback, $persisted);
    }

    public function testRoundCapIgnoresFillerTextAndUsesFallback(): void
    {
        $owner = $this->createUser('round-cap-text', 'round-cap-pass-123');
        $machine = $this->createMachine('Round cap text', 'rct-'.substr($this->suffix, 0, 8), $owner['user']);
        $this->login((string) $owner['email'], (string) $owner['password']);

        for ($i = 1; $i <= 4; ++$i) {
            $this->stub()->addResponse([['type' => 'tool_use', 'id' => 'cap-t-'.$i, 'name' => 'get_recent_events', 'input' => ['machineId' => $machine->getId()->toRfc4122()]]]);
        }
        // The capped response also carries filler text: it must NOT become
        // the final answer for a turn that never completed.
        $this->stub()->addResponse([
            ['type' => 'text', 'text' => 'Here is a partial summary.'],
            ['type' => 'tool_use', 'id' => 'cap-t-5', 'name' => 'get_recent_events', 'input' => ['machineId' => $machine->getId()->toRfc4122()]],
        ]);

        $this->client->request('POST', '/api/agent/chat', [], [], ['CONTENT_TYPE' => 'application/json'], (string) json_encode(['message' => 'keep checking events']));

        self::assertResponseStatusCodeSame(200);
        self::assertSame("I couldn't complete that lookup. Please try again.", $this->json()['data']['message']['content']);
    }

    public function testWhitespaceOnlyAnswerUsesFallback(): void
    {
        $owner = $this->createUser('white-answer', 'white-answer-pass');
        $this->login((string) $owner['email'], (string) $owner['password']);
        $this->stub()->addResponse([['type' => 'text', 'text' => "  \n\t "]]);

        $this->client->request('POST', '/api/agent/chat', [], [], ['CONTENT_TYPE' => 'application/json'], (string) json_encode(['message' => 'hello']));

        self::assertResponseStatusCodeSame(200);
        self::assertSame("I couldn't complete that lookup. Please try again.", $this->json()['data']['message']['content']);
    }

    public function testFrenchPromptGetsFrenchFallbackAtRoundCap(): void
    {
        $owner = $this->createUser('round-cap-fr', 'round-cap-pass-123');
        $machine = $this->createMachine('Round cap FR', 'rcfr-'.substr($this->suffix, 0, 8), $owner['user']);
        static::getContainer()->get(TokenStorageInterface::class)->setToken(
            new UsernamePasswordToken($owner['user'], 'main', $owner['user']->getRoles()),
        );
        $stub = $this->stub();
        $stub->reset();
        $controller = static::getContainer()->get(AgentController::class);

        for ($i = 1; $i <= 5; ++$i) {
            $stub->addResponse([['type' => 'tool_use', 'id' => 'cap-fr-'.$i, 'name' => 'get_recent_events', 'input' => ['machineId' => $machine->getId()->toRfc4122()]]]);
        }

        $request = Request::create('/api/agent/chat', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], (string) json_encode(['message' => 'vérifie encore les événements']));
        $response = $controller->chat($request);

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame("Je n'ai pas pu terminer cette recherche. Veuillez réessayer.", $body['data']['message']['content']);
        self::assertSame(5, $stub->calls);
    }

    /**
     * "comment" is also an English word, so the empty-answer fallback must not
     * switch language because of it alone.
     */
    public function testEnglishPromptWithAmbiguousWordGetsEnglishFallbackAtRoundCap(): void
    {
        $owner = $this->createUser('round-cap-en', 'round-cap-en-pass');
        $machine = $this->createMachine('Round cap EN', 'rcen-'.substr($this->suffix, 0, 8), $owner['user']);
        static::getContainer()->get(TokenStorageInterface::class)->setToken(
            new UsernamePasswordToken($owner['user'], 'main', $owner['user']->getRoles()),
        );
        $stub = $this->stub();
        $stub->reset();
        $controller = static::getContainer()->get(AgentController::class);

        for ($i = 1; $i <= 5; ++$i) {
            $stub->addResponse([['type' => 'tool_use', 'id' => 'cap-en-'.$i, 'name' => 'get_recent_events', 'input' => ['machineId' => $machine->getId()->toRfc4122()]]]);
        }

        $request = Request::create('/api/agent/chat', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], (string) json_encode(['message' => 'comment on the last session']));
        $response = $controller->chat($request);

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame("I couldn't complete that lookup. Please try again.", $body['data']['message']['content']);
    }

    public function testDailyTokenCapRetryAfterIsSecondsUntilUtcMidnight(): void
    {
        $utc = new \DateTimeZone('UTC');

        self::assertSame(3600, UtcDayReset::secondsUntilNextMidnightUtc(new \DateTimeImmutable('2026-11-06 23:00:00', $utc)));
        self::assertSame(1, UtcDayReset::secondsUntilNextMidnightUtc(new \DateTimeImmutable('2026-11-06 23:59:59', $utc)));
        self::assertSame(86400, UtcDayReset::secondsUntilNextMidnightUtc(new \DateTimeImmutable('2026-11-06 00:00:00', $utc)));
    }

    public function testToolSummariesCountListsButNotAssociativeObjects(): void
    {
        $owner = $this->createUser('summary-shapes', 'summary-shapes-pass-123');
        static::getContainer()->get(TokenStorageInterface::class)->setToken(
            new UsernamePasswordToken($owner['user'], 'main', $owner['user']->getRoles()),
        );
        $stub = $this->stub();
        $stub->reset();
        $controller = static::getContainer()->get(AgentController::class);
        $controllerOrchestrator = (new ReflectionProperty(AgentController::class, 'orchestrator'))->getValue($controller);
        self::assertSame($stub, (new ReflectionProperty(AgentOrchestrator::class, 'llm'))->getValue($controllerOrchestrator));
        $stub->addResponse([
            ['type' => 'tool_use', 'id' => 'list-shape', 'name' => 'list_my_machines', 'input' => []],
            ['type' => 'tool_use', 'id' => 'object-shape', 'name' => 'explain_fault_code', 'input' => ['faultCode' => 'unknown-code']],
        ]);
        $stub->addResponse([['type' => 'text', 'text' => 'Summaries prepared.']]);

        $request = Request::create('/api/agent/chat', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], (string) json_encode(['message' => 'summarize results']));
        $response = $controller->chat($request);
        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame([
            ['name' => 'list_my_machines', 'summary' => 'list_my_machines: 0 row(s)'],
            ['name' => 'explain_fault_code', 'summary' => 'explain_fault_code: ok'],
        ], $body['data']['message']['toolCalls']);
    }

    public function testMultipleToolUsesProduceOrderedResultsInOneFollowUpMessage(): void
    {
        $owner = $this->createUser('multi-tool', 'multi-tool-pass-123');
        $machine = $this->createMachine('Multi tool machine', 'mt-'.substr($this->suffix, 0, 8), $owner['user']);
        $this->login((string) $owner['email'], (string) $owner['password']);

        $this->stub()->addResponse([
            ['type' => 'tool_use', 'id' => 'tool-list', 'name' => 'list_my_machines', 'input' => []],
            ['type' => 'tool_use', 'id' => 'tool-fault', 'name' => 'explain_fault_code', 'input' => ['faultCode' => 'unknown-code']],
        ]);
        $this->stub()->addResponse([['type' => 'text', 'text' => 'Both lookups completed.']]);

        $this->client->request('POST', '/api/agent/chat', [], [], ['CONTENT_TYPE' => 'application/json'], (string) json_encode(['message' => 'check machines and code']));

        self::assertResponseStatusCodeSame(200);
        self::assertSame(2, $this->stub()->calls);
        $received = $this->stub()->receivedMessages;
        $toolResultMessages = array_values(array_filter($received, static fn (array $message): bool => 'tool_result' === ($message['role'] ?? null)));
        self::assertCount(1, $toolResultMessages);
        self::assertCount(2, $toolResultMessages[0]['content']);
        self::assertSame('tool-list', $toolResultMessages[0]['content'][0]['tool_use_id']);
        self::assertSame('tool-fault', $toolResultMessages[0]['content'][1]['tool_use_id']);
        self::assertStringContainsString((string) $machine->getId()->toRfc4122(), (string) json_encode($toolResultMessages[0]['content'][0]['content']));
        self::assertSame('explain_fault_code', $toolResultMessages[0]['content'][1]['name']);
    }

    public function testAuditLogRejectsInvalidMachineIdBeforeQuery(): void
    {
        $owner = $this->createUser('audit-invalid', 'audit-pass-123');
        static::getContainer()->get(TokenStorageInterface::class)->setToken(
            new UsernamePasswordToken($owner['user'], 'main', $owner['user']->getRoles()),
        );

        self::assertSame(
            ['ok' => false, 'code' => 'VALIDATION_FAILED'],
            static::getContainer()->get(GetAuditLogTool::class)->execute(['machineId' => 'abc']),
        );
    }

    public function testAuditLogForeignValidMachineIsEmptyAndOwnedMachineReturnsRows(): void
    {
        $owner = $this->createUser('audit-owner', 'audit-pass-123');
        $other = $this->createUser('audit-other', 'audit-pass-123');
        $mine = $this->createMachine('Audit mine', 'am-'.substr($this->suffix, 0, 8), $owner['user']);
        $theirs = $this->createMachine('Audit theirs', 'at-'.substr($this->suffix, 0, 8), $other['user']);
        $this->em->persist(new MachineCommandAudit($mine, $owner['user'], MachineCommandType::SetFan, 50.0));
        $this->em->persist(new MachineCommandAudit($theirs, $other['user'], MachineCommandType::SetFan, 75.0));
        $this->em->flush();

        static::getContainer()->get(TokenStorageInterface::class)->setToken(
            new UsernamePasswordToken($owner['user'], 'main', $owner['user']->getRoles()),
        );
        $tool = static::getContainer()->get(GetAuditLogTool::class);

        self::assertSame(['ok' => true, 'data' => []], $tool->execute(['machineId' => $theirs->getId()->toRfc4122()]));
        $ownResult = $tool->execute(['machineId' => $mine->getId()->toRfc4122()]);
        self::assertTrue($ownResult['ok']);
        self::assertCount(1, $ownResult['data']);
        self::assertSame($mine->getName(), $ownResult['data'][0]['machineName']);
    }

    public function testMachineToolsRejectMalformedUuidBeforeMachineLookup(): void
    {
        $owner = $this->createUser('machine-uuid', 'machine-uuid-pass-123');
        static::getContainer()->get(TokenStorageInterface::class)->setToken(
            new UsernamePasswordToken($owner['user'], 'main', $owner['user']->getRoles()),
        );

        self::assertSame(
            ['ok' => false, 'code' => 'VALIDATION_FAILED'],
            static::getContainer()->get(GetRecentEventsTool::class)->execute(['machineId' => 'abc']),
        );
        self::assertSame(
            ['ok' => false, 'code' => 'VALIDATION_FAILED'],
            static::getContainer()->get(GetLatestTelemetryTool::class)->execute(['machineId' => 'abc']),
        );
    }

    public function testThrowingToolBecomesSafeToolErrorData(): void
    {
        $owner = $this->createUser('tool-error', 'tool-error-pass-123');
        $this->login((string) $owner['email'], (string) $owner['password']);

        $secret = 'secret detail from tool exception';
        $throwingTool = new class($secret) implements AgentTool {
            public function __construct(private readonly string $secret)
            {
            }

            public function name(): string
            {
                return 'list_my_machines';
            }

            public function description(): string
            {
                return 'test tool that throws';
            }

            public function inputSchema(): array
            {
                return ['type' => 'object', 'properties' => [], 'additionalProperties' => false];
            }

            public function execute(array $input): array
            {
                throw new \RuntimeException($this->secret);
            }
        };

        $registry = static::getContainer()->get(ToolRegistry::class);
        $registryProperty = new \ReflectionProperty(ToolRegistry::class, 'tools');
        $tools = $registryProperty->getValue($registry);
        $tools['list_my_machines'] = $throwingTool;
        $registryProperty->setValue($registry, $tools);

        $logger = static::getContainer()->get('monolog.logger.agent');
        $handler = new TestHandler();
        $logger->pushHandler($handler);

        $this->stub()->addResponse([['type' => 'tool_use', 'id' => 'throwing-tool', 'name' => 'list_my_machines', 'input' => []]]);
        $this->stub()->addResponse([['type' => 'text', 'text' => 'I could not complete that lookup.']]);

        try {
            $this->client->request('POST', '/api/agent/chat', [], [], ['CONTENT_TYPE' => 'application/json'], (string) json_encode(['message' => 'list my machines']));
            self::assertResponseStatusCodeSame(200);

            $body = (string) $this->client->getResponse()->getContent();
            $received = (string) json_encode($this->stub()->receivedMessages);
            $persistedRows = $this->em->getConnection()->fetchAllAssociative('SELECT content FROM agent_message');
            $persisted = (string) json_encode($persistedRows);
            self::assertStringContainsString('TOOL_ERROR', $received);
            self::assertStringNotContainsString($secret, $received.$persisted.$body);

            $records = $handler->getRecords();
            $failureRecords = array_values(array_filter($records, static fn ($record): bool => 'agent.tool_failed' === $record['message']));
            self::assertCount(1, $failureRecords);
            self::assertSame([
                'tool' => 'list_my_machines',
                'exception_class' => \RuntimeException::class,
            ], $failureRecords[0]['context']);
        } finally {
            $logger->popHandler();
        }
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
    public function testToolResultSizeLimitTruncatesListRowsBeforeRefusingPayload(): void
    {
        $owner = $this->createUser('tool-size-limit', 'tool-size-pass-123');
        $machine = $this->createMachine('M', 'sz-'.substr($this->suffix, 0, 8), $owner['user']);
        static::getContainer()->get(TokenStorageInterface::class)->setToken(
            new UsernamePasswordToken($owner['user'], 'main', $owner['user']->getRoles()),
        );
        $controller = static::getContainer()->get(AgentController::class);
        $orchestrator = (new ReflectionProperty(AgentController::class, 'orchestrator'))->getValue($controller);
        $clamp = new \ReflectionMethod(AgentOrchestrator::class, 'clampResult');
        $payload = ['ok' => true, 'data' => array_fill(0, 1000, ['name' => str_repeat('x', 100)])];

        $result = $clamp->invoke($orchestrator, $payload);

        self::assertTrue($result['ok']);
        self::assertTrue($result['truncatedBySizeLimit']);
        self::assertLessThanOrEqual(AgentOrchestrator::TOOL_RESULT_MAX_BYTES, strlen((string) json_encode($result, JSON_UNESCAPED_SLASHES)));
        self::assertLessThan(count($payload['data']), count($result['data']));
    }

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
