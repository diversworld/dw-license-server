<?php

namespace App\EventSubscriber;

use App\Service\ApiMetrics;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\{RequestEvent, ResponseEvent};
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Uid\Uuid;

final class OperationalRequests
{
    public function __construct(private readonly ApiMetrics $metrics, private readonly LoggerInterface $logger) {}

    #[AsEventListener(event: KernelEvents::REQUEST, priority: 250)]
    public function start(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) { return; }
        $request = $event->getRequest(); $provided = $request->headers->get('X-Request-ID');
        $request->attributes->set('request_id', is_string($provided) && Uuid::isValid($provided) ? Uuid::fromString($provided)->toRfc4122() : Uuid::v7()->toRfc4122());
        $request->attributes->set('_operation_start', hrtime(true));
    }

    #[AsEventListener(event: KernelEvents::RESPONSE, priority: -250)]
    public function finish(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) { return; }
        $request = $event->getRequest(); $response = $event->getResponse();
        $response->headers->set('X-Request-ID', (string) $request->attributes->get('request_id'));
        if (!str_starts_with($request->getPathInfo(), '/api/v1/')) { return; }
        $seconds = max(0, (hrtime(true) - $request->attributes->get('_operation_start', hrtime(true)))) / 1e9;
        $operation = match ($request->attributes->get('_route')) { 'api_license_activate' => 'activate', 'api_license_validate' => 'refresh', 'api_management_create' => 'create', 'api_management_renew' => 'renew', default => 'other' };
        $this->logger->info('license.api.request', ['request_id' => $request->attributes->get('request_id'), 'operation' => $operation, 'status' => $response->getStatusCode(), 'duration_seconds' => $seconds]);
        try { $this->metrics->record($operation, $response->getStatusCode(), $seconds); } catch (\Throwable) { $this->logger->warning('license.api.metrics_unavailable', ['request_id' => $request->attributes->get('request_id')]); }
    }
}
