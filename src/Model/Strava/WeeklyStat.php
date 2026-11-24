<?php

declare(strict_types=1);

namespace App\Model\Strava;

use App\Enum\SportType;
use App\Exception\Strava\InvalidStat;
use ArrayIterator;
use DateTime;
use DateTimeImmutable;
use DateTimeInterface;
use Exception;
use IteratorAggregate;
use Traversable;

use function array_map;
use function array_sum;
use function count;
use function round;
use function sprintf;

/**
 * @phpstan-import-type Activity from DailyStat
 * @implements IteratorAggregate<string, DailyStat>
 */
class WeeklyStat implements IteratorAggregate
{
    /** @var DailyStat[] $stats */
    private array $stats;

    private readonly DateTimeInterface $firstDayOfWeek;

    /**
     * Total weekly relative distance, in meters.
     */
    private float $totalDistance = 0;

    /**
     * 5 week distance Average, in meters.
     */
    private float $fiveWeekAverage = 0;

    /** @throws InvalidStat */
    public function __construct(string $firstDayOfWeek)
    {
        $this->firstDayOfWeek = new DateTimeImmutable($firstDayOfWeek);
        if ($this->firstDayOfWeek->format('D') !== 'Mon') {
            throw new InvalidStat(sprintf(
                'First day of Week should be a Monday. %s was given',
                $this->firstDayOfWeek->format('l'),
            ));
        }

        $this->stats = [
            'Mon' => new DailyStat(),
            'Tue' => new DailyStat(),
            'Wed' => new DailyStat(),
            'Thu' => new DailyStat(),
            'Fri' => new DailyStat(),
            'Sat' => new DailyStat(),
            'Sun' => new DailyStat(),
        ];
    }

    /**
     * @param Activity $activity
     *
     * @throws Exception
     */
    public function addStravaActivity(array $activity): void
    {
        $date = new DateTime($activity['start_date_local']);
        $this->stats[$date->format('D')]->addActivity($activity);

        // Other sport types are listed but don't count towards the weekly distance.
        $sportType = SportType::fromStrava($activity['sport_type']);
        if ($sportType === null) {
            return;
        }

        $this->totalDistance += $sportType->relativeDistance((float) $activity['distance']);
    }

    public function getTotalDistance(): float
    {
        return $this->totalDistance;
    }

    /**
     * Gets total Relative Miles for the week.
     */
    public function getTotalMiles(): float
    {
        return round($this->totalDistance / 1609.34, 1);
    }

    public function getFiveWeekAverage(): float
    {
        return $this->fiveWeekAverage;
    }

    public function setFiveWeekAverage(float $fiveWeekAverage): void
    {
        $this->fiveWeekAverage = $fiveWeekAverage;
    }

    public function getDate(): string
    {
        return $this->firstDayOfWeek->format('n.d');
    }

    public function getFirstDayOfWeek(): DateTimeImmutable
    {
        return DateTimeImmutable::createFromInterface($this->firstDayOfWeek);
    }

    /**
     * Relative distance per day, in meters, keyed by day (Mon..Sun).
     *
     * @return array<string, float>
     */
    public function getDailyDistances(): array
    {
        return array_map(static fn (DailyStat $stat): float => $stat->getDistance(), $this->stats);
    }

    /**
     * All activities of the week, reduced to the fields needed to rebuild the stats.
     *
     * @return array<int, Activity>
     */
    public function getActivities(): array
    {
        $activities = [];
        foreach ($this->stats as $dailyStat) {
            foreach ($dailyStat->getActivities() as $activity) {
                $activities[] = [
                    'sport_type' => $activity['sport_type'],
                    'distance' => (float) $activity['distance'],
                    'start_date_local' => $activity['start_date_local'],
                ];
            }
        }

        return $activities;
    }

    public function getActivityCount(): int
    {
        return array_sum(array_map(static fn (DailyStat $stat): int => count($stat->getActivities()), $this->stats));
    }

    /** @return ArrayIterator<string, DailyStat> */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->stats);
    }
}
