<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\MachineTelemetryRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * One IoT telemetry sample reported by a machine (ESP32 → backend in phase 2;
 * ingested via API/ingest endpoint later).
 *
 * Deliberately KEPT EXTENSIBLE: the core scalar channels used by the 3awedlou
 * process (temperature, motor, fan, filament) are typed columns for fast
 * queries and indexing; anything machine-specific that arrives later can be
 * added either as additional nullable columns or inside `extra` (JSONB)
 * without breaking historical rows.
 */
#[ORM\Entity(repositoryClass: MachineTelemetryRepository::class)]
#[ORM\Table(name: 'machine_telemetry', options: ['comment' => 'IoT telemetry samples (phase 2 ingest)'])]
// The composite history index is created with `recorded_at DESC` by the
// migration (Doctrine cannot express sort order in attributes). DBAL compares
// indexes by name + columns only, so the plain attribute here — named exactly
// as the migration's index — keeps doctrine:schema:validate green. The DESC
// ordering exists only in migrations/…; NEVER replace migrations with
// doctrine:schema:update or the DESC ordering is lost.
#[ORM\Index(columns: ['machine_id', 'recorded_at'], name: 'idx_telemetry_machine_recorded_desc')]
#[ORM\Index(columns: ['recorded_at'], name: 'idx_telemetry_recorded')]
class MachineTelemetry
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Machine::class, inversedBy: 'telemetry')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Machine $machine;

    #[ORM\Column(type: Types::FLOAT, nullable: true, options: ['comment' => '°C'])]
    private ?float $temperature = null;

    #[ORM\Column(type: Types::FLOAT, nullable: true, options: ['comment' => '°C'])]
    private ?float $targetTemperature = null;

    #[ORM\Column(type: Types::BOOLEAN, nullable: true)]
    private ?bool $heaterState = null;

    #[ORM\Column(type: Types::BOOLEAN, nullable: true)]
    private ?bool $motorState = null;

    #[ORM\Column(type: Types::SMALLINT, nullable: true, options: ['comment' => 'RPM'])]
    private ?int $motorSpeed = null;

    #[ORM\Column(type: Types::BOOLEAN, nullable: true)]
    private ?bool $fanState = null;

    #[ORM\Column(type: Types::FLOAT, nullable: true, options: ['comment' => 'mm/s'])]
    private ?float $filamentSpeed = null;

    #[ORM\Column(type: Types::FLOAT, nullable: true, options: ['comment' => 'mm'])]
    private ?float $filamentDiameter = null;

    #[ORM\Column(type: Types::FLOAT, nullable: true, options: ['comment' => 'kWh (cumulative)'])]
    private ?float $energyConsumption = null;

    /**
     * Extensible escape hatch for future sensors (vibration, humidity, …)
     * that do not yet justify their own column.
     */
    /**
     * Extensible escape hatch for future sensors (vibration, humidity, …)
     * that do not yet justify their own column.
     *
     * @var array<string, mixed>|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $extra = null;

    /**
     * Time reported by the machine (falls back to ingestion time when the
     * firmware cannot keep clock). Always indexed — telemetry is time series data.
     */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $recordedAt;

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->recordedAt = new \DateTimeImmutable();
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

    public function getTemperature(): ?float
    {
        return $this->temperature;
    }

    public function setTemperature(?float $temperature): static
    {
        $this->temperature = $temperature;

        return $this;
    }

    public function getTargetTemperature(): ?float
    {
        return $this->targetTemperature;
    }

    public function setTargetTemperature(?float $targetTemperature): static
    {
        $this->targetTemperature = $targetTemperature;

        return $this;
    }

    public function getHeaterState(): ?bool
    {
        return $this->heaterState;
    }

    public function setHeaterState(?bool $heaterState): static
    {
        $this->heaterState = $heaterState;

        return $this;
    }

    public function getMotorState(): ?bool
    {
        return $this->motorState;
    }

    public function setMotorState(?bool $motorState): static
    {
        $this->motorState = $motorState;

        return $this;
    }

    public function getMotorSpeed(): ?int
    {
        return $this->motorSpeed;
    }

    public function setMotorSpeed(?int $motorSpeed): static
    {
        $this->motorSpeed = $motorSpeed;

        return $this;
    }

    public function getFanState(): ?bool
    {
        return $this->fanState;
    }

    public function setFanState(?bool $fanState): static
    {
        $this->fanState = $fanState;

        return $this;
    }

    public function getFilamentSpeed(): ?float
    {
        return $this->filamentSpeed;
    }

    public function setFilamentSpeed(?float $filamentSpeed): static
    {
        $this->filamentSpeed = $filamentSpeed;

        return $this;
    }

    public function getFilamentDiameter(): ?float
    {
        return $this->filamentDiameter;
    }

    public function setFilamentDiameter(?float $filamentDiameter): static
    {
        $this->filamentDiameter = $filamentDiameter;

        return $this;
    }

    public function getEnergyConsumption(): ?float
    {
        return $this->energyConsumption;
    }

    public function setEnergyConsumption(?float $energyConsumption): static
    {
        $this->energyConsumption = $energyConsumption;

        return $this;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getExtra(): ?array
    {
        return $this->extra;
    }

    /**
     * @param array<string, mixed>|null $extra
     */
    public function setExtra(?array $extra): static
    {
        $this->extra = $extra;

        return $this;
    }

    public function getRecordedAt(): \DateTimeImmutable
    {
        return $this->recordedAt;
    }

    public function setRecordedAt(\DateTimeImmutable $recordedAt): static
    {
        $this->recordedAt = $recordedAt;

        return $this;
    }
}
