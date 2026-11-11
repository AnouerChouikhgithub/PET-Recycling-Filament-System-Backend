<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\AgentTokenUsageRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Per-user, per-UTC-day LLM token accounting backing the daily cap. Not a
 * cache - it must survive deploys so a cap cannot be reset by a restart.
 */
#[ORM\Entity(repositoryClass: AgentTokenUsageRepository::class)]
#[ORM\Table(name: 'agent_token_usage')]
#[ORM\Index(columns: ['user_id', 'day'], name: 'idx_agent_usage_user_day')]
#[ORM\UniqueConstraint(name: 'uq_agent_usage_user_day', columns: ['user_id', 'day'])]
class AgentTokenUsage
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    /** UTC calendar day (Y-m-d) the usage belongs to. */
    #[ORM\Column(length: 10)]
    private string $day;

    #[ORM\Column(type: Types::INTEGER, options: ['default' => 0])]
    private int $tokensUsed = 0;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(User $user, string $day, int $initialTokens)
    {
        $this->id = Uuid::v7();
        $this->user = $user;
        $this->day = $day;
        $this->tokensUsed = max(0, $initialTokens);
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    /** Row identifier (exposed for admin tooling / future API). */
    public function getId(): Uuid
    {
        return $this->id;
    }

    /** The user this usage belongs to (used by retention/admin queries). */
    public function getUser(): User
    {
        return $this->user;
    }

    public function add(int $tokens): static
    {
        $this->tokensUsed += max(0, $tokens);
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function getTokensUsed(): int
    {
        return $this->tokensUsed;
    }

    public function getDay(): string
    {
        return $this->day;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
