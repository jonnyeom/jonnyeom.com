<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\StravaWeeklyStat;
use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<StravaWeeklyStat> */
class StravaWeeklyStatRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, StravaWeeklyStat::class);
    }

    /**
     * @param string[] $weekStarts week start dates (Y-m-d)
     *
     * @return array<string, StravaWeeklyStat> keyed by week start (Y-m-d)
     */
    public function findByAthleteAndWeeks(int $athleteId, array $weekStarts): array
    {
        if ($weekStarts === []) {
            return [];
        }

        /** @var StravaWeeklyStat[] $results */
        $results = $this->createQueryBuilder('s')
            ->andWhere('s.athleteId = :athleteId')
            ->andWhere('s.weekStart IN (:weekStarts)')
            ->setParameter('athleteId', $athleteId)
            ->setParameter('weekStarts', $weekStarts, ArrayParameterType::STRING)
            ->getQuery()
            ->getResult();

        $byWeek = [];
        foreach ($results as $stat) {
            $byWeek[$stat->getWeekStart()->format('Y-m-d')] = $stat;
        }

        return $byWeek;
    }

    /**
     * The $limit stored weeks right before $weekStart.
     *
     * @return StravaWeeklyStat[] newest first
     */
    public function findBeforeWeek(int $athleteId, DateTimeImmutable $weekStart, int $limit): array
    {
        /** @var StravaWeeklyStat[] $results */
        $results = $this->createQueryBuilder('s')
            ->andWhere('s.athleteId = :athleteId')
            ->andWhere('s.weekStart < :weekStart')
            ->setParameter('athleteId', $athleteId)
            ->setParameter('weekStart', $weekStart->format('Y-m-d'))
            ->orderBy('s.weekStart', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $results;
    }

    public function deleteForAthlete(int $athleteId): void
    {
        $this->createQueryBuilder('s')
            ->delete()
            ->andWhere('s.athleteId = :athleteId')
            ->setParameter('athleteId', $athleteId)
            ->getQuery()
            ->execute();
    }

    /** @return StravaWeeklyStat[] newest first */
    public function findLatestForAthlete(int $athleteId, int $limit): array
    {
        /** @var StravaWeeklyStat[] $results */
        $results = $this->createQueryBuilder('s')
            ->andWhere('s.athleteId = :athleteId')
            ->setParameter('athleteId', $athleteId)
            ->orderBy('s.weekStart', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $results;
    }
}
