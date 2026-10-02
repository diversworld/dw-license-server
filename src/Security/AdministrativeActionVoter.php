<?php

namespace App\Security;

use App\Entity\User;
use Scheb\TwoFactorBundle\Security\Http\Authenticator\TwoFactorAuthenticator;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;
use Symfony\Component\Security\Core\Role\RoleHierarchyInterface;

class AdministrativeActionVoter extends Voter
{
    private const array ROLES = [
        'PROFILE_SELF' => ['ROLE_VIEWER'],
        'CUSTOMER_MANAGE' => ['ROLE_CUSTOMER_MANAGE'],
        'PRODUCT_MANAGE' => ['ROLE_PRODUCT_MANAGE'],
        'USER_MANAGE' => ['ROLE_USER_MANAGE'],
        'LICENSE_CREATE' => ['ROLE_LICENSE_SELL'],
        'LICENSE_EDIT' => ['ROLE_LICENSE_SELL'],
        'LICENSE_ISSUE' => ['ROLE_LICENSE_SELL', 'ROLE_LICENSE_OPERATE'],
        'LICENSE_RENEW' => ['ROLE_LICENSE_SELL'],
        'LICENSE_PAUSE' => ['ROLE_LICENSE_OPERATE'],
        'LICENSE_REACTIVATE' => ['ROLE_LICENSE_OPERATE'],
        'LICENSE_REVOKE' => ['ROLE_LICENSE_REVOKE'],
        'ACTIVATION_MANAGE' => ['ROLE_ACTIVATION_MANAGE'],
    ];

    public function __construct(private readonly RoleHierarchyInterface $hierarchy)
    {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return isset(self::ROLES[$attribute]);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?\Symfony\Component\Security\Core\Authorization\Voter\Vote $vote = null): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User || !$user->isActive()) {
            return false;
        }
        if ($user->isTotpAuthenticationEnabled() && (!$token->hasAttribute(TwoFactorAuthenticator::FLAG_2FA_COMPLETE)
            || !$token->getAttribute(TwoFactorAuthenticator::FLAG_2FA_COMPLETE))) {
            return false;
        }

        if ($attribute === 'PROFILE_SELF' && (!$subject instanceof User || (string) $subject->getId() !== (string) $user->getId())) {
            return false;
        }

        return array_intersect(self::ROLES[$attribute], $this->hierarchy->getReachableRoleNames($token->getRoleNames())) !== [];
    }
}
