<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\User;
use App\Security\TotpSecretCipher;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:security:encrypt-totp', description: 'Seal legacy plaintext authenticator secrets using the existing independent encryption key.')]
final class EncryptLegacyTotpCommand extends Command
{
    public function __construct(private readonly EntityManagerInterface $em, private readonly TotpSecretCipher $cipher) { parent::__construct(); }
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $count = 0;
        $this->em->getConnection()->transactional(function () use (&$count): void {
            foreach ($this->em->getRepository(User::class)->findAll() as $user) {
                $stored = $user->getStoredTotpSecret();
                if ($stored !== null && !str_starts_with($stored, TotpSecretCipher::PREFIX)) {
                    $this->em->lock($user, \Doctrine\DBAL\LockMode::PESSIMISTIC_WRITE);
                    $this->em->refresh($user);
                    $stored = $user->getStoredTotpSecret();
                    if ($stored !== null && !str_starts_with($stored, TotpSecretCipher::PREFIX)) { $user->setStoredTotpSecret($this->cipher->encrypt($stored)); ++$count; }
                }
            }
            $this->em->flush();
        });
        $output->writeln(sprintf('Encrypted %d legacy authenticator records; no secrets printed.', $count));

        return Command::SUCCESS;
    }
}
