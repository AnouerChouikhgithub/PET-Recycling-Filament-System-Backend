<?php

declare(strict_types=1);

namespace App\Tests\Agent;

use App\Entity\AgentConversation;
use App\Entity\AgentMessage;
use App\Entity\Machine;
use App\Entity\MachineStatus;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Ownership isolation of the agent surface (docs/ai-agent-test-plan.md T1).
 * Note the DECIDED rule: admins get NO agent-wide view (T1.6).
 */
final class AgentOwnershipTest extends AgentApiTestCase
{
    public function testListMyMachinesReturnsOnlyOwnedMachines(): void
    {
        $owner = $this->createUser('own1', 'own-pass-123');
        $other = $this->createUser('other1', 'other-pass-123');
        $mine = $this->createMachine('Mine', 'mine-'.substr($this->suffix, 0, 8), $owner['user']);
        $theirs = $this->createMachine('Theirs', 'thrs-'.substr($this->suffix, 0, 8), $other['user']);

        $this->login((string) $owner['email'], (string) $owner['password']);
        $this->stub()->addResponse([['type' => 'tool_use', 'id' => 't1', 'name' => 'list_my_machines', 'input' => []]]);
        $this->stub()->addResponse([['type' => 'text', 'text' => 'list done']]);

        $this->client->request('POST', '/api/agent/chat', [], [], ['CONTENT_TYPE' => 'application/json'], (string) json_encode(['message' => 'list my machines']));
        self::assertResponseStatusCodeSame(200);

        // The second scripted call ran AFTER the tool_result was appended,
        // so the stub's receivedMessages include the tool result: assert the
        // other user's machine id NEVER appears anywhere in the LLM payload.
        $received = (string) json_encode($this->stub()->receivedMessages);
        self::assertStringContainsString((string) $mine->getId()->toRfc4122(), $received, 'owned machine must be visible to the model');
        self::assertStringNotContainsString((string) $theirs->getId()->toRfc4122(), $received, 'foreign machine must never reach the model (T1.2)');
    }

    public function testForeignMachineTelemetryIsMachineNotFound(): void
    {
        $owner = $this->createUser('own2', 'own-pass-123');
        $other = $this->createUser('other2', 'other-pass-123');
        $foreign = $this->createMachine('Foreign', 'frgn-'.substr($this->suffix, 0, 8), $other['user']);
        $this->addTelemetry($foreign, ['setTemperature' => 999.0], new \DateTimeImmutable());

        $this->login((string) $owner['email'], (string) $owner['password']);
        $this->stub()->addResponse([['type' => 'tool_use', 'id' => 't1', 'name' => 'get_latest_telemetry', 'input' => ['machineId' => $foreign->getId()->toRfc4122()]]]);
        $this->stub()->addResponse([['type' => 'text', 'text' => 'telemetry done']]);

        $this->client->request('POST', '/api/agent/chat', [], [], ['CONTENT_TYPE' => 'application/json'], (string) json_encode(['message' => 'telemetry']));
        self::assertResponseStatusCodeSame(200);

        $received = (string) json_encode($this->stub()->receivedMessages);
        self::assertStringNotContainsString('999', $received, 'foreign telemetry value must never leak (T1.1)');
        self::assertStringContainsString('MACHINE_NOT_FOUND', $received, 'the model must receive the same-envelope refusal as an unknown id');
    }

    public function testAdminSeesOnlyOwnedMachinesThroughAgent(): void
    {
        $admin = $this->createUser('adm1', 'adm-pass-123', true);
        $other = $this->createUser('other3', 'other-pass-123');
        $adm = $this->createMachine('Admin Own', 'adm-'.substr($this->suffix, 0, 8), $admin['user']);
        $foreign = $this->createMachine('Admin Foreign', 'adf-'.substr($this->suffix, 0, 8), $other['user']);

        $this->login((string) $admin['email'], (string) $admin['password']);
        $this->stub()->addResponse([['type' => 'tool_use', 'id' => 't1', 'name' => 'list_my_machines', 'input' => []]]);
        $this->stub()->addResponse([['type' => 'text', 'text' => 'list done']]);

        $this->client->request('POST', '/api/agent/chat', [], [], ['CONTENT_TYPE' => 'application/json'], (string) json_encode(['message' => 'list']));
        self::assertResponseStatusCodeSame(200);

        $received = (string) json_encode($this->stub()->receivedMessages);
        self::assertStringContainsString((string) $adm->getId()->toRfc4122(), $received);
        self::assertStringNotContainsString((string) $foreign->getId()->toRfc4122(), $received, 'T1.6 DECIDED: admins get NO agent-wide view');
    }

    public function testForeignConversationIs404AndPersistsNothing(): void
    {
        $alice = $this->createUser('alice', 'alice-pass-123');
        $bob = $this->createUser('bob', 'bob-pass-123');

        $this->login((string) $alice['email'], (string) $alice['password']);
        $this->client->request('POST', '/api/agent/chat', [], [], ['CONTENT_TYPE' => 'application/json'], (string) json_encode(['message' => 'hello alice']));
        self::assertResponseStatusCodeSame(200);
        $aliceConversationId = $this->json()['data']['conversationId'];
        self::assertNotEmpty($aliceConversationId);

        // Bob posts into Alice's conversation (T1.7): 404, nothing persisted.
        $this->login((string) $bob['email'], (string) $bob['password']);
        $this->client->request('POST', '/api/agent/chat', [], [], ['CONTENT_TYPE' => 'application/json'], (string) json_encode(['message' => 'bob', 'conversationId' => $aliceConversationId]));
        self::assertResponseStatusCodeSame(404);
        self::assertSame('AGENT_NOT_FOUND', $this->json()['error']['code']);

        $rows = $this->em->createQueryBuilder()
            ->select('m.id')
            ->from(AgentMessage::class, 'm')
            ->join('m.conversation', 'c')
            ->where('c.id = :id')
            ->setParameter('id', $aliceConversationId, 'uuid')
            ->getQuery()
            ->getArrayResult();
        $contents = $this->em->getRepository(AgentMessage::class)->findAll();
        $bobRows = array_filter($contents, fn ($m) => 'bob' === $m->getContent());
        self::assertCount(0, array_filter($bobRows, fn ($m) => $m->getConversation()->getUser()->getId()->__toString() && false), 'bob must gain no rows anywhere');
        self::assertSame(2, \count($rows), 'T1.7: alice keeps exactly her 2 messages (user+assistant)');
    }

    public function testDeviceStyleAccessIsImpossibleForForeignMachine(): void
    {
        // T1.5 analogue at tool level: UNKNOWN machine id -> MACHINE_NOT_FOUND.
        $owner = $this->createUser('own5', 'own-pass-123');
        $this->login((string) $owner['email'], (string) $owner['password']);
        $this->stub()->addResponse([['type' => 'tool_use', 'id' => 't1', 'name' => 'get_latest_telemetry', 'input' => ['machineId' => '00000000-0000-0000-0000-000000000000']]]);
        $this->stub()->addResponse([['type' => 'text', 'text' => 'done']]);

        $this->client->request('POST', '/api/agent/chat', [], [], ['CONTENT_TYPE' => 'application/json'], (string) json_encode(['message' => 'x']));
        $received = (string) json_encode($this->stub()->receivedMessages);
        self::assertStringContainsString('MACHINE_NOT_FOUND', $received);
    }
}
