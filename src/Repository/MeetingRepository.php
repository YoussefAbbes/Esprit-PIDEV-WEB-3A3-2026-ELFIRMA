<?php

namespace App\Repository;

use App\Entity\Meeting;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Meeting>
 */
class MeetingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Meeting::class);
    }

    /**
     * Find upcoming meetings (datetime > now)
     */
    public function findUpcomingMeetings(): array
    {
        return $this->createQueryBuilder('m')
            ->where('m.meeting_datetime > :now')
            ->setParameter('now', new \DateTime('now'))
            ->orderBy('m.meeting_datetime', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find past meetings (datetime < now)
     */
    public function findPastMeetings(): array
    {
        return $this->createQueryBuilder('m')
            ->where('m.meeting_datetime < :now')
            ->setParameter('now', new \DateTime('now'))
            ->orderBy('m.meeting_datetime', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find meetings for a specific supplier
     */
    public function findBySupplier(int $supplierId): array
    {
        return $this->createQueryBuilder('m')
            ->where('m.fournisseur = :supplier_id')
            ->setParameter('supplier_id', $supplierId)
            ->orderBy('m.meeting_datetime', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find meetings within a date range
     */
    public function findByDateRange(\DateTime $start, \DateTime $end): array
    {
        return $this->createQueryBuilder('m')
            ->where('m.meeting_datetime BETWEEN :start AND :end')
            ->setParameter('start', $start)
            ->setParameter('end', $end)
            ->orderBy('m.meeting_datetime', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find meetings for a specific month and year
     */
    public function findByMonth(int $year, int $month): array
    {
        $start = new \DateTime("{$year}-{$month}-01 00:00:00");
        $end = (clone $start)->modify('last day of this month')->setTime(23, 59, 59);

        return $this->findByDateRange($start, $end);
    }
}

