<?php

declare(strict_types=1);

namespace App\Service\IoT;

use App\Api\Exception\MqttUnavailableException;
use PhpMqtt\Client\ConnectionSettings;
use PhpMqtt\Client\MqttClient;
use PhpMqtt\Client\Repositories\MemoryRepository;
use Psr\Log\LoggerInterface;

/**
 * Real MqttConnectionInterface implementation backed by php-mqtt/client.
 *
 * Only this class and PhpMqttSubscriber may reference PhpMqtt\Client — the
 * rest of the application sees MqttConnectionInterface and stays testable
 * with a fake.
 *
 * Honesty rules encoded here:
 *  - the connection is lazy (created on first publish) so MQTT_ENABLED=false
 *    can never open a socket by accident;
 *  - a QoS 1 publish is only considered delivered after the broker's PUBACK
 *    arrives — otherwise MqttUnavailableException is thrown, so callers never
 *    report success for bytes that merely left the process;
 *  - retain is whatever the caller passes and MachineCommandService always
 *    passes false: a retained command would replay on device reconnect;
 *  - after any failure the socket is dropped so the next attempt reconnects
 *    from a clean state instead of writing into a dead socket.
 */
final class PhpMqttConnection implements MqttConnectionInterface
{
    /**
     * How long to wait for the PUBACK of a QoS > 0 publish before declaring
     * the broker unavailable. Publishes are normally acknowledged within
     * milliseconds; this is only reached when the broker is degraded.
     */
    private const ACK_WAIT_SECONDS = 5;

    private const CONNECT_TIMEOUT_SECONDS = 5;
    private const SOCKET_TIMEOUT_SECONDS = 5;
    private const RESEND_TIMEOUT_SECONDS = 5;
    private const KEEP_ALIVE_SECONDS = 10;

    private ?MqttClient $client = null;
    private ?MemoryRepository $repository = null;

    public function __construct(
        private readonly MqttConfig $config,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function publish(string $topic, string $payload, int $qos, bool $retain): void
    {
        try {
            $client = $this->client();
            $client->publish($topic, $payload, $qos, $retain);

            if ($qos > MqttClient::QOS_AT_MOST_ONCE && null !== $this->repository) {
                // Drain the outgoing queue: with no subscriptions this loop
                // returns as soon as every pending message is acknowledged
                // (or after ACK_WAIT_SECONDS).
                $client->loop(true, true, self::ACK_WAIT_SECONDS);

                if ($this->repository->countPendingOutgoingMessages() > 0) {
                    throw new MqttUnavailableException(
                        sprintf('The MQTT broker did not acknowledge a QoS %d publish within %d seconds.', $qos, self::ACK_WAIT_SECONDS),
                    );
                }
            }

            $this->logger->debug('Published to MQTT broker.', [
                'topic' => $topic,
                'qos' => $qos,
                'retain' => $retain,
                'bytes' => strlen($payload),
            ]);
        } catch (MqttUnavailableException $exception) {
            $this->logger->warning('MQTT publish failed; connection dropped for reconnect.', [
                'topic' => $topic,
                'reason' => $exception->getMessage(),
            ]);
            $this->close();
            throw $exception;
        } catch (\Throwable $exception) {
            $this->logger->warning('MQTT publish failed; connection dropped for reconnect.', [
                'topic' => $topic,
                'reason' => $exception->getMessage(),
            ]);
            $this->close();
            throw new MqttUnavailableException('The MQTT broker could not be reached.', $exception);
        }
    }

    public function isConnected(): bool
    {
        return null !== $this->client && $this->client->isConnected();
    }

    public function close(): void
    {
        if (null === $this->client) {
            return;
        }

        try {
            if ($this->client->isConnected()) {
                $this->client->disconnect();
            }
        } catch (\Throwable) {
            // The socket is already gone — nothing to clean up.
        }

        $this->client = null;
        $this->repository = null;
    }

    /** @throws MqttUnavailableException */
    private function client(): MqttClient
    {
        if (null !== $this->client && $this->client->isConnected()) {
            return $this->client;
        }

        $this->close();

        $settings = (new ConnectionSettings())
            ->setConnectTimeout(self::CONNECT_TIMEOUT_SECONDS)
            ->setSocketTimeout(self::SOCKET_TIMEOUT_SECONDS)
            ->setResendTimeout(self::RESEND_TIMEOUT_SECONDS)
            ->setKeepAliveInterval(self::KEEP_ALIVE_SECONDS);

        if ('' !== $this->config->getUsername()) {
            $settings = $settings
                ->setUsername($this->config->getUsername())
                ->setPassword($this->config->getPassword());
        }

        if ($this->config->isTlsEnabled()) {
            $settings = $settings->setUseTls(true);
        }

        $repository = new MemoryRepository();
        $client = new MqttClient(
            $this->config->getHost(),
            $this->config->getPort(),
            $this->config->createConnectionClientId(),
            MqttClient::MQTT_3_1_1,
            $repository,
            $this->logger,
        );

        try {
            // Clean session: this is a short-lived publisher, nothing of ours
            // should be queued on the broker on our behalf.
            $client->connect($settings, true);
        } catch (\Throwable $exception) {
            throw new MqttUnavailableException('The MQTT broker could not be reached.', $exception);
        }

        $this->client = $client;
        $this->repository = $repository;

        return $client;
    }
}
