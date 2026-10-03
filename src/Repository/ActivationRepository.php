<?php

namespace App\Repository;

use App\Entity\Activation;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Activation>
 */
class ActivationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Activation::class);
    }

    public function getDashboardStatistics(): array
    {
        $result = $this->createQueryBuilder('a')
            ->innerJoin('a.license', 'l')
            ->andWhere('l.deletedAt IS NULL')
            ->select('COUNT(a.id) AS total')
            ->addSelect("
                SUM(
                    CASE
                        WHEN a.active = true
                        THEN 1 ELSE 0
                    END
                ) AS active
            ")
            ->getQuery()
            ->getSingleResult();
    
        return [
            'total' => (int) ($result['total'] ?? 0),
            'active' => (int) ($result['active'] ?? 0),
        ];
    }

//    /**
//     * @return Activation[] Returns an array of Activation objects
//     */
//    public function findByExampleField($value): array
//    {
//        return $this->createQueryBuilder('a')
//            ->andWhere('a.exampleField = :val')
//            ->setParameter('val', $value)
//            ->orderBy('a.id', 'ASC')
//            ->setMaxResults(10)
//            ->getQuery()
//            ->getResult()
//        ;
//    }

//    public function findOneBySomeField($value): ?Activation
//    {
//        return $this->createQueryBuilder('a')
//            ->andWhere('a.exampleField = :val')
//            ->setParameter('val', $value)
//            ->getQuery()
//            ->getOneOrNullResult()
//        ;
//    }
}
