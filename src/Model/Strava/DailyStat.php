<?php

declare(strict_types=1);

namespace App\Model\Strava;

class DailyStat
{
    /**
     * Total daily distance, in meters.
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
