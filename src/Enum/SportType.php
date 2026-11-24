<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * The Strava sport types counted towards weekly mileage, and how each converts to "relative"
 * (run-equivalent) distance.
 */
enum SportType: string
{
    case Run    = 'Run';
    case Bike   = 'Bike';
    case Swim   = 'Swim';
    case Tennis = 'Tennis';

    /** Converts a distance of this sport to run-equivalent distance (same unit in and out). */
    public function relativeDistance(float $distance): float
    {
        return match ($this) {
            self::Run => $distance,
            self::Bike => $distance / 3,
            self::Swim => $distance * 4,
            self::Tennis => $distance * 2,
        };
    }

    /** Font Awesome icon name. */
    public function icon(): string
    {
        return match ($this) {
            self::Run => 'person-running',
            self::Bike => 'person-biking',
            self::Swim => 'person-swimming',
            self::Tennis => 'table-tennis-paddle-ball',
        };
    }
}
