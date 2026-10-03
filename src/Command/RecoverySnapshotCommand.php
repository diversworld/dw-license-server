<?php

namespace App\Command;

use App\Service\RecoverySnapshot;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\{InputArgument, InputInterface, InputOption};
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:operations:snapshot', description: 'Back up database, signing keys and recovery configuration; restore only into empty targets.')]
final class RecoverySnapshotCommand extends Command
{
    public function __construct(private readonly RecoverySnapshot $snapshots) { parent::__construct(); }
    protected function configure(): void
    {
        $this->addArgument('action', InputArgument::REQUIRED, 'backup or restore')->addArgument('directory', InputArgument::REQUIRED)
            ->addOption('maintenance-confirmed', null, InputOption::VALUE_NONE);
    }
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        match ($input->getArgument('action')) {
            'backup' => $this->snapshots->backup($input->getArgument('directory'), $input->getOption('maintenance-confirmed')),
            'restore' => $this->snapshots->restore($input->getArgument('directory')),
            default => throw new \InvalidArgumentException('Use backup or restore.'),
        };
        $output->writeln('Recovery snapshot operation completed. Verify audit, schema, signing and authenticator decryption before resuming traffic.');
        return Command::SUCCESS;
    }
}
