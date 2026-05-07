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

    public function findAllWithCultures(?int $limit = null): array
    {
        // Avoid setMaxResults() on a fetch-joined collection to prevent incomplete collections.
        if ($limit !== null && $limit > 0) {
            $idRows = $this->createQueryBuilder('p')
                ->select('p.id')
                ->orderBy('p.id', 'DESC')
                ->setMaxResults($limit)
                ->getQuery()
                ->getScalarResult();

            $ids = array_map(
                static fn (array $row): int => (int) $row['id'],
                $idRows,
            );

            if ($ids === []) {
                return [];
            }

            $parcelles = $this->createQueryBuilder('p')
                ->leftJoin('p.cultures', 'c')
                ->addSelect('c')
                ->andWhere('p.id IN (:ids)')
                ->setParameter('ids', $ids)
                ->getQuery()
                ->getResult();

            return $this->sortParcellesByIds($parcelles, $ids);
        }

        return $this->createQueryBuilder('p')
            ->leftJoin('p.cultures', 'c')
            ->addSelect('c')
            ->orderBy('p.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return array{total:int, available:int, occupied:int, resting:int, totalArea:float}
     */
    public function getClientStats(): array
    {
        /** @var array{total:mixed, available:mixed, occupied:mixed, resting:mixed, totalArea:mixed}|null $result */
        $result = $this->createQueryBuilder('p')
            ->select('COUNT(p.id) AS total')
            ->addSelect("SUM(CASE WHEN p.statut = 'Available' THEN 1 ELSE 0 END) AS available")
            ->addSelect("SUM(CASE WHEN p.statut = 'Occupied' THEN 1 ELSE 0 END) AS occupied")
            ->addSelect("SUM(CASE WHEN p.statut = 'Resting' THEN 1 ELSE 0 END) AS resting")
            ->addSelect('COALESCE(SUM(p.superficie), 0) AS totalArea')
            ->getQuery()
            ->getOneOrNullResult();

        return [
            'total' => (int) ($result['total'] ?? 0),
            'available' => (int) ($result['available'] ?? 0),
            'occupied' => (int) ($result['occupied'] ?? 0),
            'resting' => (int) ($result['resting'] ?? 0),
            'totalArea' => (float) ($result['totalArea'] ?? 0.0),
        ];
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
        $baseQb = $this->createQueryBuilder("p");

        // Search
        if ($search) {
            $baseQb->andWhere(
                "p.nom LIKE :search OR p.localisation LIKE :search OR p.typeSol LIKE :search OR p.statut LIKE :search",
            )->setParameter("search", "%" . $search . "%");
        }

        // Statut filter
        if ($statut) {
            $baseQb->andWhere("p.statut = :statut")->setParameter(
                "statut",
                $statut,
            );
        }

        // TypeSol filter
        if ($typeSol) {
            $baseQb->andWhere("p.typeSol = :typeSol")->setParameter(
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
        $direction = strtoupper($sortOrder) === "ASC" ? "ASC" : "DESC";
        if (in_array($sortBy, $allowedSortFields, true)) {
            $baseQb->orderBy("p." . $sortBy, $direction)->addOrderBy("p.id", "DESC");
        } else {
            $baseQb->orderBy("p.id", "DESC");
        }

        // Get total count
        $countQb = clone $baseQb;
        $total = $countQb
            ->select("COUNT(p.id)")
            ->getQuery()
            ->getSingleScalarResult();

        // Paginate IDs first, then fetch-join cultures in a second query.
        $offset = ($page - 1) * $limit;

        $idRows = (clone $baseQb)
            ->select("p.id")
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->getQuery()
            ->getScalarResult();

        $ids = array_map(
            static fn (array $row): int => (int) $row["id"],
            $idRows,
        );

        if ($ids === []) {
            return [
                "data" => [],
                "total" => (int) $total,
                "page" => $page,
                "limit" => $limit,
                "totalPages" => (int) ceil($total / $limit),
            ];
        }

        $dataQb = $this->createQueryBuilder("p")
            ->leftJoin("p.cultures", "c")
            ->addSelect("c")
            ->andWhere("p.id IN (:ids)")
            ->setParameter("ids", $ids);

        $parcelles = $this->sortParcellesByIds($dataQb->getQuery()->getResult(), $ids);

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

    /**
     * @param Parcelle[] $parcelles
     * @param int[] $ids
     *
     * @return Parcelle[]
     */
    private function sortParcellesByIds(array $parcelles, array $ids): array
    {
        $positions = array_flip($ids);

        usort(
            $parcelles,
            static function (Parcelle $left, Parcelle $right) use ($positions): int {
                $leftPosition = $positions[$left->getId() ?? -1] ?? PHP_INT_MAX;
                $rightPosition = $positions[$right->getId() ?? -1] ?? PHP_INT_MAX;

                return $leftPosition <=> $rightPosition;
            },
        );

        return $parcelles;
    }
}
