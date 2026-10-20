<?php

declare(strict_types=1);

namespace App\Service\Agent;

/**
 * Typed, NON-SECRET agent configuration (all from env; see .env).
 * Read-only value object - the orchestrator derives everything from it.
 *
 * The API key is intentionally NOT here: it is a secret and is injected
 * into AnthropicClient directly from the environment by the container.
 */
final class AgentConfig
{
    public const MIN_MESSAGE_CHARS = 1;

    public const DEFAULT_MODEL = 'claude-sonnet-4-5';

    public const DEFAULT_BASE_URL = 'https://api.anthropic.com';

    public const DEFAULT_CONNECT_TIMEOUT_SEC = 5;

    public const DEFAULT_REQUEST_TIMEOUT_SEC = 30;

    public function __construct(
        /** Feature flag: false = every /api/agent route answers 404 AGENT_DISABLED. */
        public readonly bool $enabled,
        public readonly int $maxTokens,
        public readonly int $maxToolRounds,
        public readonly int $contextMessages,
        public readonly int $rateLimitMessages,
        public readonly int $rateLimitIntervalSec,
        public readonly int $dailyTokenCap,
        public readonly int $msgMaxChars,
        /** Provider request fields (non-secret). Positional args below kept optional for existing tests. */
        public readonly string $model = self::DEFAULT_MODEL,
        public readonly string $baseUrl = self::DEFAULT_BASE_URL,
        public readonly int $connectTimeoutSec = self::DEFAULT_CONNECT_TIMEOUT_SEC,
        public readonly int $requestTimeoutSec = self::DEFAULT_REQUEST_TIMEOUT_SEC,
    ) {
    }

    /**
     * The CONSERVATIVE worst-case token spend of ONE whole turn (all tool
     * rounds included), reserved from the daily budget BEFORE any LLM call
     * so two concurrent turns cannot both spend the same remaining budget.
     *
     *   input side:  context messages x worst-case user message chars / ~4
     *   output side: (maxToolRounds + 1) calls x max_tokens each
     *
     * The estimate never depends on the actual content of the turn, so it
     * can be taken before anything is persisted (docs/ai-agent-test-plan.md
     * T3.2: nothing is saved on a cap-rejected turn).
     */
    public function reserveEstimateTokens(): int
    {
        $input = (int) ceil($this->contextMessages * $this->msgMaxChars / 4);

        return $input + $this->maxTokens * ($this->maxToolRounds + 1);
    }
}
