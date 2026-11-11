<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Agent\AgentRetention;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:agent:purge', description: 'Purge expired agent conversations without displaying their content.')]
final class PurgeAgentConversationsCommand extends Command
{
    public function __construct(private readonly AgentRetention $retention)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Print candidate counts without deleting rows.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dryRun = (bool) $input->getOption('dry-run');
        $counts = $dryRun ? $this->retention->candidateCounts() : $this->retention->purge();
        $output->writeln(sprintf(
            '%s conversations=%d inactive=%d soft_deleted=%d messages=%d',
            $dryRun ? 'DRY RUN' : 'PURGED',
            $counts['conversations'],
            $counts['inactive'],
            $counts['soft_deleted'],
            $counts['messages'],
        ));

        return Command::SUCCESS;
    }
}
