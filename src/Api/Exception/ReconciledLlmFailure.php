<?php

declare(strict_types=1);

namespace App\Api\Exception;

/**
 * Internal marker: the provider call already reconciled the token reservation,
 * so the orchestrator must not bill the failed turn a second time.
 */
final class ReconciledLlmFailure extends AgentLlmUnavailableException
{
}
