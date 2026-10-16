<?php

declare(strict_types=1);

namespace App\Tests\Agent;

use App\Api\Exception\AgentDailyTokenCapException;
use App\Entity\User;
use App\Repository\AgentTokenUsageRepository;
use App\Service\Agent\AgentConfig;
use App\Service\Agent\TurnGuard;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Concurrency-safe daily token accounting (session 2, phase D). The reserve
 * is ONE conditional UPDATE ... RETURNING: two parallel turns can never both
 * spend the same remaining budget - the second loses the row-lock race and
 * finds the budget insufficient. These tests prove the deterministic
 * interleaving against the real database; the SQL statement itself is the
 * multi-process guarantee (the code path contains no in-memory mutex and no
 * check-then-write sequence).
 */
final class TokenAccountingTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    private string $suffix;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->suffix = strtolower(Uuid::v7()->toBase32());
    }

    /** T3-parallel-style: two in-flight reservations, cap < 2 x estimate. */
    public function testReserveIsAtomicUnderBudgetContention(): void
    {
        $user = $this->createUser('tok1');
        $estimate = $this->estimateOf(200000);
        // Exactly ONE reservation fits under the cap (2 x estimate - 1).
        $cap = 2 * $estimate - 1;
        $guard = $this->guardWithCap($cap);

        $guard->reserveTurn($user); // turn 1 reserves the worst-case estimate
        self::assertSame($estimate, $this->tokensUsed($user), 'the reserve is durable BEFORE the LLM call, before anything is persisted');

        try {
            // turn 2 (parallel-style): only the leftover budget exists.
            $this->guardWithCap($cap)->reserveTurn($user);
            self::fail('the second reservation must lose the row-lock race');
        } catch (AgentDailyTokenCapException) {
            self::assertSame(
                $estimate,
                $this->tokensUsed($user),
                'nothing extra was charged by the losing second reservation - two parallel-style turns cannot both spend the same budget',
            );
        }

        // Settle turn 1 with real usage far below the estimate: the row ends
        // at exactly the REAL usage, so the refund is exact.
        $guard->settleTurn($user, ['input' => 30, 'output' => 20], $estimate);
        self::assertSame(50, $this->tokensUsed($user), 'settlement replaces the reservation with the actual usage');
    }

    /** T3.3: missing/zero provider usage bills the documented floor. */
    public function testMissingUsageBillsTheConservativeFloor(): void
    {
        $user = $this->createUser('tok2');
        $estimate = $this->estimateOf(200000);
        $guard = $this->guardWithCap(200000);

        $guard->reserveTurn($user);
        $guard->settleTurn($user, null, $estimate); // provider reported NOTHING

        self::assertSame(
            TurnGuard::MISSING_USAGE_ESTIMATE,
            $this->tokensUsed($user),
            'zero/missing usage is billed the 1000-token floor, never zero - a schema change cannot silently lift the cap',
        );
    }

    public function testFailedTurnReconcilesAtTheFloorAndRefundsTheRest(): void
    {
        $user = $this->createUser('tok3');
        $estimate = $this->estimateOf(200000);
        $guard = $this->guardWithCap(200000);

        $guard->reserveTurn($user);
        $guard->reconcileFailedTurn($user, $estimate, 0);

        self::assertSame(
            TurnGuard::MISSING_USAGE_ESTIMATE,
            $this->tokensUsed($user),
            'failed turn costs the conservative floor; the unused estimate remainder is refunded',
        );
    }

    public function testFailedTurnWithPartialRealUsageBillsPlusFloor(): void
    {
        $user = $this->createUser('tok4');
        $estimate = $this->estimateOf(200000);
        $guard = $this->guardWithCap(200000);

        $guard->reserveTurn($user);
        $guard->reconcileFailedTurn($user, $estimate, 400); // one completed provider call billed 400

        self::assertSame(1400, $this->tokensUsed($user), 'partial usage + floor, estimate remainder refunded');
    }

    /** UTC-day keying: rows per (user, day) are independent. */
    public function testUtcDayRowsAreIndependent(): void
    {
        $user = $this->createUser('tok5');
        $conn = $this->em->getConnection();
        $tomorrow = (new \DateTimeImmutable('+1 day'))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d');

        $conn->executeStatement(
            'INSERT INTO agent_token_usage (id, day, user_id, tokens_used, created_at, updated_at)
             VALUES (CAST(:id AS uuid), :day, CAST(:user_id AS uuid), 999999, now(), now())',
            ['id' => Uuid::v7()->toRfc4122(), 'day' => $tomorrow, 'user_id' => $user->getId()->toRfc4122()],
        );

        // A TIGHT cap still accepts TODAY's reserve even though "tomorrow"
        // is already far over any cap -> per-day keying works.
        $this->guardWithCap(200000)->reserveTurn($user);
        self::assertSame($this->estimateOf(200000), $this->tokensUsed($user));

        // Tomorrow's pre-existing row is untouched by today's activity.
        $untouched = $conn->fetchOne(
            'SELECT tokens_used FROM agent_token_usage WHERE user_id = CAST(:u AS uuid) AND day = :d',
            ['u' => $user->getId()->toRfc4122(), 'd' => $tomorrow],
        );
        self::assertSame(999999, (int) $untouched, 'no leak between UTC days');
    }

    /** T3.4: separate users never share the budget (keyed by USER id). */
    public function testSeparateUsersCapIndependently(): void
    {
        $a = $this->createUser('tok8a');
        $b = $this->createUser('tok8b');
        $estimate = $this->estimateOf(200000);

        $this->guardWithCap(2 * $estimate - 1)->reserveTurn($a);
        // B still has the full budget: A's reservation blocks nobody else.
        $this->guardWithCap(2 * $estimate - 1)->reserveTurn($b);

        self::assertSame($estimate, $this->tokensUsed($b), 'keys are the USER id, not IP or global');
    }

    /** Durable across deploys/restarts: NO in-memory counter is involved. */
    public function testUsageIsDurableAcrossRequests(): void
    {
        $user = $this->createUser('tok6');
        $estimate = $this->estimateOf(200000);

        $guard = $this->guardWithCap(200000);
        $guard->reserveTurn($user);
        $guard->settleTurn($user, ['input' => 500, 'output' => 300], $estimate);

        // A FRESH guard (new "request") must still see today's row.
        $fresh = $this->guardWithCap(200000);
        self::assertSame(800, $fresh->tokensUsedToday($user), 'accounting lives in the table, not memory');
    }

    // ------------------------------------------------------------ helpers

    private function estimateOf(int $dailyCap): int
    {
        return $this->config($dailyCap)->reserveEstimateTokens();
    }

    private function config(int $dailyCap): AgentConfig
    {
        $live = static::getContainer()->get(AgentConfig::class);

        return new AgentConfig(
            $live->enabled,
            $live->maxTokens,
            $live->maxToolRounds,
            $live->contextMessages,
            $live->rateLimitMessages,
            $live->rateLimitIntervalSec,
            $dailyCap,
            $live->msgMaxChars,
        );
    }

    private function guardWithCap(int $dailyCap): TurnGuard
    {
        return new TurnGuard(
            $this->config($dailyCap),
            static::getContainer()->get(AgentTokenUsageRepository::class),
        );
    }

    private function createUser(string $prefix): User
    {
        $user = new User();
        $user->setEmail(strtolower($prefix.'-'.$this->suffix.'@3awedlou.test'))->setName('T '.substr($prefix, 0, 8));
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user->setPassword($hasher->hashPassword($user, 'pass-123-321'));

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function tokensUsed(User $user): int
    {
        $row = $this->em->getConnection()->fetchOne(
            'SELECT tokens_used FROM agent_token_usage WHERE user_id = CAST(:u AS uuid) AND day = :d',
            [
                'u' => $user->getId()->toRfc4122(),
                'd' => (new \DateTimeImmutable())->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d'),
            ],
        );

        return (int) $row;
    }
}
