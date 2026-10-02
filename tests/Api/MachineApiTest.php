<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Entity\Machine;
use App\Entity\MachineSession;
use App\Entity\MachineStatus;
use App\Entity\MachineTelemetry;
use App\Entity\RecyclingSession;
use App\Entity\SessionStatus;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Functional tests for the machine API (run against the test database).
 */
final class MachineApiTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private string $email;
    private User $user;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->email = strtolower(sprintf('tester-%s@3awedlou.test', Uuid::v7()->toBase32()));

        $this->user = $this->createUser($this->email, 'secret-password');
    }

    public function testMachineListRequiresAuthentication(): void
    {
        $this->client->request('GET', '/api/machines');

        self::assertResponseStatusCodeSame(401);
        self::assertJson($this->client->getResponse()->getContent());
        $body = $this->json();
        self::assertFalse($body['success']);
        self::assertSame('UNAUTHORIZED', $body['error']['code']);
    }

    public function testMachineListReturnsEnvelope(): void
    {
        $machine = $this->createMachine('Test Unit', 'test-unit-' . substr(Uuid::v7()->toBase32(), 0, 8));
        $this->login();

        $this->client->request('GET', '/api/machines');

        self::assertResponseIsSuccessful();
        $body = $this->json();
        self::assertTrue($body['success']);
        self::assertGreaterThan(0, $body['meta']['count']);

        $ids = array_column($body['data'], 'id');
        self::assertContains($machine->getId()->toRfc4122(), $ids);
    }

    public function testMachineDetailIncludesTelemetryAndActiveSession(): void
    {
        $machine = $this->createMachine('Detailed Unit', 'detailed-unit-' . substr(Uuid::v7()->toBase32(), 0, 8));
        $this->addTelemetry($machine, 192.5);
        $session = $this->addSession($machine);

        $this->login();

        $this->client->request('GET', '/api/machines/' . $machine->getId()->toRfc4122());

        self::assertResponseIsSuccessful();
        $data = $this->json()['data'];
        self::assertSame('Detailed Unit', $data['name']);
        self::assertSame(192.5, $data['telemetry']['temperature']);
        self::assertSame($session->getId()->toRfc4122(), $data['activeSessionId']);
    }

    public function testUnknownMachineReturnsMachineNotFoundError(): void
    {
        $this->login();

        $this->client->request('GET', '/api/machines/' . Uuid::v7()->toRfc4122());

        self::assertResponseStatusCodeSame(404);
        $body = $this->json();
        self::assertFalse($body['success']);
        self::assertSame('MACHINE_NOT_FOUND', $body['error']['code']);
        self::assertSame('Machine not found.', $body['error']['message']);
    }

    public function testMachineStatusEndpoint(): void
    {
        $machine = $this->createMachine('Status Unit', 'status-unit-' . substr(Uuid::v7()->toBase32(), 0, 8));
        $this->login();

        $url = '/api/machines/' . $machine->getId()->toRfc4122() . '/status';

        // Never seen → effective status must be offline, even with a lively
        // reported status (offline is DERIVED from lastSeenAt, not stored).
        $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();
        $data = $this->json()['data'];
        self::assertSame('offline', $data['status']);
        self::assertSame(MachineStatus::Idle->value, $data['reportedStatus']);
        self::assertNull($data['lastSeenAt']);

        // A fresh report — the telemetry ingest bumps lastSeenAt — flips the
        // effective status back to the reported one. Ingest is DEVICE-only,
        // so this uses a per-machine token, not the user's JWT.
        $machineId = $machine->getId()->toRfc4122();
        $plaintext = bin2hex(random_bytes(32));
        $tokenHash = hash('sha512', $plaintext);
        $machine = $this->em->find(Machine::class, $machineId); // fresh EM after the GET above
        $this->em->persist(new \App\Entity\DeviceToken($machine, $tokenHash, 'test device'));
        $this->em->flush();

        $this->client->request('POST', '/api/machines/'.$machineId.'/telemetry', [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_DEVICE_TOKEN' => $plaintext], (string) json_encode(['temperature' => 24.5]));
        self::assertResponseStatusCodeSame(201);

        $this->client->request('GET', $url);
        $data = $this->json()['data'];
        self::assertSame(MachineStatus::Idle->value, $data['status']);
        self::assertNotNull($data['secondsSinceLastSeen']);
    }

    public function testTelemetryHistoryEndpointWithPagination(): void
    {
        $machine = $this->createMachine('Telemetry Unit', 'telemetry-unit-' . substr(Uuid::v7()->toBase32(), 0, 8));
        $this->addTelemetry($machine, 190.0);
        $this->addTelemetry($machine, 191.0);
        $this->addTelemetry($machine, 192.0);

        $this->login();

        $this->client->request('GET', '/api/machines/' . $machine->getId()->toRfc4122() . '/telemetry?limit=2');

        self::assertResponseIsSuccessful();
        $body = $this->json();
        self::assertCount(2, $body['data']);
        self::assertSame(2, $body['meta']['limit']);
        // newest first
        self::assertSame(192.0, $body['data'][0]['temperature']);
    }

    public function testSessionsEndpointFiltersByStatus(): void
    {
        $machine = $this->createMachine('Session Unit', 'session-unit-' . substr(Uuid::v7()->toBase32(), 0, 8));
        $this->addSession($machine, SessionStatus::Completed);
        $this->addSession($machine, SessionStatus::InProgress);

        $this->login();

        $this->client->request('GET', '/api/machines/' . $machine->getId()->toRfc4122() . '/sessions?status=in_progress');

        self::assertResponseIsSuccessful();
        $body = $this->json();
        self::assertCount(1, $body['data']);
        self::assertSame('in_progress', $body['data'][0]['status']);
    }

    public function testRecyclingEndpointReturnsRecordsAndTotals(): void
    {
        $machine = $this->createMachine('Recycling Unit', 'recycling-unit-' . substr(Uuid::v7()->toBase32(), 0, 8));
        $session = $this->addSession($machine, SessionStatus::Completed);
        $this->addRecycling($machine, $session, 500.0, 400.0);

        $this->login();

        $this->client->request('GET', '/api/machines/' . $machine->getId()->toRfc4122() . '/recycling');

        self::assertResponseIsSuccessful();
        $data = $this->json()['data'];
        self::assertCount(1, $data['records']);
        self::assertSame(1, $data['totals']['records']);
        self::assertSame(500.0, $data['totals']['inputGrams']);
        self::assertSame(400.0, $data['totals']['outputGrams']);
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

        // Authenticate all subsequent requests of this test.
        $this->client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer ' . $token);
    }

    private function createUser(string $email, string $password): User
    {

        $user = new User();
        $user->setEmail($email)->setName('API Tester');

        /** @var UserPasswordHasherInterface $hasher */
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user->setPassword($hasher->hashPassword($user, $password));

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function createMachine(string $name, string $identifier): Machine
    {
        $machine = new Machine();
        $machine->setName($name)->setIdentifier($identifier)->setStatus(MachineStatus::Idle)->setOwner($this->user);
        $this->em->persist($machine);
        $this->em->flush();

        return $machine;
    }

    private function addTelemetry(Machine $machine, float $temperature): MachineTelemetry
    {
        $telemetry = new MachineTelemetry();
        $telemetry->setMachine($machine)
            ->setTemperature($temperature)
            ->setTargetTemperature(195.0);
        $this->em->persist($telemetry);
        $this->em->flush();

        return $telemetry;
    }

    private function addSession(Machine $machine, SessionStatus $status = SessionStatus::InProgress): MachineSession
    {
        $session = new MachineSession();
        $session->setMachine($machine)->setStatus($status);
        $this->em->persist($session);
        $this->em->flush();

        return $session;
    }

    private function addRecycling(Machine $machine, MachineSession $session, float $input, float $output): RecyclingSession
    {
        $recycling = new RecyclingSession();
        $recycling->setSession($session)
            ->setMachine($machine)
            ->setInputMassGrams($input)
            ->setOutputMassGrams($output);
        $this->em->persist($recycling);
        $this->em->flush();

        return $recycling;
    }
}
