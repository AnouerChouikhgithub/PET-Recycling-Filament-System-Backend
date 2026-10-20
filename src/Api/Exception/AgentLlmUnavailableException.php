<?php

declare(strict_types=1);

namespace App\Api\Exception;

/**
 * Thrown when the LLM provider cannot produce a usable turn (connection
 * failure, timeout, provider 4xx/5xx, malformed response, or missing API
 * credentials). Rendered as a stable 503 AGENT_LLM_UNAVAILABLE by the
 * exception subscriber (mapped in config packages services.yaml).
 *
 * The message MUST stay free of provider details - clients only ever see
 * the fixed wording; provider status/type goes to the agent log channel as
 * metadata, never into this exception's text. NOT final on purpose: the
 * orchestrator raises an internal Reconciled marker subclass after the
 * reservation is already settled so the reconcile step never runs twice.
 */
class AgentLlmUnavailableException extends \RuntimeException
{
    public function __construct(
        string $message = 'The assistant service is temporarily unavailable. Please try again shortly.',
        public readonly string $errorCode = 'AGENT_LLM_UNAVAILABLE',
    ) {
        parent::__construct($message);
    }
}
