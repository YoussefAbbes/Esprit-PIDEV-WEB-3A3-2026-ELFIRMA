<?php

namespace App\Repository;

use App\Entity\Reclamation;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Reclamation>
 */
class ReclamationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Reclamation::class);
    }

    public function save(Reclamation $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(Reclamation $entity, bool $flush = false): void
    {
        $this->getEntityManager()->remove($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function findAllWithUtilisateur(): array
    {
        return $this->createQueryBuilder('r')
            ->leftJoin('r.utilisateur', 'u')
            ->addSelect('u')
            ->orderBy('r.idr_u', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function findByUtilisateur(int $utilisateurId): array
    {
        return $this->createQueryBuilder('r')
            ->where('r.utilisateur = :utilisateurId')
            ->setParameter('utilisateurId', $utilisateurId)
            ->orderBy('r.date_reclamation_u', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function findByStatus(string $statut): array
    {
        return $this->createQueryBuilder('r')
            ->leftJoin('r.utilisateur', 'u')
            ->addSelect('u')
            ->where('r.statut_u = :statut')
            ->setParameter('statut', $statut)
            ->orderBy('r.date_reclamation_u', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function findByType(string $type): array
    {
        return $this->createQueryBuilder('r')
            ->leftJoin('r.utilisateur', 'u')
            ->addSelect('u')
            ->where('r.type_reclamation_u = :type')
            ->setParameter('type', $type)
            ->orderBy('r.date_reclamation_u', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function findRecentReclamations(int $days = 30): array
    {
        $date = new \DateTime();
        $date->modify('-' . $days . ' days');

        return $this->createQueryBuilder('r')
            ->leftJoin('r.utilisateur', 'u')
            ->addSelect('u')
            ->where('r.date_reclamation_u >= :date')
            ->setParameter('date', $date)
            ->orderBy('r.date_reclamation_u', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function findOpenReclamations(): array
    {
        return $this->createQueryBuilder('r')
            ->leftJoin('r.utilisateur', 'u')
            ->addSelect('u')
            ->where('r.statut_u != :resolved')
            ->setParameter('resolved', 'Resolved')
            ->orderBy('r.date_reclamation_u', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function findResolvedReclamations(): array
    {
        return $this->createQueryBuilder('r')
            ->leftJoin('r.utilisateur', 'u')
            ->addSelect('u')
            ->where('r.statut_u = :resolved')
            ->setParameter('resolved', 'Resolved')
            ->orderBy('r.date_reclamation_u', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function findPaginated(int $page = 1, int $limit = 10, ?string $search = null, ?string $type = null, ?string $statut = null, string $sortBy = 'idr_u', string $sortOrder = 'DESC'): array
    {
        $qb = $this->createQueryBuilder('r')
            ->leftJoin('r.utilisateur', 'u')
            ->addSelect('u');

        // Search by title or description
        if ($search) {
            $qb->andWhere('r.titre_u LIKE :search OR r.description_u LIKE :search')
               ->setParameter('search', '%' . $search . '%');
        }

        // Filter by type
        if ($type) {
            $qb->andWhere('r.type_reclamation_u = :type')
               ->setParameter('type', $type);
        }

        // Filter by status
        if ($statut) {
            $qb->andWhere('r.statut_u = :statut')
               ->setParameter('statut', $statut);
        }

        // Sorting
        $allowedSortFields = ['idr_u', 'titre_u', 'type_reclamation_u', 'date_reclamation_u', 'statut_u'];
        if (in_array($sortBy, $allowedSortFields)) {
            $qb->orderBy('r.' . $sortBy, strtoupper($sortOrder) === 'ASC' ? 'ASC' : 'DESC');
        } else {
            $qb->orderBy('r.idr_u', 'DESC');
        }

        // Get total count
        $countQb = clone $qb;
        $total = $countQb->select('COUNT(DISTINCT r.idr_u)')->getQuery()->getSingleScalarResult();

        // Pagination
        $offset = ($page - 1) * $limit;
        $qb->setFirstResult($offset)->setMaxResults($limit);

        $reclamations = $qb->getQuery()->getResult();

        return [
            'data' => $reclamations,
            'total' => (int) $total,
            'page' => $page,
            'limit' => $limit,
            'totalPages' => (int) ceil($total / $limit),
        ];
    }

    public function countByStatus(): array
    {
        return $this->createQueryBuilder('r')
            ->select('r.statut_u, COUNT(r.idr_u) as count')
            ->groupBy('r.statut_u')
            ->getQuery()
            ->getResult();
    }

    public function countByType(): array
    {
        return $this->createQueryBuilder('r')
            ->select('r.type_reclamation_u, COUNT(r.idr_u) as count')
            ->groupBy('r.type_reclamation_u')
            ->getQuery()
            ->getResult();
    }

    public function countByUtilisateur(): array
    {
        return $this->createQueryBuilder('r')
            ->select('u.id_u, u.nom_u, COUNT(r.idr_u) as count')
            ->leftJoin('r.utilisateur', 'u')
            ->groupBy('u.id_u', 'u.nom_u')
            ->orderBy('count', 'DESC')
            ->getQuery()
            ->getResult();
    }
}