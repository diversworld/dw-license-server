<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use App\Service\AuditService;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Scheb\TwoFactorBundle\Security\TwoFactor\Provider\Totp\TotpAuthenticatorInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final class TwoFactorManagement
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TotpAuthenticatorInterface $totp,
        private readonly UserPasswordHasherInterface $passwords,
        private readonly Security $security,
        private readonly AuditService $audit,
    ) {}

    public function rotate(User $user, #[\SensitiveParameter] string $password, #[\SensitiveParameter] string $proof, #[\SensitiveParameter] string $secret, #[\SensitiveParameter] string $newCode): array
    {
        return $this->change($user, $password, $proof, function () use ($user, $secret, $newCode): array {
            $pending = clone $user; $pending->enableTwoFactor($secret, []);
            if (!$this->totp->checkCode($pending, $newCode)) { throw new \DomainException('two_factor.invalid_new_code'); }
            $codes = $this->newCodes(); $user->enableTwoFactor($secret, $codes);

            return $codes;
        }, 'security.two_factor_rotated');
    }

    public function regenerateCodes(User $user, #[\SensitiveParameter] string $password, #[\SensitiveParameter] string $proof): array
    {
        return $this->change($user, $password, $proof, function () use ($user): array {
            $codes = $this->newCodes(); $user->regenerateBackupCodes($codes);

            return $codes;
        }, 'security.recovery_codes_regenerated');
    }

    public function recover(User $user, #[\SensitiveParameter] string $password, #[\SensitiveParameter] string $backupCode): void
    {
        $this->change($user, $password, $backupCode, function () use ($user): array {
            $user->disableTwoFactorForRecovery();

            return [];
        }, 'security.two_factor_recovered', backupOnly: true);
    }

    private function change(User $user, #[\SensitiveParameter] string $password, #[\SensitiveParameter] string $proof, callable $change, string $event, bool $backupOnly = false): array
    {
        $actor = $this->security->getUser();
        if (!$actor instanceof User || (string) $actor->getId() !== (string) $user->getId()) { throw new AccessDeniedException(); }

        return $this->em->getConnection()->transactional(function () use ($user, $password, $proof, $change, $event, $backupOnly): array {
            $this->em->refresh($user, LockMode::PESSIMISTIC_WRITE);
            if (!$user->isTotpAuthenticationEnabled() || !$this->passwords->isPasswordValid($user, $password)) { throw new \DomainException('two_factor.reauthentication_failed'); }
            $isBackup = $user->isBackupCode($proof);
            if (!$isBackup && ($backupOnly || !$this->totp->checkCode($user, $proof))) { throw new \DomainException('two_factor.reauthentication_failed'); }
            $result = $change();
            // Changes replace/clear the old recovery-code list. No old code can remain usable.
            $this->em->flush();
            $this->audit->log($event, 'user', 'user:'.$user->getId(), (string) $user->getId(), 'Two-factor security changed; existing sessions revoked', $user, ['securityVersion' => $user->getSecurityVersion()]);

            return $result;
        });
    }

    private function newCodes(): array { return array_map(static fn (): string => bin2hex(random_bytes(10)), range(1, 10)); }
}
