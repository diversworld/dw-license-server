<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Audit\AuditableEntityInterface;
use App\Entity\User;
use App\Service\AuditService;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PostRemoveEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\Events;
use Symfony\Bundle\SecurityBundle\Security;

#[AsDoctrineListener(event: Events::postPersist)]
#[AsDoctrineListener(event: Events::postUpdate)]
#[AsDoctrineListener(event: Events::postRemove)]
final class AuditSubscriber
{
    public function __construct(
        private readonly AuditService $auditService,
        private readonly Security $security,
    ) {
    }

    public function postPersist(PostPersistEventArgs $args): void
    {
        $entity = $args->getObject();

        if (!$entity instanceof AuditableEntityInterface) {
            return;
        }

        $this->auditService->log(
            $entity->getAuditType() . '.created',
            $entity->getAuditType(),
            $entity->getAuditIdentifier(),
            method_exists($entity, 'getId')
                ? (string) $entity->getId()
                : null,
            'Datensatz erstellt',
            $this->getCurrentUser()
        );
    }

    public function postUpdate(PostUpdateEventArgs $args): void
    {
        $entity = $args->getObject();

        if (!$entity instanceof AuditableEntityInterface) {
            return;
        }

        $this->auditService->log(
            $entity->getAuditType() . '.updated',
            $entity->getAuditType(),
            $entity->getAuditIdentifier(),
            method_exists($entity, 'getId')
                ? (string) $entity->getId()
                : null,
            'Datensatz geändert',
            $this->getCurrentUser()
        );
    }

    public function postRemove(PostRemoveEventArgs $args): void
    {
        $entity = $args->getObject();

        if (!$entity instanceof AuditableEntityInterface) {
            return;
        }

        $this->auditService->log(
            $entity->getAuditType() . '.deleted',
            $entity->getAuditType(),
            $entity->getAuditIdentifier(),
            null,
            'Datensatz gelöscht',
            $this->getCurrentUser()
        );
    }

    private function getCurrentUser(): ?User
    {
        $user = $this->security->getUser();

        return $user instanceof User
            ? $user
            : null;
    }
}