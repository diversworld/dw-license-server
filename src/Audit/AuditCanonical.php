<?php

declare(strict_types=1);

namespace App\Audit;

use App\Entity\AuditLog;

final class AuditCanonical
{
    public static function mask(array $data): array
    {
        foreach ($data as $key => $value) {
            if (preg_match('/password|token|secret|licensekey|selector|backupcode|recoverycode|authorization|cookie/i', (string) $key)) {
                $data[$key] = is_array($value) && array_key_exists('old', $value) && array_key_exists('new', $value)
                    ? ['old' => $value['old'] === null ? null : '[redacted]', 'new' => $value['new'] === null ? null : '[redacted]']
                    : ($value === null ? null : '[redacted]');
            } elseif (is_array($value)) {
                $data[$key] = self::mask($value);
            }
        }

        return $data;
    }

    public static function hash(AuditLog $log): string
    {
        if ($log->getHashVersion() === 1) {
            return hash('sha256', ($log->getPreviousHash() ?? '').$log->getEventType().$log->getEntityType().$log->getEntityIdentifier().json_encode($log->getContext(), JSON_THROW_ON_ERROR));
        }
        if ($log->getHashVersion() !== 2) {
            throw new \UnexpectedValueException('Unsupported audit hash version '.$log->getHashVersion());
        }

        return hash('sha256', self::json([
            'version' => 2, 'sequence' => $log->getChainSequence(), 'id' => (string) $log->getId(),
            'createdAt' => $log->getCreatedAt()->format('Y-m-d H:i:s'),
            'actor' => $log->getActorIdentity(), 'eventType' => $log->getEventType(),
            'entityType' => $log->getEntityType(), 'entityIdentifier' => $log->getEntityIdentifier(),
            'entityId' => $log->getEntityId(), 'message' => $log->getMessage(), 'context' => $log->getContext(),
            'ipAddress' => $log->getIpAddress(), 'userAgent' => $log->getUserAgent(), 'previousHash' => $log->getPreviousHash(),
        ]));
    }

    public static function json(array $data): string
    {
        $sort = static function (array $value) use (&$sort): array {
            if (!array_is_list($value)) { ksort($value, SORT_STRING); }
            foreach ($value as $key => $item) { if (is_array($item)) { $value[$key] = $sort($item); } }
            return $value;
        };

        return json_encode($sort($data), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    }
}
