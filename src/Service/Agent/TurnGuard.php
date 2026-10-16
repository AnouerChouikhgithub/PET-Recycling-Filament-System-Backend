<?php

declare(strict_types=1);

namespace App\Service\Agent;

use App\Api\Exception\AgentDailyTokenCapException;
use App\Entity\User;
use App\Repository\AgentTokenUsageRepository;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

/**
 * Per-USER turn guard: message rate limit + daily token cap. Keys are the
 * user id, NEVER the IP (T3.4), and both run BEFORE any LLM call (T3.1).
 *
 * Token cap accounting (phase D, concurrency-safe):
 *
 *   1. reserveTurn() charges a CONSERVATIVE worst-case estimate for the
 *      whole turn (all tool rounds) atomically (single conditional UPDATE
 *      with RETURNING) BEFORE anything is persisted (T3.2) and before any
 *      LLM call. Two parallel calls can never both spend the same
 *      remaining budget - the second UPDATE loses the row-lock race and
 *      finds the budget insufficient.
 *   2. settleTurn() replaces the reservation with the provider-reported
 *      usage (delta may be negative). Missing/zero usage bills the
 *      MISSING_USAGE_ESTIMATE floor (T3.3), so payload changes cannot
 *      silently lift the cap.
 *   3. reconcileFailedTurn() runs when the provider/turn failed after the
 *      reservation: it bills max(actual-so-far floor, 0) + T3.3 floor and
 *      refunds the rest of the estimate, so failures cost at most the
 *      documented conservative floor, never zero - a schema change or a
 *      crash cannot silently lift the cap.
 *
 * Everything lives in the agent_token_usage table (durable across deploys):
 * no in-memory counter participates in cap enforcement.
 */
final class TurnGuard
{
    /** Billed when a turn consumed tokens but the usage is unknowable. */
    public const MISSING_USAGE_ESTIMATE = 1000;

    private RateLimiterFactory $rateLimiter;

    public function __construct(
        private readonly AgentConfig $config,
        private readonly AgentTokenUsageRepository $usage,
    ) {
        // Per-user sliding window built per-request from env-resolved values
        // (symfony rate_limiter.yaml is per-config, so this factory is
        // constructed here with array storage scoped to this guard - the
        // token cap is the durable limit; the message window is rate only).
        $this->rateLimiter = new RateLimiterFactory([
            'id' => 'agent_chat',
            'policy' => 'sliding_window',
            'limit' => max(1, $config->rateLimitMessages),
            // Symfony limiter intervals are RELATIVE date strings, e.g.
            // '+300 seconds' parses as +300 seconds.
            'interval' => '+'.$config->rateLimitIntervalSec.' seconds',
        ], new InMemoryStorage());
    }

    /**
     * Rate limit BEFORE the turn. Returns null when accepted, or the
     * retry-after seconds when the user is over the message budget.
     */
    public function reserveMessage(User $user): ?int
    {
        $limit = $this->rateLimiter->create($this->key($user))->consume();
        if ($limit->isAccepted()) {
            return null;
        }

        return max(1, $limit->getRetryAfter()->getTimestamp() - time());
    }

    /**
     * Reserve the daily-token estimate for ONE whole turn, ATOMICALLY,
     * before any LLM call and before any context is persisted.
     *
     * @throws AgentDailyTokenCapException when today's remaining budget is below the estimate
     */
    public function reserveTurn(User $user): void
    {
        $accepted = $this->usage->reserveBudget(
            $user,
            $this->config->reserveEstimateTokens(),
            $this->config->dailyTokenCap,
            new \DateTimeImmutable(),
        );

        if (!$accepted) {
            throw new AgentDailyTokenCapException();
        }
    }

    /**
     * Replace the turn's reservation with the real provider usage (or the
     * conservative floor when the usage is missing/zero - T3.3).
     *
     * @param array<string, mixed>|null $usage  provider-reported {input, output}
     * @param int                       $reserve the estimate reserveTurn() took
     */
    public function settleTurn(User $user, ?array $usage, int $reserve): void
    {
        $this->usage->settleBudget($user, $this->delta($usage, $reserve), new \DateTimeImmutable());
    }

    /**
     * The turn failed after the reservation (provider error, persistence
     * failure): bill the ACTUAL usage of the completed provider calls plus
     * ONE conservative floor charge for the call that failed (its usage is
     * unknowable - the provider may have generated tokens anyway), and refund
     * the unused remainder of the estimate.
     *
     * @param int $reserve the estimate reserveTurn() took
     * @param int $tokens  actual usage accumulated by COMPLETED provider calls
     */
    public function reconcileFailedTurn(User $user, int $reserve, int $tokens = 0): void
    {
        $billed = $tokens + self::MISSING_USAGE_ESTIMATE;

        $this->usage->settleBudget($user, $billed - $reserve, new \DateTimeImmutable());
    }

    /** TODAY's stored usage (tests, admin queries). */
    public function tokensUsedToday(User $user): int
    {
        return $this->usageRowFor($user)?->getTokensUsed() ?? 0;
    }

    private function usageRowFor(User $user): ?\App\Entity\AgentTokenUsage
    {
        $today = (new \DateTimeImmutable())->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d');

        $row = $this->usage->createQueryBuilder('u')
            ->where('u.user = :user')
            ->andWhere('u.day = :day')
            ->setParameter('user', $user)
            ->setParameter('day', $today)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $row instanceof \App\Entity\AgentTokenUsage ? $row : null;
    }

    /**
     * Change in today's row: REPORTED usage minus the estimate, or the
     * conservative floor when nothing was reported (missing usage counts,
     * so a schema change cannot silently lift the cap).
     *
     * @param array<string, mixed>|null $usage
     */
    private function delta(?array $usage, int $reserve): int
    {
        $input = (int) ($usage['input'] ?? 0);
        $output = (int) ($usage['output'] ?? 0);
        $billed = $input + $output;
        if ($billed <= 0) {
            $billed = self::MISSING_USAGE_ESTIMATE;
        }

        return $billed - $reserve;
    }

    private function key(User $user): string
    {
        return 'agent-user-'.$user->getId()->toRfc4122();
    }
}
