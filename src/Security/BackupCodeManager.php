<?php

namespace App\Security;

use App\Entity\User;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Scheb\TwoFactorBundle\Security\TwoFactor\Backup\BackupCodeManagerInterface;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;

class BackupCodeManager implements BackupCodeManagerInterface
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function isBackupCode(object $user, string $code): bool
    {
        return $user instanceof User && $user->isBackupCode($code);
    }

    public function invalidateBackupCode(object $user, string $code): void
    {
        $this->em->getConnection()->transactional(function () use ($user, $code): void {
            $this->em->refresh($user, LockMode::PESSIMISTIC_WRITE);
            if (!$user instanceof User || !$user->isBackupCode($code)) {
                throw new BadCredentialsException('Invalid recovery code.');
            }
            $user->invalidateBackupCode($code);
            $this->em->flush();
        });
    }
}
