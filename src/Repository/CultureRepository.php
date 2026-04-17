<?php

namespace App\Repository;

use App\Entity\Culture;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Culture>
 */
class CultureRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Culture::class);
    }

    public function save(Culture $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(Culture $entity, bool $flush = false): void
    {
        $this->getEntityManager()->remove($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function findAllWithParcelle(): array
    {
        return $this->createQueryBuilder('c')
            ->leftJoin('c.parcelle', 'p')
            ->addSelect('p')
            ->orderBy('c.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function findPaginated(int $page = 1, int $limit = 10, ?string $search = null, string $sortBy = 'id', string $sortOrder = 'DESC'): array
    {
        $qb = $this->createQueryBuilder('c')
            ->leftJoin('c.parcelle', 'p')
            ->addSelect('p');

        // Search
        if ($search) {
            $qb->andWhere('c.nomCulture LIKE :search OR c.variete LIKE :search OR c.statut LIKE :search OR p.nom LIKE :search')
               ->setParameter('search', '%' . $search . '%');
        }

        // Sorting
        $allowedSortFields = ['id', 'nomCulture', 'variete', 'datePlantation', 'quantitePlantee', 'statut'];
        if (in_array($sortBy, $allowedSortFields)) {
            $qb->orderBy('c.' . $sortBy, strtoupper($sortOrder) === 'ASC' ? 'ASC' : 'DESC');
        } elseif ($sortBy === 'parcelle') {
            $qb->orderBy('p.nom', strtoupper($sortOrder) === 'ASC' ? 'ASC' : 'DESC');
        } else {
            $qb->orderBy('c.id', 'DESC');
        }

        // Get total count
        $countQb = clone $qb;
        $total = $countQb->select('COUNT(DISTINCT c.id)')->getQuery()->getSingleScalarResult();

        // Pagination
        $offset = ($page - 1) * $limit;
        $qb->setFirstResult($offset)->setMaxResults($limit);

        $cultures = $qb->getQuery()->getResult();

        return [
            'data' => $cultures,
            'total' => (int) $total,
            'page' => $page,
            'limit' => $limit,
            'totalPages' => (int) ceil($total / $limit),
        ];
    }
}
