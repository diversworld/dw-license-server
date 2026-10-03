<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Audit\AuditableEntityInterface;
use App\Audit\AuditChanges;
use App\Entity\AuditLog;
use App\Entity\AuditChainHead;
use App\Entity\User;
use App\Service\AuditService;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PostRemoveEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\Event\PreRemoveEventArgs;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\Events;
use Symfony\Bundle\SecurityBundle\Security;

#[AsDoctrineListener(event: Events::postPersist)]
#[AsDoctrineListener(event: Events::postUpdate)]
#[AsDoctrineListener(event: Events::preRemove)]
#[AsDoctrineListener(event: Events::preUpdate)]
#[AsDoctrineListener(event: Events::postRemove)]
final class AuditSubscriber
{
    /** @var \WeakMap<object, array{type: string, identifier: string, id: ?string, table: string, changes: array}> */
    private \WeakMap $removedEntities;

    public function __construct(
        private readonly AuditService $auditService,
        private readonly Security $security,
        private readonly AuditChanges $auditChanges,
    ) {
        $this->removedEntities = new \WeakMap();
    }

    public function postPersist(PostPersistEventArgs $args): void
    {
        $this->record($args->getObject(), $args->getObjectManager(), 'created', 'Datensatz erstellt');
    }

    public function postUpdate(PostUpdateEventArgs $args): void
    {
        $this->record($args->getObject(), $args->getObjectManager(), 'updated', 'Datensatz geändert');
    }

    public function preUpdate(PreUpdateEventArgs $args): void
    {
        if ($args->getObject() instanceof AuditLog || $args->getObject() instanceof AuditChainHead) {
            throw new \LogicException('Audit records and chain metadata are immutable through ORM operations.');
        }
    }

    public function preRemove(PreRemoveEventArgs $args): void
    {
        $entity = $args->getObject();
        if ($entity instanceof AuditLog || $entity instanceof AuditChainHead) {
            throw new \LogicException('Audit records and chain metadata cannot be deleted through ORM operations.');
        }
        if ($entity instanceof \App\Archive\ArchivableInterface) {
            throw new \LogicException('Customers, products and licenses must be archived instead of deleted.');
        }
        if (!($entity instanceof AuditLog || $entity instanceof AuditChainHead)) {
            // Doctrine clears generated identifiers before postRemove is dispatched.
            $this->removedEntities[$entity] = $this->describe($entity, $args->getObjectManager()) + [
                'changes' => $this->auditChanges->capture($entity, $args->getObjectManager(), deleted: true),
            ];
        }
    }

    public function postRemove(PostRemoveEventArgs $args): void
    {
        $this->record($args->getObject(), $args->getObjectManager(), 'deleted', 'Datensatz gelöscht');
        unset($this->removedEntities[$args->getObject()]);
    }

    private function record(object $entity, EntityManagerInterface $em, string $action, string $message): void
    {
        if ($entity instanceof AuditLog || $entity instanceof AuditChainHead) {
            return;
        }

        $description = $action === 'deleted' && isset($this->removedEntities[$entity])
            ? $this->removedEntities[$entity]
            : $this->describe($entity, $em);
        $changes = $description['changes'] ?? $this->auditChanges->capture($entity, $em);
        $user = $this->security->getUser();
        if (!$user instanceof User || !$em->contains($user) || $em->getUnitOfWork()->isScheduledForDelete($user) || ($user === $entity && $action === 'deleted')) {
            $user = null;
        }

        $this->auditService->log(
            $description['type'].'.'.$action,
            $description['type'],
            $description['identifier'],
            $description['id'],
            $message,
            $user,
            [
                'customerId' => $description['customerId'] ?? null,
                'table' => $description['table'],
                'entityClass' => $em->getClassMetadata($entity::class)->name,
                'changedFields' => array_keys($changes),
                'changes' => $changes,
            ],
        );
    }

    /** @return array{type: string, identifier: string, id: ?string, table: string} */
    private function describe(object $entity, EntityManagerInterface $em): array
    {
        $metadata = $em->getClassMetadata($entity::class);
        $ids = array_map(static fn (mixed $value): string => (string) $value, $metadata->getIdentifierValues($entity));
        $id = $ids === [] ? null : implode(':', $ids);
        $type = $entity instanceof AuditableEntityInterface ? $entity->getAuditType() : $metadata->getTableName();

        return [
            'customerId' => ($customer = \App\Security\CustomerAccess::customerOf($entity)) === null ? null : (string) $customer->getId(),
            'type' => $type,
            'identifier' => $entity instanceof AuditableEntityInterface ? $entity->getAuditIdentifier() : $type.':'.($id ?? 'new'),
            'id' => $id,
            'table' => $metadata->getTableName(),
        ];
    }
}
