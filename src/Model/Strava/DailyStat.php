<?php

declare(strict_types=1);

namespace App\Model\Strava;

use function round;

/** @phpstan-type Activity array{sport_type: string, distance: int, start_date_local: string} */
class DailyStat
{
    /** @var array<int, Activity> $activities */
    private array $activities;

    /**
     * Total daily relative distance, in meters.
     */
    private float $distance = 0;

    public function __construct()
    {
    }

    /** @return Activity[] */
    public function getActivities(): array
    {
        return $this->activities;
    }

    /** @param Activity $activity */
    public function addActivity(array $activity): void
    {
        $this->activities[] = $activity;

        switch ($activity['sport_type']) {
            case 'Tennis':
                $this->distance += $activity['distance'] * 2;
                break;
            case 'Swim':
                $this->distance += $activity['distance'] * 4;
                break;
            case 'Bike':
                $this->distance += $activity['distance'] / 3;
                break;
            case 'Run':
                $this->distance += $activity['distance'];
                break;
            default:
                // Log something.
        }
    }

    public function getDistance(): float
    {
        return $this->distance;
    }

    public function getMiles(): float
    {
        return $this->getRelativeMiles();
    }

    public function getRelativeMiles(): float
    {
        // Meters >> Miles.
        return round($this->distance / 1609.34, 1);
    }
}
