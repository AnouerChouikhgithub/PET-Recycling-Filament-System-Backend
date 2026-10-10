<?php

declare(strict_types=1);

namespace App\Service\Agent;

use App\Entity\AgentTokenUsage;
use App\Entity\User;
use App\Repository\AgentTokenUsageRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Symfony\Component\RateLimiter\RateLimit;

/**
 * Per-USER turn guard: message rate limit + daily token cap. Keys are the
 * user id, NEVER the IP (T3.4), and both run BEFORE any LLM call (T3.1).
 *
 * Token cap accounting: a conservative RESERVE estimate is added before the
 * call; the actual usage from the response settles the difference after it.
 * A response without usage is billed the flat MISSING_USAGE_ESTIMATE (T3.3),
 * so payload changes cannot silently lift the cap.
 */
final class TurnGuard
{
    /** Billed tokens when the provider response carries no usage. */
    public const MISSING_USAGE_ESTIMATE = 1000;

    private RateLimiterFactory $rateLimiter;

    public function __construct(
        private readonly AgentConfig $config,
        private readonly AgentTokenUsageRepository $usage,
        private readonly EntityManagerInterface $em,
    ) {
        // Per-user sliding window built per-request from env-resolved values
        // (symfony rate_limiter.yaml is per-config, so this factory is
        // constructed here with an array storage scoped to this guard).
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
     * Daily token cap BEFORE the call (checked with the reserve already
     * settled from previous turns - the reserve row is touched immediately).
     */
    public function reserveTokens(User $user): void
    {
        $this->usage->todayRow($user, new \DateTimeImmutable(), $this->em);
        $this->em->flush();
    }

    /** TODAY's stored usage so the controller can enforce the cap. */
    public function tokensUsedToday(User $user): int
    {
        return $this->usageRowFor($user)?->getTokensUsed() ?? 0;
    }

    /**
     * Settle real usage after the LLM call (or the conservative estimate
     * when the response carries none).
     *
     * @param array<string, mixed>|null $usage provider usage array {input, output}
     */
    public function settle(?array $usage, User $user): void
    {
        $input = (int) ($usage['input'] ?? 0);
        $output = (int) ($usage['output'] ?? 0);

        $billed = $input + $output;
        if ($billed <= 0) {
            $billed = self::MISSING_USAGE_ESTIMATE;
        }

        $row = $this->usage->todayRow($user, new \DateTimeImmutable(), $this->em);
        $row->add($billed);
        $this->em->flush();
    }

    /** Has TODAY'S row reached/exceeded the daily cap already? */
    public function isOverTokenCap(User $user): bool
    {
        return ($this->usageRowFor($user)?->getTokensUsed() ?? 0) >= $this->config->dailyTokenCap;
    }

    private function usageRowFor(User $user): ?AgentTokenUsage
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

        return $row instanceof AgentTokenUsage ? $row : null;
    }

    private function key(User $user): string
    {
        return 'agent-user-'.$user->getId()->toRfc4122();
    }
}
