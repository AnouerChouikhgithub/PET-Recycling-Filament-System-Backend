<?php

declare(strict_types=1);

namespace App\Api\Exception;

/**
 * The MQTT broker could not be reached, refused the connection, or did not
 * acknowledge a QoS 1 publish in time.
 *
 * Rendered by App\Api\EventSubscriber\ApiExceptionSubscriber through the
 * `app.api_exception_map` binding as HTTP 503 / code `MQTT_UNAVAILABLE`.
 *
 * HONESTY CONTRACT: this exception exists so the API can never claim a
 * command left the server when it did not. The command is validated and
 * audited first; if the publish then fails, the caller gets 503 and the
 * audit row records the failure — success is only reported when the broker
 * acknowledged the message.
 */
final class MqttUnavailableException extends \RuntimeException
{
    /**
     * @param \Throwable|null $previous the library/socket error — logged, never
     *                                  exposed to API clients
     */
    public function __construct(string $message, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
