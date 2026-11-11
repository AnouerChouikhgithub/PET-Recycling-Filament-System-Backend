<?php

declare(strict_types=1);

namespace App\Api\Exception;

/**
 * Thrown when the per-user per-UTC-day token cap cannot accept a new
 * reservation. Rendered as 429 AGENT_DAILY_TOKEN_CAP by the exception
 * subscriber (mapped in config packages services.yaml; the controller adds
 * the Retry-After header when it can construct the response itself).
 */
final class AgentDailyTokenCapException extends \RuntimeException
{
    public function __construct(
        string $message = 'Daily AI usage limit reached. Resets at 00:00 UTC.',
        public readonly string $errorCode = 'AGENT_DAILY_TOKEN_CAP',
    ) {
        parent::__construct($message);
    }
}
