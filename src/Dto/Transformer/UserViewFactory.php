<?php

declare(strict_types=1);

namespace App\Dto\Transformer;

use App\Entity\User;

/**
 * Builds the public representation of a user (never exposes password hash).
 */
final class UserViewFactory
{
    /**
     * @return array<string, mixed>
     */
    public function create(User $user): array
    {
        return [
            'id' => $user->getId()->toRfc4122(),
            'email' => $user->getEmail(),
            'name' => $user->getName(),
            'roles' => $user->getRoles(),
            'createdAt' => $user->getCreatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }
}
