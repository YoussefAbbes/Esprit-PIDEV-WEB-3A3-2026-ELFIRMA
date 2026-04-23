<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Vaccination;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Vaccination>
 */
class VaccinationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Vaccination::class);
    }

    public function existsById(int $idVaccination): bool
    {
        if ($idVaccination <= 0) {
            return false;
        }

        return (bool) $this->connection()->fetchOne(
            'SELECT id_vaccination FROM vaccination WHERE id_vaccination = :id_vaccination',
            ['id_vaccination' => $idVaccination]
        );
    }

    public function animalExistsById(int $idAnimal): bool
    {
        if ($idAnimal <= 0) {
            return false;
        }

        return (bool) $this->connection()->fetchOne(
            'SELECT id_animal FROM animal WHERE id_animal = :id_animal',
            ['id_animal' => $idAnimal]
        );
    }

    /**
     * @param array{id_animal:int,vaccine_name:string,date_done:?string,date_next:string,notes:?string,status:?string} $payload
     */
    public function createVaccination(array $payload): void
    {
        $this->connection()->executeStatement(
            'INSERT INTO vaccination (id_animal, vaccine_name, date_done, date_next, notes, status)
             VALUES (:id_animal, :vaccine_name, :date_done, :date_next, :notes, :status)',
            $payload
        );
    }

    /**
     * @param array{id_animal:int,vaccine_name:string,date_done:?string,date_next:string,notes:?string,status:?string} $payload
     */
    public function updateVaccination(int $idVaccination, array $payload): void
    {
        $this->connection()->executeStatement(
            'UPDATE vaccination
             SET id_animal = :id_animal,
                 vaccine_name = :vaccine_name,
                 date_done = :date_done,
                 date_next = :date_next,
                 notes = :notes,
                 status = :status
             WHERE id_vaccination = :id_vaccination',
            [
                'id_vaccination' => $idVaccination,
                'id_animal' => $payload['id_animal'],
                'vaccine_name' => $payload['vaccine_name'],
                'date_done' => $payload['date_done'],
                'date_next' => $payload['date_next'],
                'notes' => $payload['notes'],
                'status' => $payload['status'],
            ]
        );
    }

    public function deleteVaccination(int $idVaccination): void
    {
        $this->connection()->executeStatement(
            'DELETE FROM vaccination WHERE id_vaccination = :id_vaccination',
            ['id_vaccination' => $idVaccination]
        );
    }

    /**
     * @return array{id_vaccination:int,id_animal:int,vaccine_name:string,date_done:?string,date_next:string,notes:?string,status:?string}|null
     */
    public function findForEdit(int $idVaccination): ?array
    {
        $row = $this->connection()->fetchAssociative(
            'SELECT id_vaccination, id_animal, vaccine_name, date_done, date_next, notes, status
             FROM vaccination
             WHERE id_vaccination = :id_vaccination',
            ['id_vaccination' => $idVaccination]
        );

        return $row === false ? null : $row;
    }

    /**
     * @return list<array{id_vaccination:int,id_animal:int,animal_type:string,vaccine_name:string,date_done:?string,date_next:string,notes:?string,status:?string}>
     */
    public function findAllForManagement(): array
    {
        return $this->connection()->fetchAllAssociative(
            'SELECT v.id_vaccination,
                    v.id_animal,
                    COALESCE(a.type_animal, CONCAT("Animal #", v.id_animal)) AS animal_type,
                    v.vaccine_name,
                    v.date_done,
                    v.date_next,
                    v.notes,
                    v.status
             FROM vaccination v
             LEFT JOIN animal a ON a.id_animal = v.id_animal
             ORDER BY v.date_next ASC, v.id_vaccination DESC'
        );
    }

    /**
     * @return list<array{id_animal:int,type_animal:string}>
     */
    public function findAnimalOptions(): array
    {
        return $this->connection()->fetchAllAssociative(
            'SELECT id_animal, type_animal
             FROM animal
             ORDER BY type_animal ASC, id_animal ASC'
        );
    }

    /**
     * @return array{total_vaccinations:int|string|null,upcoming_vaccinations:int|string|null}
     */
    public function fetchStats(): array
    {
        $stats = $this->connection()->fetchAssociative(
            'SELECT COUNT(*) AS total_vaccinations,
                    SUM(CASE WHEN date_next BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) AS upcoming_vaccinations
             FROM vaccination'
        );

        return $stats === false
            ? ['total_vaccinations' => 0, 'upcoming_vaccinations' => 0]
            : $stats;
    }

    /**
     * @return list<array{id_vaccination:int,id_animal:int,animal_type:string,vaccine_name:string,date_done:string,date_next:string,status:?string,interval_days:int|string}>
     */
    public function findEligibleForIntervalSmsAlerts(int $days = 2): array
    {
        $safeDays = max(0, $days);

        return $this->connection()->fetchAllAssociative(
            'SELECT v.id_vaccination,
                    v.id_animal,
                    COALESCE(a.type_animal, CONCAT("Animal #", v.id_animal)) AS animal_type,
                    v.vaccine_name,
                    v.date_done,
                    v.date_next,
                    v.status,
                    DATEDIFF(v.date_next, v.date_done) AS interval_days
             FROM vaccination v
             LEFT JOIN animal a ON a.id_animal = v.id_animal
             WHERE v.date_done IS NOT NULL
               AND v.date_next IS NOT NULL
               AND v.date_next >= CURDATE()
               AND DATEDIFF(v.date_next, v.date_done) BETWEEN 0 AND :days
             ORDER BY v.date_next ASC, v.id_vaccination ASC',
            ['days' => $safeDays],
            ['days' => ParameterType::INTEGER]
        );
    }

    /**
     * @return list<array{id_vaccination:int,id_animal:int,animal_type:string,vaccine_name:string,date_next:string,status:?string}>
     */
    public function findUpcomingForSmsAlerts(int $days = 2): array
    {
        $safeDays = max(0, $days);

        return $this->connection()->fetchAllAssociative(
            'SELECT v.id_vaccination,
                    v.id_animal,
                    COALESCE(a.type_animal, CONCAT("Animal #", v.id_animal)) AS animal_type,
                    v.vaccine_name,
                    v.date_next,
                    v.status
             FROM vaccination v
             LEFT JOIN animal a ON a.id_animal = v.id_animal
             WHERE v.date_next IS NOT NULL
               AND DATEDIFF(v.date_next, CURDATE()) BETWEEN 0 AND :days
             ORDER BY v.date_next ASC, v.id_vaccination ASC',
            ['days' => $safeDays],
            ['days' => ParameterType::INTEGER]
        );
    }

    private function connection(): Connection
    {
        return $this->getEntityManager()->getConnection();
    }
}
