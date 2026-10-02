<?php

namespace App\Repository;

use App\Entity\License;
use App\Entity\LicenseAuditLog;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class LicenseAuditLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LicenseAuditLog::class);
    }

    /**
     * @return LicenseAuditLog[]
     */
    public function findLatest(int $limit = 100): array
    {
        return $this->createQueryBuilder('l')
            ->orderBy('l.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return LicenseAuditLog[]
     */
    public function findByLicense(License $license): array
    {
        return $this->createQueryBuilder('l')
            ->andWhere('l.license = :license')
            ->setParameter('license', $license)
            ->orderBy('l.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return LicenseAuditLog[]
     */
    public function findByEventType(string $eventType): array
    {
        return $this->createQueryBuilder('l')
            ->andWhere('l.eventType = :eventType')
            ->setParameter('eventType', $eventType)
            ->orderBy('l.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function save(LicenseAuditLog $entity, bool $flush = true): void
    {
        $this->getEntityManager()->persist($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(LicenseAuditLog $entity, bool $flush = true): void
    {
        $this->getEntityManager()->remove($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}