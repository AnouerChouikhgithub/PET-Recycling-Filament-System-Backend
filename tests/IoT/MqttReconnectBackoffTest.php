<?php

declare(strict_types=1);

namespace App\Tests\IoT;

use App\Service\IoT\MqttReconnectBackoff;
use PHPUnit\Framework\TestCase;

final class MqttReconnectBackoffTest extends TestCase
{
    public function testDelayIsExponentialBoundedAndJittered(): void
    {
        $backoff = new MqttReconnectBackoff();
        $maximums = [1, 2, 4, 8, 16, 30, 30, 30];

        foreach ($maximums as $attempt => $maximum) {
            $upper = $backoff->delaySeconds($attempt, static fn (int $min, int $max): int => $max);
            self::assertSame((float) $maximum, $upper, 'ceiling must follow 1, 2, 4 … then stay at 30 s');
            $lower = $backoff->delaySeconds($attempt, static fn (int $min, int $max): int => $min);
            self::assertSame(0.0, $lower, 'full jitter may choose no wait');
        }
    }

    public function testRealJitterStaysWithinTheAttemptCeiling(): void
    {
        $backoff = new MqttReconnectBackoff();
        for ($i = 0; $i < 50; ++$i) {
            $delay = $backoff->delaySeconds(5);
            self::assertGreaterThanOrEqual(0.0, $delay);
            self::assertLessThanOrEqual(30.0, $delay);
        }
    }
}
