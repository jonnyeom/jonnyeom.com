<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\StravaWeeklyStat;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class StravaWeeklyStatTest extends TestCase
{
    public function testNewEntityHasIdentityAndEmptyMetrics(): void
    {
        $weekStart = new DateTimeImmutable('2026-11-16');
        $before    = new DateTimeImmutable();

        $stat = new StravaWeeklyStat(12345, $weekStart);

        self::assertNull($stat->getId());
        self::assertSame(12345, $stat->getAthleteId());
        self::assertSame($weekStart, $stat->getWeekStart());
        self::assertSame(0.0, $stat->getTotalDistance());
        self::assertSame(0.0, $stat->getTotalMiles());
        self::assertSame(0.0, $stat->getFiveWeekAverage());
        self::assertSame([], $stat->getDailyDistances());
        self::assertSame(0, $stat->getActivityCount());
        self::assertSame([], $stat->getActivities());
        self::assertGreaterThanOrEqual($before, $stat->getUpdatedAt());
    }

    public function testUpdateStoresMetrics(): void
    {
        $stat = new StravaWeeklyStat(12345, new DateTimeImmutable('2026-11-16'));

        $daily = ['Mon' => 5000.0, 'Tue' => 0.0, 'Wed' => 8046.7, 'Thu' => 0.0, 'Fri' => 0.0, 'Sat' => 16093.4, 'Sun' => 0.0];
        $stat->update(29140.1, 25000.0, $daily, 3);

        self::assertSame(29140.1, $stat->getTotalDistance());
        self::assertSame(25000.0, $stat->getFiveWeekAverage());
        self::assertSame($daily, $stat->getDailyDistances());
        self::assertSame(3, $stat->getActivityCount());
    }

    public function testUpdateOverwritesPreviousValues(): void
    {
        $stat = new StravaWeeklyStat(12345, new DateTimeImmutable('2026-11-16'));

        $stat->update(1000.0, 1000.0, ['Mon' => 1000.0], 1);
        $firstUpdatedAt = $stat->getUpdatedAt();

        $stat->update(2000.0, 1500.0, ['Mon' => 1000.0, 'Tue' => 1000.0], 2);

        self::assertSame(2000.0, $stat->getTotalDistance());
        self::assertSame(1500.0, $stat->getFiveWeekAverage());
        self::assertSame(['Mon' => 1000.0, 'Tue' => 1000.0], $stat->getDailyDistances());
        self::assertSame(2, $stat->getActivityCount());
        self::assertGreaterThanOrEqual($firstUpdatedAt, $stat->getUpdatedAt());
    }

    public function testToWeeklyStatRebuildsTheDisplayModelFromStoredActivities(): void
    {
        $stat = new StravaWeeklyStat(12345, new DateTimeImmutable('2026-11-16'));
        $stat->update(9000.0, 7000.0, [], 2, [
            ['sport_type' => 'Run', 'distance' => 5000.0, 'start_date_local' => '2026-11-16T08:00:00Z'],
            ['sport_type' => 'Swim', 'distance' => 1000.0, 'start_date_local' => '2026-11-18T07:00:00Z'],
        ]);

        $weeklyStat = $stat->toWeeklyStat();

        self::assertSame('2026-11-16', $weeklyStat->getFirstDayOfWeek()->format('Y-m-d'));
        self::assertSame(9000.0, $weeklyStat->getTotalDistance()); // 5000 + 1000 * 4 (swim weighting)
        self::assertSame(7000.0, $weeklyStat->getFiveWeekAverage());
        self::assertSame(2, $weeklyStat->getActivityCount());
        self::assertSame(5000.0, $weeklyStat->getDailyDistances()['Mon']);
        self::assertSame(4000.0, $weeklyStat->getDailyDistances()['Wed']);
    }

    public function testToWeeklyStatCountsRidesAsBiking(): void
    {
        $stat = new StravaWeeklyStat(12345, new DateTimeImmutable('2026-11-16'));
        $stat->update(0.0, 0.0, [], 1, [
            ['sport_type' => 'Ride', 'distance' => 30000.0, 'start_date_local' => '2026-11-16T08:00:00Z'],
        ]);

        $weeklyStat = $stat->toWeeklyStat();

        self::assertSame(10000.0, $weeklyStat->getTotalDistance()); // 30000 / 3 (bike weighting)
        self::assertSame(10000.0, $weeklyStat->getDailyDistances()['Mon']);
    }

    public function testToWeeklyStatWithoutActivitiesIsAnEmptyWeek(): void
    {
        $weeklyStat = (new StravaWeeklyStat(12345, new DateTimeImmutable('2026-11-16')))->toWeeklyStat();

        self::assertSame(0.0, $weeklyStat->getTotalDistance());
        self::assertSame(0, $weeklyStat->getActivityCount());
    }

    public function testTotalMilesConvertsMetersAndRoundsToOneDecimal(): void
    {
        $stat = new StravaWeeklyStat(12345, new DateTimeImmutable('2026-11-16'));

        $stat->update(16093.4, 0.0, [], 1);
        self::assertSame(10.0, $stat->getTotalMiles());

        $stat->update(5000.0, 0.0, [], 1);
        self::assertSame(3.1, $stat->getTotalMiles());
    }
}
