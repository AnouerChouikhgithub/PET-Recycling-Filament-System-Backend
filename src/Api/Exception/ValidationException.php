<?php

declare(strict_types=1);

namespace App\Api\Exception;

use Symfony\Component\Validator\ConstraintViolationListInterface;

/**
 * Input validation failure — rendered as a consistent 422 JSON error with
 * per-field details by the exception subscriber.
 */
final class ValidationException extends \RuntimeException
{
    /**
     * @param array<string, list<string>> $violations field => list of messages
     */
    public function __construct(
        string $message,
        public readonly array $violations = [],
    ) {
        parent::__construct($message);
    }

    /**
     * Convenience factory from a Symfony validator violation list.
     */
    public static function fromViolations(ConstraintViolationListInterface $violations): self
    {
        $grouped = [];
        foreach ($violations as $violation) {
            $field = $violation->getPropertyPath();
            $grouped[$field][] = $violation->getMessage();
        }

        return new self('The provided data is invalid.', $grouped);
    }
}
