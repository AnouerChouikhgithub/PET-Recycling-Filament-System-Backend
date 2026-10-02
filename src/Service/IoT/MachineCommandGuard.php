<?php

declare(strict_types=1);

namespace App\Service\IoT;

use App\Api\Exception\ValidationException;
use App\Entity\Machine;
use App\Entity\MachineCommandType;
use App\Entity\MachineStatus;
use App\Service\Machine\MachineConnectivityService;

/**
 * Validates that a command is safe to send to a machine BEFORE anything is
 * published. Safety rules live here — in one auditable place — not in
 * controllers and not in firmware.
 *
 *   1. unreachable machines accept nothing (offline is derived from
 *      lastSeenAt via MachineConnectivityService, not the stored status);
 *   2. `start` only from idle/error;
 *   3. `resume` only from paused;
 *   4. `pause`/`stop` only while running;
 *   5. parameterized commands must carry a numeric value inside the safe
 *      range — the ranges are deployment config (env → services.yaml), not
 *      magic numbers. Defaults: heater ≤ 300 °C (firmware PET PID target is
 *      245 °C), motor/fan 0–100.
 */
final class MachineCommandGuard
{
    public function __construct(
        private readonly MachineConnectivityService $connectivity,
        /** Maximum safe heater target in °C (COMMAND_HEATER_MAX_C). */
        private readonly float $heaterMaxC = 300.0,
        /** Maximum motor speed setting, 0–100 scale (COMMAND_MOTOR_MAX). */
        private readonly float $motorMax = 100.0,
        /** Maximum fan setting, 0–100 scale (COMMAND_FAN_MAX). */
        private readonly float $fanMax = 100.0,
    ) {
    }

    /** @param array<string,mixed> $payload the full command payload (checked for unknown fields) */
    public function guard(Machine $machine, MachineCommandType $type, array $payload): mixed
    {
        if ($this->connectivity->isOffline($machine)) {
            throw new ValidationException('Command rejected.', [
                'command' => ['Machine is offline — commands are only accepted while it is reachable.'],
            ]);
        }

        // Unknown fields are rejected (payload hygiene) — `command` is the
        // envelope and `value`/`params` the documented optional members.
        $unknown = array_diff(array_keys($payload), ['command', 'value', 'params']);
        if ([] !== $unknown) {
            throw new ValidationException('Command rejected.', [
                'payload' => [sprintf('Unknown field(s): %s.', implode(', ', $unknown))],
            ]);
        }

        switch ($type) {
            case MachineCommandType::Start:
                if (!\in_array($machine->getStatus(), [MachineStatus::Idle, MachineStatus::Error], true)) {
                    throw $this->stateViolation($machine, $type);
                }
                break;

            case MachineCommandType::Resume:
                if (MachineStatus::Paused !== $machine->getStatus()) {
                    throw $this->stateViolation($machine, $type);
                }
                break;

            case MachineCommandType::Pause:
            case MachineCommandType::Stop:
                if (!\in_array($machine->getStatus(), [MachineStatus::Heating, MachineStatus::Extruding, MachineStatus::Paused], true)) {
                    throw $this->stateViolation($machine, $type);
                }
                break;

            case MachineCommandType::SetTargetTemperature:
            case MachineCommandType::SetMotorSpeed:
            case MachineCommandType::SetFan:
                $value = $payload['value'] ?? null;
                if (null === $value) {
                    throw new ValidationException('Command rejected.', [
                        'value' => [sprintf('%s requires a numeric value.', $type->value)],
                    ]);
                }
                if (!\is_int($value) && !\is_float($value)) {
                    throw new ValidationException('Command rejected.', [
                        'value' => [sprintf('%s requires a numeric value.', $type->value)],
                    ]);
                }
                $this->assertInRange($type, (float) $value);
                break;
        }

        return $payload['value'] ?? null;
    }

    private function assertInRange(MachineCommandType $type, float $value): void
    {
        $range = match ($type) {
            MachineCommandType::SetTargetTemperature => ['min' => 0.0, 'max' => $this->heaterMaxC, 'unit' => '°C'],
            MachineCommandType::SetMotorSpeed => ['min' => 0.0, 'max' => $this->motorMax, 'unit' => '%'],
            MachineCommandType::SetFan => ['min' => 0.0, 'max' => $this->fanMax, 'unit' => '%'],
            default => null,
        };

        if (null === $range) {
            throw new ValidationException('Command rejected.', [
                'command' => ['Command does not accept a value.'],
            ]);
        }

        if ($value < $range['min'] || $value > $range['max']) {
            throw new ValidationException('Command rejected.', [
                'value' => [sprintf('This value must be between %s and %s %s.', $range['min'], $range['max'], $range['unit'])],
            ]);
        }
    }

    private function stateViolation(Machine $machine, MachineCommandType $type): ValidationException
    {
        return new ValidationException('Command rejected.', [
            'command' => [
                sprintf('%s is not allowed while the machine is in "%s" state.', $type->value, $machine->getStatus()->value),
            ],
        ]);
    }
}
