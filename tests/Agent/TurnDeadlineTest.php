<?php

declare(strict_types=1);

namespace App\Tests\Agent;

use App\Entity\AgentMessage;
use App\Entity\User;
use App\Repository\AgentConversationRepository;
use App\Repository\AgentMessageRepository;
use App\Service\Agent\AgentConfig;
use App\Service\Agent\AgentOrchestrator;
use App\Service\Agent\LlmClientInterface;
use App\Service\Agent\ToolInputValidator;
use App\Service\Agent\ToolRegistry;
use App\Service\Agent\TurnGuard;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Uid\Uuid;

/**
 * AGENT_TURN_DEADLINE_SEC bounds the WHOLE turn, checked before every model
 * call. A deadline hit returns the normal fallback answer and never leaves a
 * half-billed reservation.
 */
final class TurnDeadlineTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private string $suffix;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->suffix = strtolower(Uuid::v7()->toBase32());
    }

    public function testDeadlineStopsBeforeTheSecondModelCallAndBillsActualUsage(): void
    {
        $user = $this->createUser('deadline');
        $clock = new MockClock(new \DateTimeImmutable('2026-01-01 00:00:00', new \DateTimeZone('UTC')));
        $llm = new DeadlineLlm($clock, 46);

        $result = $this->orchestrator($llm, $clock, 45)->turn($user, null, 'hello');

        self::assertSame(1, $llm->calls, 'the second model call is never made after the deadline');
        self::assertSame("I couldn't complete that lookup. Please try again.", $result['answer']);
        self::assertSame($result['answer'], $result['assistantMessage']->getContent());
        $row = $this->usageRow($user);
        self::assertSame(20, (int) $row, 'settled with the completed call usage, not the whole reserve');
    }

    public function testDeadlineBeforeTheFirstCallRefundsEverything(): void
    {
        $user = $this->createUser('deadline-zero');
        $clock = new MockClock(new \DateTimeImmutable('2026-01-01 00:00:00', new \DateTimeZone('UTC')));
        $llm = new DeadlineLlm($clock, 0, failIfCalled: true);

        $result = $this->orchestrator($llm, $clock, 0)->turn($user, null, 'hello');

        self::assertSame(0, $llm->calls);
        self::assertSame("I couldn't complete that lookup. Please try again.", $result['answer']);
        self::assertSame(0, (int) $this->usageRow($user), 'no provider work means no charge');
    }

    private function orchestrator(LlmClientInterface $llm, MockClock $clock, int $deadlineSec): AgentOrchestrator
    {
        $container = static::getContainer();
        $live = $container->get(AgentConfig::class);
        $config = new AgentConfig(
            $live->enabled,
            $live->maxTokens,
            $live->maxToolRounds,
            $live->contextMessages,
            $live->rateLimitMessages,
            $live->rateLimitIntervalSec,
            $live->dailyTokenCap,
            $live->msgMaxChars,
            $live->model,
            $live->baseUrl,
            $live->connectTimeoutSec,
            $live->requestTimeoutSec,
            $deadlineSec,
        );

        return new AgentOrchestrator(
            $llm,
            $container->get(ToolRegistry::class),
            $container->get(AgentConversationRepository::class),
            $container->get(AgentMessageRepository::class),
            $this->em,
            new NullLogger(),
            $config,
            $container->get(TurnGuard::class),
            $container->get(ToolInputValidator::class),
            $clock,
        );
    }

    private function createUser(string $prefix): User
    {
        $user = new User();
        $user->setEmail(strtolower($prefix.'-'.$this->suffix.'@3awedlou.test'))->setName('Deadline Tester');
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user->setPassword($hasher->hashPassword($user, 'pass-123-321'));
        $this->em->persist($user);
        $this->em->flush();

        static::getContainer()->get(TokenStorageInterface::class)->setToken(
            new UsernamePasswordToken($user, 'main', $user->getRoles()),
        );

        return $user;
    }

    private function usageRow(User $user): int
    {
        return (int) $this->em->getConnection()->fetchOne(
            'SELECT tokens_used FROM agent_token_usage WHERE user_id = CAST(:u AS uuid)',
            ['u' => $user->getId()->toRfc4122()],
        );
    }
}

/**
 * First call returns a tool use and advances the clock past the deadline;
 * any later call means the deadline check failed.
 */
final class DeadlineLlm implements LlmClientInterface
{
    public int $calls = 0;

    public function __construct(
        private readonly MockClock $clock,
        private readonly int $advanceSeconds,
        private readonly bool $failIfCalled = false,
    ) {
    }

    public function complete(array $messages, array $tools): array
    {
        ++$this->calls;
        if ($this->failIfCalled && $this->calls > 1) {
            throw new \RuntimeException('the deadline did not stop the turn');
        }

        if (1 === $this->calls) {
            if ($this->advanceSeconds > 0) {
                $this->clock->sleep($this->advanceSeconds);
            }

            return [
                'role' => 'assistant',
                'content' => [['type' => 'tool_use', 'id' => 'deadline-tool', 'name' => 'list_my_machines', 'input' => []]],
                'usage' => ['input' => 10, 'output' => 10],
            ];
        }

        return [
            'role' => 'assistant',
            'content' => [['type' => 'text', 'text' => 'must not be reached']],
            'usage' => ['input' => 10, 'output' => 10],
        ];
    }
}
