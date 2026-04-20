<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\IrrigationEvent;
use App\Entity\Parcelle;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<IrrigationEvent>
 */
class IrrigationEventRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, IrrigationEvent::class);
    }

    public function save(IrrigationEvent $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(IrrigationEvent $entity, bool $flush = false): void
    {
        $this->getEntityManager()->remove($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * @return list<IrrigationEvent>
     */
    public function findLatestByParcelle(Parcelle $parcelle, ?int $limit = null): array
    {
        $queryBuilder = $this->createQueryBuilder("event")
            ->andWhere("event.parcelle = :parcelle")
            ->setParameter("parcelle", $parcelle)
            ->orderBy("event.createdAt", "DESC")
            ->addOrderBy("event.id", "DESC");

        if ($limit !== null && $limit > 0) {
            $queryBuilder->setMaxResults($limit);
        }

        return $queryBuilder->getQuery()->getResult();
    }
}
