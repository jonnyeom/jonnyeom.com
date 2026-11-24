<?php

declare(strict_types=1);

namespace App\Service\Strava;

use App\Entity\StravaWeeklyPlan;
use App\Entity\StravaWeeklyStat;
use App\Exception\Strava\InvalidPlan;
use App\Repository\StravaWeeklyPlanRepository;
use App\Repository\StravaWeeklyStatRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Exception;

use function array_map;
use function count;
use function round;
use function sprintf;

/**
 * Reads and writes the weekly mileage plans of the allowed athlete, and puts them next to the
 * actual (Strava) mileage of the same weeks.
 *
 * @phpstan-type WeekPlanView array{
 *     weekStart: string,
 *     days: array<string, array<string, float>>,
 *     notes: string|null,
 *     actual: array{totalMiles: float, fiveWeekAverage: float}|null,
 * }
 */
class WeeklyPlanManager
{
    private const float METERS_PER_MILE = 1609.34;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly StravaWeeklyPlanRepository $planRepository,
        private readonly StravaWeeklyStatRepository $statRepository,
        private readonly AthleteGuard $athleteGuard,
    ) {
    }

    /**
     * Parses a week start (Y-m-d), which must be a Monday.
     *
     * @throws InvalidPlan
     */
    public static function parseWeekStart(string $weekStart): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $weekStart);
        if ($date === false || $date->format('Y-m-d') !== $weekStart) {
            throw new InvalidPlan(sprintf('Invalid week "%s", expected a date as YYYY-MM-DD.', $weekStart));
        }

        if ($date->format('D') !== 'Mon') {
            throw new InvalidPlan(sprintf('A week starts on a Monday, %s was given.', $date->format('l Y-m-d')));
        }

        return $date;
    }

    /**
     * The Monday of the week $date is in.
     *
     * @throws Exception
     */
    public static function weekStartOf(DateTimeImmutable $date): DateTimeImmutable
    {
        return $date->modify('monday this week')->setTime(0, 0);
    }

    /**
     * $weeks consecutive weeks from $from (a Monday), oldest first. Weeks without a plan are
     * included with an empty plan.
     *
     * @return list<WeekPlanView>
     */
    public function getWeeks(DateTimeImmutable $from, int $weeks): array
    {
        $athleteId  = $this->athleteGuard->getAllowedAthleteId();
        $weekStarts = [];
        for ($i = 0; $i < $weeks; $i++) {
            $weekStarts[] = $from->modify(sprintf('+%d weeks', $i));
        }

        if ($weekStarts === []) {
            return [];
        }

        $plans = $this->planRepository->findBetween($athleteId, $weekStarts[0], $weekStarts[$weeks - 1]);
        $stats = $this->statRepository->findByAthleteAndWeeks(
            $athleteId,
            array_map(static fn (DateTimeImmutable $week): string => $week->format('Y-m-d'), $weekStarts),
        );

        $views = [];
        foreach ($weekStarts as $weekStart) {
            $key     = $weekStart->format('Y-m-d');
            $views[] = self::toView($weekStart, $plans[$key] ?? null, $stats[$key] ?? null);
        }

        return $views;
    }

    /**
     * Creates or replaces the plan of a week. Saving an empty plan (no miles, no notes) removes it.
     *
     * @param array<mixed> $days
     *
     * @return WeekPlanView
     *
     * @throws InvalidPlan
     */
    public function save(DateTimeImmutable $weekStart, array $days, string|null $notes): array
    {
        $this->apply($weekStart, $days, $notes);
        $this->entityManager->flush();

        return $this->getWeeks($weekStart, 1)[0];
    }

    /**
     * Creates or replaces the plans of several weeks (CSV import, already validated). The single
     * flush writes all of them in one transaction.
     *
     * @param array<string, array{days: array<mixed>, notes: string|null}> $plans keyed by week start (Y-m-d)
     *
     * @return int the number of weeks saved
     *
     * @throws InvalidPlan
     */
    public function saveMany(array $plans): int
    {
        foreach ($plans as $weekStart => $plan) {
            $this->apply(new DateTimeImmutable((string) $weekStart), $plan['days'], $plan['notes']);
        }

        $this->entityManager->flush();

        return count($plans);
    }

    /**
     * Creates, updates or removes (when empty) the week's plan, without flushing.
     *
     * @param array<mixed> $days
     *
     * @throws InvalidPlan
     */
    private function apply(DateTimeImmutable $weekStart, array $days, string|null $notes): void
    {
        $athleteId = $this->athleteGuard->getAllowedAthleteId();
        $plan      = $this->planRepository->findOneByAthleteAndWeek($athleteId, $weekStart);

        if (! $plan instanceof StravaWeeklyPlan) {
            $plan = new StravaWeeklyPlan($athleteId, $weekStart);
            $plan->update($days, $notes);
            if (! $plan->isEmpty()) {
                $this->entityManager->persist($plan);
            }

            return;
        }

        $plan->update($days, $notes);
        if (! $plan->isEmpty()) {
            return;
        }

        $this->entityManager->remove($plan);
    }

    /** @return WeekPlanView */
    private static function toView(DateTimeImmutable $weekStart, StravaWeeklyPlan|null $plan, StravaWeeklyStat|null $stat): array
    {
        return [
            'weekStart' => $weekStart->format('Y-m-d'),
            'days' => $plan?->getDays() ?? [],
            'notes' => $plan?->getNotes(),
            'actual' => $stat === null ? null : [
                'totalMiles' => $stat->getTotalMiles(),
                'fiveWeekAverage' => round($stat->getFiveWeekAverage() / self::METERS_PER_MILE, 1),
            ],
        ];
    }
}
