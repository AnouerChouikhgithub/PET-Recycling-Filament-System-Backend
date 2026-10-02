<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Entity\Machine;
use App\Entity\MachineCommandAudit;
use App\Entity\MachineStatus;
use App\Entity\User;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Ownership edge cases + the command audit trail:
 *
 *   1. machines with NO owner are visible to ROLE_ADMIN only — a regular
 *      user gets the standard 404 envelope on both the list and the detail;
 *   2. an admin override reaches machines owned by other users;
 *   3. every ACCEPTED command writes one audit row (user, machine, command,
 *      value, transport, timestamp) — and rejected commands write none.
 */
final class OwnershipAndAuditTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private string $suffix;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        // strtolower: base32 UUIDs are UPPERCASE and emails normalize lowercase.
        $this->suffix = strtolower(Uuid::v7()->toBase32());
    }

    public function testOrphanMachineIsInvisibleToRegularUsers(): void
    {
        $orphan = $this->createOrphanMachine('orphan-'.substr($this->suffix, 0, 10));

        $plain = $this->createUser('plain-'.$this->suffix, 'plain-pass-123', false);
        $this->login((string) $plain['email'], (string) $plain['password']);

        $this->client->request('GET', '/api/machines');
        $ids = array_column($this->json()['data'], 'id');
        self::assertNotContains($orphan->getId()->toRfc4122(), $ids, 'ownerless machines must not leak into user lists');

        $this->client->request('GET', '/api/machines/'.$orphan->getId()->toRfc4122());
        self::assertResponseStatusCodeSame(404, 'ownerless machines are the standard 404 for users');

        $this->client->request('POST', '/api/machines/'.$orphan->getId()->toRfc4122().'/commands', [], [], ['CONTENT_TYPE' => 'application/json'], (string) json_encode(['command' => 'start']));
        self::assertResponseStatusCodeSame(404, 'no commands to machines you cannot see');
    }

    public function testOrphanMachineIsVisibleToAdmin(): void
    {
        $orphan = $this->createOrphanMachine('orphan-admin-'.substr($this->suffix, 0, 8));

        $admin = $this->createUser('admin-'.$this->suffix, 'admin-pass-123', true);
        $this->login((string) $admin['email'], (string) $admin['password']);

        $this->client->request('GET', '/api/machines');
        $ids = array_column($this->json()['data'], 'id');
        self::assertContains($orphan->getId()->toRfc4122(), $ids, 'ROLE_ADMIN sees ownerless machines');

        $this->client->request('GET', '/api/machines/'.$orphan->getId()->toRfc4122());
        self::assertResponseIsSuccessful('admin can read the ownerless machine');
    }

    public function testAdminSeesAnotherUsersMachine(): void
    {
        $owner = $this->createUser('owner-'.$this->suffix, 'owner-pass-123', false);
        $machine = $this->createMachine('Owned by someone else', 'adm-'.substr($this->suffix, 0, 10), $owner['user']);

        $admin = $this->createUser('admin2-'.$this->suffix, 'admin2-pass-123', true);
        $this->login((string) $admin['email'], (string) $admin['password']);

        $this->client->request('GET', '/api/machines/'.$machine->getId()->toRfc4122());
        self::assertResponseIsSuccessful('admin override reaches other users machines');
    }

    public function testAcceptedCommandWritesOneAuditRow(): void
    {
        $owner = $this->createUser('audit-'.$this->suffix, 'audit-pass-123', false);
        $machine = $this->createMachine('Audit Unit', 'audit-'.substr($this->suffix, 0, 10), $owner['user']);
        $machine->setStatus(MachineStatus::Idle)->markAsSeen();
        $this->em->flush();

        $this->login((string) $owner['email'], (string) $owner['password']);
        $this->client->request('POST', '/api/machines/'.$machine->getId()->toRfc4122().'/commands', [], [], ['CONTENT_TYPE' => 'application/json'], (string) json_encode(['command' => 'start']));

        self::assertResponseStatusCodeSame(202);

        /** @var list<MachineCommandAudit> $rows */
        $rows = $this->em->getRepository(MachineCommandAudit::class)->findBy(['machine' => $machine]);
        self::assertCount(1, $rows, 'exactly one audit row per accepted command');

        $row = $rows[0];
        self::assertSame('start', $row->getCommand()->value, 'the command is stored as the public string value');
        self::assertSame($owner['user']->getId()->toRfc4122(), $row->getUser()?->getId()->toRfc4122());
        self::assertSame($machine->getId()->toRfc4122(), $row->getMachine()->getId()->toRfc4122());
        self::assertInstanceOf(\DateTimeImmutable::class, $row->getCreatedAt(), 'audit row must carry the command timestamp');
        self::assertSame('buffered-log', $row->getTransport());
    }

    public function testRejectedCommandWritesNoAuditRow(): void
    {
        $owner = $this->createUser('audit2-'.$this->suffix, 'audit2-pass-123', false);
        $machine = $this->createMachine('Audit Reject Unit', 'audit2-'.substr($this->suffix, 0, 8), $owner['user']);
        // Never seen → offline → command must be refused.
        $this->em->flush();

        $this->login((string) $owner['email'], (string) $owner['password']);
        $this->client->request('POST', '/api/machines/'.$machine->getId()->toRfc4122().'/commands', [], [], ['CONTENT_TYPE' => 'application/json'], (string) json_encode(['command' => 'start']));

        self::assertResponseStatusCodeSame(422, 'offline machines refuse commands');

        $rows = $this->em->getRepository(MachineCommandAudit::class)->findBy(['machine' => $machine]);
        self::assertCount(0, $rows, 'rejected commands must leave no audit row');
    }

    public function testHeaterCeilingDefaultsTo260C(): void
    {
        // The firmware PET PID target is 245 °C; the backend guard cap must
        // default to 260 °C (see COMMAND_HEATER_MAX_C in .env.example).
        $owner = $this->createUser('heat-'.$this->suffix, 'heat-pass-123', false);
        $machine = $this->createMachine('Heater Unit', 'heat-'.substr($this->suffix, 0, 9), $owner['user']);
        $machine->setStatus(MachineStatus::Extruding)->markAsSeen();
        $this->em->flush();

        $this->login((string) $owner['email'], (string) $owner['password']);
        $url = '/api/machines/'.$machine->getId()->toRfc4122().'/commands';

        // 261 °C is above the configured default cap of 260 → refused.
        $this->client->request('POST', $url, [], [], ['CONTENT_TYPE' => 'application/json'], (string) json_encode(['command' => 'setTargetTemperature', 'value' => 261]));
        self::assertResponseStatusCodeSame(422, 'heater cap default must be 260 C');

        // 245 °C — the firmware target — must be accepted.
        $this->client->request('POST', $url, [], [], ['CONTENT_TYPE' => 'application/json'], (string) json_encode(['command' => 'setTargetTemperature', 'value' => 245]));
        self::assertResponseStatusCodeSame(202, 'the firmware PET target (245 C) must be accepted');
    }

    public function testMachineCommandTypePublicValuesMatchContract(): void
    {
        // The public API contract is docs/api-contract.md — this test fails
        // if anyone adds/removes/renames a command value silently.
        $contract = file_get_contents(dirname(__DIR__, 2).'/docs/api-contract.md');
        self::assertIsString($contract);

        // The whole list must sit on ONE "Commands:" line, every value backticked.
        preg_match('/^Commands:((?:\s+`[^`]+`)+)\.?$/m', $contract, $line);
        self::assertArrayHasKey(1, $line, 'docs/api-contract.md must list the command values on one line');

        preg_match_all('/`([^`]+)`/', $line[1], $m);
        $documented = $m[1];
        self::assertSame(
            \App\Entity\MachineCommandType::values(),
            $documented,
            'MachineCommandType public values and docs/api-contract.md must stay identical',
        );
    }

    public function testDeviceTokenIsBoundToItsOwnMachineOnly(): void
    {
        $owner = $this->createUser('bind-'.$this->suffix, 'bind-pass-123', false);
        $machine1 = $this->createMachine('Token Unit 1', 'tok1-'.substr($this->suffix, 0, 8), $owner['user']);
        $machine2 = $this->createMachine('Token Unit 2', 'tok2-'.substr($this->suffix, 0, 8), $owner['user']);

        $plaintext = bin2hex(random_bytes(32));
        $this->em->persist(new \App\Entity\DeviceToken($machine1, hash('sha512', $plaintext), 'binding test'));
        $this->em->flush();

        // Ingest into machine 1 → accepted.
        $this->client->request('POST', '/api/machines/'.$machine1->getId()->toRfc4122().'/telemetry', [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_DEVICE_TOKEN' => $plaintext], (string) json_encode(['temperature' => 244.0]));
        self::assertResponseStatusCodeSame(201, 'a machine token works on its own machine');

        // The same token against machine 2 → the standard 404 envelope.
        $this->client->request('POST', '/api/machines/'.$machine2->getId()->toRfc4122().'/telemetry', [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_DEVICE_TOKEN' => $plaintext], (string) json_encode(['temperature' => 244.0]));
        self::assertResponseStatusCodeSame(404, 'a token must never act on another machine');
        self::assertSame('MACHINE_NOT_FOUND', $this->json()['error']['code']);

        // And machine 2's telemetry table must not have received the sample.
        $count = $this->em->getConnection()->fetchOne(
            'SELECT count(*) FROM machine_telemetry WHERE machine_id = ?',
            [$machine2->getId()->toRfc4122()],
            [Types::STRING],
        );
        self::assertSame(0, (int) $count, 'no sample may be written for the wrong machine');
    }

    public function testDeviceTokensAreStoredHashedOnly(): void
    {
        $owner = $this->createUser('hash-'.$this->suffix, 'hash-pass-123', false);
        $machine = $this->createMachine('Hash Unit', 'hash-'.substr($this->suffix, 0, 9), $owner['user']);

        $plaintext = bin2hex(random_bytes(32));
        $this->em->persist(new \App\Entity\DeviceToken($machine, hash('sha512', $plaintext), 'hash test'));
        $this->em->flush();

        $this->em->clear();
        $stored = $this->em->getRepository(\App\Entity\DeviceToken::class)->findOneBy(['machine' => $machine]);
        if (!$stored instanceof \App\Entity\DeviceToken) {
            self::fail('device token row must exist after persist+flush');
        }
        self::assertNotSame($plaintext, $stored->getTokenHash(), 'plaintext must never be stored');
        self::assertSame(128, strlen($stored->getTokenHash()), 'sha512 hex is 128 chars');
        self::assertSame(hash('sha512', $plaintext), $stored->getTokenHash());
        self::assertNull($stored->getLastUsedAt());

        // Using it records usage but still stores only the hash.
        $this->client->request('POST', '/api/machines/'.$machine->getId()->toRfc4122().'/telemetry', [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_DEVICE_TOKEN' => $plaintext], (string) json_encode(['temperature' => 100.0]));
        self::assertResponseStatusCodeSame(201);
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

    /** @return array{email: string, password: string, user: User} */
    private function createUser(string $prefix, string $password, bool $admin): array
    {
        $user = new User();
        $user->setEmail(strtolower($prefix.'@3awedlou.test'))->setName('Test User '.substr($prefix, 0, 12));
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user->setPassword($hasher->hashPassword($user, $password));
        if ($admin) {
            $user->setRoles(['ROLE_ADMIN']);
        }
        $this->em->persist($user);
        $this->em->flush();

        return ['email' => $user->getEmail(), 'password' => $password, 'user' => $user];
    }

    private function createMachine(string $name, string $identifier, User $owner): Machine
    {
        $machine = new Machine();
        $machine->setName($name)->setIdentifier($identifier)->setStatus(MachineStatus::Idle)->setOwner($owner);
        $this->em->persist($machine);
        $this->em->flush();

        return $machine;
    }

    private function createOrphanMachine(string $identifier): Machine
    {
        $machine = new Machine();
        $machine->setName('Orphan '.$identifier)->setIdentifier($identifier)->setStatus(MachineStatus::Idle);
        $this->em->persist($machine);
        $this->em->flush();

        return $machine;
    }

    private function login(string $email, string $password): void
    {
        $this->client->request('POST', '/api/auth/login', [], [], ['CONTENT_TYPE' => 'application/json'], (string) json_encode([
            'email' => $email,
            'password' => $password,
        ]));

        self::assertResponseStatusCodeSame(200, 'login must succeed for the ownership tests to proceed');

        $token = $this->json()['data']['token'] ?? null;
        self::assertNotEmpty($token);
        $this->client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer '.$token);
    }
}
