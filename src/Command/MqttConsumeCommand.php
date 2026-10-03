<?php

declare(strict_types=1);

namespace App\Command;

use App\Api\Exception\MqttUnavailableException;
use App\Service\IoT\MqttConfig;
use App\Service\IoT\MqttMessageHandler;
use App\Service\IoT\MqttSubscriberInterface;
use App\Service\IoT\MqttTopicBuilder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Long-running MQTT consumer.
 *
 *     php bin/console app:mqtt:consume
 *     php bin/console app:mqtt:consume --max-runtime=3600 --memory-limit=256M
 *
 * This command only wires the socket to MqttMessageHandler — every message
 * rule lives in the handler so it is unit-testable without a broker.
 *
 * Designed to run under a supervisor (systemd, NSSM, Windows Task Scheduler):
 *   * --max-runtime bounds each run so a worker is recycled regularly and a
 *     supervisor restart is always safe;
 *   * SIGINT/SIGTERM stop it gracefully when ext-pcntl exists — it does NOT
 *     on Windows, which is exactly why --max-runtime is the primary bound;
 *   * the broker connection is retried with exponential backoff;
 *   * any fatal error, memory pressure, or a run in which the broker was
 *     never reachable exits NON-ZERO so the supervisor restarts it.
 */
#[AsCommand(
    name: 'app:mqtt:consume',
    description: 'Consume machine telemetry/status/events from MQTT and feed the shared TelemetryProcessor.',
)]
final class MqttConsumeCommand extends Command
{
    private const MAX_BACKOFF_SECONDS = 30;

    private const LOOP_SLICE_SECONDS = 1.0;

    /** Exit at 90% of --memory-limit, while the process can still report it. */
    private const MEMORY_HIGH_WATERMARK = 0.9;

    private bool $stopRequested = false;

    public function __construct(
        private readonly MqttConfig $config,
        private readonly MqttTopicBuilder $topics,
        private readonly MqttSubscriberInterface $subscriber,
        private readonly MqttMessageHandler $handler,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('max-runtime', null, InputOption::VALUE_REQUIRED, 'Exit cleanly after this many seconds (0 = run until interrupted).', '3600')
            ->addOption('memory-limit', null, InputOption::VALUE_REQUIRED, 'memory_limit for this worker, e.g. 256M. At 90% it exits non-zero so a supervisor restarts it clean.', null);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (!$this->config->isEnabled()) {
            $io->error([
                'MQTT_ENABLED is false — there is no broker configured, so the consumer would only spin.',
                'Set MQTT_ENABLED=true (plus MQTT_HOST/USERNAME/PASSWORD) in .env.local and restart.',
            ]);

            return Command::FAILURE;
        }

        $maxRuntime = max(0, (int) $input->getOption('max-runtime'));
        $memoryLimitRaw = $input->getOption('memory-limit');
        $memoryLimitBytes = null;

        if (\is_string($memoryLimitRaw) && '' !== $memoryLimitRaw) {
            $memoryLimitBytes = $this->toBytes($memoryLimitRaw);
            if ($memoryLimitBytes <= 0) {
                $io->error(sprintf('Invalid --memory-limit "%s" (use a PHP size such as 256M, 1G, or -1 for unlimited).', $memoryLimitRaw));

                return Command::FAILURE;
            }
            // Bounding the process here would make PHP fatal before we can
            // report anything, so the option is applied to the ini and the
            // graceful exit happens at the 90% watermark below.
            ini_set('memory_limit', $memoryLimitRaw);
        }

        $deadline = $maxRuntime > 0 ? microtime(true) + $maxRuntime : null;
        $qos = $this->config->getQos();
        $filters = $this->topics->inboundFilters();

        $onMessage = function (string $topic, string $payload, bool $retained): void {
            $this->handler->handle($topic, $payload);
        };

        $this->installSignalHandlers();

        $io->section('MQTT consumer');
        $io->listing($filters);
        $io->text(sprintf(
            'QoS %d · max payload %d B · rate guard %d msg/s per machine · max runtime %s · memory limit %s',
            $qos,
            $this->config->getMaxPayloadBytes(),
            $this->config->getTelemetryMaxPerSecond(),
            $maxRuntime > 0 ? $maxRuntime.'s' : 'unbounded',
            $memoryLimitBytes !== null ? $memoryLimitRaw : (string) ini_get('memory_limit'),
        ));

        $connected = false;
        $everConnected = false;
        $backoff = 1;

        while (!$this->stopRequested && (null === $deadline || microtime(true) < $deadline)) {
            if (null !== $memoryLimitBytes && memory_get_usage(true) >= (int) ($memoryLimitBytes * self::MEMORY_HIGH_WATERMARK)) {
                $io->error(sprintf(
                    'Memory high watermark reached (%s of %s) — exiting non-zero so a supervisor restarts a clean worker.',
                    $this->formatBytes(memory_get_usage(true)),
                    $memoryLimitRaw,
                ));
                $this->subscriber->close();

                return Command::FAILURE;
            }

            try {
                if (!$connected) {
                    $this->subscriber->subscribe($filters, $qos, $onMessage);
                    $connected = true;
                    $everConnected = true;
                    $backoff = 1;
                    $io->success('Connected and subscribed.');
                }

                $slice = null === $deadline
                    ? self::LOOP_SLICE_SECONDS
                    : min(self::LOOP_SLICE_SECONDS, max(0.05, $deadline - microtime(true)));

                $this->subscriber->loopFor($slice);
            } catch (MqttUnavailableException $exception) {
                // Recoverable: drop the socket, back off, reconnect.
                $connected = false;
                $this->subscriber->close();

                $wait = $backoff;
                if (null !== $deadline) {
                    $wait = (int) min((float) $backoff, max(0.0, $deadline - microtime(true)));
                }

                $io->warning(sprintf('Broker unavailable: %s — retrying in %ds.', $exception->getMessage(), $wait));
                if ($wait > 0) {
                    sleep($wait);
                }

                $backoff = min($backoff * 2, self::MAX_BACKOFF_SECONDS);
            } catch (\Throwable $exception) {
                $io->error(sprintf('Fatal error in the MQTT consumer: %s (%s)', $exception->getMessage(), $exception::class));
                $this->subscriber->close();

                return Command::FAILURE;
            }
        }

        $this->subscriber->close();

        $counters = $this->handler->counters();
        ksort($counters);
        if ([] !== $counters) {
            $io->text('Messages: '.implode(', ', array_map(
                static fn (string $key, int $value): string => sprintf('%s=%d', $key, $value),
                array_keys($counters),
                $counters,
            )).'.');
        }

        if (!$everConnected) {
            $io->error(sprintf(
                'Never reached the broker at %s:%d during this run — exiting non-zero so a supervisor retries.',
                $this->config->getHost(),
                $this->config->getPort(),
            ));

            return Command::FAILURE;
        }

        $io->success($this->stopRequested
            ? 'Interrupted — consumer stopped cleanly.'
            : sprintf('Max runtime of %ds reached — consumer stopped cleanly.', $maxRuntime));

        return Command::SUCCESS;
    }

    /**
     * SIGINT/SIGTERM → graceful shutdown, but only where ext-pcntl exists.
     * It does not on Windows, so --max-runtime (not a signal) is what bounds
     * the worker there.
     */
    private function installSignalHandlers(): void
    {
        if (!\function_exists('pcntl_async_signals') || !\function_exists('pcntl_signal') || !\defined('SIGINT')) {
            return;
        }

        pcntl_async_signals(true);

        $handler = function (): void {
            $this->stopRequested = true;
            $this->subscriber->interrupt();
        };

        pcntl_signal(SIGINT, $handler);
        if (\defined('SIGTERM')) {
            pcntl_signal(SIGTERM, $handler);
        }
    }

    /** Parse a PHP-style size ("256M", "1G", "-1") into bytes (0 = unlimited). */
    private function toBytes(string $value): int
    {
        $value = trim($value);
        if ('' === $value || '-1' === $value) {
            return 0;
        }

        $number = (float) $value;

        return match (strtolower(substr($value, -1))) {
            'g' => (int) ($number * 1024 ** 3),
            'm' => (int) ($number * 1024 ** 2),
            'k' => (int) ($number * 1024),
            default => (int) $number,
        };
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes >= 1024 ** 3) {
            return round($bytes / 1024 ** 3, 1).'G';
        }
        if ($bytes >= 1024 ** 2) {
            return round($bytes / 1024 ** 2, 1).'M';
        }

        return $bytes.'B';
    }
}
