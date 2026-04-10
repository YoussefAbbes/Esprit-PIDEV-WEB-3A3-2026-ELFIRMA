<?php

namespace App\Repository;

use App\Entity\Fournisseur;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Fournisseur>
 */
class FournisseurRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Fournisseur::class);
    }

    public function save(Fournisseur $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(Fournisseur $entity, bool $flush = false): void
    {
        $this->getEntityManager()->remove($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function findAllWithContracts(): array
    {
        return $this->createQueryBuilder('f')
            ->leftJoin('f.contrats', 'c')
            ->addSelect('c')
            ->orderBy('f.id_f', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function findByStatus(string $statut): array
    {
        return $this->createQueryBuilder('f')
            ->where('f.statut_f = :statut')
            ->setParameter('statut', $statut)
            ->orderBy('f.type_f', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findByType(string $type): array
    {
        return $this->createQueryBuilder('f')
            ->where('f.type_f = :type')
            ->setParameter('type', $type)
            ->orderBy('f.type_f', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findPaginated(int $page = 1, int $limit = 10, ?string $search = null, string $sortBy = 'id_f', string $sortOrder = 'DESC'): array
    {
        $qb = $this->createQueryBuilder('f')
            ->leftJoin('f.contrats', 'c')
            ->addSelect('c');

        // Search
        if ($search) {
            $qb->andWhere('f.type_f LIKE :search OR f.description_f LIKE :search OR f.adresse_f LIKE :search OR f.email_f LIKE :search OR f.tel_f LIKE :search OR f.statut_f LIKE :search')
               ->setParameter('search', '%' . $search . '%');
        }

        // Sorting
        $allowedSortFields = ['id_f', 'type_f', 'adresse_f', 'email_f', 'tel_f', 'statut_f'];
        if (in_array($sortBy, $allowedSortFields)) {
            $qb->orderBy('f.' . $sortBy, strtoupper($sortOrder) === 'ASC' ? 'ASC' : 'DESC');
        } else {
            $qb->orderBy('f.id_f', 'DESC');
        }

        // Get total count
        $countQb = clone $qb;
        $total = $countQb->select('COUNT(DISTINCT f.id_f)')->getQuery()->getSingleScalarResult();

        // Pagination
        $offset = ($page - 1) * $limit;
        $qb->setFirstResult($offset)->setMaxResults($limit);

        $fournisseurs = $qb->getQuery()->getResult();

        return [
            'data' => $fournisseurs,
            'total' => (int) $total,
            'page' => $page,
            'limit' => $limit,
            'totalPages' => (int) ceil($total / $limit),
        ];
    }

    public function countByStatus(): array
    {
        $result = $this->createQueryBuilder('f')
            ->select('f.statut_f, COUNT(f.id_f) as count')
            ->groupBy('f.statut_f')
            ->getQuery()
            ->getResult();

        return $result;
    }

    public function findActiveSuppliers(): array
    {
        return $this->createQueryBuilder('f')
            ->where('f.statut_f = :statut')
            ->setParameter('statut', 'Actif')
            ->orderBy('f.type_f', 'ASC')
            ->getQuery()
            ->getResult();
    }
}