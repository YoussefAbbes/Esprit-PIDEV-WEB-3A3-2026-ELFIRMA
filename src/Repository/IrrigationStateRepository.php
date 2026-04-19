<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\IrrigationState;
use App\Entity\Parcelle;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<IrrigationState>
 */
class IrrigationStateRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, IrrigationState::class);
    }

    public function save(IrrigationState $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(IrrigationState $entity, bool $flush = false): void
    {
        $this->getEntityManager()->remove($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function findLatestByParcelle(Parcelle $parcelle): ?IrrigationState
    {
        return $this->createQueryBuilder("state")
            ->andWhere("state.parcelle = :parcelle")
            ->setParameter("parcelle", $parcelle)
            ->orderBy("state.updatedAt", "DESC")
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
