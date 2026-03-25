<?php

declare(strict_types=1);

namespace App\Model\Strava;

use function array_map;
use function array_unique;
use function in_array;
use function round;

/** @phpstan-type Activity array{sport_type: string, sport_icon: float, distance: float, start_date_local: string} */
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

    /** @return string[] */
    public function getActivitiesIcons(): array
    {
        return array_unique(array_map(static fn (array $activity) => $activity['sport_icon'], $this->activities));
    }

    /** @param Activity $activity */
    public function addActivity(array $activity): void
    {
        if (! in_array($activity['sport_type'], ['Tennis', 'Swim', 'Bike', 'Run'], true)) {
            return;
        }

        switch ($activity['sport_type']) {
            case 'Tennis':
                $this->distance        += $activity['distance'] * 2;
                $activity['sport_icon'] = 'table-tennis-paddle-ball';
                break;
            case 'Swim':
                $this->distance        += $activity['distance'] * 4;
                $activity['sport_icon'] = 'person-swimming';
                break;
            case 'Bike':
                $this->distance        += $activity['distance'] / 3;
                $activity['sport_icon'] = 'person-biking';
                break;
            case 'Run':
                $this->distance        += $activity['distance'];
                $activity['sport_icon'] = 'person-running';
                break;
            default:
                // Technically, this should never happen.
                $activity['sport_icon'] = 'strava';
        }

        $this->activities[] = $activity;
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
