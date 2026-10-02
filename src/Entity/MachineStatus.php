<?php

declare(strict_types=1);

namespace App\Entity;

/**
 * Lifecycle status of a 3awedlou machine.
 *
 * The frontends already use these values (idle / heating / extruding / paused /
 * error — see mobile-app data/types.ts); offline covers "not connected".
 */
enum MachineStatus: string
{
    case Idle = 'idle';
    case Heating = 'heating';
    case Extruding = 'extruding';
    case Paused = 'paused';
    case Error = 'error';
    case Offline = 'offline';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
