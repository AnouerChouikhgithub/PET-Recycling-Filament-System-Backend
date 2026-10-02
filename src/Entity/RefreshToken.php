<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\RefreshTokenRepository;
use Gesdinet\JWTRefreshTokenBundle\Model\AbstractRefreshToken;
use Doctrine\ORM\Mapping as ORM;

/**
 * Constraint/index names are EXPLICIT here so the mapping matches the
 * migration exactly (Doctrine's generated names differ from the migration's).
 * doctrine:schema:validate must exit 0 after migrating an empty database.
 */

/**
 * Refresh token for the JWT auth flow (rotation + revocation).
 *
 * Rotated on every use (`single_use: true`) and stored HASHED
 * (`hash_tokens: true`) — a database copy alone cannot refresh a session.
 * `POST /api/auth/logout` revokes it, so "sign out" is server-enforced.
 */
#[ORM\Entity(repositoryClass: RefreshTokenRepository::class)]
#[ORM\Table(name: 'refresh_token')]
#[ORM\UniqueConstraint(name: 'uniq_refresh_token', fields: ['refreshToken'])]
#[ORM\Index(columns: ['username'], name: 'idx_refresh_token_username')]
class RefreshToken extends AbstractRefreshToken
{
    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    protected int|string|null $id = null;

    #[ORM\Column(length: 128)]
    protected ?string $refreshToken = null;

    #[ORM\Column(length: 180)]
    protected ?string $username = null;

    #[ORM\Column(type: 'datetime')]
    protected ?\DateTimeInterface $valid = null;
}
