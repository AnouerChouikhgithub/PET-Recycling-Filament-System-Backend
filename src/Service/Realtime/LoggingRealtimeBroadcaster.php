<?php

declare(strict_types=1);

namespace App\Service\Realtime;

use Psr\Log\LoggerInterface;

/**
 * Default RealtimeBroadcaster: logs every realtime event. Swap to a WebSocket
 * or SSE hub by rebinding the `App\Service\Realtime\RealtimeBroadcaster`
 * alias — see docs/api-contract.md ("Realtime layer").
 */
final class LoggingRealtimeBroadcaster implements RealtimeBroadcaster
{
    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    public function broadcast(array $event): void
    {
        // The interface contract guarantees the shape — no defensive ?? needed.
        $this->logger->info('Realtime event.', [
            'type' => $event['type'],
            'topic' => $event['topic'],
        ]);
    }
}
