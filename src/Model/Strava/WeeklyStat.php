<?php

declare(strict_types=1);

namespace App\Model\Strava;

use App\Exception\Strava\InvalidStat;
use ArrayIterator;
use DateTime;
use DateTimeImmutable;
use DateTimeInterface;
use Exception;
use IteratorAggregate;
use Traversable;

use function round;
use function sprintf;

class WeeklyStat implements IteratorAggregate
{
    /** @var DailyStat[] $stats */
    private array $stats;
    private DateTimeInterface $firstDayOfWeek;

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
     * @param array<string, mixed> $activity
     *
     * @throws Exception
     */
    public function addStravaActivity(array $activity): void
    {
        $date = new DateTime($activity['start_date_local']);
        $this->stats[$date->format('D')]->addActivity($activity);

        switch ($activity['sport_type']) {
            case 'Tennis':
                $this->totalDistance += $activity['distance'] * 2;
                break;
            case 'Swim':
                $this->totalDistance += $activity['distance'] * 4;
                break;
            case 'Bike':
                $this->totalDistance += $activity['distance'] / 3;
                break;
            case 'Run':
                $this->totalDistance += $activity['distance'];
                break;
            default:
                // Log something.
        }
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

    /** @return ArrayIterator<string, DailyStat> */
    public function getIterator(): Traversable
    {
        yield from $this->stats;
    }
}
