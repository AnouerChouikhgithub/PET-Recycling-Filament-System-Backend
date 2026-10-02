<?php

declare(strict_types=1);

namespace App\Service\Realtime;

/**
 * Fan-out point of the realtime layer.
 *
 * Telemetry ingestion and machine commands dispatch domain events; a listener
 * forwards them here, and the active implementation delivers them to connected
 * clients (WebSocket, SSE, Mercure, push channel for mobile — the transport
 * is a deployment detail).
 *
 * Today the `logging` implementation records events (visible in dev logs) so
 * the whole pipeline is observable without running broker infrastructure.
 * Wiring a WebSocket hub later = implementing this interface + swapping the
 * alias in config/services.yaml. Clients never talk to this layer directly —
 * they always go through the API contract.
 */
interface RealtimeBroadcaster
{
    /**
     * @param array{type: string, topic: string, payload: array<string, mixed>, occurredAt: string} $event
     */
    public function broadcast(array $event): void;
}
