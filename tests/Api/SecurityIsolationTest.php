<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Entity\DeviceToken;
use App\Entity\Machine;
use App\Entity\MachineStatus;
use App\Entity\User;
use App\Repository\DeviceTokenRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Uid\Uuid;

/**
 * SECURITY contract tests — ownership isolation + device-token ingest.
 *
 * The core guarantees under test:
 *   1. user A cannot read or command user B's machine (404, never 403, so
 *      machine UUIDs cannot be enumerated);
 *   2. telemetry ingest accepts a valid DEVICE token and REJECTS user JWTs;
 *   3. revoked/unknown device tokens are refused.
 */
final class SecurityIsolationTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    private string $emailA;
    private string $emailB;
    private string $passwordA;
    private string $passwordB;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        // strtolower matters: base32 UUIDs are UPPERCASE and User::setEmail()
        // normalizes to lowercase — lookups must use the stored form.
        $suffix = strtolower(Uuid::v7()->toBase32());
        $this->emailA = sprintf('owner-a-%s@3awedlou.test', $suffix);
        $this->emailB = sprintf('owner-b-%s@3awedlou.test', $suffix);
        $this->passwordA = 'password-a-secret';
        $this->passwordB = 'password-b-secret';

        $this->createUser($this->emailA, $this->passwordA, 'Owner A');
        $this->createUser($this->emailB, $this->passwordB, 'Owner B');
    }

    public function testUserCannotSeeAnotherUsersMachineInList(): void
    {
        $machineA = $this->createMachine('A unit', 'iso-a-'.substr(Uuid::v7()->toBase32(), 0, 8), $this->emailA);
        $machineB = $this->createMachine('B unit', 'iso-b-'.substr(Uuid::v7()->toBase32(), 0, 8), $this->emailB);

        $this->login($this->emailA, $this->passwordA);
        $this->client->request('GET', '/api/machines');

        $ids = array_column($this->json()['data'], 'id');
        self::assertContains($machineA->getId()->toRfc4122(), $ids);
        self::assertNotContains($machineB->getId()->toRfc4122(), $ids);
    }

    public function testUserCannotReadAnotherUsersMachine(): void
    {
        $machineB = $this->createMachine('B unit', 'iso-c-'.substr(Uuid::v7()->toBase32(), 0, 8), $this->emailB);

        $this->login($this->emailA, $this->passwordA);

        // 404 — identical to an unknown id, never 403 (no enumeration).
        $this->client->request('GET', '/api/machines/'.$machineB->getId()->toRfc4122());
        self::assertResponseStatusCodeSame(404);
        self::assertSame('MACHINE_NOT_FOUND', $this->json()['error']['code']);
    }

    public function testUserCannotReadAnotherUsersMachineSubresources(): void
    {
        $machineB = $this->createMachine('B unit', 'iso-d-'.substr(Uuid::v7()->toBase32(), 0, 8), $this->emailB);
        $id = $machineB->getId()->toRfc4122();

        $this->login($this->emailA, $this->passwordA);

        foreach ([
            '/status', '/dashboard', '/telemetry', '/sessions', '/production', '/recycling',
        ] as $sub) {
            $this->client->request('GET', '/api/machines/'.$id.$sub);
            self::assertResponseStatusCodeSame(404, $sub.' must 404 for non-owners');
        }
    }

    public function testUserCannotCommandAnotherUsersMachine(): void
    {
        $machineB = $this->createMachine('B unit', 'iso-e-'.substr(Uuid::v7()->toBase32(), 0, 8), $this->emailB);
        $machineB->setStatus(MachineStatus::Idle)->markAsSeen();
        $this->em->flush();

        $this->login($this->emailA, $this->passwordA);

        $this->client->request(
            'POST',
            '/api/machines/'.$machineB->getId()->toRfc4122().'/commands',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            (string) json_encode(['command' => 'start']),
        );

        self::assertResponseStatusCodeSame(404);

        // And nothing may have been audited for B's machine — a machine-
        // scoped read (never a blanket assertEmpty: other machines may have
        // their own legitimate audit rows).
        $audits = $this->em->getRepository(\App\Entity\MachineCommandAudit::class)
            ->findBy(['machine' => $machineB]);
        self::assertEmpty($audits);
    }

    public function testUnownedMachineYieldsSameErrorAsUnknownId(): void
    {
        $machineB = $this->createMachine('B unit', 'iso-f-'.substr(Uuid::v7()->toBase32(), 0, 8), $this->emailB);
        $this->login($this->emailA, $this->passwordA);

        $this->client->request('GET', '/api/machines/'.$machineB->getId()->toRfc4122());
        $ownedError = $this->client->getResponse()->getContent();

        $this->client->request('GET', '/api/machines/'.Uuid::v7()->toRfc4122());
        $unknownError = $this->client->getResponse()->getContent();

        self::assertSame($ownedError, $unknownError, 'owned-by-other and unknown must be indistinguishable');
    }

    // --------------------------- device tokens ----------------------------

    public function testDeviceTokenCanIngestTelemetry(): void
    {
        $machine = $this->createMachine('Device unit', 'dev-a-'.substr(Uuid::v7()->toBase32(), 0, 8), $this->emailA);
        $plaintext = $this->issueDeviceToken($machine);

        $this->client->request(
            'POST',
            '/api/machines/'.$machine->getId()->toRfc4122().'/telemetry',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X_DEVICE_TOKEN' => $plaintext],
            (string) json_encode(['temperature' => 244.0, 'status' => 'extruding']),
        );

        self::assertResponseStatusCodeSame(201);
        self::assertSame(244.0, $this->json()['data']['temperature']);
    }

    public function testUserJwtCannotIngestTelemetry(): void
    {
        $machine = $this->createMachine('JWT unit', 'dev-b-'.substr(Uuid::v7()->toBase32(), 0, 8), $this->emailA);

        // Owner's JWT must NOT be able to ingest — that endpoint is device-only.
        $this->login($this->emailA, $this->passwordA);

        $this->client->request(
            'POST',
            '/api/machines/'.$machine->getId()->toRfc4122().'/telemetry',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            (string) json_encode(['temperature' => 200.0]),
        );

        self::assertResponseStatusCodeSame(401, 'user JWTs must never pass the device firewall');
    }

    public function testRevokedDeviceTokenCannotIngest(): void
    {
        $machine = $this->createMachine('Revoke unit', 'dev-c-'.substr(Uuid::v7()->toBase32(), 0, 8), $this->emailA);
        $plaintext = $this->issueDeviceToken($machine);

        /** @var DeviceTokenRepository $repo */
        $repo = $this->em->getRepository(DeviceToken::class);
        $repo->revokeAllForMachine($machine);
        $this->em->clear();

        $this->client->request(
            'POST',
            '/api/machines/'.$machine->getId()->toRfc4122().'/telemetry',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X_DEVICE_TOKEN' => $plaintext],
            (string) json_encode(['temperature' => 200.0]),
        );

        self::assertResponseStatusCodeSame(401);
        self::assertSame('INVALID_DEVICE_TOKEN', $this->json()['error']['code']);
    }

    public function testDeviceTokenWithoutHeaderIsUnauthorized(): void
    {
        $machine = $this->createMachine('Nohdr unit', 'dev-d-'.substr(Uuid::v7()->toBase32(), 0, 8), $this->emailA);

        $this->client->request(
            'POST',
            '/api/machines/'.$machine->getId()->toRfc4122().'/telemetry',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            (string) json_encode(['temperature' => 200.0]),
        );

        self::assertResponseStatusCodeSame(401);
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

    private function createUser(string $email, string $password, string $name): User
    {
        $user = new User();
        $user->setEmail($email)->setName($name);
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user->setPassword($hasher->hashPassword($user, $password));
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function createMachine(string $name, string $identifier, string $ownerEmail): Machine
    {
        $owner = $this->em->getRepository(User::class)->findOneBy(['email' => $ownerEmail]);
        self::assertNotNull($owner);

        $machine = new Machine();
        $machine->setName($name)->setIdentifier($identifier)->setStatus(MachineStatus::Idle)->setOwner($owner);
        $this->em->persist($machine);
        $this->em->flush();

        return $machine;
    }

    private function issueDeviceToken(Machine $machine): string
    {
        $plaintext = bin2hex(random_bytes(32));
        $this->em->persist(new DeviceToken($machine, hash('sha512', $plaintext), 'test device'));
        $this->em->flush();

        return $plaintext;
    }

    private function login(string $email, string $password): void
    {
        $this->client->request('POST', '/api/auth/login', [], [], ['CONTENT_TYPE' => 'application/json'], (string) json_encode([
            'email' => $email,
            'password' => $password,
        ]));

        self::assertResponseStatusCodeSame(200, 'Login must succeed for the security tests to proceed.');

        $token = $this->json()['data']['token'] ?? null;
        self::assertNotEmpty($token);

        $this->client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer '.$token);
    }
}
