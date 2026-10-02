<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\RecyclingSessionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Recycling metrics for material recovered during a MachineSession —
 * PET bottles/shreds in, recycled output out.
 *
 * Frontend equivalent: `RecyclingSession` (id, petInputG, filamentOutputG,
 * durationMin, status, operator).
 */
#[ORM\Entity(repositoryClass: RecyclingSessionRepository::class)]
#[ORM\Table(name: 'recycling_session')]
#[ORM\Index(columns: ['session_id'], name: 'idx_recycling_session')]
#[ORM\Index(columns: ['recycled_at'], name: 'idx_recycling_recycled_at')]
class RecyclingSession
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: MachineSession::class, inversedBy: 'recyclingRecords')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private MachineSession $session;

    #[ORM\ManyToOne(targetEntity: Machine::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Machine $machine;

    /**
     * Input material type (PET bottles, HDPE, …).
     */
    #[ORM\Column(length: 32)]
    private string $inputMaterial = 'PET';

    /**
     * Input mass in grams (frontend: `petInputG`).
     */
    #[ORM\Column(type: Types::FLOAT, nullable: true, options: ['comment' => 'grams'])]
    private ?float $inputMassGrams = null;

    /**
     * Recycled output material type (rPET filament/granulate/…).
     */
    #[ORM\Column(length: 32, nullable: true)]
    private ?string $outputMaterial = null;

    /**
     * Recycled output mass in grams (frontend: `filamentOutputG`).
     */
    #[ORM\Column(type: Types::FLOAT, nullable: true, options: ['comment' => 'grams'])]
    private ?float $outputMassGrams = null;

    /**
     * Recycling duration in minutes (null while running).
     */
    #[ORM\Column(type: Types::FLOAT, nullable: true)]
    private ?float $durationMinutes = null;

    /**
     * Average processing temperature, when measured.
     */
    #[ORM\Column(type: Types::FLOAT, nullable: true, options: ['comment' => '°C'])]
    private ?float $avgTemperature = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $notes = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $recycledAt;

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->recycledAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getSession(): MachineSession
    {
        return $this->session;
    }

    public function setSession(MachineSession $session): static
    {
        $this->session = $session;

        return $this;
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

    public function getInputMaterial(): string
    {
        return $this->inputMaterial;
    }

    public function setInputMaterial(string $inputMaterial): static
    {
        $this->inputMaterial = $inputMaterial;

        return $this;
    }

    public function getInputMassGrams(): ?float
    {
        return $this->inputMassGrams;
    }

    public function setInputMassGrams(?float $inputMassGrams): static
    {
        $this->inputMassGrams = $inputMassGrams;

        return $this;
    }

    public function getOutputMaterial(): ?string
    {
        return $this->outputMaterial;
    }

    public function setOutputMaterial(?string $outputMaterial): static
    {
        $this->outputMaterial = $outputMaterial;

        return $this;
    }

    public function getOutputMassGrams(): ?float
    {
        return $this->outputMassGrams;
    }

    public function setOutputMassGrams(?float $outputMassGrams): static
    {
        $this->outputMassGrams = $outputMassGrams;

        return $this;
    }

    public function getDurationMinutes(): ?float
    {
        return $this->durationMinutes;
    }

    public function setDurationMinutes(?float $durationMinutes): static
    {
        $this->durationMinutes = $durationMinutes;

        return $this;
    }

    public function getAvgTemperature(): ?float
    {
        return $this->avgTemperature;
    }

    public function setAvgTemperature(?float $avgTemperature): static
    {
        $this->avgTemperature = $avgTemperature;

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

    public function getRecycledAt(): \DateTimeImmutable
    {
        return $this->recycledAt;
    }

    public function setRecycledAt(\DateTimeImmutable $recycledAt): static
    {
        $this->recycledAt = $recycledAt;

        return $this;
    }
}
