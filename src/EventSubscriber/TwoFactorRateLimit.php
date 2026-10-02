<?php

namespace App\EventSubscriber;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

class TwoFactorRateLimit
{
    public function __construct(#[Autowire(service: 'limiter.two_factor')] private readonly RateLimiterFactoryInterface $limiter)
    {
    }

    #[AsEventListener(event: 'kernel.request', priority: 16)]
    public function onRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || $request->attributes->get('_route') !== '2fa_login_check') {
            return;
        }
        if (!$this->limiter->create('challenge:'.$request->getClientIp())->consume()->isAccepted()) {
            throw new TooManyRequestsHttpException(60);
        }
    }
}
