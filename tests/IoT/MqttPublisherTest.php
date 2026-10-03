<?php

declare(strict_types=1);

namespace App\Tests\IoT;

use App\Api\Exception\MqttUnavailableException;
use App\Service\IoT\BufferingMqttPublisher;
use App\Service\IoT\BrokerMqttPublisher;
use App\Service\IoT\MqttConfig;
use App\Service\IoT\SelectedMqttPublisher;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Pure unit tests for the outbound transport — no kernel, no database, no
 * broker: everything goes through the FakeMqttConnection double.
 */
final class MqttPublisherTest extends TestCase
{
    public function testBrokerPublisherUsesTheRequestedQosAndNeverRetains(): void
    {
        $connection = new FakeMqttConnection();
        $publisher = new BrokerMqttPublisher($connection);

        $publisher->publish('3awedlou/machines/3awedlou-001/commands', '{"v":1}', 1);

        self::assertCount(1, $connection->published, 'exactly one message reaches the connection');
        $record = $connection->published[0];
        self::assertSame('3awedlou/machines/3awedlou-001/commands', $record['topic']);
        self::assertSame(1, $record['qos'], 'MQTT_QOS default of 1 must be honoured');
        self::assertFalse(
            $record['retain'],
            'Commands must never be retained: a retained "start heater" would replay on every device reconnect.',
        );
    }

    public function testBrokerPublisherPropagatesTheBrokerFailureInsteadOfPretending(): void
    {
        $connection = new FakeMqttConnection();
        $connection->failure = new MqttUnavailableException('The MQTT broker could not be reached.');
        $publisher = new BrokerMqttPublisher($connection);

        $this->expectException(MqttUnavailableException::class);

        $publisher->publish('3awedlou/machines/x/commands', '{}', 1);
    }

    public function testDisabledConfigFallsBackToTheBufferingPublisher(): void
    {
        $buffering = new BufferingMqttPublisher(new NullLogger());
        $connection = new FakeMqttConnection();
        $selected = new SelectedMqttPublisher(new MqttConfig(enabled: false), $buffering, new BrokerMqttPublisher($connection));

        $selected->publish('3awedlou/machines/x/commands', '{"v":1}', 1);

        self::assertSame([], $connection->published, 'no broker is contacted when MQTT_ENABLED=false');
        self::assertCount(1, $buffering->published(), 'the message stays in the in-memory buffer');
        self::assertSame('3awedlou/machines/x/commands', $buffering->published()[0]['topic']);
        self::assertSame(1, $buffering->published()[0]['qos']);
    }

    public function testEnabledConfigRoutesToTheBroker(): void
    {
        $buffering = new BufferingMqttPublisher(new NullLogger());
        $connection = new FakeMqttConnection();
        $selected = new SelectedMqttPublisher(new MqttConfig(enabled: true), $buffering, new BrokerMqttPublisher($connection));

        $selected->publish('3awedlou/machines/x/commands', '{"v":1}', 1);

        self::assertCount(1, $connection->published, 'the real broker client is used when MQTT_ENABLED=true');
        self::assertSame([], $buffering->published(), 'nothing is buffered once a broker is configured');
    }

    public function testMqttConfigNeverExposesItsBrokerPasswordWhenDebugged(): void
    {
        $config = new MqttConfig(password: 'super-secret-broker-password');

        self::assertSame('super-secret-broker-password', $config->getPassword());

        $dump = print_r($config, true);
        self::assertStringNotContainsString(
            'super-secret-broker-password',
            $dump,
            'A var_dump/print_r of the config must never leak the broker password.',
        );

        self::assertSame('[redacted]', $config->__debugInfo()['password']);
    }

    public function testEachConnectionGetsItsOwnClientIdSuffix(): void
    {
        // MQTT allows only one live connection per client id: without a
        // suffix the HTTP publisher and the consumer would kick each other
        // off the broker.
        $config = new MqttConfig(clientId: '3awedlou-backend');

        self::assertStringStartsWith('3awedlou-backend-', $config->createConnectionClientId());
        self::assertNotSame($config->createConnectionClientId(), $config->createConnectionClientId());
    }
}
