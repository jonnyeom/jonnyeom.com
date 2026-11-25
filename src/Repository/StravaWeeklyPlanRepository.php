<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\StravaWeeklyPlan;
use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<StravaWeeklyPlan> */
class StravaWeeklyPlanRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, StravaWeeklyPlan::class);
    }

    public function findOneByAthleteAndWeek(int $athleteId, DateTimeImmutable $weekStart): StravaWeeklyPlan|null
    {
        return $this->findOneBy(['athleteId' => $athleteId, 'weekStart' => $weekStart]);
    }

    /**
     * Plans of the weeks starting in [$from, $to].
     *
     * @return array<string, StravaWeeklyPlan> keyed by week start (Y-m-d), oldest first
     */
    public function findBetween(int $athleteId, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        /** @var StravaWeeklyPlan[] $results */
        $results = $this->createQueryBuilder('p')
            ->andWhere('p.athleteId = :athleteId')
            ->andWhere('p.weekStart >= :from')
            ->andWhere('p.weekStart <= :to')
            ->setParameter('athleteId', $athleteId)
            ->setParameter('from', $from->format('Y-m-d'))
            ->setParameter('to', $to->format('Y-m-d'))
            ->orderBy('p.weekStart', 'ASC')
            ->getQuery()
            ->getResult();

        $byWeek = [];
        foreach ($results as $plan) {
            $byWeek[$plan->getWeekStart()->format('Y-m-d')] = $plan;
        }

        return $byWeek;
    }
}
