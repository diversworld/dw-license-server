<?php

namespace App\EventSubscriber;

use App\Entity\User;
use App\Security\CustomerAccess;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Events;
use Doctrine\Persistence\Event\LifecycleEventArgs;

#[AsDoctrineListener(event: Events::prePersist)]
#[AsDoctrineListener(event: Events::preUpdate)]
final class CustomerScopeSubscriber
{
    public function __construct(private readonly EntityManagerInterface $em, private readonly Security $security) {}

    #[AsEventListener(event: KernelEvents::REQUEST, priority: 256)]
    public function resetScope(RequestEvent $event): void
    {
        if ($event->isMainRequest() && $this->em->getFilters()->isEnabled('customer_scope')) { $this->em->getFilters()->disable('customer_scope'); }
    }

    #[AsEventListener(event: KernelEvents::REQUEST, priority: 7)]
    public function scope(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) { return; }
        $filters = $this->em->getFilters();
        if ($filters->isEnabled('customer_scope')) { $filters->disable('customer_scope'); }
        $user = $this->security->getUser();
        if ($user instanceof \App\Security\ApiPrincipal) { $filters->enable('customer_scope')->setParameter('customers', bin2hex($user->customerId->toBinary())); return; }
        if (!$user instanceof User || $user->hasGlobalAccess()) { return; }
        $ids = [];
        foreach ($user->getCustomers() as $customer) { $ids[] = bin2hex($customer->getId()->toBinary()); }
        $filters->enable('customer_scope')->setParameter('customers', implode(',', $ids));
    }

    #[AsEventListener(event: \Symfony\Component\HttpKernel\Event\ControllerArgumentsEvent::class, priority: 64)]
    public function arguments(\Symfony\Component\HttpKernel\Event\ControllerArgumentsEvent $event): void
    {
        foreach ($event->getArguments() as $argument) {
            if (is_object($argument) && CustomerAccess::customerOf($argument) !== null) { $this->assertReadable($argument); }
        }
    }

    #[AsEventListener(event: \EasyCorp\Bundle\EasyAdminBundle\Event\BeforeCrudActionEvent::class)]
    public function crud(\EasyCorp\Bundle\EasyAdminBundle\Event\BeforeCrudActionEvent $event): void
    {
        $entity = $event->getAdminContext()?->getEntity()?->getInstance();
        if ($entity !== null) { $this->assertReadable($entity); }
    }

    private function assertReadable(object $entity): void
    {
        $user = $this->security->getUser();
        if ($user instanceof \App\Security\ApiPrincipal && ($customer = CustomerAccess::customerOf($entity)) !== null && (string) $customer->getId() !== (string) $user->customerId) { throw new AccessDeniedException(); }
        if ($user instanceof User && !CustomerAccess::allows($user, $entity)) { throw new AccessDeniedException(); }
    }

    public function prePersist(LifecycleEventArgs $event): void { $this->guard($event->getObject()); }
    public function preUpdate(LifecycleEventArgs $event): void { $this->guard($event->getObject()); }

    private function guard(object $entity): void
    {
        $user = $this->security->getUser();
        if ($user instanceof \App\Security\ApiPrincipal) {
            if ($entity instanceof \App\Entity\AuditLog || $entity instanceof \App\Entity\AuditChainHead) { return; }
            $customer = CustomerAccess::customerOf($entity);
            if ($customer === null || (string) $customer->getId() !== (string) $user->customerId) { throw new AccessDeniedException(); }
            return;
        }
        if (!$user instanceof User || $user->hasGlobalAccess()) { return; }
        if ($entity instanceof \App\Entity\AuditLog || $entity instanceof \App\Entity\AuditChainHead) { return; }
        if ($entity instanceof User && (string) $entity->getId() === (string) $user->getId()) { return; }
        if ($entity instanceof User || $entity instanceof \App\Entity\Product || !CustomerAccess::allows($user, $entity)) { throw new AccessDeniedException(); }
    }
}
