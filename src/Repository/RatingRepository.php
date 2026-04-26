<?php

namespace App\Repository;

use App\Entity\Rating;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Rating>
 */
class RatingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Rating::class);
    }

    /**
     * Find ratings for a supplier ordered by newest first
     */
    public function findBySupplierOrderedByDate(int $supplierId)
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.fournisseur = :supplierId')
            ->setParameter('supplierId', $supplierId)
            ->orderBy('r.created_at', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Get average rating for a supplier
     */
    public function getAverageRating(int $supplierId): ?float
    {
        $result = $this->createQueryBuilder('r')
            ->select('AVG(r.number_of_stars) as avg_rating')
            ->andWhere('r.fournisseur = :supplierId')
            ->setParameter('supplierId', $supplierId)
            ->getQuery()
            ->getOneOrNullResult();

        return $result['avg_rating'] ? (float) $result['avg_rating'] : null;
    }

    /**
     * Get rating statistics for a supplier (count by star level)
     */
    public function getRatingStats(int $supplierId): array
    {
        $results = $this->createQueryBuilder('r')
            ->select('r.number_of_stars as stars, COUNT(r.id_rating) as count')
            ->andWhere('r.fournisseur = :supplierId')
            ->setParameter('supplierId', $supplierId)
            ->groupBy('r.number_of_stars')
            ->orderBy('r.number_of_stars', 'DESC')
            ->getQuery()
            ->getResult();

        return $results;
    }

    /**
     * Get total rating count for a supplier
     */
    public function countBySupplier(int $supplierId): int
    {
        return (int) $this->createQueryBuilder('r')
            ->select('COUNT(r.id_rating)')
            ->andWhere('r.fournisseur = :supplierId')
            ->setParameter('supplierId', $supplierId)
            ->getQuery()
            ->getSingleScalarResult();
    }
}