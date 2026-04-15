<?php

namespace App\Repository;

use App\Entity\Produit;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Produit>
 */
class ProduitRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Produit::class);
    }

    /**
     * @return Produit[] Returns an array of Produit objects
     */
    public function findAll(): array
    {
        return $this->findBy([], ['nom' => 'ASC']);
    }

    /**
     * Find products with low stock (less than given quantity)
     */
    public function findLowStock(int $threshold = 20): array
    {
        return $this->createQueryBuilder('p')
            ->where('p.quantite_stock < :threshold')
            ->setParameter('threshold', $threshold)
            ->orderBy('p.quantite_stock', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find products by category
     */
    public function findByCategorie(int $categorieId): array
    {
        return $this->createQueryBuilder('p')
            ->leftJoin('p.categorie', 'c')
            ->where('c.id = :categorieId')
            ->setParameter('categorieId', $categorieId)
            ->orderBy('p.nom', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find products by status
     */
    public function findByStatut(string $statut): array
    {
        return $this->createQueryBuilder('p')
            ->where('p.statut = :statut')
            ->setParameter('statut', $statut)
            ->orderBy('p.nom', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
