<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use Scheb\TwoFactorBundle\Security\TwoFactor\Provider\Totp\TotpAuthenticatorInterface;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

final class PendingTotpEnrollment
{
    public function __construct(private readonly TotpSecretCipher $cipher, private readonly TotpAuthenticatorInterface $totp) {}

    public function secret(User $user, SessionInterface $session, string $key): string
    {
        $pending = $session->get($key);
        if (!is_array($pending) || ($pending['owner'] ?? null) !== (string) $user->getId()
            || ($pending['version'] ?? null) !== $user->getSecurityVersion() || ($pending['expiresAt'] ?? 0) <= time()) {
            $secret = $this->totp->generateSecret();
            $session->set($key, ['owner' => (string) $user->getId(), 'version' => $user->getSecurityVersion(), 'expiresAt' => time() + 600, 'secret' => $this->cipher->encrypt($secret)]);

            return $secret;
        }

        return $this->cipher->decrypt($pending['secret']);
    }
}
