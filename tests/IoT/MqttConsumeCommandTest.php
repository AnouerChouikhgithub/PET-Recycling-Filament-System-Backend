<?php

declare(strict_types=1);

namespace App\Tests\IoT;

use App\Api\Exception\MqttUnavailableException;
use App\Command\MqttConsumeCommand;
use App\Service\IoT\MqttConfig;
use App\Service\IoT\MqttMessageHandler;
use App\Service\IoT\MqttReconnectBackoff;
use App\Service\IoT\MqttSubscriberInterface;
use App\Service\IoT\MqttTopicBuilder;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Worker lifecycle rules that the broker integration test cannot prove:
 * bounded runs, portable stop, reconnect-until-deadline and never-connected
 * exits. The socket is always a fake — no broker, no sleeping beyond the
 * bounded backoff of one short run.
 */
final class MqttConsumeCommandTest extends KernelTestCase
{
    private FakeMqttSubscriber $subscriber;

    private MqttTopicBuilder $topics;

    private MqttMessageHandler $handler;

    private string $stopFile;

    protected function setUp(): void
    {
        static::bootKernel();

        $this->subscriber = new FakeMqttSubscriber();
        $this->topics = $this->service(MqttTopicBuilder::class);
        $this->handler = $this->service(MqttMessageHandler::class);
        $this->stopFile = sys_get_temp_dir().'/mqtt-consume-'.bin2hex(random_bytes(6)).'.stop';
    }

    protected function tearDown(): void
    {
        @unlink($this->stopFile);
        parent::tearDown();
    }

    public function testDisabledBrokerFailsBeforeTouchingTheSubscriber(): void
    {
        $tester = $this->tester(new MqttConfig(enabled: false));

        self::assertSame(Command::FAILURE, $tester->execute(['--max-runtime' => '0']));
        self::assertSame(0, $this->subscriber->loops, 'no loop may run without a broker');
        self::assertStringContainsString('MQTT_ENABLED is false', $tester->getDisplay());
    }

    public function testMessageLimitStopsTheWorkerCleanly(): void
    {
        for ($i = 0; $i < 5; ++$i) {
            // Unknown machine: the handler counts it and drops it, so the run
            // exercises the cap without writing telemetry rows.
            $this->subscriber->push('3awedlou/machines/absent-'.$i.'/telemetry', '{}');
        }

        $tester = $this->tester(new MqttConfig(enabled: true, host: '127.0.0.1'));

        self::assertSame(Command::SUCCESS, $tester->execute([
            '--max-runtime' => '0',
            '--max-messages' => '3',
            '--stop-file' => $this->stopFile,
        ]));
        self::assertStringContainsString('Message limit of 3 reached', $tester->getDisplay());
        self::assertSame(1, $this->subscriber->loops, 'the cap is checked between loop slices');
        self::assertSame(['received' => 5, 'dropped' => 5], $this->handler->counters());
    }

    public function testPortableStopFileEndsTheRunAndIsRemoved(): void
    {
        $stopFile = $this->stopFile;
        $subscriber = new class($stopFile) implements MqttSubscriberInterface {
            public int $loops = 0;

            public function __construct(private readonly string $stopFile)
            {
            }

            public function subscribe(array $filters, int $qos, callable $onMessage): void
            {
            }

            public function loopFor(float $seconds): void
            {
                ++$this->loops;
                // A supervisor (or the signal handler) asks the worker to stop.
                file_put_contents($this->stopFile, 'stop');
            }

            public function interrupt(): void
            {
            }

            public function isConnected(): bool
            {
                return true;
            }

            public function close(): void
            {
            }
        };

        $tester = $this->tester(new MqttConfig(enabled: true, host: '127.0.0.1'), $subscriber);

        self::assertSame(Command::SUCCESS, $tester->execute([
            '--max-runtime' => '0',
            '--stop-file' => $stopFile,
        ]));
        self::assertSame(1, $subscriber->loops, 'the stop flag is polled between loop slices');
        self::assertStringContainsString('Interrupted', $tester->getDisplay());
        self::assertFileDoesNotExist($stopFile, 'a consumed stop file must not stop the next run');
    }

    public function testUnreachableBrokerIsRetriedUntilDeadlineThenFails(): void
    {
        $this->subscriber->failure = new MqttUnavailableException('broker down');
        $tester = $this->tester(new MqttConfig(enabled: true, host: '127.0.0.1'));

        self::assertSame(Command::FAILURE, $tester->execute([
            '--max-runtime' => '1',
            '--stop-file' => $this->stopFile,
        ]));
        self::assertStringContainsString('Broker unavailable (mqtt_unavailable)', $tester->getDisplay());
        self::assertStringContainsString('Never reached the broker', $tester->getDisplay());
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $id
     *
     * @return T
     */
    private function service(string $id): object
    {
        $service = static::getContainer()->get($id);
        \assert($service instanceof $id);

        return $service;
    }

    private function tester(MqttConfig $config, ?MqttSubscriberInterface $subscriber = null): CommandTester
    {
        $command = new MqttConsumeCommand(
            $config,
            $this->topics,
            $subscriber ?? $this->subscriber,
            $this->handler,
            new MqttReconnectBackoff(),
            10000,
        );

        return new CommandTester($command);
    }
}
