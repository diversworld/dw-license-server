<?php

namespace App\Security;

final class RoleCatalog
{
    public const array CHOICES = ['role.super_admin' => 'ROLE_SUPER_ADMIN', 'role.admin' => 'ROLE_ADMIN',
        'role.support' => 'ROLE_SUPPORT', 'role.sales' => 'ROLE_SALES', 'role.viewer' => 'ROLE_VIEWER'];
    public const array ALLOWED = ['ROLE_SUPER_ADMIN', 'ROLE_ADMIN', 'ROLE_SUPPORT', 'ROLE_SALES', 'ROLE_VIEWER', 'ROLE_USER'];
    public const array HIERARCHY = [
        'ROLE_VIEWER' => ['ROLE_USER'],
        'ROLE_SUPPORT' => ['ROLE_VIEWER', 'ROLE_LICENSE_OPERATE', 'ROLE_ACTIVATION_MANAGE'],
        'ROLE_SALES' => ['ROLE_VIEWER', 'ROLE_CUSTOMER_MANAGE', 'ROLE_LICENSE_SELL'],
        'ROLE_ADMIN' => ['ROLE_SUPPORT', 'ROLE_SALES', 'ROLE_PRODUCT_MANAGE', 'ROLE_USER_MANAGE', 'ROLE_LICENSE_REVOKE'],
        'ROLE_SUPER_ADMIN' => ['ROLE_ADMIN'],
    ];

    public static function choices(bool $superAdministrator): array
    {
        return $superAdministrator ? self::CHOICES : array_diff(self::CHOICES, ['ROLE_SUPER_ADMIN']);
    }
}
