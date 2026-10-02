<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\MachineCommandAuditRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Audit record of who sent which command to which machine, and when.
 *
 * One row per accepted command (202). Kept even though nothing reaches a
 * device yet: the acceptance + caller identity is the security-relevant fact.
 */
#[ORM\Entity(repositoryClass: MachineCommandAuditRepository::class)]
#[ORM\Table(name: 'machine_command_audit')]
#[ORM\Index(columns: ['machine_id', 'created_at'], name: 'idx_command_audit_machine_created')]
#[ORM\Index(columns: ['user_id'], name: 'idx_command_audit_user')]
class MachineCommandAudit
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Machine::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Machine $machine;

    /** Null for device-originated commands (none accepted today). */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $user;

    #[ORM\Column(length: 32, type: Types::STRING, enumType: MachineCommandType::class)]
    private MachineCommandType $command;

    #[ORM\Column(type: Types::FLOAT, nullable: true)]
    private ?float $value;

    /** Transport the command was handed to — `buffered-log` until a real broker exists. */
    #[ORM\Column(length: 32)]
    private string $transport = 'buffered-log';

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(Machine $machine, ?User $user, MachineCommandType $command, ?float $value)
    {
        $this->id = Uuid::v7();
        $this->machine = $machine;
        $this->user = $user;
        $this->command = $command;
        $this->value = $value;
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

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function getCommand(): MachineCommandType
    {
        return $this->command;
    }

    public function getValue(): ?float
    {
        return $this->value;
    }

    public function getTransport(): string
    {
        return $this->transport;
    }

    public function setTransport(string $transport): static
    {
        $this->transport = $transport;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
