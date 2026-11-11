<?php

declare(strict_types=1);

namespace App\Api\Exception;

/**
 * A provider failure that provably never left this machine: the request was
 * refused BEFORE anything was sent (no credentials configured), so the
 * provider cannot have generated a single token.
 *
 * It is a plain AgentLlmUnavailableException to every client and to the
 * exception subscriber (same 503 AGENT_LLM_UNAVAILABLE, same wording) - the
 * subtype exists ONLY so the orchestrator can settle the turn honestly: a
 * turn that was never sent is refunded in full instead of being billed the
 * conservative MISSING_USAGE_ESTIMATE floor (BUGS-TO-FIX F4).
 *
 * Do NOT raise this for timeouts, connection resets, provider 4xx/5xx or
 * malformed responses: for those the provider may already have generated
 * tokens, so they keep the floor.
 */
final class AgentLlmNotAttemptedException extends AgentLlmUnavailableException
{
}
