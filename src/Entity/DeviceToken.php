<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\DeviceTokenRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Per-machine device credential for IoT endpoints (telemetry ingest today,
 * the future MQTT consumer and the ESP32 later).
 *
 * SECURITY MODEL:
 *   - the plaintext token is shown ONCE at creation (`app:machine:issue-device-token`)
 *     and is NEVER stored — only its SHA-512 hash (hex, 128 chars);
 *   - devices send it on a DEDICATED header (X-Device-Token), never as a bearer JWT,
 *     so a regular user JWT can never ingest telemetry;
 *   - revocable per token (revokedAt) and per machine (machine.deviceTokensEnabled).
 *
 * This is the same credential the future MQTT consumer will authenticate with.
 */
#[ORM\Entity(repositoryClass: DeviceTokenRepository::class)]
#[ORM\Table(name: 'device_token')]
// token_hash equality lookup is served by the UNIQUE constraint's index.
#[ORM\UniqueConstraint(name: 'uniq_device_token_hash', fields: ['tokenHash'])]
#[ORM\Index(columns: ['machine_id'], name: 'idx_device_token_machine')]
class DeviceToken
{
    /** Dedicated header devices must send the plaintext token in. */
    public const HEADER = 'X-Device-Token';

    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Machine::class, inversedBy: 'deviceTokens')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Machine $machine;

    /** SHA-512 hex hash of the plaintext token. Lookup by equality only.
     *  Uniqueness is declared as a named constraint on the class (see above). */
    #[ORM\Column(length: 128)]
    private string $tokenHash;

    #[ORM\Column(length: 120)]
    private string $label = 'ESP32';

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastUsedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $revokedAt = null;

    public function __construct(Machine $machine, string $tokenHash, string $label = 'ESP32')
    {
        $this->id = Uuid::v7();
        $this->machine = $machine;
        $this->tokenHash = $tokenHash;
        $this->label = $label;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getMachine(): Machine
    {
        return $this->machine;
    }

    public function getTokenHash(): string
    {
        return $this->tokenHash;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getLastUsedAt(): ?\DateTimeImmutable
    {
        return $this->lastUsedAt;
    }

    public function markUsed(): void
    {
        $this->lastUsedAt = new \DateTimeImmutable();
    }

    public function getRevokedAt(): ?\DateTimeImmutable
    {
        return $this->revokedAt;
    }

    public function revoke(): void
    {
        $this->revokedAt ??= new \DateTimeImmutable();
    }

    public function isRevoked(): bool
    {
        return null !== $this->revokedAt;
    }
}
