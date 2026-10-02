<?php

declare(strict_types=1);

namespace App\Service\IoT;

use App\Api\Exception\ValidationException;
use App\Entity\Machine;
use App\Entity\MachineStatus;
use App\Entity\MachineTelemetry;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * The SINGLE telemetry ingestion pipeline for the whole platform.
 *
 * Every telemetry sample — whether it arrives over HTTP with a device token
 * (POST /api/machines/{id}/telemetry) or later over MQTT — flows through here:
 *
 *     MQTT message / HTTP POST
 *        → TelemetryProcessor::process()
 *        → validation
 *        → MachineTelemetry entity → PostgreSQL
 *        → machine status + lastSeenAt refreshed (race-safe UPDATE)
 *        → TelemetryProcessedEvent (future realtime layer)
 *
 * Concurrency: liveness is refreshed with a targeted UPDATE (not a
 * read-modify-write of the whole entity), so concurrent ingests from
 * several devices can never resurrect stale status data.
 */
final class TelemetryProcessor
{
    public const EVENT_NAME = 'app.machine_telemetry_processed';

    private const TEMPERATURE_MIN = -10.0;
    private const TEMPERATURE_MAX = 400.0;

    private const DIAMETER_MIN = 0.5;
    private const DIAMETER_MAX = 4.0;

    /** How far in the future a device-reported recordedAt may be (clock skew). */
    private const RECORDED_AT_FUTURE_TOLERANCE = 'PT5M';

    /** Lifecycle states a device may report for itself (never `offline`). */
    private const REPORTABLE_STATUSES = [
        MachineStatus::Idle,
        MachineStatus::Heating,
        MachineStatus::Extruding,
        MachineStatus::Paused,
        MachineStatus::Error,
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly EventDispatcherInterface $dispatcher,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Validate + persist one sample and refresh the machine's liveness state.
     *
     * @param array<string,mixed> $payload  raw fields (any subset of the
     *                                      MachineTelemetry channels + `extra` + `status`)
     * @param bool                $markSeen whether to bump lastSeenAt (true for
     *                                      real device reports; fixtures may pass false)
     *
     * @throws ValidationException when no channel is present or values are out of range
     */
    public function process(Machine $machine, array $payload, bool $markSeen = true): MachineTelemetry
    {
        $status = null;
        if (\array_key_exists('status', $payload)) {
            if (null !== $payload['status'] && !\is_string($payload['status'])) {
                throw $this->typeViolation('status', 'string');
            }
            $status = $payload['status'];
            unset($payload['status']);
        }

        $telemetry = $this->hydrate($machine, $payload);
        $violations = $this->validate($telemetry);
        if ([] !== $violations) {
            throw new ValidationException('Telemetry payload is invalid.', $violations);
        }

        $reported = null;
        if (null !== $status) {
            // Devices report their own lifecycle state; `offline` is derived by
            // the backend from lastSeenAt and is never accepted from a device.
            $reported = MachineStatus::tryFrom($status);
            if (null === $reported || !\in_array($reported, self::REPORTABLE_STATUSES, true)) {
                throw new ValidationException('Telemetry payload is invalid.', [
                    'status' => ['Accepted values: '.implode(', ', array_map(static fn (MachineStatus $s) => $s->value, self::REPORTABLE_STATUSES)).'.'],
                ]);
            }
        }

        $this->em->wrapInTransaction(function () use ($machine, $telemetry, $status, $markSeen): void {
            $this->em->persist($telemetry);
            $this->em->flush();

            if ($markSeen) {
                // Race-safe targeted UPDATE — never a read-modify-write of the
                // whole row, and status moves forward only when fresher.
                $conn = $this->em->getConnection();
                if (null !== $status) {
                    $conn->executeStatement(
                        'UPDATE machine SET last_seen_at = CASE WHEN last_seen_at IS NULL OR last_seen_at < :now THEN :now ELSE last_seen_at END,'
                        .' status = :status, updated_at = :now WHERE id = :id',
                        ['now' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'), 'status' => $status, 'id' => $machine->getId()->toRfc4122()],
                    );
                } else {
                    $conn->executeStatement(
                        'UPDATE machine SET last_seen_at = CASE WHEN last_seen_at IS NULL OR last_seen_at < :now THEN :now ELSE last_seen_at END,'
                        .' updated_at = :now WHERE id = :id',
                        ['now' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'), 'id' => $machine->getId()->toRfc4122()],
                    );
                }
            }
        });

        $view = $this->telemetryView($telemetry);
        $this->dispatcher->dispatch(new TelemetryProcessedEvent($machine, $telemetry, $view), self::EVENT_NAME);
        $this->logger->info('Telemetry ingested.', [
            'machine' => $machine->getIdentifier(),
            'telemetryId' => $telemetry->getId()->toRfc4122(),
        ]);

        return $telemetry;
    }

    /**
     * JSON view of a sample — identical to MachineViewFactory::telemetry() so
     * HTTP ingestion and the future MQTT path expose the same shape.
     *
     * @return array<string, mixed>
     */
    public function telemetryView(MachineTelemetry $t): array
    {
        return [
            'id' => $t->getId()->toRfc4122(),
            'machineId' => $t->getMachine()->getId()->toRfc4122(),
            'temperature' => $t->getTemperature(),
            'targetTemperature' => $t->getTargetTemperature(),
            'heaterState' => $t->getHeaterState(),
            'motorState' => $t->getMotorState(),
            'motorSpeed' => $t->getMotorSpeed(),
            'fanState' => $t->getFanState(),
            'filamentSpeed' => $t->getFilamentSpeed(),
            'filamentDiameter' => $t->getFilamentDiameter(),
            'energyConsumption' => $t->getEnergyConsumption(),
            'extra' => $t->getExtra(),
            'recordedAt' => $t->getRecordedAt()->format(\DateTimeInterface::ATOM),
        ];
    }

    /**
     * Map a raw payload onto a new MachineTelemetry entity. Unknown keys are
     * rejected (payload hygiene); `extra` accepts any JSON object.
     *
     * @param array<string,mixed> $payload
     */
    private function hydrate(Machine $machine, array $payload): MachineTelemetry
    {
        $known = [
            'temperature', 'targetTemperature', 'heaterState', 'motorState',
            'motorSpeed', 'fanState', 'filamentSpeed', 'filamentDiameter',
            'energyConsumption', 'recordedAt', 'extra',
        ];
        $unknown = array_diff(array_keys($payload), $known);
        if ([] !== $unknown) {
            throw new ValidationException('Telemetry payload is invalid.', [
                'payload' => [sprintf('Unknown field(s): %s.', implode(', ', $unknown))],
            ]);
        }

        $telemetry = new MachineTelemetry();
        $telemetry->setMachine($machine);

        $floats = ['temperature', 'targetTemperature', 'filamentSpeed', 'filamentDiameter', 'energyConsumption'];
        foreach ($floats as $floatField) {
            if (\array_key_exists($floatField, $payload) && null !== $payload[$floatField]) {
                if (!\is_int($payload[$floatField]) && !\is_float($payload[$floatField])) {
                    throw $this->typeViolation($floatField, 'number');
                }
                $telemetry->{'set'.ucfirst($floatField)}((float) $payload[$floatField]);
            }
        }

        foreach (['heaterState', 'motorState', 'fanState'] as $boolField) {
            if (\array_key_exists($boolField, $payload) && null !== $payload[$boolField]) {
                if (!\is_bool($payload[$boolField])) {
                    throw $this->typeViolation($boolField, 'boolean');
                }
                $telemetry->{'set'.ucfirst($boolField)}($payload[$boolField]);
            }
        }

        if (\array_key_exists('motorSpeed', $payload) && null !== $payload['motorSpeed']) {
            if (!\is_int($payload['motorSpeed'])) {
                throw $this->typeViolation('motorSpeed', 'integer');
            }
            $telemetry->setMotorSpeed($payload['motorSpeed']);
        }

        if (\array_key_exists('extra', $payload) && null !== $payload['extra']) {
            if (!\is_array($payload['extra'])) {
                throw $this->typeViolation('extra', 'object');
            }
            $telemetry->setExtra($payload['extra']);
        }

        if (\array_key_exists('recordedAt', $payload) && null !== $payload['recordedAt']) {
            if (!\is_string($payload['recordedAt'])) {
                throw $this->typeViolation('recordedAt', 'ISO-8601 string');
            }
            try {
                $recordedAt = new \DateTimeImmutable($payload['recordedAt']);
            } catch (\Exception) {
                throw $this->typeViolation('recordedAt', 'ISO-8601 string');
            }
            // Devices with drifting clocks must not poison the history with
            // samples from the far future (UTC comparisons; small skew OK).
            if ($recordedAt > (new \DateTimeImmutable('now'))->add(new \DateInterval(self::RECORDED_AT_FUTURE_TOLERANCE))) {
                throw new ValidationException('Telemetry payload is invalid.', [
                    'recordedAt' => ['This value must not be in the far future.'],
                ]);
            }
            $telemetry->setRecordedAt($recordedAt);
        }

        // `recordedAt` falls back to ingestion time (entity constructor default).

        return $telemetry;
    }

    /**
     * @return array<string, list<string>>
     */
    private function validate(MachineTelemetry $telemetry): array
    {
        $violations = [];

        $numeric = [
            'temperature' => [self::TEMPERATURE_MIN, self::TEMPERATURE_MAX],
            'targetTemperature' => [0.0, self::TEMPERATURE_MAX],
            'filamentSpeed' => [0.0, 50.0],
            'filamentDiameter' => [self::DIAMETER_MIN, self::DIAMETER_MAX],
            'energyConsumption' => [0.0, 1000000.0],
        ];
        foreach ($numeric as $field => [$min, $max]) {
            $value = $telemetry->{'get'.ucfirst($field)}();
            if (null !== $value && ($value < $min || $value > $max)) {
                $violations[$field][] = sprintf('This value must be between %s and %s.', $min, $max);
            }
        }

        $motorSpeed = $telemetry->getMotorSpeed();
        if (null !== $motorSpeed && ($motorSpeed < 0 || $motorSpeed > 10000)) {
            $violations['motorSpeed'][] = 'This value must be between 0 and 10000.';
        }

        // Reject completely empty samples — nothing to store, nothing to plot.
        $hasChannel = null !== $telemetry->getTemperature()
            || null !== $telemetry->getTargetTemperature()
            || null !== $telemetry->getHeaterState()
            || null !== $telemetry->getMotorState()
            || null !== $telemetry->getMotorSpeed()
            || null !== $telemetry->getFanState()
            || null !== $telemetry->getFilamentSpeed()
            || null !== $telemetry->getFilamentDiameter()
            || null !== $telemetry->getEnergyConsumption()
            || null !== $telemetry->getExtra();
        if (!$hasChannel) {
            $violations['payload'][] = 'At least one telemetry channel is required.';
        }

        return $violations;
    }

    private function typeViolation(string $field, string $expected): ValidationException
    {
        return new ValidationException('Telemetry payload is invalid.', [
            $field => [sprintf('This value must be of type %s.', $expected)],
        ]);
    }
}
