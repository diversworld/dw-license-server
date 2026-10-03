<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\User;
use App\Service\AuditService;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Security\Http\Event\{LoginFailureEvent, LoginSuccessEvent, LogoutEvent};

final class SecurityAuditSubscriber
{
    public function __construct(private readonly AuditService $audit) {}

    #[AsEventListener]
    public function loginSuccess(LoginSuccessEvent $event): void
    {
        $user = $event->getAuthenticatedToken()->getUser();
        $this->audit->log('security.login_succeeded', 'security', 'authentication', null, 'Authentication succeeded', $user instanceof User ? $user : null, ['firewall' => $event->getFirewallName()]);
    }

    #[AsEventListener]
    public function loginFailure(LoginFailureEvent $event): void
    {
        // Never store exception messages, submitted identifiers, request bodies or credentials.
        $this->audit->log('security.login_failed', 'security', 'authentication', null, 'Authentication failed', null, ['firewall' => $event->getFirewallName()]);
    }

    #[AsEventListener]
    public function logout(LogoutEvent $event): void
    {
        $user = $event->getToken()?->getUser();
        $this->audit->log('security.logout', 'security', 'authentication', null, 'User signed out', $user instanceof User ? $user : null);
    }
}
