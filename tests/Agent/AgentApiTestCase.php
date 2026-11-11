<?php

declare(strict_types=1);

namespace App\Tests\Agent;

use App\Entity\Machine;
use App\Entity\MachineStatus;
use App\Entity\MachineTelemetry;
use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Shared bootstrap for the agent functional tests: create users/machines,
 * login, and grab the ScriptedStubLlmClient from the (public) container.
 */
abstract class AgentApiTestCase extends WebTestCase
{
    protected KernelBrowser $client;

    protected EntityManagerInterface $em;

    protected string $suffix;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        // Without this the kernel reboots per request and the scripted stub
        // state (owned by the test) is disconnected from the new container.
        $this->client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->suffix = strtolower(Uuid::v7()->toBase32());
    }

    /** @return array{email: string, password: string, user: User} */
    protected function createUser(string $prefix, string $password, bool $admin = false): array
    {
        $user = new User();
        $user->setEmail(strtolower($prefix.'-'.$this->suffix.'@3awedlou.test'))
            ->setName('Test '.substr($prefix, 0, 12));
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

    protected function createMachine(string $name, string $identifier, User $owner): Machine
    {
        $machine = new Machine();
        $machine->setName($name)
            ->setIdentifier($identifier)
            ->setStatus(MachineStatus::Idle)
            ->setOwner($owner);
        $this->em->persist($machine);
        $this->em->flush();

        return $machine;
    }

    /**
     * @param array<string, mixed> $channels setter name => value
     */
    protected function addTelemetry(Machine $machine, array $channels, \DateTimeImmutable $recordedAt): MachineTelemetry
    {
        $telemetry = new MachineTelemetry();
        $telemetry->setMachine($machine)->setRecordedAt($recordedAt);
        foreach ($channels as $setter => $value) {
            $telemetry->{$setter}($value);
        }
        $this->em->persist($telemetry);
        $this->em->flush();

        return $telemetry;
    }

    protected function login(string $email, string $password): void
    {
        $this->client->request('POST', '/api/auth/login', [], [], ['CONTENT_TYPE' => 'application/json'], (string) json_encode([
            'email' => $email,
            'password' => $password,
        ]));

        self::assertResponseStatusCodeSame(200, 'login must succeed for agent tests to proceed');
        $body = (array) json_decode($this->client->getResponse()->getContent(), true);
        $token = $body['data']['token'] ?? null;
        self::assertNotEmpty($token);
        $this->client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer '.$token);
    }

    /**
     * @return array<string, mixed> decoded JSON envelope
     */
    protected function json(): array
    {
        $decoded = json_decode($this->client->getResponse()->getContent(), true);
        self::assertIsArray($decoded);

        return $decoded;
    }

    protected function stub(): \App\Service\Agent\ScriptedStubLlmClient
    {
        /** @var \App\Service\Agent\ScriptedStubLlmClient $stub */
        $stub = static::getContainer()->get(\App\Service\Agent\ScriptedStubLlmClient::class);

        return $stub;
    }

    protected function auditRowCount(): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT count(*) FROM machine_command_audit');
    }
}
