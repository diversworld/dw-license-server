<?php

namespace App\Service;

final class LicenseTransitions
{
    public const array ACTIONS = ['renew', 'pause', 'revoke', 'reactivate', 'withdraw_revocation'];
    public const array ROUTE_REQUIREMENTS = ['action' => 'renew|pause|revoke|reactivate|withdraw_revocation'];
    public const array TARGETS = [
        'renew' => ['active' => 'active', 'suspended' => 'suspended', 'revoked' => 'revoked'],
        'pause' => ['active' => 'suspended'],
        'revoke' => ['active' => 'revoked', 'suspended' => 'revoked'],
        'reactivate' => ['suspended' => 'active'],
        'withdraw_revocation' => ['revoked' => 'active'],
    ];

    public static function target(string $action, string $status, bool $expired): string
    {
        $target = self::TARGETS[$action][$status] ?? null;
        if ($target === null || ($target === 'active' && $expired && $action !== 'renew')) {
            throw new \DomainException('license_action.invalid_transition');
        }

        return $target;
    }
}
