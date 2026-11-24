<?php

declare(strict_types=1);

namespace App\Model\Strava;

use App\Enum\SportType;

use function array_filter;
use function array_map;
use function array_unique;
use function round;

/** @phpstan-type Activity array{sport_type: string, distance: float, start_date_local: string} */
class DailyStat
{
    /** @var array<int, Activity> $activities */
    private array $activities = [];

    /**
     * Total daily relative distance, in meters.
     */
    private float $distance = 0;

    /** @return Activity[] */
    public function getActivities(): array
    {
        return $this->activities;
    }

    /** @return string[] */
    public function getActivitiesIcons(): array
    {
        return array_filter(array_unique(array_map(fn (array $activity): string|null => $this->getSportIcon($activity['sport_type']), $this->activities)), static fn (string|null $icon): bool => $icon !== null);
    }

    /** @param Activity $activity */
    public function addActivity(array $activity): void
    {
        $sportType = SportType::tryFrom($activity['sport_type']);
        if ($sportType !== null) {
            $this->distance += $sportType->relativeDistance((float) $activity['distance']);
        }

        $this->activities[] = $activity;
    }

    public function getSportIcon(string $sportType): string|null
    {
        return SportType::tryFrom($sportType)?->icon();
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
