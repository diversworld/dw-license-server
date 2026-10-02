<?php

namespace App\EventSubscriber;

use App\Entity\User;
use Scheb\TwoFactorBundle\Security\Http\Authenticator\TwoFactorAuthenticator;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

class RequireTwoFactorEnrollment
{
    public function __construct(private readonly Security $security, private readonly UrlGeneratorInterface $router, private readonly TokenStorageInterface $tokens)
    {
    }

    #[AsEventListener(event: 'kernel.request', priority: -16)]
    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest() || !str_starts_with($event->getRequest()->getPathInfo(), '/admin')
            || !$this->security->isGranted('IS_AUTHENTICATED_FULLY') || !$this->security->isGranted('ROLE_VIEWER')) {
            return;
        }
        $user = $this->security->getUser();
        if ($user instanceof User && !$user->isTotpAuthenticationEnabled()) {
            $event->setResponse(new RedirectResponse($this->router->generate('two_factor_setup')));
        } elseif (!$this->tokens->getToken()?->hasAttribute(TwoFactorAuthenticator::FLAG_2FA_COMPLETE) || !$this->tokens->getToken()->getAttribute(TwoFactorAuthenticator::FLAG_2FA_COMPLETE)) {
            // Sessions created before 2FA became mandatory must authenticate again.
            $this->security->logout(false);
            $event->setResponse(new RedirectResponse($this->router->generate('app_login')));
        }
    }
}
