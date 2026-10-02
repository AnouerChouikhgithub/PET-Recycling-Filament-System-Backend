<?php

declare(strict_types=1);

namespace App\Api\Exception;

/**
 * Thrown by services when a resource does not exist (or is not visible to the
 * current user). Rendered as a consistent 404 JSON error by the exception
 * subscriber. The message MUST be safe to expose to API clients.
 */
final class ResourceNotFoundException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $errorCode = 'RESOURCE_NOT_FOUND',
    ) {
        parent::__construct($message);
    }
}
