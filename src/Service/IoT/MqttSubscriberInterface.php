<?php

declare(strict_types=1);

namespace App\Service\IoT;

use App\Api\Exception\MqttUnavailableException;

/**
 * OUR thin seam over the MQTT client library for the inbound side
 * (the `app:mqtt:consume` worker).
 *
 * The console command only wires this interface to MqttMessageHandler — all
 * message logic lives in the handler and is unit-testable without a socket.
 */
interface MqttSubscriberInterface
{
    /**
     * Subscribe to every filter in $filters at the given QoS.
     *
     * @param list<string>                          $filters  full topic filters
     *                                                        ({prefix}/machines/+/telemetry, …)
     * @param callable(string, string, bool): void  $onMessage receives (topic, payload, retained)
     *
     * @throws MqttUnavailableException when the broker is unreachable
     */
    public function subscribe(array $filters, int $qos, callable $onMessage): void;

    /**
     * Run the receive loop for at most $seconds, dispatching $onMessage for
     * every inbound message. Returns earlier when interrupt() was called.
     *
     * @throws MqttUnavailableException when the connection to the broker is lost
     */
    public function loopFor(float $seconds): void;

    /** Ask a running loopFor() to return at the end of its current pass. */
    public function interrupt(): void;

    public function isConnected(): bool;

    /** Drop the connection so the next subscribe() reconnects cleanly. */
    public function close(): void;
}
