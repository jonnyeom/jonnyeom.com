<?php

declare(strict_types=1);

namespace App\Model\Strava;

use function round;

class DailyStat
{
    /** @var array<int, mixed> $activities */
    private array $activities;

    /**
     * Total daily relative distance, in meters.
     */
    private float $distance = 0;

    public function __construct()
    {
    }

    public function addDistance(float $distance): void
    {
        // @Todo: Convert to relative distance.
        $this->distance += $distance;
    }

    /** @param array<int, mixed> $activity */
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
        return round($this->distance / 1690.34, 1);
    }
}
