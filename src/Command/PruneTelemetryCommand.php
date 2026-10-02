<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\MachineTelemetryRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Telemetry retention: deletes samples older than the cutoff.
 *
 *     php bin/console app:telemetry:prune --older-than="90 days ago"
 *     php bin/console app:telemetry:prune --older-than="30 days ago" --batch=5000
 *
 * Run it from cron/scheduler in production. This is deliberately NOT a
 * time-series system: prune + the (machine_id, recorded_at DESC) index are
 * all the history management this platform needs.
 */
#[AsCommand(
    name: 'app:telemetry:prune',
    description: 'Delete telemetry samples older than the given age (retention policy).',
)]
final class PruneTelemetryCommand extends Command
{
    public function __construct(
        private readonly MachineTelemetryRepository $telemetry,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('older-than', null, InputOption::VALUE_REQUIRED, 'Delete samples older than this (strtotime-compatible, UTC).', '90 days ago')
            ->addOption('batch', null, InputOption::VALUE_REQUIRED, 'Delete in batches of N rows (0 = single statement).', '5000')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Count the rows that would be deleted without deleting.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $cutoffRaw = (string) $input->getOption('older-than');
        $cutoff = @new \DateTimeImmutable($cutoffRaw, new \DateTimeZone('UTC'));
        if ($cutoff > new \DateTimeImmutable('now', new \DateTimeZone('UTC'))) {
            $io->error(sprintf('Invalid --older-than value "%s" (must be a past date, e.g. "90 days ago").', $cutoffRaw));

            return Command::FAILURE;
        }

        $batch = max(0, (int) $input->getOption('batch'));

        if (true === $input->getOption('dry-run')) {
            $count = $this->telemetry->countOlderThan($cutoff);
            $io->info(sprintf('[dry-run] %d sample(s) older than %s would be deleted.', $count, $cutoff->format(\DateTimeInterface::ATOM)));

            return Command::SUCCESS;
        }

        $deleted = 0;
        do {
            $n = $this->telemetry->deleteOlderThan($cutoff, $batch > 0 ? $batch : null);
            $deleted += $n;
            if ($batch > 0) {
                $io->write('.');
            }
        } while ($batch > 0 && $n === $batch);

        if ($batch > 0) {
            $io->newLine(2);
        }

        $io->success(sprintf('Deleted %d telemetry sample(s) recorded before %s (UTC).', $deleted, $cutoff->format(\DateTimeInterface::ATOM)));

        return Command::SUCCESS;
    }
}
