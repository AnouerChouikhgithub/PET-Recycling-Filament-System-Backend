<?php

declare(strict_types=1);

namespace App\Tests\IoT;

use App\Api\Exception\MqttUnavailableException;
use App\Service\IoT\MqttConfig;
use App\Service\IoT\PhpMqttConnection;
use App\Service\IoT\PhpMqttSubscriber;
use PHPUnit\Framework\TestCase;

/**
 * OPTIONAL round-trip test against a REAL broker.
 *
 * It is skipped unless MQTT_TEST_HOST is set, so `php bin/phpunit` never
 * opens a socket and never touches any database (this class does not boot
 * the kernel at all). Run it manually against the local Mosquitto:
 *
 *   $env:MQTT_TEST_HOST='127.0.0.1'
 *   $env:MQTT_TEST_USERNAME='backend'
 *   $env:MQTT_TEST_PASSWORD='…'
 *   php bin/phpunit --filter MqttBrokerIntegrationTest
 *
 * Never point it at production, and never at the dev database.
 */
final class MqttBrokerIntegrationTest extends TestCase
{
    private const TIMEOUT_SECONDS = 5.0;

    protected function setUp(): void
    {
        $host = getenv('MQTT_TEST_HOST');
        if (!\is_string($host) || '' === $host) {
            self::markTestSkipped('Set MQTT_TEST_HOST to run the real-broker integration test.');
        }
    }

    public function testPublishSubscribeRoundTrip(): void
    {
        $config = $this->config();

        $connection = new PhpMqttConnection($config, new \Psr\Log\NullLogger());
        $subscriber = new PhpMqttSubscriber($config, new \Psr\Log\NullLogger());

        $topic = sprintf('%s/.integration/%s/telemetry', $config->getPrefix(), bin2hex(random_bytes(4)));
        $payload = (string) json_encode(['v' => 1, 'probe' => bin2hex(random_bytes(8))]);

        /** @var list<array{topic: string, payload: string}> $received */
        $received = [];

        try {
            $subscriber->subscribe([$topic], 1, function (string $messageTopic, string $message, bool $retained) use (&$received): void {
                $received[] = ['topic' => $messageTopic, 'payload' => $message];
            });

            $connection->publish($topic, $payload, 1, false);

            $deadline = microtime(true) + self::TIMEOUT_SECONDS;
            while (microtime(true) < $deadline && [] === $received) {
                $subscriber->loopFor(0.1);
            }
        } finally {
            $connection->close();
            $subscriber->close();
        }

        self::assertCount(1, $received, 'the broker must round-trip a QoS 1 message');
        self::assertSame($topic, $received[0]['topic']);
        self::assertSame($payload, $received[0]['payload']);
    }

    public function testBrokerRejectsBadCredentials(): void
    {
        $config = new MqttConfig(
            enabled: true,
            host: (string) getenv('MQTT_TEST_HOST'),
            port: (int) ((string) (getenv('MQTT_TEST_PORT') ?: 1883)),
            username: 'definitely-not-a-user-'.bin2hex(random_bytes(4)),
            password: 'wrong-password',
            clientId: '3awedlou-integration',
            tls: false,
            prefix: '3awedlou',
        );

        $connection = new PhpMqttConnection($config, new \Psr\Log\NullLogger());

        $this->expectException(MqttUnavailableException::class);
        $connection->publish('3awedlou/never/reached', '{}', 0, false);
    }

    private function config(): MqttConfig
    {
        $host = (string) getenv('MQTT_TEST_HOST');

        return new MqttConfig(
            enabled: true,
            host: $host,
            port: (int) ((string) (getenv('MQTT_TEST_PORT') ?: 1883)),
            username: (string) (getenv('MQTT_TEST_USERNAME') ?: ''),
            password: (string) (getenv('MQTT_TEST_PASSWORD') ?: ''),
            clientId: '3awedlou-integration',
            tls: 'true' === (string) (getenv('MQTT_TEST_TLS') ?: 'false'),
            // The dev ACL grants `backend` readwrite on {prefix}/#, so the
            // integration probe must stay under the same prefix.
            prefix: '3awedlou',
        );
    }
}
