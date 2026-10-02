<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\MachineSessionRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A machine operating session (recycling run and/or filament production run).
 *
 * Both FilamentProduction and RecyclingSession point back to a session, so a
 * single physical run can carry production metrics, recycling metrics, or both.
 */
#[ORM\Entity(repositoryClass: MachineSessionRepository::class)]
#[ORM\Table(name: 'machine_session')]
#[ORM\Index(columns: ['machine_id', 'started_at'], name: 'idx_session_machine_started')]
#[ORM\Index(columns: ['status'], name: 'idx_session_status')]
#[ORM\HasLifecycleCallbacks]
class MachineSession
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Machine::class, inversedBy: 'sessions')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Machine $machine;

    /**
     * Nullable while the API is used without logins (e.g. machine-initiated runs);
     * set when a known user operates the machine.
     */
    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'sessions')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $operator = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $startedAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $endedAt = null;

    #[ORM\Column(type: Types::STRING, enumType: SessionStatus::class)]
    private SessionStatus $status = SessionStatus::InProgress;

    /**
     * Material fed INTO the machine during this session, in grams
     * (e.g. shredded PET). Matches the frontend `petInputG`.
     */
    #[ORM\Column(type: Types::FLOAT, nullable: true, options: ['comment' => 'grams'])]
    private ?float $materialInput = null;

    /**
     * Material produced by the session, in grams (e.g. filament wound on spool).
     * Null while the session is still running.
     */
    #[ORM\Column(type: Types::FLOAT, nullable: true, options: ['comment' => 'grams'])]
    private ?float $materialOutput = null;

    /**
     * Free-form operator notes ("Mixed clear bottles", "nozzle cleaned", …).
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $notes = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    /**
     * @var Collection<int, FilamentProduction>
     */
    #[ORM\OneToMany(targetEntity: FilamentProduction::class, mappedBy: 'session', cascade: ['persist', 'remove'])]
    private Collection $productions;

    /**
     * @var Collection<int, RecyclingSession>
     */
    #[ORM\OneToMany(targetEntity: RecyclingSession::class, mappedBy: 'session', cascade: ['persist', 'remove'])]
    private Collection $recyclingRecords;

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->startedAt = new \DateTimeImmutable();
        $this->productions = new ArrayCollection();
        $this->recyclingRecords = new ArrayCollection();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getMachine(): Machine
    {
        return $this->machine;
    }

    public function setMachine(Machine $machine): static
    {
        $this->machine = $machine;

        return $this;
    }

    public function getOperator(): ?User
    {
        return $this->operator;
    }

    public function setOperator(?User $operator): static
    {
        $this->operator = $operator;

        return $this;
    }

    public function getStartedAt(): \DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function setStartedAt(\DateTimeImmutable $startedAt): static
    {
        $this->startedAt = $startedAt;

        return $this;
    }

    public function getEndedAt(): ?\DateTimeImmutable
    {
        return $this->endedAt;
    }

    public function setEndedAt(?\DateTimeImmutable $endedAt): static
    {
        $this->endedAt = $endedAt;

        return $this;
    }

    public function getStatus(): SessionStatus
    {
        return $this->status;
    }

    public function setStatus(SessionStatus $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getMaterialInput(): ?float
    {
        return $this->materialInput;
    }

    public function setMaterialInput(?float $materialInput): static
    {
        $this->materialInput = $materialInput;

        return $this;
    }

    public function getMaterialOutput(): ?float
    {
        return $this->materialOutput;
    }

    public function setMaterialOutput(?float $materialOutput): static
    {
        $this->materialOutput = $materialOutput;

        return $this;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function setNotes(?string $notes): static
    {
        $this->notes = $notes;

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

    /** Duration in minutes, null while the session has not ended. */
    public function getDurationMinutes(): ?float
    {
        if (null === $this->endedAt) {
            return null;
        }

        return ($this->endedAt->getTimestamp() - $this->startedAt->getTimestamp()) / 60;
    }

    /**
     * @return Collection<int, FilamentProduction>
     */
    public function getProductions(): Collection
    {
        return $this->productions;
    }

    public function addProduction(FilamentProduction $production): static
    {
        if (!$this->productions->contains($production)) {
            $this->productions->add($production);
            $production->setSession($this);
        }

        return $this;
    }

    public function removeProduction(FilamentProduction $production): static
    {
        $this->productions->removeElement($production);

        return $this;
    }

    /**
     * @return Collection<int, RecyclingSession>
     */
    public function getRecyclingRecords(): Collection
    {
        return $this->recyclingRecords;
    }

    public function addRecyclingRecord(RecyclingSession $recyclingRecord): static
    {
        if (!$this->recyclingRecords->contains($recyclingRecord)) {
            $this->recyclingRecords->add($recyclingRecord);
            $recyclingRecord->setSession($this);
        }

        return $this;
    }

    public function removeRecyclingRecord(RecyclingSession $recyclingRecord): static
    {
        $this->recyclingRecords->removeElement($recyclingRecord);

        return $this;
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
