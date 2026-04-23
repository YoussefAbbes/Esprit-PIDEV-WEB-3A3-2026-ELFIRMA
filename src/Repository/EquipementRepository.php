<?php

namespace App\Repository;

use App\Entity\Equipement;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class EquipementRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Equipement::class);
    }

    /**
     * 🔹 Récupérer tous les équipements triés par date d'achat (récent → ancien)
     */
    public function findAllOrderedByDate(): array
    {
        return $this->createQueryBuilder('e')
            ->orderBy('e.dateAchat', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * 🔹 Recherche par nom ou type
     */
    public function search(string $term): array
    {
        return $this->createQueryBuilder('e')
            ->where('e.nomEq LIKE :term')
            ->orWhere('e.typeEq LIKE :term')
            ->setParameter('term', '%' . $term . '%')
            ->orderBy('e.nomEq', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * 🔹 Filtrer par état (ex: "Opérationnel", "En réparation")
     */
    public function findByEtat(string $etat): array
    {
        return $this->createQueryBuilder('e')
            ->where('e.etat = :etat')
            ->setParameter('etat', $etat)
            ->getQuery()
            ->getResult();
    }

    /**
     * 🔹 Récupérer les équipements avec coût supérieur à une valeur
     */
    public function findByCoutMin(float $cout): array
    {
        return $this->createQueryBuilder('e')
            ->where('e.coutAchat >= :cout')
            ->setParameter('cout', $cout)
            ->orderBy('e.coutAchat', 'DESC')
            ->getQuery()
            ->getResult();
    }
}