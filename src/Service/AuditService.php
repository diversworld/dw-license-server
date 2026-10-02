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
        $previousHash = $this->repository->getLastHash();

        $request = $this->requestStack->getCurrentRequest();

        $entryHash = hash(
            'sha256',
            ($previousHash ?? '')
            . $eventType
            . $entityType
            . $entityIdentifier
            . json_encode($context, JSON_THROW_ON_ERROR)
        );

        $log = new AuditLog();

        $log
            ->setEventType($eventType)
            ->setEntityType($entityType)
            ->setEntityIdentifier($entityIdentifier)
            ->setEntityId($entityId)
            ->setMessage($message)
            ->setContext($context)
            ->setPreviousHash($previousHash)
            ->setEntryHash($entryHash)
            ->setPerformedBy($user)
            ->setIpAddress($request?->getClientIp())
            ->setUserAgent(
                $request?->headers->get('User-Agent')
            );

        $this->repository->append($log);

        return $log;
    }
}
