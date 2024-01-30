<?php

declare(strict_types=1);

namespace App\Model\Strava;

class DailyStat
{
    private float $distance = 0;

    public function __construct()
    {
    }

    public function addDistance(float $distance): void
    {
        $this->distance += $distance;
    }

    public function getDistance(): float
    {
        return $this->distance;
    }
}
