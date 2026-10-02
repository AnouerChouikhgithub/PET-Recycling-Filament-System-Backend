<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\MachineRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A physical 3awedlou recycling machine.
 *
 * The system is designed for MULTIPLE machines — never assume there is only one.
 */
#[ORM\Entity(repositoryClass: MachineRepository::class)]
#[ORM\Table(name: 'machine')]
#[ORM\Index(columns: ['status'], name: 'idx_machine_status')]
#[ORM\Index(columns: ['owner_id'], name: 'idx_machine_owner')]
#[ORM\HasLifecycleCallbacks]
class Machine
{
    #[ORM\Column(type: Types::STRING, enumType: MachineStatus::class)]
    private MachineStatus $status = MachineStatus::Offline;

    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\Column(length: 120)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 120)]
    private string $name;

    /**
     * Unique machine identifier (e.g. "3awedlou-001"). This is what the
     * ESP32 controller will use to identify itself in phase 2 (MQTT topic / commands).
     */
    #[ORM\Column(length: 64, unique: true)]
    #[Assert\NotBlank]
    #[Assert\Regex(pattern: '/^[a-z0-9][a-z0-9\-_]{1,63}$/i', message: 'Identifier may only contain letters, digits, dashes and underscores.')]
    private string $identifier;

    /**
     * Last time the machine was observed alive (HTTP ping today, MQTT in phase 2).
     */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastSeenAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'machines')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $owner = null;

    /**
     * Device API tokens (hashed at rest). False disables every token of this
     * machine at once — the kill switch for a lost/compromised device.
     */
    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => true, 'comment' => 'Kill switch: when false, every device token of this machine is refused'])]
    private bool $deviceTokensEnabled = true;

    /**
     * Reverse side of DeviceToken.machine. Tokens are created/revoked through
     * DeviceTokenRepository (never via this collection) — it exists so the
     * mapping is complete and admin tooling can list a machine's tokens.
     *
     * @var Collection<int, DeviceToken>
     */
    #[ORM\OneToMany(targetEntity: DeviceToken::class, mappedBy: 'machine', cascade: ['persist'], fetch: 'EXTRA_LAZY')]
    private Collection $deviceTokens;

    /**
     * @var Collection<int, MachineTelemetry>
     */
    #[ORM\OneToMany(targetEntity: MachineTelemetry::class, mappedBy: 'machine', orphanRemoval: true)]
    #[ORM\OrderBy(['recordedAt' => 'DESC'])]
    private Collection $telemetry;

    /**
     * @var Collection<int, MachineSession>
     */
    #[ORM\OneToMany(targetEntity: MachineSession::class, mappedBy: 'machine')]
    #[ORM\OrderBy(['startedAt' => 'DESC'])]
    private Collection $sessions;

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->telemetry = new ArrayCollection();
        $this->sessions = new ArrayCollection();
        $this->deviceTokens = new ArrayCollection();
    }

    public function __toString(): string
    {
        return $this->identifier;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = trim($name);

        return $this;
    }

    public function getIdentifier(): string
    {
        return $this->identifier;
    }

    public function setIdentifier(string $identifier): static
    {
        $this->identifier = strtolower(trim($identifier));

        return $this;
    }

    public function getStatus(): MachineStatus
    {
        return $this->status;
    }

    public function setStatus(MachineStatus $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getLastSeenAt(): ?\DateTimeImmutable
    {
        return $this->lastSeenAt;
    }

    public function markAsSeen(?\DateTimeImmutable $at = null): static
    {
        $this->lastSeenAt = $at ?? new \DateTimeImmutable();

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getOwner(): ?User
    {
        return $this->owner;
    }

    public function setOwner(?User $owner): static
    {
        $this->owner = $owner;

        return $this;
    }

    public function isDeviceTokensEnabled(): bool
    {
        return $this->deviceTokensEnabled;
    }

    public function setDeviceTokensEnabled(bool $enabled): static
    {
        $this->deviceTokensEnabled = $enabled;

        return $this;
    }

    /**
     * Device tokens of this machine (EXTRA_LAZY: only loaded on demand).
     *
     * @return Collection<int, DeviceToken>
     */
    public function getDeviceTokens(): Collection
    {
        return $this->deviceTokens;
    }

    /**
     * @return Collection<int, MachineTelemetry>
     */
    public function getTelemetry(): Collection
    {
        return $this->telemetry;
    }

    /**
     * @return Collection<int, MachineSession>
     */
    public function getSessions(): Collection
    {
        return $this->sessions;
    }

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        $now = new \DateTimeImmutable();
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
