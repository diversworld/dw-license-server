<?php

declare(strict_types=1);

namespace App\Audit;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\PersistentCollection;

final class AuditChanges
{
    public function capture(object $entity, EntityManagerInterface $em, bool $deleted = false): array
    {
        $metadata = $em->getClassMetadata($entity::class);
        if ($deleted) {
            $changeSet = [];
            foreach (array_merge($metadata->getFieldNames(), $metadata->getAssociationNames()) as $field) {
                if (!$metadata->isCollectionValuedAssociation($field)) {
                    $changeSet[$field] = [$metadata->getFieldValue($entity, $field), null];
                }
            }
        } else {
            $changeSet = $em->getUnitOfWork()->getEntityChangeSet($entity);
        }

        $changes = [];
        foreach ($changeSet as $field => [$old, $new]) {
            if ($old instanceof PersistentCollection || $new instanceof PersistentCollection) {
                continue;
            }
            $changes[$field] = [
                'old' => $this->normalize($old, $field, $em),
                'new' => $this->normalize($new, $field, $em),
            ];
        }

        return $changes;
    }

    private function normalize(mixed $value, string $field, EntityManagerInterface $em): mixed
    {
        if (preg_match('/password|token|secret|licensekey|selector|backupcode|recoverycode/i', $field)) {
            return $value === null ? null : '[redacted]';
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d\TH:i:s.uP');
        }
        if ($value instanceof \BackedEnum) {
            return $value->value;
        }
        if ($value instanceof \UnitEnum) {
            return $value->name;
        }
        if (is_array($value)) {
            $normalized = [];
            foreach ($value as $key => $item) {
                $normalized[$key] = $this->normalize($item, (string) $key, $em);
            }

            return $normalized;
        }
        if (is_object($value) && !$em->getMetadataFactory()->isTransient($value::class)) {
            $metadata = $em->getClassMetadata($value::class);

            return [
                'type' => $metadata->getTableName(),
                'id' => array_map(static fn (mixed $id): string => (string) $id, $metadata->getIdentifierValues($value)),
            ];
        }
        if ($value instanceof \Stringable) {
            return (string) $value;
        }

        return is_scalar($value) || $value === null ? $value : '[unsupported]';
    }
}
