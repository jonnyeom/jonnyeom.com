<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * The sports counted towards weekly mileage, and how each converts to "relative" (run-equivalent)
 * distance. The values are the names used in the plans; see fromStrava() for Strava's own types.
 */
enum SportType: string
{
    case Run    = 'Run';
    case Bike   = 'Bike';
    case Swim   = 'Swim';
    case Tennis = 'Tennis';

    /** The sport a Strava sport_type counts as; null when it doesn't count (e.g. walks, e-bike rides). */
    public static function fromStrava(string $sportType): self|null
    {
        return match ($sportType) {
            'Run', 'TrailRun', 'VirtualRun' => self::Run,
            'Ride', 'GravelRide', 'MountainBikeRide', 'VirtualRide' => self::Bike,
            'Swim' => self::Swim,
            'Tennis' => self::Tennis,
            default => null,
        };
    }

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
