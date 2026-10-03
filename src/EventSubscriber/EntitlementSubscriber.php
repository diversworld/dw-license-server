<?php

declare(strict_types=1);
namespace App\EventSubscriber;

use App\Entity\{License, LicensePlan, Product};
use App\Service\ProductEntitlements;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\{PrePersistEventArgs, PreUpdateEventArgs};
use Doctrine\ORM\Events;

#[AsDoctrineListener(event: Events::prePersist, priority: 50)]
#[AsDoctrineListener(event: Events::preUpdate, priority: 50)]
final class EntitlementSubscriber
{
    public function __construct(private readonly ProductEntitlements $rights) {}
    public function prePersist(PrePersistEventArgs $event): void { $this->check($event->getObject(), true); }
    public function preUpdate(PreUpdateEventArgs $event): void
    {
        $entity = $event->getObject();
        $changedRights = $entity instanceof License && array_intersect(array_keys($event->getEntityChangeSet()), ['features', 'maxDomains', 'quotas', 'product']) !== [];
        $this->check($entity, $changedRights);
        if ($entity instanceof License && $changedRights) { $event->getObjectManager()->getUnitOfWork()->recomputeSingleEntityChangeSet($event->getObjectManager()->getClassMetadata(License::class), $entity); }
    }
    private function check(object $entity, bool $capture): void
    {
        if ($entity instanceof Product) { ProductEntitlements::validateDefinition($entity); }
        if ($entity instanceof LicensePlan) { $this->rights->assertRights($entity->getProduct(), $entity->getFeatures(), $entity->getMaxDomains(), $entity->getQuotas()); }
        if ($entity instanceof License) { if ($capture) { $this->rights->capture($entity); } else { $this->rights->assertLicense($entity); } }
    }
}
