<?php

declare(strict_types=1);

namespace App\Tests\Model\Strava;

use App\Model\Strava\DailyStat;
use Generator;
use PHPUnit\Framework\TestCase;

/** @phpstan-import-type Activity from DailyStat */
class DailyStatTest extends TestCase
{
    use UseSampleWeeklyActivities;

    /**
     * @param Activity $activity
     *
     * @dataProvider provideDifferentActivities
     */
    public function testDifferentActivities($activity, float $distance, float $miles): void
    {
        $dailyStat = new DailyStat();
        $dailyStat->addActivity($activity);

        $this->assertCount(1, $dailyStat->getActivities());
        $this->assertSame($distance, $dailyStat->getDistance());
        $this->assertSame($miles, $dailyStat->getMiles());
        $this->assertSame($miles, $dailyStat->getRelativeMiles());
    }

    public static function provideDifferentActivities(): Generator
    {
        $activities = self::getSampleWeeklyActivities();

        yield '5k run' => [$activities[0], 5000.0, 3.1];
        yield '10k run' => [$activities[1], 10000.0, 6.2];
        yield '2mi tennis' => [$activities[2], 6437.4, 4.0];
        yield '1mi swim' => [$activities[3], 6492.24, 4.0];
    }
}
