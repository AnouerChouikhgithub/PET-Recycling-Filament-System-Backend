<?php

declare(strict_types=1);

namespace App\Dto\Request;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Body of POST /api/auth/register.
 */
final class RegisterRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Email]
        public readonly string $email = '',

        #[Assert\NotBlank]
        #[Assert\Length(min: 8, max: 128)]
        public readonly string $password = '',

        #[Assert\NotBlank]
        #[Assert\Length(max: 120)]
        public readonly string $name = '',
    ) {
    }
}
