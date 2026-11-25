<?php

declare(strict_types=1);

namespace App\Entity;

use App\Model\Strava\DailyStat;
use App\Model\Strava\WeeklyStat;
use App\Repository\StravaWeeklyStatRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

use function round;

/**
 * Calculated weekly Strava metrics, persisted per athlete and ISO week.
 *
 * @phpstan-import-type Activity from DailyStat
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

    /**
     * The week's activities (sport type, distance, local start date), so the stats page can be
     * rendered from the database without calling Strava.
     *
     * @var array<int, Activity>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $activities = [];

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $updatedAt;

    public function __construct(
        #[ORM\Column(type: Types::BIGINT)]
        private int $athleteId,
        #[ORM\Column(type: Types::DATE_IMMUTABLE)]
        private DateTimeImmutable $weekStart,
    ) {
        $this->updatedAt = new DateTimeImmutable();
    }

    /**
     * @param array<string, float> $dailyDistances
     * @param array<int, Activity> $activities
     */
    public function update(
        float $totalDistance,
        float $fiveWeekAverage,
        array $dailyDistances,
        int $activityCount,
        array $activities = [],
    ): void {
        $this->totalDistance   = $totalDistance;
        $this->fiveWeekAverage = $fiveWeekAverage;
        $this->dailyDistances  = $dailyDistances;
        $this->activityCount   = $activityCount;
        $this->activities      = $activities;
        $this->updatedAt       = new DateTimeImmutable();
    }

    /**
     * Rebuilds the display model from the stored data (no Strava call).
     */
    public function toWeeklyStat(): WeeklyStat
    {
        $weeklyStat = new WeeklyStat($this->weekStart->format('Y-m-d'));
        foreach ($this->activities as $activity) {
            $weeklyStat->addStravaActivity($activity);
        }

        $weeklyStat->setFiveWeekAverage($this->fiveWeekAverage);

        return $weeklyStat;
    }

    public function getId(): int|null
    {
        return $this->id;
    }

    public function getAthleteId(): int
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

    /** @return array<int, Activity> */
    public function getActivities(): array
    {
        return $this->activities;
    }

    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
