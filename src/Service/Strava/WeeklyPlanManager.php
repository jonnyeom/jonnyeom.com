<?php

declare(strict_types=1);

namespace App\Service\Strava;

use App\Entity\StravaWeeklyPlan;
use App\Entity\StravaWeeklyStat;
use App\Enum\SportType;
use App\Exception\Strava\InvalidPlan;
use App\Repository\StravaWeeklyPlanRepository;
use App\Repository\StravaWeeklyStatRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Exception;

use function array_combine;
use function array_fill_keys;
use function array_keys;
use function array_map;
use function ksort;
use function preg_match;
use function round;
use function sprintf;

use const DATE_ATOM;

/**
 * Reads and writes the weekly mileage plans of the allowed athlete, and puts them next to the
 * actual (Strava) mileage of the same weeks.
 *
 * @phpstan-type WeekPlanView array{
 *     weekStart: string,
 *     days: array<string, array<string, float>>,
 *     dailyMiles: array<string, float>,
 *     sportTotals: array<string, float>,
 *     totalMiles: float,
 *     notes: string|null,
 *     updatedAt: string|null,
 *     actual: array{totalMiles: float, dailyMiles: array<string, float>, fiveWeekAverage: float}|null,
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
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $weekStart) !== 1) {
            throw new InvalidPlan(sprintf('Invalid week "%s", expected a date as YYYY-MM-DD.', $weekStart));
        }

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
            $views[] = $this->toView($weekStart, $plans[$key] ?? null, $stats[$key] ?? null);
        }

        return $views;
    }

    /** @return WeekPlanView */
    public function getWeek(DateTimeImmutable $weekStart): array
    {
        return $this->getWeeks($weekStart, 1)[0];
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

        return $this->getWeek($weekStart);
    }

    /**
     * Creates or replaces the plans of several weeks in one transaction (CSV import): either all
     * weeks are saved, or none.
     *
     * @param array<string, array{days: array<mixed>, notes: string|null}> $plans keyed by week start (Y-m-d)
     *
     * @return list<WeekPlanView> the saved weeks, oldest first
     *
     * @throws InvalidPlan
     */
    public function saveMany(array $plans): array
    {
        ksort($plans);

        $this->entityManager->wrapInTransaction(function () use ($plans): void {
            foreach ($plans as $weekStart => $plan) {
                $this->apply(self::parseWeekStart((string) $weekStart), $plan['days'], $plan['notes']);
            }

            $this->entityManager->flush();
        });

        return array_map(
            fn (string $weekStart): array => $this->getWeek(self::parseWeekStart($weekStart)),
            array_map('strval', array_keys($plans)),
        );
    }

    public function delete(DateTimeImmutable $weekStart): void
    {
        $plan = $this->planRepository->findOneByAthleteAndWeek($this->athleteGuard->getAllowedAthleteId(), $weekStart);
        if (! $plan instanceof StravaWeeklyPlan) {
            return;
        }

        $this->entityManager->remove($plan);
        $this->entityManager->flush();
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
    private function toView(DateTimeImmutable $weekStart, StravaWeeklyPlan|null $plan, StravaWeeklyStat|null $stat): array
    {
        $emptyDays = array_fill_keys(StravaWeeklyPlan::DAYS, 0.0);

        return [
            'weekStart' => $weekStart->format('Y-m-d'),
            'days' => $plan?->getDays() ?? [],
            'dailyMiles' => $plan?->getDailyRelativeMiles() ?? $emptyDays,
            'sportTotals' => $plan?->getSportTotals()
                ?? array_fill_keys(array_map(static fn (SportType $sport): string => $sport->value, SportType::cases()), 0.0),
            'totalMiles' => $plan?->getTotalMiles() ?? 0.0,
            'notes' => $plan?->getNotes(),
            'updatedAt' => $plan?->getUpdatedAt()->format(DATE_ATOM),
            'actual' => $stat === null ? null : [
                'totalMiles' => $stat->getTotalMiles(),
                'dailyMiles' => array_combine(
                    StravaWeeklyPlan::DAYS,
                    array_map(
                        static fn (string $day): float => round(($stat->getDailyDistances()[$day] ?? 0.0) / self::METERS_PER_MILE, 1),
                        StravaWeeklyPlan::DAYS,
                    ),
                ),
                'fiveWeekAverage' => round($stat->getFiveWeekAverage() / self::METERS_PER_MILE, 1),
            ],
        ];
    }
}
