<?php

declare(strict_types=1);

namespace App\Service\Agent;

final class UtcDayReset
{
    public static function secondsUntilNextMidnightUtc(\DateTimeImmutable $now): int
    {
        $utcNow = $now->setTimezone(new \DateTimeZone('UTC'));
        $nextMidnight = $utcNow->setTime(0, 0)->modify('+1 day');

        return $nextMidnight->getTimestamp() - $utcNow->getTimestamp();
    }
}
