<?php

declare(strict_types=1);

namespace App\Entity;

/**
 * Commands the platform may send to a machine (backend → MQTT → ESP32).
 *
 * Deliberately conservative: start/pause/resume/stop map to the machine's
 * own session lifecycle, and the three `set*` commands are bound to safe
 * ranges by MachineCommandGuard. No raw/hardware-level commands exist.
 *
 * Safe ranges are CONFIG (services.yaml: app.command.* ← env), not magic
 * numbers. Defaults keep the heater cap at 300 °C — safely above the
 * firmware's real PET PID target (245 °C) but far below danger — and
 * motor/fan at 0–100.
 */
enum MachineCommandType: string
{
    case Start = 'start';
    case Pause = 'pause';
    case Resume = 'resume';
    case Stop = 'stop';
    case SetTargetTemperature = 'setTargetTemperature';
    case SetMotorSpeed = 'setMotorSpeed';
    case SetFan = 'setFan';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    /**
     * Commands that accept a numeric parameter.
     *
     * @return list<self>
     */
    public static function parameterized(): array
    {
        return [self::SetTargetTemperature, self::SetMotorSpeed, self::SetFan];
    }
}
