<?php

namespace App\Repository;

use App\Entity\Utilisateur;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Utilisateur>
 */
class UtilisateurRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Utilisateur::class);
    }

    public function save(Utilisateur $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(Utilisateur $entity, bool $flush = false): void
    {
        $this->getEntityManager()->remove($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function findAllWithReclamations(): array
    {
        return $this->createQueryBuilder('u')
            ->leftJoin('u.reclamations', 'r')
            ->addSelect('r')
            ->orderBy('u.id_u', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function findByRole(string $role): array
    {
        return $this->createQueryBuilder('u')
            ->where('u.role_u = :role')
            ->setParameter('role', $role)
            ->orderBy('u.nom_u', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findByEmail(string $email): ?Utilisateur
    {
        return $this->createQueryBuilder('u')
            ->where('u.email_u = :email')
            ->setParameter('email', $email)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findEmployees(): array
    {
        return $this->createQueryBuilder('u')
            ->where('u.role_u = :role')
            ->setParameter('role', 'employee')
            ->orderBy('u.nom_u', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findClients(): array
    {
        return $this->createQueryBuilder('u')
            ->where('u.role_u = :role')
            ->setParameter('role', 'client')
            ->orderBy('u.nom_u', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findRecentUsers(int $days = 30): array
    {
        $date = new \DateTime();
        $date->modify('-' . $days . ' days');

        return $this->createQueryBuilder('u')
            ->where('u.date_creation_u >= :date')
            ->setParameter('date', $date)
            ->orderBy('u.date_creation_u', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function findPaginated(int $page = 1, int $limit = 10, ?string $search = null, ?string $role = null, string $sortBy = 'id_u', string $sortOrder = 'DESC'): array
    {
        $qb = $this->createQueryBuilder('u')
            ->leftJoin('u.reclamations', 'r')
            ->addSelect('r');

        // Search by name or email
        if ($search) {
            $qb->andWhere('u.nom_u LIKE :search OR u.prenom_u LIKE :search OR u.email_u LIKE :search')
               ->setParameter('search', '%' . $search . '%');
        }

        // Filter by role
        if ($role) {
            $qb->andWhere('u.role_u = :role')
               ->setParameter('role', $role);
        }

        // Sorting
        $allowedSortFields = ['id_u', 'nom_u', 'prenom_u', 'email_u', 'role_u', 'date_creation_u'];
        if (in_array($sortBy, $allowedSortFields)) {
            $qb->orderBy('u.' . $sortBy, strtoupper($sortOrder) === 'ASC' ? 'ASC' : 'DESC');
        } else {
            $qb->orderBy('u.id_u', 'DESC');
        }

        // Get total count
        $countQb = clone $qb;
        $total = $countQb->select('COUNT(DISTINCT u.id_u)')->getQuery()->getSingleScalarResult();

        // Pagination
        $offset = ($page - 1) * $limit;
        $qb->setFirstResult($offset)->setMaxResults($limit);

        $utilisateurs = $qb->getQuery()->getResult();

        return [
            'data' => $utilisateurs,
            'total' => (int) $total,
            'page' => $page,
            'limit' => $limit,
            'totalPages' => (int) ceil($total / $limit),
        ];
    }

    public function countByRole(): array
    {
        return $this->createQueryBuilder('u')
            ->select('u.role_u, COUNT(u.id_u) as count')
            ->groupBy('u.role_u')
            ->getQuery()
            ->getResult();
    }

    public function countByCreationDate(): array
    {
        return $this->createQueryBuilder('u')
            ->select('DATE(u.date_creation_u) as creation_date, COUNT(u.id_u) as count')
            ->groupBy('creation_date')
            ->orderBy('creation_date', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function findUsersWithMostReclamations(int $limit = 10): array
    {
        return $this->createQueryBuilder('u')
            ->select('u.id_u, u.nom_u, u.prenom_u, u.email_u, COUNT(r.idr_u) as reclamation_count')
            ->leftJoin('u.reclamations', 'r')
            ->groupBy('u.id_u', 'u.nom_u', 'u.prenom_u', 'u.email_u')
            ->orderBy('reclamation_count', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function getStatistics(): array
    {
        return [
            'total' => $this->count([]),
            'employees' => $this->count(['role_u' => 'employee']),
            'clients' => $this->count(['role_u' => 'client']),
        ];
    }
}