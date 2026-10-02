<?php

declare(strict_types=1);

namespace App\Entity;

/**
 * Lifecycle of a machine session, mirroring the frontend `SessionStatus`
 * type (in_progress / paused / completed / failed).
 */
enum SessionStatus: string
{
    case InProgress = 'in_progress';
    case Paused = 'paused';
    case Completed = 'completed';
    case Failed = 'failed';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
