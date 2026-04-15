<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Livestock;
use Doctrine\DBAL\Connection;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Livestock>
 */
final class LivestockRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Livestock::class);
    }

    public function existsById(int $idElevage): bool
    {
        if ($idElevage <= 0) {
            return false;
        }

        return (bool) $this->connection()->fetchOne(
            'SELECT id_elevage FROM elevage WHERE id_elevage = :id_elevage',
            ['id_elevage' => $idElevage]
        );
    }

    /**
     * @param array{type_elevage:string,etat_elevage:string,capacite:int,production:string} $payload
     */
    public function createLivestock(array $payload): void
    {
        $this->connection()->executeStatement(
            'INSERT INTO elevage (type_elevage, etat_elevage, capacite, nombre_animaux, production)
             VALUES (:type_elevage, :etat_elevage, :capacite, :nombre_animaux, :production)',
            [
                'type_elevage' => $payload['type_elevage'],
                'etat_elevage' => $payload['etat_elevage'],
                'capacite' => $payload['capacite'],
                'nombre_animaux' => 0,
                'production' => $payload['production'],
            ]
        );
    }

    /**
     * @param array{type_elevage:string,etat_elevage:string,capacite:int,production:string} $payload
     */
    public function updateLivestock(int $idElevage, array $payload, int $nombreAnimaux): void
    {
        $this->connection()->executeStatement(
            'UPDATE elevage
             SET type_elevage = :type_elevage,
                 etat_elevage = :etat_elevage,
                 capacite = :capacite,
                 nombre_animaux = :nombre_animaux,
                 production = :production
             WHERE id_elevage = :id_elevage',
            [
                'id_elevage' => $idElevage,
                'type_elevage' => $payload['type_elevage'],
                'etat_elevage' => $payload['etat_elevage'],
                'capacite' => $payload['capacite'],
                'nombre_animaux' => $nombreAnimaux,
                'production' => $payload['production'],
            ]
        );
    }

    public function deleteLivestock(int $idElevage): void
    {
        $this->connection()->executeStatement(
            'DELETE FROM elevage WHERE id_elevage = :id_elevage',
            ['id_elevage' => $idElevage]
        );
    }

    public function countAnimalsForLivestock(int $idElevage): int
    {
        return (int) $this->connection()->fetchOne(
            'SELECT COUNT(*) FROM animal WHERE id_elevage = :id_elevage',
            ['id_elevage' => $idElevage]
        );
    }

    public function syncAnimalCount(int $idElevage): void
    {
        if ($idElevage <= 0) {
            return;
        }

        $count = $this->countAnimalsForLivestock($idElevage);

        $this->connection()->executeStatement(
            'UPDATE elevage SET nombre_animaux = :nombre_animaux WHERE id_elevage = :id_elevage',
            [
                'id_elevage' => $idElevage,
                'nombre_animaux' => $count,
            ]
        );
    }

    /**
     * @return array{id_elevage:int,type_elevage:string,etat_elevage:string,capacite:int,nombre_animaux:int,production:string}|null
     */
    public function findForEdit(int $idElevage): ?array
    {
        $row = $this->connection()->fetchAssociative(
            'SELECT id_elevage, type_elevage, etat_elevage, capacite, nombre_animaux, production
             FROM elevage
             WHERE id_elevage = :id_elevage',
            ['id_elevage' => $idElevage]
        );

        return $row === false ? null : $row;
    }

    /**
     * @return list<array{id_elevage:int,type_elevage:string,etat_elevage:string,capacite:int,nombre_animaux:int,production:string}>
     */
    public function findAllForManagement(): array
    {
        return $this->connection()->fetchAllAssociative(
            'SELECT id_elevage, type_elevage, etat_elevage, capacite, nombre_animaux, production
             FROM elevage
             ORDER BY id_elevage DESC'
        );
    }

    /**
     * @return list<array{id_elevage:int,type_elevage:string}>
     */
    public function findOptionsForAnimalForm(): array
    {
        return $this->connection()->fetchAllAssociative(
            'SELECT id_elevage, type_elevage
             FROM elevage
             ORDER BY type_elevage ASC, id_elevage ASC'
        );
    }

    /**
     * @return list<string>
     */
    public function findDistinctStates(): array
    {
        return $this->connection()->fetchFirstColumn(
            "SELECT DISTINCT etat_elevage
             FROM elevage
             WHERE etat_elevage IS NOT NULL
               AND etat_elevage <> ''
               AND LOWER(TRIM(etat_elevage)) NOT IN ('under treatment', 'en traitement')
             ORDER BY etat_elevage ASC"
        );
    }

    /**
     * @return array{total_livestock:int|string|null,clean_livestock:int|string|null}
     */
    public function fetchStats(): array
    {
        $stats = $this->connection()->fetchAssociative(
            'SELECT COUNT(*) AS total_livestock,
                    SUM(CASE WHEN LOWER(etat_elevage) IN (\'clean\', \'propre\') THEN 1 ELSE 0 END) AS clean_livestock
             FROM elevage'
        );

        return $stats === false
            ? ['total_livestock' => 0, 'clean_livestock' => 0]
            : $stats;
    }

    private function connection(): Connection
    {
        return $this->getEntityManager()->getConnection();
    }
}
