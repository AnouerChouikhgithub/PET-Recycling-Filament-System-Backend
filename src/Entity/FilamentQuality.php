<?php

declare(strict_types=1);

namespace App\Entity;

/**
 * Quality grade of a produced filament spool (frontend: `quality`).
 */
enum FilamentQuality: string
{
    case Excellent = 'excellent';
    case Good = 'good';
    case Fair = 'fair';
    case Poor = 'poor';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
