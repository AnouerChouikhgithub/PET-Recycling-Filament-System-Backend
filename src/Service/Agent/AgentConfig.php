<?php

declare(strict_types=1);

namespace App\Service\Agent;

/**
 * Typed, non-secret agent configuration (all from env; see .env).
 * Read-only value object - the orchestrator derives everything from it.
 */
final class AgentConfig
{
    public const MIN_MESSAGE_CHARS = 1;

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
    ) {
    }
}
