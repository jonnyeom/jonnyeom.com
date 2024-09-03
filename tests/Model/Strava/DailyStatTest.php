<?php

declare(strict_types=1);

namespace App\Tests\Model\Strava;

use App\Model\Strava\DailyStat;
use PHPUnit\Framework\TestCase;

class DailyStatTest extends TestCase
{
    use UseSampleWeeklyActivities;

    public function testCorrectConstruct(): void
    {
        $dailyStat = new DailyStat();
        $dailyStat->addActivity($this->getSampleWeeklyActivities()[0]);

        $this->assertCount(1, $dailyStat->getActivities());
        $this->assertSame(5000.0, $dailyStat->getDistance());
        $this->assertSame(3.1, $dailyStat->getMiles());
        $this->assertSame(3.1, $dailyStat->getRelativeMiles());
    }
}
