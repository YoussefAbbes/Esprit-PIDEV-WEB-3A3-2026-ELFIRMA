<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Animal;
use Doctrine\DBAL\Connection;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Animal>
 */
final class AnimalRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Animal::class);
    }

    /**
     * @param array{id_elevage:int,type_animal:string,sexe:string,age:int,etat_sante:string,statut:string} $payload
     */
    public function createAnimal(array $payload): void
    {
        $this->connection()->executeStatement(
            'INSERT INTO animal (id_elevage, type_animal, sexe, age, etat_sante, statut)
             VALUES (:id_elevage, :type_animal, :sexe, :age, :etat_sante, :statut)',
            $payload
        );
    }

    /**
     * @param array{id_elevage:int,type_animal:string,sexe:string,age:int,etat_sante:string,statut:string} $payload
     */
    public function updateAnimal(int $idAnimal, array $payload): void
    {
        $this->connection()->executeStatement(
            'UPDATE animal
             SET id_elevage = :id_elevage,
                 type_animal = :type_animal,
                 sexe = :sexe,
                 age = :age,
                 etat_sante = :etat_sante,
                 statut = :statut
             WHERE id_animal = :id_animal',
            [
                'id_animal' => $idAnimal,
                'id_elevage' => $payload['id_elevage'],
                'type_animal' => $payload['type_animal'],
                'sexe' => $payload['sexe'],
                'age' => $payload['age'],
                'etat_sante' => $payload['etat_sante'],
                'statut' => $payload['statut'],
            ]
        );
    }

    public function deleteAnimal(int $idAnimal): void
    {
        $this->connection()->executeStatement(
            'DELETE FROM animal WHERE id_animal = :id_animal',
            ['id_animal' => $idAnimal]
        );
    }

    public function findElevageIdByAnimalId(int $idAnimal): ?int
    {
        $idElevage = $this->connection()->fetchOne(
            'SELECT id_elevage FROM animal WHERE id_animal = :id_animal',
            ['id_animal' => $idAnimal]
        );

        if ($idElevage === false || $idElevage === null) {
            return null;
        }

        return (int) $idElevage;
    }

    /**
     * @return array{id_animal:int,id_elevage:int,type_animal:string,sexe:string,age:int,etat_sante:string,statut:string}|null
     */
    public function findForEdit(int $idAnimal): ?array
    {
        $row = $this->connection()->fetchAssociative(
            'SELECT id_animal, id_elevage, type_animal, sexe, age, etat_sante, statut
             FROM animal
             WHERE id_animal = :id_animal',
            ['id_animal' => $idAnimal]
        );

        return $row === false ? null : $row;
    }

    /**
     * @return list<array{id_animal:int,id_elevage:int,type_animal:string,sexe:string,age:int,etat_sante:string,statut:string}>
     */
    public function findAllForManagement(): array
    {
        return $this->connection()->fetchAllAssociative(
            'SELECT id_animal, id_elevage, type_animal, sexe, age, etat_sante, statut
             FROM animal
             ORDER BY id_animal DESC'
        );
    }

    /**
     * @return list<string>
     */
    public function findDistinctStatuses(): array
    {
        return $this->connection()->fetchFirstColumn(
            "SELECT DISTINCT statut
             FROM animal
             WHERE statut IS NOT NULL AND statut <> ''
             ORDER BY statut ASC"
        );
    }

    /**
     * @return list<string>
     */
    public function findDistinctHealthOptions(): array
    {
        return $this->connection()->fetchFirstColumn(
            "SELECT DISTINCT etat_sante
             FROM animal
             WHERE etat_sante IS NOT NULL AND etat_sante <> ''
             ORDER BY etat_sante ASC"
        );
    }

    /**
     * @return array{total_animals:int|string|null,healthy_animals:int|string|null}
     */
    public function fetchStats(): array
    {
        $stats = $this->connection()->fetchAssociative(
            'SELECT COUNT(*) AS total_animals,
                    SUM(CASE WHEN LOWER(etat_sante) IN (\'healthy\', \'sain\', \'bonne sante\') THEN 1 ELSE 0 END) AS healthy_animals
             FROM animal'
        );

        return $stats === false
            ? ['total_animals' => 0, 'healthy_animals' => 0]
            : $stats;
    }

    private function connection(): Connection
    {
        return $this->getEntityManager()->getConnection();
    }
}
