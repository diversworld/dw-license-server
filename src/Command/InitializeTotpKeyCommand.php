<?php

declare(strict_types=1);

namespace App\Command;

use App\Security\TotpSecretCipher;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:security:totp-key:init', description: 'Initialize a separate authenticator encryption key without overwriting an existing key.')]
final class InitializeTotpKeyCommand extends Command
{
    public function __construct(private readonly TotpSecretCipher $cipher, private readonly EntityManagerInterface $em) { parent::__construct(); }
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Even if the file was lost, never replace the key for already encrypted database secrets.
        $encrypted = (int) $this->em->getConnection()->fetchOne("SELECT COUNT(*) FROM user WHERE totp_secret LIKE 'enc:v1:%'");
        if ($encrypted > 0) {
            try {
                $secret = $this->em->getConnection()->fetchOne("SELECT totp_secret FROM user WHERE totp_secret LIKE 'enc:v1:%' LIMIT 1");
                $this->cipher->decrypt($secret);
            } catch (\RuntimeException) { $output->writeln('<error>Encrypted authenticators exist. Restore their original encryption key; no new key was generated.</error>'); return Command::FAILURE; }
        }
        $this->cipher->initializeKey();
        $output->writeln('Authenticator encryption key is ready. Back it up separately and distribute the same key to every server instance.');

        return Command::SUCCESS;
    }
}
