<?php

declare(strict_types=1);

namespace App\Tests\Model\Strava;

use App\Exception\Strava\InvalidStat;
use App\Model\Strava\WeeklyStat;
use DateTime;
use PHPUnit\Framework\TestCase;

use function date;
use function strtotime;

class WeeklyStatTest extends TestCase
{
    use UseSampleWeeklyActivities;

    public function testNonMondayFailsConstruct(): void
    {
        // @Todo: mock.
        $activities = $this->getSampleWeeklyActivities();

        $weekOfActivity = new DateTime($activities[0]['start_date_local']);
        $firstDayOfWeek = date('Y-m-d', strtotime('tuesday this week', $weekOfActivity->getTimestamp()));

        $this->expectException(InvalidStat::class);
        $this->expectExceptionMessage('First day of Week should be a Monday. Tuesday was given');

        new WeeklyStat($firstDayOfWeek);
    }

    public function testCorrectConstruct(): void
    {
        // @Todo: mock.
        $activities = $this->getSampleWeeklyActivities();

        $weekOfActivity = new DateTime($activities[0]['start_date_local']);
        $firstDayOfWeek = date('Y-m-d', strtotime('monday this week', $weekOfActivity->getTimestamp()));

        $weeklyStat = new WeeklyStat($firstDayOfWeek);

        foreach ($activities as $item) {
            $weeklyStat->addStravaActivity($item);
        }

        $this->assertCount(7, $weeklyStat);
        $this->assertSame(9136.9, $weeklyStat->getTotalDistance());
        $this->assertSame(5.7, $weeklyStat->getTotalMiles());
        $this->assertSame('10.30', $weeklyStat->getDate());
    }
}
