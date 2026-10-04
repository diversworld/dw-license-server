<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final class DialogLocaleListener
{
    private const array LOCALES = ['de', 'en', 'fr', 'es'];

    #[AsEventListener(event: KernelEvents::REQUEST, priority: 20)]
    public function rememberLocale(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !$request->hasSession() || str_starts_with($request->getPathInfo(), '/api/')) {
            return;
        }

        $locale = $request->attributes->get('_locale');
        if (\in_array($locale, self::LOCALES, true)) {
            $request->getSession()->set('_dialog_locale', $locale);
        } elseif ($locale === null && $request->hasPreviousSession()) {
            $savedLocale = $request->getSession()->get('_dialog_locale');
            if (\in_array($savedLocale, self::LOCALES, true)) {
                $request->attributes->set('_locale', $savedLocale);
            }
        }
    }
}
