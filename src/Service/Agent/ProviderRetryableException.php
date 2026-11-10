<?php

declare(strict_types=1);

namespace App\Service\Agent;

/**
 * Internal carrier for conditions the policy allows ONE immediate retry for:
 * transport failures (no provider status) and provider 5xx responses.
 */
final class ProviderRetryableException extends \RuntimeException
{
    public function __construct(
        public readonly ?int $status,
    ) {
        parent::__construct('provider call retryable');
    }
}
