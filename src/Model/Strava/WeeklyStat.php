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

use function sprintf;

class WeeklyStat implements IteratorAggregate
{
    /** @var DailyStat[] $stats */
    private array $stats;
    private DateTimeInterface $firstDayOfWeek;
    private float $totalDistance   = 0;
    private float $fiveWeekAverage = 0;

    /** @throws InvalidStat */
    public function __construct(string $firstDayOfWeek)
    {
        $this->firstDayOfWeek = new DateTimeImmutable($firstDayOfWeek);
        if ($this->firstDayOfWeek->format('D') !== 'Mon') {
            throw new InvalidStat(sprintf('First day of Week should be a Monday. %s was given', $this->firstDayOfWeek->format('l')));
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
     * @param array<mixed, mixed> $activity
     *
     * @throws Exception
     */
    public function addStravaActivity(array $activity): void
    {
        $date = new DateTime($activity['start_date_local']);
        $this->stats[$date->format('D')]->addDistance($activity['distance']);
        $this->totalDistance += $activity['distance'];
    }

    public function getTotalDistance(): float
    {
        return $this->totalDistance;
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
