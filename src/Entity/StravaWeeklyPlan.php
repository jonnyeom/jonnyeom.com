<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\SportType;
use App\Exception\Strava\InvalidPlan;
use App\Repository\StravaWeeklyPlanRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

use function array_keys;
use function array_sum;
use function implode;
use function in_array;
use function is_array;
use function is_finite;
use function is_float;
use function is_int;
use function is_numeric;
use function is_string;
use function mb_strlen;
use function round;
use function sprintf;
use function trim;

/**
 * Planned mileage for one athlete and ISO week (Monday start), per day and sport.
 *
 * The counterpart of StravaWeeklyStat: the stat is what was done, the plan is what is intended.
 * Planned distances are stored in miles of the sport itself (e.g. 20 miles of biking); the totals
 * are relative (run-equivalent) miles, weighted like the stats (see SportType::relativeDistance()).
 */
#[ORM\Entity(repositoryClass: StravaWeeklyPlanRepository::class)]
#[ORM\Table(name: 'strava_weekly_plan')]
#[ORM\UniqueConstraint(name: 'uniq_strava_weekly_plan_athlete_week', columns: ['athlete_id', 'week_start'])]
class StravaWeeklyPlan
{
    public const array DAYS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

    /** Upper bound for a single day + sport, to catch typos (e.g. 500 instead of 5.0). */
    public const float MAX_MILES = 300.0;

    public const int MAX_NOTES_LENGTH = 2000;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private int|null $id = null;

    /**
     * Planned miles per day (Mon..Sun) and sport type. Only non-zero entries are stored.
     *
     * @var array<string, array<string, float>>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $days = [];

    /** Total planned relative (run-equivalent) miles of the week. */
    #[ORM\Column(type: Types::FLOAT)]
    private float $totalMiles = 0;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private string|null $notes = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $updatedAt;

    /** @throws InvalidPlan when $weekStart is not a Monday. */
    public function __construct(
        #[ORM\Column(type: Types::BIGINT)]
        private string $athleteId,
        #[ORM\Column(type: Types::DATE_IMMUTABLE)]
        private DateTimeImmutable $weekStart,
    ) {
        if ($weekStart->format('D') !== 'Mon') {
            throw new InvalidPlan(sprintf('A week starts on a Monday, %s was given.', $weekStart->format('l Y-m-d')));
        }

        $this->weekStart = $weekStart->setTime(0, 0);
        $this->updatedAt = new DateTimeImmutable();
    }

    /**
     * Replaces the planned miles and notes.
     *
     * @param array<mixed> $days miles keyed by day (Mon..Sun), then by sport type (Run, Bike, Swim, Tennis)
     *
     * @throws InvalidPlan
     */
    public function update(array $days, string|null $notes = null): void
    {
        $this->days       = self::normalizeDays($days);
        $this->totalMiles = round(array_sum($this->getDailyRelativeMiles()), 2);
        $this->notes      = self::normalizeNotes($notes);
        $this->updatedAt  = new DateTimeImmutable();
    }

    public function isEmpty(): bool
    {
        return $this->days === [] && $this->notes === null;
    }

    /**
     * Relative (run-equivalent) miles per day, for all days Mon..Sun.
     *
     * @return array<string, float>
     */
    public function getDailyRelativeMiles(): array
    {
        $daily = [];
        foreach (self::DAYS as $day) {
            $miles = 0.0;
            foreach ($this->days[$day] ?? [] as $sport => $distance) {
                $miles += SportType::from($sport)->relativeDistance($distance);
            }

            $daily[$day] = round($miles, 2);
        }

        return $daily;
    }

    /**
     * Planned miles per sport type over the whole week (sport miles, not relative).
     *
     * @return array<string, float>
     */
    public function getSportTotals(): array
    {
        $totals = [];
        foreach (SportType::cases() as $sport) {
            $miles = 0.0;
            foreach ($this->days as $sports) {
                $miles += $sports[$sport->value] ?? 0.0;
            }

            $totals[$sport->value] = round($miles, 2);
        }

        return $totals;
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

    /** @return array<string, array<string, float>> */
    public function getDays(): array
    {
        return $this->days;
    }

    public function getTotalMiles(): float
    {
        return $this->totalMiles;
    }

    public function getNotes(): string|null
    {
        return $this->notes;
    }

    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /**
     * @param array<mixed> $days
     *
     * @return array<string, array<string, float>> in Mon..Sun and SportType order, zero entries dropped
     *
     * @throws InvalidPlan
     */
    private static function normalizeDays(array $days): array
    {
        foreach (array_keys($days) as $day) {
            if (! in_array($day, self::DAYS, true)) {
                throw new InvalidPlan(sprintf('Unknown day "%s", expected one of %s.', $day, implode(', ', self::DAYS)));
            }
        }

        $normalized = [];
        foreach (self::DAYS as $day) {
            $sports = $days[$day] ?? [];
            if (! is_array($sports)) {
                throw new InvalidPlan(sprintf('%s must be an object of miles keyed by sport type.', $day));
            }

            foreach (array_keys($sports) as $sport) {
                if (SportType::tryFrom((string) $sport) === null) {
                    throw new InvalidPlan(sprintf('Unknown sport type "%s" on %s.', $sport, $day));
                }
            }

            foreach (SportType::cases() as $sport) {
                $miles = self::normalizeMiles($sports[$sport->value] ?? null, $day, $sport);
                if ($miles <= 0.0) {
                    continue;
                }

                $normalized[$day][$sport->value] = $miles;
            }
        }

        return $normalized;
    }

    /** @throws InvalidPlan */
    private static function normalizeMiles(mixed $miles, string $day, SportType $sport): float
    {
        if ($miles === null || $miles === '') {
            return 0.0;
        }

        if (! is_int($miles) && ! is_float($miles) && ! (is_string($miles) && is_numeric($miles))) {
            throw new InvalidPlan(sprintf('%s miles on %s must be a number.', $sport->value, $day));
        }

        $miles = (float) $miles;
        if (! is_finite($miles) || $miles < 0.0 || $miles > self::MAX_MILES) {
            throw new InvalidPlan(sprintf(
                '%s miles on %s must be between 0 and %d.',
                $sport->value,
                $day,
                (int) self::MAX_MILES,
            ));
        }

        return round($miles, 2);
    }

    /** @throws InvalidPlan */
    private static function normalizeNotes(string|null $notes): string|null
    {
        $notes = $notes === null ? '' : trim($notes);
        if (mb_strlen($notes) > self::MAX_NOTES_LENGTH) {
            throw new InvalidPlan(sprintf('Notes must be at most %d characters.', self::MAX_NOTES_LENGTH));
        }

        return $notes === '' ? null : $notes;
    }
}
