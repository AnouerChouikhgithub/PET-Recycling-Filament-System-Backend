<?php

declare(strict_types=1);

namespace App\Service\IoT;

/** Bounded exponential reconnect delay with full injectable jitter. */
final class MqttReconnectBackoff
{
    public const MAX_SECONDS = 30;

    /** @param callable(int, int): int|null $randomInt inclusive range, for tests */
    public function delaySeconds(int $attempt, ?callable $randomInt = null): float
    {
        $base = min(self::MAX_SECONDS, 2 ** min(30, max(0, $attempt)));
        $randomInt ??= static fn (int $min, int $max): int => random_int($min, $max);

        // Full jitter from zero up to the bounded exponential ceiling prevents
        // a fleet of consumers from reconnecting in lockstep.
        return (float) $randomInt(0, $base * 1000) / 1000;
    }
}
