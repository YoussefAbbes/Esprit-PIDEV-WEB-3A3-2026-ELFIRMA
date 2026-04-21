<?php

namespace App\Repository;

use App\Entity\Maintenance;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class MaintenanceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Maintenance::class);
    }

    /**
     * 🔹 Récupérer toutes les maintenances triées par date (récentes en premier)
     */
    public function findAllOrderedByDate(): array
    {
        return $this->createQueryBuilder('m')
            ->orderBy('m.dateM', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * 🔹 Trouver les maintenances d’un équipement spécifique
     */
    public function findByEquipement(int $equipementId): array
    {
        return $this->createQueryBuilder('m')
            ->join('m.equipement', 'e')
            ->where('e.idEq = :id')
            ->setParameter('id', $equipementId)
            ->orderBy('m.dateM', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * 🔹 Filtrer par statut
     */
    public function findByStatut(string $statut): array
    {
        return $this->createQueryBuilder('m')
            ->where('m.statut = :statut')
            ->setParameter('statut', $statut)
            ->orderBy('m.dateM', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * 🔹 Filtrer par priorité
     */
    public function findByPriorite(string $priorite): array
    {
        return $this->createQueryBuilder('m')
            ->where('m.priorite = :priorite')
            ->setParameter('priorite', $priorite)
            ->orderBy('m.dateM', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * 🔹 Rechercher par type ou description
     */
    public function search(string $term): array
    {
        return $this->createQueryBuilder('m')
            ->where('m.typeM LIKE :term')
            ->orWhere('m.description LIKE :term')
            ->setParameter('term', '%' . $term . '%')
            ->orderBy('m.dateM', 'DESC')
            ->getQuery()
            ->getResult();
    }
}