<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AuditLog;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Type;
use Doctrine\Persistence\ManagerRegistry;
use SortDirection;

final class AuditLogRepository extends ServiceEntityRepository
{
    public function __construct(
        ManagerRegistry $registry
    ) {
        parent::__construct(
            $registry,
            AuditLog::class
        );
    }

    public function save(
        AuditLog $entity,
        bool $flush = true
    ): void {
        $this->getEntityManager()->persist($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function getLastHash(): ?string
    {
        return $this->createQueryBuilder('a')
            ->select('a.entryHash')
            ->orderBy('a.createdAt', SortDirection::Descending)
            ->addOrderBy('a.id', SortDirection::Descending)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult()['entryHash'] ?? null;
    }

    /** Writes inside the caller's transaction without re-entering ORM flush(). */
    public function append(AuditLog $log): void
    {
        $em = $this->getEntityManager();
        $connection = $em->getConnection();
        $metadata = $em->getClassMetadata(AuditLog::class);
        $data = [];
        $types = [];

        foreach ($metadata->getFieldNames() as $field) {
            $type = Type::getType($metadata->getTypeOfField($field));
            $column = $metadata->getColumnName($field);
            $data[$column] = $type->convertToDatabaseValue($metadata->getFieldValue($log, $field), $connection->getDatabasePlatform());
            $types[$column] = $type->getBindingType();
        }

        $association = $metadata->getAssociationMapping('performedBy');
        $column = $association->joinColumns[0]->name;
        $userMetadata = $em->getClassMetadata(User::class);
        $type = Type::getType($userMetadata->getTypeOfField('id'));
        $data[$column] = $type->convertToDatabaseValue($log->getPerformedBy()?->getId(), $connection->getDatabasePlatform());
        $types[$column] = $type->getBindingType();

        $connection->insert($metadata->getTableName(), $data, $types);
    }
}
