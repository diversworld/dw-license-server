<?php

namespace App\Repository;

use App\Entity\License;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<License>
 */
class LicenseRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, License::class);
    }

    public function getDashboardStatistics(): array
    {
        $now = new \DateTimeImmutable();
        $expiresSoon = $now->modify('+30 days');
    
        $result = $this->createQueryBuilder('l')
            ->select('COUNT(l.id) AS total')
    
            ->addSelect("
                SUM(
                    CASE
                        WHEN l.status = 'active'
                        AND (
                            l.expiresAt IS NULL
                            OR l.expiresAt > :now
                        )
                        THEN 1 ELSE 0
                    END
                ) AS active
            ")
    
            ->addSelect("
                SUM(
                    CASE
                        WHEN l.status = 'suspended'
                        THEN 1 ELSE 0
                    END
                ) AS suspended
            ")
    
            ->addSelect("
                SUM(
                    CASE
                        WHEN l.status = 'revoked'
                        THEN 1 ELSE 0
                    END
                ) AS revoked
            ")
    
            ->addSelect("
                SUM(
                    CASE
                        WHEN l.expiresAt IS NOT NULL
                        AND l.expiresAt <= :now
                        THEN 1 ELSE 0
                    END
                ) AS expired
            ")
    
            ->addSelect("
                SUM(
                    CASE
                        WHEN l.status = 'active'
                        AND l.expiresAt > :now
                        AND l.expiresAt <= :expiresSoon
                        THEN 1 ELSE 0
                    END
                ) AS expiring
            ")
    
            ->setParameter('now', $now)
            ->setParameter('expiresSoon', $expiresSoon)
            ->getQuery()
            ->getSingleResult();
    
        return array_map(
            static fn ($value): int => (int) ($value ?? 0),
            $result
        );
    }

    /**
     * Liefert die Lizenzstatistik gruppiert nach Modul/Produkt.
     */
    public function getStatisticsByProduct(): array
    {
        $now = new \DateTimeImmutable();
 
        return $this->createQueryBuilder('l')
            ->select('p.id AS productId')
            ->addSelect('p.name AS productName')
            ->addSelect('p.slug AS productSlug')
            ->addSelect('p.active AS productActive')
            ->addSelect('(SELECT COUNT(a.id) FROM App\Entity\Activation a JOIN a.license activationLicense WHERE activationLicense.product = p AND a.active = true) AS activationCount')
 
            ->addSelect('COUNT(l.id) AS total')
 
            ->addSelect(
                "SUM(
                    CASE
                        WHEN l.status = 'active'
                        AND (l.expiresAt IS NULL OR l.expiresAt > :now)
                        THEN 1
                        ELSE 0
                    END
                ) AS active"
            )
 
            ->addSelect(
                "SUM(
                    CASE
                        WHEN l.status = 'suspended'
                        THEN 1
                        ELSE 0
                    END
                ) AS suspended"
            )
 
            ->addSelect(
                "SUM(
                    CASE
                        WHEN l.status = 'revoked'
                        THEN 1
                        ELSE 0
                    END
                ) AS revoked"
            )
 
            ->addSelect(
                "SUM(
                    CASE
                        WHEN l.expiresAt IS NOT NULL
                        AND l.expiresAt <= :now
                        THEN 1
                        ELSE 0
                    END
                ) AS expired"
            )
 
            ->innerJoin('l.product', 'p')
            ->setParameter('now', $now)
            ->groupBy('p.id')
            ->addGroupBy('p.name')
            ->addGroupBy('p.slug')
            ->addGroupBy('p.active')
            ->orderBy('p.name', 'ASC')
            ->getQuery()
            ->getArrayResult();
    }
 
    /**
     * Liefert die Gesamtstatistik aller Lizenzen.
     */
    public function getTotalStatistics(): array
    {
        $now = new \DateTimeImmutable();
 
        $result = $this->createQueryBuilder('l')
            ->select('COUNT(l.id) AS total')
 
            ->addSelect(
                "SUM(
                    CASE
                        WHEN l.status = 'active'
                        AND (l.expiresAt IS NULL OR l.expiresAt > :now)
                        THEN 1
                        ELSE 0
                    END
                ) AS active"
            )
 
            ->addSelect(
                "SUM(
                    CASE
                        WHEN l.status = 'suspended'
                        THEN 1
                        ELSE 0
                    END
                ) AS suspended"
            )
 
            ->addSelect(
                "SUM(
                    CASE
                        WHEN l.status = 'revoked'
                        THEN 1
                        ELSE 0
                    END
                ) AS revoked"
            )
 
            ->addSelect(
                "SUM(
                    CASE
                        WHEN l.expiresAt IS NOT NULL
                        AND l.expiresAt <= :now
                        THEN 1
                        ELSE 0
                    END
                ) AS expired"
            )
 
            ->setParameter('now', $now)
            ->getQuery()
            ->getSingleResult();
 
        return [
            'total' => (int) ($result['total'] ?? 0),
            'active' => (int) ($result['active'] ?? 0),
            'suspended' => (int) ($result['suspended'] ?? 0),
            'revoked' => (int) ($result['revoked'] ?? 0),
            'expired' => (int) ($result['expired'] ?? 0),
        ];
    }

//    /**
//     * @return License[] Returns an array of License objects
//     */
//    public function findByExampleField($value): array
//    {
//        return $this->createQueryBuilder('l')
//            ->andWhere('l.exampleField = :val')
//            ->setParameter('val', $value)
//            ->orderBy('l.id', 'ASC')
//            ->setMaxResults(10)
//            ->getQuery()
//            ->getResult()
//        ;
//    }

//    public function findOneBySomeField($value): ?License
//    {
//        return $this->createQueryBuilder('l')
//            ->andWhere('l.exampleField = :val')
//            ->setParameter('val', $value)
//            ->getQuery()
//            ->getOneOrNullResult()
//        ;
//    }
}
