<?php

namespace App\Command;

use App\Service\LicenseSigner;
use App\Service\SigningKeyRotation;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:license:rotate', description: 'Manage signing-key lifecycle with an explicit operator, reason and reviewed rollout.')]
class RotateSigningKeyCommand extends Command
{
    public function __construct(private readonly SigningKeyRotation $rotation, private readonly LicenseSigner $signer)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('action', InputArgument::REQUIRED, 'prepare, publish, activate, retire, revoke, status, public-keys or manifest')
            ->addArgument('kid', InputArgument::OPTIONAL)
            ->addOption('actor', null, InputOption::VALUE_REQUIRED)
            ->addOption('reason', null, InputOption::VALUE_REQUIRED)
            ->addOption('expected', null, InputOption::VALUE_REQUIRED, 'Reviewed fingerprint from status')
            ->addOption('rollout-confirmed', null, InputOption::VALUE_NONE)
            ->addOption('legacy-safe-after', null, InputOption::VALUE_REQUIRED, 'Verified last legacy token expiry, ISO-8601');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $action = $input->getArgument('action');
        if ($action === 'public-keys') {
            $output->writeln(json_encode($this->signer->publicKeys(), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        } elseif ($action === 'status') {
            $output->writeln(json_encode(['fingerprint' => $this->rotation->fingerprint(), 'keyring' => $this->signer->keyring()], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        } elseif ($action === 'manifest') {
            $output->writeln($this->signer->sign(['type' => 'signing-key-manifest']));
        } elseif ($action === 'prepare') {
            $output->writeln($this->rotation->prepare((string) $input->getOption('actor'), (string) $input->getOption('reason')));
        } else {
            $cutoff = $input->getOption('legacy-safe-after');
            $this->rotation->transition((string) $action, (string) $input->getArgument('kid'), (string) $input->getOption('actor'), (string) $input->getOption('reason'), (string) $input->getOption('expected'), $input->getOption('rollout-confirmed'), $cutoff === null ? null : new \DateTimeImmutable($cutoff));
            $output->writeln('Signing-key transition recorded.');
        }
        return Command::SUCCESS;
    }
}
