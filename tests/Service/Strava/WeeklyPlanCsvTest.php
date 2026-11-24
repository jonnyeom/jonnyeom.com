<?php

declare(strict_types=1);

namespace App\Tests\Service\Strava;

use App\Exception\Strava\InvalidPlan;
use App\Service\Strava\WeeklyPlanCsv;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function explode;
use function str_repeat;

final class WeeklyPlanCsvTest extends TestCase
{
    public function testExportWritesOneRowPerWeek(): void
    {
        $csv = (new WeeklyPlanCsv())->export([
            [
                'weekStart' => '2026-11-23',
                'days' => ['Mon' => ['Run' => 5.0, 'Bike' => 30.0], 'Wed' => ['Swim' => 1.0]],
                'dailyMiles' => [],
                'sportTotals' => [],
                'totalMiles' => 19.0,
                'notes' => 'Build, "hard" week',
                'updatedAt' => null,
                'actual' => ['totalMiles' => 20.5, 'dailyMiles' => [], 'fiveWeekAverage' => 18.0],
            ],
        ]);

        $lines = explode("\n", $csv);
        self::assertStringStartsWith('Week,"Mon Run","Mon Bike","Mon Swim","Mon Tennis","Tue Run"', $lines[0]);
        self::assertStringEndsWith('"Sun Tennis","Plan (rel. mi)","Actual (mi)",Notes', $lines[0]);
        self::assertSame('2026-11-23,5,30,,,,,,,,,1,,,,,,,,,,,,,,,,,,19,20.5,"Build, ""hard"" week"', $lines[1]);
    }

    public function testExportedCsvImportsBack(): void
    {
        $csv   = new WeeklyPlanCsv();
        $plans = $csv->parse($csv->export([
            [
                'weekStart' => '2026-11-23',
                'days' => ['Mon' => ['Run' => 5.0], 'Sat' => ['Bike' => 30.0, 'Tennis' => 1.5]],
                'dailyMiles' => [],
                'sportTotals' => [],
                'totalMiles' => 18.0,
                'notes' => "Line 1\nLine 2",
                'updatedAt' => null,
                'actual' => null,
            ],
        ]));

        self::assertSame([
            '2026-11-23' => [
                'days' => ['Mon' => ['Run' => 5.0], 'Sat' => ['Bike' => 30.0, 'Tennis' => 1.5]],
                'notes' => "Line 1\nLine 2",
            ],
        ], $plans);
    }

    public function testParseAcceptsWhatASpreadsheetSaves(): void
    {
        // Byte order mark, CRLF, any column order and case, missing columns, reformatted dates,
        // blank lines and unknown columns.
        $csv = "\u{FEFF}Notes,week,Sat Run,Mon Tennis,Plan (rel. mi)\r\n"
            . "Long run,11/23/2026,12,1.5,15\r\n"
            . ",,,,\r\n"
            . ",2026/12/7,,,\r\n";

        self::assertSame([
            '2026-11-23' => ['days' => ['Mon' => ['Tennis' => 1.5], 'Sat' => ['Run' => 12.0]], 'notes' => 'Long run'],
            // An empty row clears the week.
            '2026-12-07' => ['days' => [], 'notes' => null],
        ], (new WeeklyPlanCsv())->parse($csv));
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidCsv(): iterable
    {
        yield 'empty' => ['', 'The CSV is empty.'];
        yield 'no week column' => ["Mon Run\n5\n", 'The CSV needs a "Week" column'];
        yield 'not a monday' => ["Week,Mon Run\n2026-11-24,5\n", 'Row 2: A week starts on a Monday'];
        yield 'not a date' => ["Week,Mon Run\nnext week,5\n", 'Row 2: Invalid week "next week"'];
        yield 'not a number' => ["Week,Mon Run\n2026-11-23,abc\n", 'Row 2: "Mon Run" must be a number of miles'];
        yield 'too many miles' => ["Week,Mon Run\n2026-11-23,400\n", 'Row 2: Run miles on Mon must be between 0 and 300'];
        yield 'week twice' => ["Week,Mon Run\n2026-11-23,5\n11/23/2026,6\n", 'Row 3: Week 2026-11-23 is listed twice.'];
        yield 'notes too long' => ["Week,Notes\n2026-11-23," . str_repeat('a', 2001) . "\n", 'Row 2: Notes must be at most 2000 characters.'];
    }

    #[DataProvider('invalidCsv')]
    public function testParseRejectsInvalidCsv(string $csv, string $message): void
    {
        $this->expectException(InvalidPlan::class);
        $this->expectExceptionMessage($message);

        (new WeeklyPlanCsv())->parse($csv);
    }
}
