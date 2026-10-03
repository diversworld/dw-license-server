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
        $webhookSecret = $this->em->getConnection()->createSchemaManager()->tablesExist(['webhook_endpoint']) ? $this->em->getConnection()->fetchOne("SELECT secret_ciphertext FROM webhook_endpoint WHERE secret_ciphertext LIKE 'enc:v1:%' LIMIT 1") : false;
        $encrypted = (int) $this->em->getConnection()->fetchOne("SELECT COUNT(*) FROM user WHERE totp_secret LIKE 'enc:v1:%'");
        if ($encrypted > 0 || $webhookSecret !== false) {
            try {
                $secret = $this->em->getConnection()->fetchOne("SELECT totp_secret FROM user WHERE totp_secret LIKE 'enc:v1:%' LIMIT 1");
                $this->cipher->decrypt($secret ?: $webhookSecret);
            } catch (\RuntimeException $error) {
                $output->writeln('<error>'.$error->getMessage().'</error>');
                $output->writeln('<error>Encrypted authenticators exist. Check access to their original encryption key and restore it if missing or replaced; no new key was generated.</error>');

                return Command::FAILURE;
            }
        }
        $this->cipher->initializeKey();
        $output->writeln('Authenticator encryption key is ready. Back it up separately and distribute the same key to every server instance.');

        return Command::SUCCESS;
    }
}
