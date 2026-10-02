<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Entity\Machine;
use App\Entity\MachineStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Functional tests for the IoT write API: telemetry ingestion and machine
 * commands (run against the test database).
 */
final class IotApiTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private string $email;
    private \App\Entity\User $user;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->email = strtolower(sprintf('iot-%s@3awedlou.test', Uuid::v7()->toBase32()));

        $user = new \App\Entity\User();
        $user->setEmail($this->email)->setName('IoT Tester');
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user->setPassword($hasher->hashPassword($user, 'secret-password'));
        $this->em->persist($user);
        $this->em->flush();
        $this->user = $user;

        $this->login();
    }

    public function testTelemetryIngestPersistsSampleAndRefreshesLastSeen(): void
    {
        $machine = $this->createMachine('Ingest Unit', 'ingest-unit-'.substr(Uuid::v7()->toBase32(), 0, 8));

        // Ingest is DEVICE-only: authenticate with a per-machine token.
        $this->client->request('POST', '/api/machines/'.$machine->getId()->toRfc4122().'/telemetry', [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_DEVICE_TOKEN' => $this->issueDeviceToken($machine)], (string) json_encode([
            'temperature' => 192.4,
            'targetTemperature' => 245.0,
            'heaterState' => true,
            'motorState' => true,
            'motorSpeed' => 42,
            'extra' => ['vibrationG' => 0.02],
        ]));

        self::assertResponseStatusCodeSame(201);
        $data = $this->json()['data'];
        self::assertSame(192.4, $data['temperature']);
        self::assertSame(42, $data['motorSpeed']);
        self::assertSame(['vibrationG' => 0.02], $data['extra']);

        // Ingestion must mark the machine as seen (connectivity signal) —
        // verified through the API since each request reboots the EM.
        $this->client->request('GET', '/api/machines/'.$machine->getId()->toRfc4122().'/status');
        $status = $this->json()['data'];
        self::assertNotNull($status['lastSeenAt']);
        self::assertNotNull($status['secondsSinceLastSeen']);

        // And the sample must be queryable in the history.
        $this->client->request('GET', '/api/machines/'.$machine->getId()->toRfc4122().'/telemetry?limit=1');
        self::assertSame(192.4, $this->json()['data'][0]['temperature']);
    }

    public function testTelemetryIngestUpdatesMachineStatus(): void
    {
        $machine = $this->createMachine('Status Unit', 'status-unit-'.substr(Uuid::v7()->toBase32(), 0, 8));

        $this->client->request('POST', '/api/machines/'.$machine->getId()->toRfc4122().'/telemetry', [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_DEVICE_TOKEN' => $this->issueDeviceToken($machine)], (string) json_encode([
            'temperature' => 100.0,
            'status' => 'heating',
        ]));

        self::assertResponseStatusCodeSame(201);

        $this->client->request('GET', '/api/machines/'.$machine->getId()->toRfc4122().'/status');
        self::assertSame('heating', $this->json()['data']['reportedStatus']);
    }

    public function testTelemetryIngestRejectsEmptyAndOutOfRangePayloads(): void
    {
        $machine = $this->createMachine('Guard Unit', 'guard-unit-'.substr(Uuid::v7()->toBase32(), 0, 8));
        $url = '/api/machines/'.$machine->getId()->toRfc4122().'/telemetry';
        $device = ['HTTP_X_DEVICE_TOKEN' => $this->issueDeviceToken($machine), 'CONTENT_TYPE' => 'application/json'];

        // No channel at all → 422.
        $this->client->request('POST', $url, [], [], $device, '{}');
        self::assertResponseStatusCodeSame(422);
        $body = $this->json();
        self::assertSame('VALIDATION_FAILED', $body['error']['code']);

        // Out of range → 422.
        $this->client->request('POST', $url, [], [], $device, (string) json_encode(['temperature' => 9999.0]));
        self::assertResponseStatusCodeSame(422);
        self::assertArrayHasKey('temperature', $this->json()['error']['details']);

        // Unknown field → 422 (payload hygiene).
        $this->client->request('POST', $url, [], [], $device, (string) json_encode(['voltage' => 12.0]));
        self::assertResponseStatusCodeSame(422);

        // `offline` may never be pushed by a device — it is derived backend-side.
        $this->client->request('POST', $url, [], [], $device, (string) json_encode(['temperature' => 20.0, 'status' => 'offline']));
        self::assertResponseStatusCodeSame(422);

        // recordedAt in the far future → 422 (clock-skew guard, UTC).
        $this->client->request('POST', $url, [], [], $device, (string) json_encode(['temperature' => 20.0, 'recordedAt' => '2099-01-01T00:00:00+00:00']));
        self::assertResponseStatusCodeSame(422);
    }

    public function testCommandRequiresKnownCommand(): void
    {
        $machine = $this->createMachine('Cmd Unit', 'cmd-unit-'.substr(Uuid::v7()->toBase32(), 0, 8));
        $machine->setStatus(MachineStatus::Idle)->markAsSeen();
        $this->em->flush();
        $url = '/api/machines/'.$machine->getId()->toRfc4122().'/commands';

        $this->client->request('POST', $url, [], [], ['CONTENT_TYPE' => 'application/json'], (string) json_encode(['command' => 'selfDestruct']));
        self::assertResponseStatusCodeSame(422);

        $this->client->request('POST', $url, [], [], ['CONTENT_TYPE' => 'application/json'], '[]');
        self::assertResponseStatusCodeSame(422);
    }

    public function testStartCommandAcceptedFromIdleAndPublishedToTopic(): void
    {
        $machine = $this->createMachine('Publish Unit', 'publish-unit-'.substr(Uuid::v7()->toBase32(), 0, 8));
        $machine->setStatus(MachineStatus::Idle)->markAsSeen();
        $this->em->flush();

        $this->client->request('POST', '/api/machines/'.$machine->getId()->toRfc4122().'/commands', [], [], ['CONTENT_TYPE' => 'application/json'], (string) json_encode(['command' => 'start']));

        self::assertResponseStatusCodeSame(202);
        $data = $this->json()['data'];
        self::assertTrue($data['accepted']);
        self::assertSame('start', $data['command']);
        self::assertSame('3awedlou/machines/'.$machine->getIdentifier().'/commands', $data['topic']);
        // Honesty: the response must say the delivery transport, and never
        // claim the device executed anything.
        self::assertArrayHasKey('delivery', $data);
        self::assertFalse($data['deviceAcknowledged']);
    }

    public function testCommandRejectedWhenMachineOffline(): void
    {
        // Never seen → offline → every command must be refused.
        $machine = $this->createMachine('Sleepy Unit', 'sleepy-unit-'.substr(Uuid::v7()->toBase32(), 0, 8));

        $this->client->request('POST', '/api/machines/'.$machine->getId()->toRfc4122().'/commands', [], [], ['CONTENT_TYPE' => 'application/json'], (string) json_encode(['command' => 'start']));

        self::assertResponseStatusCodeSame(422);
        $body = $this->json();
        self::assertFalse($body['success']);
    }

    public function testResumeRejectedUnlessPausedAndSetFanRangeEnforced(): void
    {
        $machine = $this->createMachine('Range Unit', 'range-unit-'.substr(Uuid::v7()->toBase32(), 0, 8));
        $machine->setStatus(MachineStatus::Idle)->markAsSeen();
        $this->em->flush();
        $url = '/api/machines/'.$machine->getId()->toRfc4122().'/commands';

        // resume from idle → rejected
        $this->client->request('POST', $url, [], [], ['CONTENT_TYPE' => 'application/json'], (string) json_encode(['command' => 'resume']));
        self::assertResponseStatusCodeSame(422);

        // fan over 100% → rejected
        $this->client->request('POST', $url, [], [], ['CONTENT_TYPE' => 'application/json'], (string) json_encode(['command' => 'setFan', 'value' => 120]));
        self::assertResponseStatusCodeSame(422);

        // valid fan value → accepted
        $this->client->request('POST', $url, [], [], ['CONTENT_TYPE' => 'application/json'], (string) json_encode(['command' => 'setFan', 'value' => 80]));
        self::assertResponseStatusCodeSame(202);
    }

    public function testDashboardEndpointReturnsAggregatedView(): void
    {
        $machine = $this->createMachine('Dash Unit', 'dash-unit-'.substr(Uuid::v7()->toBase32(), 0, 8));
        $machine->markAsSeen();
        $this->em->flush();

        $samples = [190.0, 191.5, 192.3];
        foreach ($samples as $temp) {
            $t = new \App\Entity\MachineTelemetry();
            $t->setMachine($machine)->setTemperature($temp)->setTargetTemperature(195.0);
            $this->em->persist($t);
        }
        $this->em->flush();

        $this->client->request('GET', '/api/machines/'.$machine->getId()->toRfc4122().'/dashboard');

        self::assertResponseIsSuccessful();
        $data = $this->json()['data'];
        self::assertCount(3, $data['recentTelemetry']);
        // oldest → newest for charts
        self::assertSame(190.0, $data['recentTelemetry'][0]['temperature']);
        self::assertSame(192.3, $data['telemetry']['temperature']);
        self::assertArrayHasKey('recyclingTotals', $data);
        self::assertSame(0, $data['recyclingTotals']['records']);
    }

    // ---------------------------------------------------------------- helpers

    /**
     * @return array<string, mixed>
     */
    private function json(): array
    {
        $decoded = json_decode($this->client->getResponse()->getContent(), true);
        self::assertIsArray($decoded);

        return $decoded;
    }

    private function login(string $password = 'secret-password'): void
    {
        $this->client->request('POST', '/api/auth/login', [], [], ['CONTENT_TYPE' => 'application/json'], (string) json_encode([
            'email' => $this->email,
            'password' => $password,
        ]));

        self::assertResponseStatusCodeSame(200, 'Login must succeed for the API tests to proceed.');

        $token = $this->json()['data']['token'] ?? null;
        self::assertNotEmpty($token);

        $this->client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer '.$token);
    }

    private function createMachine(string $name, string $identifier): Machine
    {
        $machine = new Machine();
        $machine->setName($name)->setIdentifier($identifier)->setStatus(MachineStatus::Idle)->setOwner($this->user);
        $this->em->persist($machine);
        $this->em->flush();

        return $machine;
    }

    private function issueDeviceToken(Machine $machine): string
    {
        $plaintext = bin2hex(random_bytes(32));
        $this->em->persist(new \App\Entity\DeviceToken($machine, hash('sha512', $plaintext), 'test device'));
        $this->em->flush();

        return $plaintext;
    }
}
