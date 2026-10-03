<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use Scheb\TwoFactorBundle\Security\Http\Authenticator\TwoFactorAuthenticator;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Role\RoleHierarchyInterface;

final class TwoFactorPolicy
{
    public function __construct(
        private readonly RoleHierarchyInterface $hierarchy,
        #[Autowire('%env(json:TWO_FACTOR_REQUIRED_ROLES)%')] private readonly array $roles,
        #[Autowire('%env(json:TWO_FACTOR_REQUIRED_ACTIONS)%')] private readonly array $actions,
    ) {
        if (array_filter($roles, static fn ($role) => !is_string($role) || !in_array($role, RoleCatalog::ALLOWED, true)) !== []) { throw new \InvalidArgumentException('TWO_FACTOR_REQUIRED_ROLES contains an unknown assignable role.'); }
        foreach ($actions as $action) {
            if (!is_string($action) || !in_array($action, [...AdministrativeActionVoter::supportedActions(), 'SIGNING_KEY_ACTIVATE', 'SIGNING_KEY_REVOKE', 'API_CREDENTIAL_MANAGE', 'PORTAL_DOMAIN_CHANGE'], true)) { throw new \InvalidArgumentException('TWO_FACTOR_REQUIRED_ACTIONS contains an unknown permission name.'); }
        }
    }

    public function requiredForUser(User $user): bool
    {
        return array_intersect($this->roles, $this->hierarchy->getReachableRoleNames($user->getRoles())) !== [];
    }

    public function permits(User $user, string $action, TokenInterface $token): bool
    {
        if (!$this->requiredForUser($user) && !in_array($action, $this->actions, true)) { return true; }

        return $user->isTotpAuthenticationEnabled() && $token->hasAttribute(TwoFactorAuthenticator::FLAG_2FA_COMPLETE) && $token->getAttribute(TwoFactorAuthenticator::FLAG_2FA_COMPLETE) === true;
    }
}
