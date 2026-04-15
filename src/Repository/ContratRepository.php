<?php

namespace App\Repository;

use App\Entity\Contrat;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Contrat>
 */
class ContratRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Contrat::class);
    }

    public function save(Contrat $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(Contrat $entity, bool $flush = false): void
    {
        $this->getEntityManager()->remove($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function findAllWithFournisseur(): array
    {
        return $this->createQueryBuilder('c')
            ->leftJoin('c.fournisseur', 'f')
            ->addSelect('f')
            ->orderBy('c.id_contrat', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function findByFournisseur(int $fournisseurId): array
    {
        return $this->createQueryBuilder('c')
            ->where('c.fournisseur = :fournisseurId')
            ->setParameter('fournisseurId', $fournisseurId)
            ->orderBy('c.date_debut_f', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function findByStatus(string $statut): array
    {
        return $this->createQueryBuilder('c')
            ->leftJoin('c.fournisseur', 'f')
            ->addSelect('f')
            ->where('c.statutC_f = :statut')
            ->setParameter('statut', $statut)
            ->orderBy('c.date_debut_f', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function findByType(string $type): array
    {
        return $this->createQueryBuilder('c')
            ->leftJoin('c.fournisseur', 'f')
            ->addSelect('f')
            ->where('c.typeC_f = :type')
            ->setParameter('type', $type)
            ->orderBy('c.date_debut_f', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function findActiveContracts(): array
    {
        return $this->createQueryBuilder('c')
            ->leftJoin('c.fournisseur', 'f')
            ->addSelect('f')
            ->where('c.statutC_f = :statut')
            ->setParameter('statut', 'Active')
            ->orderBy('c.date_debut_f', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function findExpiringContracts(int $days = 30): array
    {
        $today = new \DateTime();
        $futureDate = (clone $today)->modify('+' . $days . ' days');

        return $this->createQueryBuilder('c')
            ->leftJoin('c.fournisseur', 'f')
            ->addSelect('f')
            ->where('c.date_fin_f BETWEEN :today AND :futureDate')
            ->andWhere('c.statutC_f = :statut')
            ->setParameter('today', $today)
            ->setParameter('futureDate', $futureDate)
            ->setParameter('statut', 'Active')
            ->orderBy('c.date_fin_f', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findExpiredContracts(): array
    {
        $today = new \DateTime();

        return $this->createQueryBuilder('c')
            ->leftJoin('c.fournisseur', 'f')
            ->addSelect('f')
            ->where('c.date_fin_f < :today')
            ->setParameter('today', $today)
            ->orderBy('c.date_fin_f', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function findPaginated(int $page = 1, int $limit = 10, ?string $search = null, ?int $fournisseurId = null, string $sortBy = 'id_contrat', string $sortOrder = 'DESC'): array
    {
        $qb = $this->createQueryBuilder('c')
            ->leftJoin('c.fournisseur', 'f')
            ->addSelect('f');

        // Search by supplier name or type
        if ($search) {
            $qb->andWhere('f.type_f LIKE :search OR f.description_f LIKE :search OR c.typeC_f LIKE :search')
               ->setParameter('search', '%' . $search . '%');
        }

        // Filter by fournisseur
        if ($fournisseurId) {
            $qb->andWhere('c.fournisseur = :fournisseurId')
               ->setParameter('fournisseurId', $fournisseurId);
        }

        // Sorting
        $allowedSortFields = ['id_contrat', 'date_debut_f', 'date_fin_f', 'typeC_f', 'statutC_f'];
        if (in_array($sortBy, $allowedSortFields)) {
            $qb->orderBy('c.' . $sortBy, strtoupper($sortOrder) === 'ASC' ? 'ASC' : 'DESC');
        } else {
            $qb->orderBy('c.id_contrat', 'DESC');
        }

        // Get total count
        $countQb = clone $qb;
        $total = $countQb->select('COUNT(DISTINCT c.id_contrat)')->getQuery()->getSingleScalarResult();

        // Pagination
        $offset = ($page - 1) * $limit;
        $qb->setFirstResult($offset)->setMaxResults($limit);

        $contrats = $qb->getQuery()->getResult();

        return [
            'data' => $contrats,
            'total' => (int) $total,
            'page' => $page,
            'limit' => $limit,
            'totalPages' => (int) ceil($total / $limit),
        ];
    }

    public function countByStatus(): array
    {
        return $this->createQueryBuilder('c')
            ->select('c.statutC_f, COUNT(c.id_contrat) as count')
            ->groupBy('c.statutC_f')
            ->getQuery()
            ->getResult();
    }

    public function countByType(): array
    {
        return $this->createQueryBuilder('c')
            ->select('c.typeC_f, COUNT(c.id_contrat) as count')
            ->groupBy('c.typeC_f')
            ->getQuery()
            ->getResult();
    }
}