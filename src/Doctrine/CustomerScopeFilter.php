<?php

namespace App\Doctrine;

use App\Entity\{Customer, License, Activation, LicenseAction, ApiToken, AuditLog, LogEntry};
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Query\Filter\SQLFilter;

final class CustomerScopeFilter extends SQLFilter
{
    public function addFilterConstraint(ClassMetadata $targetEntity, string $targetTableAlias): string
    {
        $ids = explode(',', trim($this->getParameter('customers'), "'"));
        $ids = array_values(array_filter($ids, static fn (string $id): bool => (bool) preg_match('/^[a-f0-9]{32}$/D', $id)));
        $binary = $ids === [] ? 'NULL' : implode(',', array_map(static fn (string $id): string => "X'".$id."'", $ids));
        if ($this->getConnection()->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\SQLitePlatform && $ids !== []) { $binary = implode(',', array_map(static fn (string $id): string => "CAST(X'".$id."' AS TEXT)", $ids)); }
        return match ($targetEntity->name) {
            Customer::class => $targetTableAlias.'.id IN ('.$binary.')',
            License::class, ApiToken::class => $targetTableAlias.'.customer_id IN ('.$binary.')',
            Activation::class, LicenseAction::class => $targetTableAlias.'.license_id IN (SELECT scope_license.id FROM license scope_license WHERE scope_license.customer_id IN ('.$binary.'))',
            AuditLog::class => $this->auditConstraint($targetTableAlias, $ids),
            LogEntry::class => '1 = 0',
            default => '',
        };
    }

    private function auditConstraint(string $alias, array $ids): string
    {
        if ($ids === []) { return '1 = 0'; }
        $strings = implode(',', array_map(static fn (string $id): string => "'".\Symfony\Component\Uid\Uuid::fromBinary(hex2bin($id))->toRfc4122()."'", $ids));
        // Unknown legacy ownership stays visible only to global administrators; no historical rewrite.
        $expression = "JSON_EXTRACT(".$alias.".context, '$.customerId')";
        if (!$this->getConnection()->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\SQLitePlatform) { $expression = 'JSON_UNQUOTE('.$expression.')'; }
        return $expression.' IN ('.$strings.')';
    }
}
