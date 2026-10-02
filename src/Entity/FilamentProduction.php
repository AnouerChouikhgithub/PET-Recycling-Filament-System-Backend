<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\FilamentProductionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Filament production record — one spool (or partial spool) of filament
 * produced during a MachineSession.
 *
 * Frontend equivalent: `FilamentBatch` (id, sessionId, date, diameter,
 * weightG, lengthM, material, quality).
 */
#[ORM\Entity(repositoryClass: FilamentProductionRepository::class)]
#[ORM\Table(name: 'filament_production')]
#[ORM\Index(columns: ['session_id'], name: 'idx_production_session')]
#[ORM\Index(columns: ['produced_at'], name: 'idx_production_produced_at')]
class FilamentProduction
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: MachineSession::class, inversedBy: 'productions')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private MachineSession $session;

    /**
     * Human-friendly batch code (e.g. "F-082"), unique when present.
     * Generated later for real production data — optional during prototyping.
     */
    #[ORM\Column(length: 32, unique: true, nullable: true)]
    #[Assert\Length(max: 32)]
    private ?string $batchCode = null;

    /**
     * Target filament diameter in mm (1.75 or 2.85).
     */
    #[ORM\Column(type: Types::FLOAT)]
    #[Assert\Positive]
    private float $diameterTarget = 1.75;

    /**
     * Measured average diameter in mm (null until measured).
     */
    #[ORM\Column(type: Types::FLOAT, nullable: true)]
    private ?float $diameterActual = null;

    /**
     * @var list<float>|null measured deviations from target in mm (QA log)
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $diameterSamples = null;

    #[ORM\Column(type: Types::FLOAT, nullable: true, options: ['comment' => 'grams'])]
    private ?float $weightGrams = null;

    #[ORM\Column(type: Types::FLOAT, nullable: true, options: ['comment' => 'meters'])]
    private ?float $lengthMeters = null;

    /**
     * Production duration in minutes (null while still producing).
     */
    #[ORM\Column(type: Types::FLOAT, nullable: true)]
    private ?float $durationMinutes = null;

    /**
     * rPET / PETG / PLA blend … (frontend: `material`).
     */
    #[ORM\Column(length: 32)]
    private string $material = 'rPET';

    /**
     * Cosmetic color name + hex captured at production time.
     */
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $colorName = null;

    #[ORM\Column(length: 7, nullable: true)]
    #[Assert\Regex(pattern: '/^#[0-9a-fA-F]{6}$/', message: 'Color must be a #RRGGBB hex value.')]
    private ?string $colorHex = null;

    #[ORM\Column(type: Types::STRING, enumType: FilamentQuality::class)]
    private FilamentQuality $quality = FilamentQuality::Good;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $notes = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $producedAt;

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->producedAt = new \DateTimeImmutable();
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

    public function getBatchCode(): ?string
    {
        return $this->batchCode;
    }

    public function setBatchCode(?string $batchCode): static
    {
        $this->batchCode = $batchCode;

        return $this;
    }

    public function getDiameterTarget(): float
    {
        return $this->diameterTarget;
    }

    public function setDiameterTarget(float $diameterTarget): static
    {
        $this->diameterTarget = $diameterTarget;

        return $this;
    }

    public function getDiameterActual(): ?float
    {
        return $this->diameterActual;
    }

    public function setDiameterActual(?float $diameterActual): static
    {
        $this->diameterActual = $diameterActual;

        return $this;
    }

    /**
     * @return list<float>|null
     */
    public function getDiameterSamples(): ?array
    {
        return $this->diameterSamples;
    }

    /**
     * @param list<float>|null $diameterSamples
     */
    public function setDiameterSamples(?array $diameterSamples): static
    {
        $this->diameterSamples = $diameterSamples;

        return $this;
    }

    public function getWeightGrams(): ?float
    {
        return $this->weightGrams;
    }

    public function setWeightGrams(?float $weightGrams): static
    {
        $this->weightGrams = $weightGrams;

        return $this;
    }

    public function getLengthMeters(): ?float
    {
        return $this->lengthMeters;
    }

    public function setLengthMeters(?float $lengthMeters): static
    {
        $this->lengthMeters = $lengthMeters;

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

    public function getMaterial(): string
    {
        return $this->material;
    }

    public function setMaterial(string $material): static
    {
        $this->material = $material;

        return $this;
    }

    public function getColorName(): ?string
    {
        return $this->colorName;
    }

    public function setColorName(?string $colorName): static
    {
        $this->colorName = $colorName;

        return $this;
    }

    public function getColorHex(): ?string
    {
        return $this->colorHex;
    }

    public function setColorHex(?string $colorHex): static
    {
        $this->colorHex = $colorHex;

        return $this;
    }

    public function getQuality(): FilamentQuality
    {
        return $this->quality;
    }

    public function setQuality(FilamentQuality $quality): static
    {
        $this->quality = $quality;

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

    public function getProducedAt(): \DateTimeImmutable
    {
        return $this->producedAt;
    }

    public function setProducedAt(\DateTimeImmutable $producedAt): static
    {
        $this->producedAt = $producedAt;

        return $this;
    }
}
