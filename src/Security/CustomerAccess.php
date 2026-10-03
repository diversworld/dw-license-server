<?php

namespace App\Security;

use App\Entity\{User, Customer, License, Activation, LicenseAction, ApiToken};

final class CustomerAccess
{
    public static function customerOf(object $entity): ?Customer
    {
        return match (true) {
            $entity instanceof Customer => $entity,
            $entity instanceof \App\Entity\ApiOperation => $entity->getCredential()->getCustomer(),
            $entity instanceof \App\Entity\WebhookEndpoint => $entity->getCustomer(),
            $entity instanceof \App\Entity\WebhookDelivery => $entity->getEndpoint()->getCustomer(),
            $entity instanceof License, $entity instanceof ApiToken => $entity->getCustomer(),
            $entity instanceof \App\Entity\ReminderDelivery, $entity instanceof Activation, $entity instanceof LicenseAction => $entity->getLicense()?->getCustomer(),
            default => null,
        };
    }

    public static function allows(User $user, object $entity): bool
    {
        if ($user->hasGlobalAccess()) { return true; }
        if ($entity instanceof \App\Entity\AuditLog) {
            foreach ($user->getCustomers() as $assigned) { if ((string) $assigned->getId() === ($entity->getContext()['customerId'] ?? null)) { return true; } }
            return false;
        }
        if ($entity instanceof \App\Entity\LogEntry) { return false; }
        $customer = self::customerOf($entity);
        if ($customer === null) { return !($entity instanceof License || $entity instanceof ApiToken); }
        foreach ($user->getCustomers() as $assigned) {
            if ((string) $assigned->getId() === (string) $customer->getId()) { return true; }
        }
        return false;
    }
}
