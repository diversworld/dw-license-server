<?php

declare(strict_types=1);

namespace App\Command;

use App\Security\TotpSecretCipher;
use Doctrine\DBAL\Connection;
use Scheb\TwoFactorBundle\Security\Http\EventListener\CheckBackupCodeListener;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Security\Http\Event\CheckPassportEvent;

#[AsCommand(name: 'app:security:2fa:diagnose', description: 'Read-only check of account two-factor storage and recovery-code registration; never prints secrets.')]
final class DiagnoseTwoFactorCommand extends Command
{
    public function __construct(
        private readonly Connection $connection,
        private readonly TotpSecretCipher $cipher,
        private readonly EventDispatcherInterface $dispatcher,
        private readonly KernelInterface $kernel,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('email', InputArgument::REQUIRED, 'Email of the affected account.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->writeln('Environment: '.$this->kernel->getEnvironment());
        $io->writeln('Server UTC: '.gmdate('Y-m-d H:i:s'));
        // Read raw storage so a key-loading failure cannot hide the account diagnostics.
        $row = $this->connection->fetchAssociative('SELECT active, totp_secret, backup_code_hashes FROM user WHERE email = ?', [$input->getArgument('email')]);
        if ($row === false) {
            $io->error('Account not found in the configured database.');

            return Command::FAILURE;
        }

        $listenerPresent = false;
        foreach ($this->dispatcher->getListeners(CheckPassportEvent::class) as $listener) {
            if (is_array($listener) && $listener[0] instanceof CheckBackupCodeListener) {
                $listenerPresent = true;
            }
        }
        $io->writeln('Recovery-code listener: '.($listenerPresent ? 'enabled' : 'MISSING'));
        $io->writeln('Account active: '.($row['active'] ? 'yes' : 'no'));
        $hashes = is_string($row['backup_code_hashes']) ? json_decode($row['backup_code_hashes'], true) : null;
        $validHashes = is_array($hashes) && array_is_list($hashes);
        foreach (is_array($hashes) ? $hashes : [] as $hash) {
            $validHashes = $validHashes && is_string($hash) && preg_match('/\A[0-9a-f]{64}\z/', $hash) === 1;
        }
        $io->writeln('Unused recovery codes: '.($validHashes ? count($hashes) : 'INVALID STORAGE'));
        $stored = $row['totp_secret'];
        $io->writeln('Authenticator storage: '.($stored === null ? 'disabled' : (str_starts_with($stored, TotpSecretCipher::PREFIX) ? 'encrypted' : 'legacy plaintext')));
        if ($stored !== null) {
            try {
                $plain = $this->cipher->decrypt($stored);
                if (preg_match('/\A[A-Z2-7]+=*\z/i', $plain) !== 1) {
                    $io->error('Invalid authenticator secret format.');

                    return Command::FAILURE;
                }
                $io->writeln('Authenticator readable: yes');
            } catch (\RuntimeException $error) {
                $io->error($error->getMessage());

                return Command::FAILURE;
            }
        }
        $io->note('No data changed; no recovery code consumed. CLI results must match the configuration and release used by PHP-FPM.');

        return $listenerPresent && $validHashes ? Command::SUCCESS : Command::FAILURE;
    }
}
