<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\DeviceToken;
use App\Repository\DeviceTokenRepository;
use App\Repository\MachineRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Issues a device token for one machine. The plaintext token is printed ONCE
 * and only its SHA-512 hash is stored — this is the credential the ESP32 (and
 * later the MQTT consumer) will present in the X-Device-Token header.
 *
 *     php bin/console app:machine:issue-device-token 3awedlou-001 [--label="shop unit"]
 *     php bin/console app:machine:issue-device-token 3awedlou-001 --revoke-old
 */
#[AsCommand(
    name: 'app:machine:issue-device-token',
    description: 'Issue a device API token for a machine (printed once, stored hashed).',
)]
final class IssueDeviceTokenCommand extends Command
{
    public function __construct(
        private readonly MachineRepository $machines,
        private readonly DeviceTokenRepository $deviceTokens,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('machine', InputArgument::REQUIRED, 'Machine identifier (e.g. 3awedlou-001)')
            ->addOption('label', null, InputOption::VALUE_REQUIRED, 'Human label for the token', 'ESP32')
            ->addOption('revoke-old', null, InputOption::VALUE_NONE, 'Revoke the machine\'s existing tokens first');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $machine = $this->machines->findByIdentifier((string) $input->getArgument('machine'));
        if (null === $machine) {
            $io->error(sprintf('Machine "%s" not found.', $input->getArgument('machine')));

            return Command::FAILURE;
        }

        if (true === $input->getOption('revoke-old')) {
            $revoked = $this->deviceTokens->revokeAllForMachine($machine);
            if ($revoked > 0) {
                $io->note(sprintf('Revoked %d existing token(s) for this machine.', $revoked));
            }
        }

        // 64 hex chars = 256 bits of entropy. Only the hash is persisted.
        $plaintext = bin2hex(random_bytes(32));
        $this->em->persist(new DeviceToken(
            $machine,
            hash('sha512', $plaintext),
            (string) $input->getOption('label'),
        ));
        $this->em->flush();

        $io->success('Device token created (stored hashed).');
        $io->writeln(sprintf('Machine:  %s (%s)', $machine->getName(), $machine->getIdentifier()));
        $io->writeln('Header:   X-Device-Token');
        $io->newLine();
        $io->writeln('<fg=green;options=bold>'.$plaintext.'</>');
        $io->newLine();
        $io->warning('Copy it now — this is the ONLY time the plaintext is shown.');

        return Command::SUCCESS;
    }
}
