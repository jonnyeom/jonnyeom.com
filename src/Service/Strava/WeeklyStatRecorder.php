<?php

declare(strict_types=1);

namespace App\Service\Strava;

use App\Entity\StravaWeeklyStat;
use App\Model\Strava\WeeklyStat;
use App\Repository\StravaWeeklyStatRepository;
use Doctrine\ORM\EntityManagerInterface;

use function array_map;

/**
 * Persists calculated weekly stats to the database (insert or update per athlete + week).
 */
class WeeklyStatRecorder
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly StravaWeeklyStatRepository $repository,
    ) {
    }

    /**
     * Replaces all stored weeks of the athlete with $weeklyStats, in one transaction.
     *
     * @param WeeklyStat[] $weeklyStats
     */
    public function replaceAll(string $athleteId, array $weeklyStats): void
    {
        $this->entityManager->wrapInTransaction(function () use ($athleteId, $weeklyStats): void {
            $this->repository->deleteForAthlete($athleteId);
            $this->entityManager->clear();

            $this->record($athleteId, $weeklyStats);
        });
    }

    /** @param WeeklyStat[] $weeklyStats */
    public function record(string $athleteId, array $weeklyStats): void
    {
        if ($weeklyStats === []) {
            return;
        }

        $existing = $this->repository->findByAthleteAndWeeks(
            $athleteId,
            array_map(static fn (WeeklyStat $stat): string => $stat->getFirstDayOfWeek()->format('Y-m-d'), $weeklyStats),
        );

        foreach ($weeklyStats as $weeklyStat) {
            $weekStart = $weeklyStat->getFirstDayOfWeek();
            $entity    = $existing[$weekStart->format('Y-m-d')] ?? null;

            if (! $entity instanceof StravaWeeklyStat) {
                $entity = new StravaWeeklyStat($athleteId, $weekStart);
                $this->entityManager->persist($entity);
            }

            $entity->update(
                $weeklyStat->getTotalDistance(),
                $weeklyStat->getFiveWeekAverage(),
                $weeklyStat->getDailyDistances(),
                $weeklyStat->getActivityCount(),
                $weeklyStat->getActivities(),
            );
        }

        $this->entityManager->flush();
    }
}
