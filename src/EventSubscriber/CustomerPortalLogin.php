<?php
namespace App\EventSubscriber;
use App\Entity\User;
use Scheb\TwoFactorBundle\Security\Authentication\Token\TwoFactorTokenInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;
final class CustomerPortalLogin
{
    public function __construct(private readonly UrlGeneratorInterface $router) {}
    #[AsEventListener(event: LoginSuccessEvent::class, priority: -256)]
    public function redirect(LoginSuccessEvent $event): void
    {
        $user = $event->getUser();
        if ($user instanceof User && in_array('ROLE_CUSTOMER', $user->getRoles(), true) && !$event->getAuthenticatedToken() instanceof TwoFactorTokenInterface) {
            $event->setResponse(new RedirectResponse($this->router->generate('portal_index', ['_locale' => $event->getRequest()->getLocale()])));
        }
    }
}
