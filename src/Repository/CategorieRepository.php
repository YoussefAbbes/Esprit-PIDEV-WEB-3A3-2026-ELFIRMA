<?php

namespace App\Repository;

use App\Entity\Categorie;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Categorie>
 */
class CategorieRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Categorie::class);
    }

    /**
     * @return Categorie[] Returns an array of Categorie objects
     */
    public function findAll(): array
    {
        return $this->findBy([], ['nom' => 'ASC']);
    }

    /**
     * Find categories with product count
     */
    public function findAllWithProductCount(): array
    {
        return $this->createQueryBuilder('c')
            ->leftJoin('c.produits', 'p')
            ->addSelect('COUNT(p.id) as productCount')
            ->groupBy('c.id')
            ->orderBy('c.nom', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
