<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\StravaWeeklyPlan;
use App\Exception\Strava\InvalidPlan;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function str_repeat;

final class StravaWeeklyPlanTest extends TestCase
{
    public function testNewPlanIsEmpty(): void
    {
        $plan = new StravaWeeklyPlan('12345', new DateTimeImmutable('2026-11-23'));

        self::assertNull($plan->getId());
        self::assertSame('12345', $plan->getAthleteId());
        self::assertSame('2026-11-23', $plan->getWeekStart()->format('Y-m-d'));
        self::assertSame([], $plan->getDays());
        self::assertSame(0.0, $plan->getTotalMiles());
        self::assertNull($plan->getNotes());
        self::assertTrue($plan->isEmpty());
    }

    public function testWeekMustStartOnMonday(): void
    {
        $this->expectException(InvalidPlan::class);

        new StravaWeeklyPlan('12345', new DateTimeImmutable('2026-11-24'));
    }

    public function testUpdateNormalizesDaysAndComputesRelativeMiles(): void
    {
        $plan = new StravaWeeklyPlan('12345', new DateTimeImmutable('2026-11-23'));

        $plan->update([
            'Sat' => ['Run' => 10],
            'Mon' => ['Bike' => '30', 'Run' => 5.0, 'Swim' => 0],
            'Wed' => ['Swim' => 1, 'Tennis' => 1.5],
            'Thu' => [],
        ], '  Recovery week  ');

        // Mon..Sun and Run, Bike, Swim, Tennis order; zero and empty entries dropped.
        self::assertSame([
            'Mon' => ['Run' => 5.0, 'Bike' => 30.0],
            'Wed' => ['Swim' => 1.0, 'Tennis' => 1.5],
            'Sat' => ['Run' => 10.0],
        ], $plan->getDays());
        self::assertSame(
            ['Mon' => 15.0, 'Tue' => 0.0, 'Wed' => 7.0, 'Thu' => 0.0, 'Fri' => 0.0, 'Sat' => 10.0, 'Sun' => 0.0],
            $plan->getDailyRelativeMiles(),
        );
        self::assertSame(32.0, $plan->getTotalMiles());
        self::assertSame(['Run' => 15.0, 'Bike' => 30.0, 'Swim' => 1.0, 'Tennis' => 1.5], $plan->getSportTotals());
        self::assertSame('Recovery week', $plan->getNotes());
        self::assertFalse($plan->isEmpty());
    }

    public function testUpdateReplacesThePreviousPlan(): void
    {
        $plan = new StravaWeeklyPlan('12345', new DateTimeImmutable('2026-11-23'));
        $plan->update(['Mon' => ['Run' => 5]], 'notes');

        $plan->update(['Tue' => ['Run' => 3]]);

        self::assertSame(['Tue' => ['Run' => 3.0]], $plan->getDays());
        self::assertSame(3.0, $plan->getTotalMiles());
        self::assertNull($plan->getNotes());
    }

    /** @return iterable<string, array{array<mixed>}> */
    public static function invalidDays(): iterable
    {
        yield 'unknown day' => [['Monday' => ['Run' => 5]]];
        yield 'unknown sport' => [['Mon' => ['Hike' => 5]]];
        yield 'day is not an object' => [['Mon' => 5]];
        yield 'negative miles' => [['Mon' => ['Run' => -1]]];
        yield 'too many miles' => [['Mon' => ['Run' => 301]]];
        yield 'not a number' => [['Mon' => ['Run' => 'five']]];
    }

    /** @param array<mixed> $days */
    #[DataProvider('invalidDays')]
    public function testUpdateRejectsInvalidDays(array $days): void
    {
        $plan = new StravaWeeklyPlan('12345', new DateTimeImmutable('2026-11-23'));

        $this->expectException(InvalidPlan::class);

        $plan->update($days);
    }

    public function testUpdateRejectsTooLongNotes(): void
    {
        $plan = new StravaWeeklyPlan('12345', new DateTimeImmutable('2026-11-23'));

        $this->expectException(InvalidPlan::class);

        $plan->update([], str_repeat('a', StravaWeeklyPlan::MAX_NOTES_LENGTH + 1));
    }
}
