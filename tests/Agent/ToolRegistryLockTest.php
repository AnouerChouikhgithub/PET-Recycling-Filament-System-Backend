<?php

declare(strict_types=1);

namespace App\Tests\Agent;

use App\Entity\Machine;
use App\Entity\MachineStatus;
use App\Entity\User;
use App\Repository\MachineCommandAuditRepository;
use App\Service\Agent\ScriptedStubLlmClient;
use App\Service\Agent\ToolRegistry;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * T4.1: the tool set is EXACTLY the five phase-1 read-only tools. Any added,
 * renamed or removed tool fails this test - the hard gate that keeps heater/
 * duty/PWM tooling out of the agent.
 * T5: the telemetry tool payload contract (Not reported / staleness).
 */
final class ToolRegistryLockTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    private string $suffix;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->suffix = strtolower(\Symfony\Component\Uid\Uuid::v7()->toBase32());
    }

    public function testRegistryContainsExactlyTheFiveReadOnlyTools(): void
    {
        /** @var ToolRegistry $registry */
        $registry = static::getContainer()->get(ToolRegistry::class);

        self::assertSame(
            ['list_my_machines', 'get_latest_telemetry', 'get_recent_events', 'explain_fault_code', 'get_audit_log'],
            $registry->names(),
            'T4.1 the registry is LOCKED to the five read-only tools',
        );
    }

    public function testNoRegisteredToolMapsToACommandType(): void
    {
        /** @var ToolRegistry $registry */
        $registry = static::getContainer()->get(ToolRegistry::class);

        foreach ($registry->names() as $name) {
            self::assertDoesNotMatchRegularExpression('/^(set_|send_|exec_|dispatch_)/', $name, 'phase-1 tools must never look like command dispatchers');
        }
    }

    public function testAgentCodeNeverImportsMachineCommandService(): void
    {
        // T4.2: static check - no agent class may reference the command
        // service, guard or publisher, so there is no code path to dispatch.
        $dir = __DIR__.'/../../src/Service/Agent';
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir));
        foreach ($it as $file) {
            if (!$file->isFile() || 'php' !== $file->getExtension()) {
                continue;
            }
            $code = (string) file_get_contents($file->getPathname());
            self::assertStringNotContainsString('MachineCommandService', $code, 'agent code must not import the command service: '.$file->getFilename());
            self::assertStringNotContainsString('MachineCommandGuard', $code, 'agent code must not import the command guard: '.$file->getFilename());
            self::assertStringNotContainsString('MachineCommandType', $code, 'agent code must not import command types: '.$file->getFilename());
        }
    }

    public function testTelemetryPayloadContract(): void
    {
        $owner = $this->createUser('t5a', 't5-pass-123');
        $machine = $this->createMachine('T5 Unit', 't5-'.substr($this->suffix, 0, 8), $owner['user']);
        // Only temperature: fan/diameter/energy stay NULL -> "Not reported".
        $this->addTelemetry($machine, ['setTemperature' => 212.5], new \DateTimeImmutable('-900 seconds'));

        $this->loginAs($owner['user']);
        $result = static::getContainer()->get(\App\Service\Agent\Tools\GetLatestTelemetryTool::class)
            ->execute(['machineId' => $machine->getId()->toRfc4122()]);

        self::assertTrue($result['ok']);
        $data = $result['data'];
        self::assertSame('Not reported', $data['fanState'], 'T5.2 null fan must surface as Not reported');
        self::assertSame('Not reported', $data['filamentDiameter'], 'T5.2 null diameter must surface as Not reported');
        self::assertSame('Not reported', $data['energyConsumption'], 'T5.2 null energy must surface as Not reported');
        self::assertSame(212.5, $data['temperature']);
        self::assertSame('very stale, telemetry missing', $data['staleness'], 'T5.1 >600 s must be labelled very stale');
        self::assertSame(900, $data['dataAgeSeconds']);
        self::assertArrayHasKey('recordedAt', $data);
    }

    public function testFreshTelemetryCarriesFreshLabel(): void
    {
        $owner = $this->createUser('t5b', 't5-pass-123');
        $machine = $this->createMachine('T5 Fresh', 't5f-'.substr($this->suffix, 0, 8), $owner['user']);
        $this->addTelemetry($machine, ['setTemperature' => 200.0, 'setHeaterState' => false], new \DateTimeImmutable('-10 seconds'));

        $this->loginAs($owner['user']);
        $result = static::getContainer()->get(\App\Service\Agent\Tools\GetLatestTelemetryTool::class)
            ->execute(['machineId' => $machine->getId()->toRfc4122()]);

        self::assertSame('fresh', $result['data']['staleness']);
        self::assertFalse($result['data']['heaterState'], 'fresh explicit false is legitimate data (T5.3)');
    }

    public function testFaultCodeExactMatchOnly(): void
    {
        $tool = static::getContainer()->get(\App\Service\Agent\Tools\ExplainFaultCodeTool::class);

        $known = $tool->execute(['faultCode' => 'idle']);
        self::assertTrue($known['data']['known']);
        self::assertSame('Idle', $known['data']['title']);

        $unknown = $tool->execute(['faultCode' => 'E-9999-INJECT']);
        self::assertFalse($unknown['data']['known'], 'unknown codes must return known:false, never a guess');

        $evil = $tool->execute(['faultCode' => "bad; rm -rf /"]);
        self::assertFalse($evil['data']['known'], 'charset violations are rejected before any matching');
    }

    // ---------------------------------------------------------------- helpers

    /** @return array{email: string, password: string, user: \App\Entity\User} */
    private function createUser(string $prefix, string $password, bool $admin = false): array
    {
        $user = new \App\Entity\User();
        $user->setEmail(strtolower($prefix.'-'.$this->suffix.'@3awedlou.test'))->setName('T '.substr($prefix, 0, 8));
        $hasher = static::getContainer()->get(\Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface::class);
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

    /**
     * @param array<string, mixed> $channels setter name => value
     */
    private function addTelemetry(Machine $machine, array $channels, \DateTimeImmutable $recordedAt): void
    {
        $t = new \App\Entity\MachineTelemetry();
        $t->setMachine($machine)->setRecordedAt($recordedAt);
        foreach ($channels as $setter => $value) {
            $t->{$setter}($value);
        }
        $this->em->persist($t);
        $this->em->flush();
    }

    private function loginAs(User $user): void
    {
        $storage = static::getContainer()->get(TokenStorageInterface::class);
        $storage->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));
    }
}
