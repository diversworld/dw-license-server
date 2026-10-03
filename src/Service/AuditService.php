<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AuditLog;
use App\Entity\User;
use App\Repository\AuditLogRepository;
use Symfony\Component\HttpFoundation\RequestStack;

final class AuditService
{
    public function __construct(
        private readonly AuditLogRepository $repository,
        private readonly RequestStack $requestStack,
        private readonly ?\Symfony\Bundle\SecurityBundle\Security $security = null,
    ) {
    }

    public function log(
        string $eventType,
        string $entityType,
        string $entityIdentifier,
        ?string $entityId,
        string $message,
        ?User $user = null,
        array $context = [],
    ): AuditLog {
        $request = $this->requestStack->getCurrentRequest();

        $apiActor = $this->security?->getUser();
        $log = new AuditLog();

        $log
            ->setEventType($eventType)
            ->setEntityType($entityType)
            ->setEntityIdentifier($entityIdentifier)
            ->setEntityId($entityId)
            ->setMessage($message)
            ->setContext(\App\Audit\AuditCanonical::mask($context))
            ->setHashVersion(2)
            ->setActorIdentity($user === null ? ($apiActor instanceof \App\Security\ApiPrincipal ? $apiActor->getUserIdentifier() : null) : (string) $user->getId().":".$user->getUserIdentifier())
            ->setPerformedBy($user)
            ->setIpAddress($request?->getClientIp())
            ->setUserAgent(
                ($agent = $request?->headers->get('User-Agent')) === null ? null : mb_substr($agent, 0, 512)
            );

        $this->repository->append($log);

        return $log;
    }
}
