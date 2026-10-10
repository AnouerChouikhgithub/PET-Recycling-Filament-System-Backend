<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\AgentMessageRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * One turn inside an AgentConversation. Content is private user data:
 * logged nowhere else, and only ever read back for the owning conversation.
 */
#[ORM\Entity(repositoryClass: AgentMessageRepository::class)]
#[ORM\Table(name: 'agent_message')]
#[ORM\Index(columns: ['conversation_id', 'created_at'], name: 'idx_agent_msg_conv_created')]
class AgentMessage
{
    public const ROLE_USER = 'user';
    public const ROLE_ASSISTANT = 'assistant';

    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: AgentConversation::class, inversedBy: 'messages')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private AgentConversation $conversation;

    /** 'user' | 'assistant' (AgentMessage::ROLE_*). */
    #[ORM\Column(length: 16)]
    private string $role;

    #[ORM\Column(type: Types::TEXT)]
    private string $content;

    /**
     * Metadata-only record of the tool calls this turn made: name + a short
     * summary. NEVER raw tool payloads (untrusted data must not be persisted
     * next to chat content and replayed later).
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $toolCalls = null;

    #[ORM\Column(type: Types::INTEGER, options: ['default' => 0])]
    private int $usageInput = 0;

    #[ORM\Column(type: Types::INTEGER, options: ['default' => 0])]
    private int $usageOutput = 0;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(AgentConversation $conversation, string $role, string $content)
    {
        $this->id = Uuid::v7();
        $this->conversation = $conversation;
        $this->role = $role;
        $this->content = $content;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getConversation(): AgentConversation
    {
        return $this->conversation;
    }

    public function getRole(): string
    {
        return $this->role;
    }

    public function getContent(): string
    {
        return $this->content;
    }

    /** @param list<array{name: string, summary: string}> $toolCalls */
    public function setToolCalls(array $toolCalls): static
    {
        $this->toolCalls = [] === $toolCalls ? null : $toolCalls;

        return $this;
    }

    /** @return list<array{name: string, summary: string}>|null */
    public function getToolCalls(): ?array
    {
        return $this->toolCalls;
    }

    public function getUsageInput(): int
    {
        return $this->usageInput;
    }

    public function getUsageOutput(): int
    {
        return $this->usageOutput;
    }

    public function setUsage(int $input, int $output): static
    {
        $this->usageInput = max(0, $input);
        $this->usageOutput = max(0, $output);

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
