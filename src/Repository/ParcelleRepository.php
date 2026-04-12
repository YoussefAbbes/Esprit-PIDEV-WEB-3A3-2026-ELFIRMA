<?php

namespace App\Repository;

use App\Entity\Parcelle;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Parcelle>
 */
class ParcelleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Parcelle::class);
    }

    public function save(Parcelle $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(Parcelle $entity, bool $flush = false): void
    {
        $this->getEntityManager()->remove($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function findAllWithCultures(): array
    {
        return $this->createQueryBuilder("p")
            ->leftJoin("p.cultures", "c")
            ->addSelect("c")
            ->orderBy("p.id", "DESC")
            ->getQuery()
            ->getResult();
    }

    public function findPaginated(
        int $page = 1,
        int $limit = 10,
        ?string $search = null,
        string $sortBy = "id",
        string $sortOrder = "DESC",
        ?string $statut = null,
        ?string $typeSol = null,
    ): array {
        $qb = $this->createQueryBuilder("p")
            ->leftJoin("p.cultures", "c")
            ->addSelect("c");

        // Search
        if ($search) {
            $qb->andWhere(
                "p.nom LIKE :search OR p.localisation LIKE :search OR p.typeSol LIKE :search OR p.statut LIKE :search",
            )->setParameter("search", "%" . $search . "%");
        }

        // Statut filter
        if ($statut) {
            $qb->andWhere("p.statut = :statut")->setParameter(
                "statut",
                $statut,
            );
        }

        // TypeSol filter
        if ($typeSol) {
            $qb->andWhere("p.typeSol = :typeSol")->setParameter(
                "typeSol",
                $typeSol,
            );
        }

        // Sorting
        $allowedSortFields = [
            "id",
            "nom",
            "localisation",
            "superficie",
            "typeSol",
            "statut",
            "dateCreation",
        ];
        if (in_array($sortBy, $allowedSortFields)) {
            $qb->orderBy(
                "p." . $sortBy,
                strtoupper($sortOrder) === "ASC" ? "ASC" : "DESC",
            );
        } else {
            $qb->orderBy("p.id", "DESC");
        }

        // Get total count
        $countQb = clone $qb;
        $total = $countQb
            ->select("COUNT(DISTINCT p.id)")
            ->getQuery()
            ->getSingleScalarResult();

        // Pagination
        $offset = ($page - 1) * $limit;
        $qb->setFirstResult($offset)->setMaxResults($limit);

        $parcelles = $qb->getQuery()->getResult();

        return [
            "data" => $parcelles,
            "total" => (int) $total,
            "page" => $page,
            "limit" => $limit,
            "totalPages" => (int) ceil($total / $limit),
        ];
    }

    /**
     * Count parcels by their status — used for global stats (not paginated).
     */
    public function countByStatus(string $status): int
    {
        return (int) $this->createQueryBuilder("p")
            ->select("COUNT(p.id)")
            ->where("p.statut = :status")
            ->setParameter("status", $status)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Sum of all parcel areas across the entire database.
     */
    public function getTotalArea(): float
    {
        return (float) ($this->createQueryBuilder("p")
            ->select("SUM(p.superficie)")
            ->getQuery()
            ->getSingleScalarResult() ?? 0.0);
    }
}
