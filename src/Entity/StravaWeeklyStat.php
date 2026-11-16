<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\StravaWeeklyStatRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

use function round;

/**
 * Calculated weekly Strava metrics, persisted per athlete and ISO week.
 */
#[ORM\Entity(repositoryClass: StravaWeeklyStatRepository::class)]
#[ORM\Table(name: 'strava_weekly_stat')]
#[ORM\UniqueConstraint(name: 'uniq_strava_weekly_stat_athlete_week', columns: ['athlete_id', 'week_start'])]
class StravaWeeklyStat
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private int|null $id = null;

    /** Total weekly relative distance, in meters. */
    #[ORM\Column(type: Types::FLOAT)]
    private float $totalDistance = 0;

    /** Five-week rolling average of relative distance, in meters. */
    #[ORM\Column(type: Types::FLOAT)]
    private float $fiveWeekAverage = 0;

    /**
     * Relative distance per day (Mon..Sun), in meters.
     *
     * @var array<string, float>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $dailyDistances = [];

    #[ORM\Column(type: Types::INTEGER)]
    private int $activityCount = 0;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $updatedAt;

    public function __construct(
        #[ORM\Column(type: Types::BIGINT)]
        private string $athleteId,
        #[ORM\Column(type: Types::DATE_IMMUTABLE)]
        private DateTimeImmutable $weekStart,
    ) {
        $this->updatedAt = new DateTimeImmutable();
    }

    /** @param array<string, float> $dailyDistances */
    public function update(float $totalDistance, float $fiveWeekAverage, array $dailyDistances, int $activityCount): void
    {
        $this->totalDistance   = $totalDistance;
        $this->fiveWeekAverage = $fiveWeekAverage;
        $this->dailyDistances  = $dailyDistances;
        $this->activityCount   = $activityCount;
        $this->updatedAt       = new DateTimeImmutable();
    }

    public function getId(): int|null
    {
        return $this->id;
    }

    public function getAthleteId(): string
    {
        return $this->athleteId;
    }

    public function getWeekStart(): DateTimeImmutable
    {
        return $this->weekStart;
    }

    public function getTotalDistance(): float
    {
        return $this->totalDistance;
    }

    public function getTotalMiles(): float
    {
        return round($this->totalDistance / 1609.34, 1);
    }

    public function getFiveWeekAverage(): float
    {
        return $this->fiveWeekAverage;
    }

    /** @return array<string, float> */
    public function getDailyDistances(): array
    {
        return $this->dailyDistances;
    }

    public function getActivityCount(): int
    {
        return $this->activityCount;
    }

    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
