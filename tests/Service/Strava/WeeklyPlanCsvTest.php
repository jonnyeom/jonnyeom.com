<?php

declare(strict_types=1);

namespace App\Tests\Service\Strava;

use App\Exception\Strava\InvalidPlan;
use App\Service\Strava\WeeklyPlanCsv;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function array_keys;
use function explode;
use function str_repeat;
use function str_replace;
use function trim;

final class WeeklyPlanCsvTest extends TestCase
{
    public function testExportWritesOneRowPerWeek(): void
    {
        $csv = (new WeeklyPlanCsv())->export([
            [
                'weekStart' => '2026-11-23',
                'days' => ['Mon' => ['Run' => 5.0, 'Bike' => 30.0], 'Wed' => ['Swim' => 1.0]],
                'notes' => 'Build, "hard" week',
                'actual' => ['totalMiles' => 20.5, 'fiveWeekAverage' => 18.0],
            ],
        ]);

        $lines = explode("\n", $csv);
        self::assertStringStartsWith('Week,"Mon Run","Mon Bike","Mon Swim","Mon Tennis","Tue Run"', $lines[0]);
        self::assertStringEndsWith('"Sun Tennis",Notes', $lines[0]);
        self::assertStringNotContainsString('20.5', $csv);
        self::assertSame('2026-11-23,5,30,,,,,,,,,1,,,,,,,,,,,,,,,,,,"Build, ""hard"" week"', $lines[1]);
    }

    public function testExampleHasTwoWeeksThatImport(): void
    {
        $csv   = new WeeklyPlanCsv();
        $plans = $csv->parse($csv->example(new DateTimeImmutable('2026-11-30')));

        self::assertSame(['2026-11-30', '2026-12-07'], array_keys($plans));
        foreach ($plans as $plan) {
            self::assertNotSame([], $plan['days']);
            self::assertNotNull($plan['notes']);
        }
    }

    public function testExportedCsvImportsBack(): void
    {
        $csv   = new WeeklyPlanCsv();
        $plans = $csv->parse($csv->export([
            [
                'weekStart' => '2026-11-23',
                'days' => ['Mon' => ['Run' => 5.0], 'Sat' => ['Bike' => 30.0, 'Tennis' => 1.5]],
                'notes' => "Line 1\nLine 2",
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
        // Byte order mark, CRLF, any column order and case, reformatted dates, blank lines and
        // unknown columns.
        $header = str_replace(['Week,', ',Notes'], ['week,', ',NOTES,My column'], self::header());
        $csv    = "\u{FEFF}Unknown," . $header . "\r\n"
            . 'x,11/23/2026,,,,1.5' . str_repeat(',', 25) . "Long run,y\r\n"
            . str_repeat(',', 31) . "\r\n"
            . "x,2026/12/7\r\n";

        self::assertSame([
            '2026-11-23' => ['days' => ['Mon' => ['Tennis' => 1.5]], 'notes' => 'Long run'],
            // An empty row clears the week.
            '2026-12-07' => ['days' => [], 'notes' => null],
        ], (new WeeklyPlanCsv())->parse($csv));
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidCsv(): iterable
    {
        yield 'empty' => ['', 'The CSV is empty.'];
        yield 'no week column' => [str_replace('Week,', '', self::header()) . "\n5\n", 'The CSV is missing these columns: Week.'];
        yield 'no notes column' => [str_replace(',Notes', '', self::header()) . "\n2026-11-23,5\n", 'The CSV is missing these columns: Notes.'];
        yield 'no sport column' => [str_replace('"Mon Bike",', '', self::header()) . "\n2026-11-23,5\n", 'The CSV is missing these columns: Mon Bike.'];
        yield 'not a monday' => [self::header() . "\n2026-11-24,5\n", 'Row 2: A week starts on a Monday'];
        yield 'not a date' => [self::header() . "\nnext week,5\n", 'Row 2: Invalid week "next week"'];
        yield 'not a number' => [self::header() . "\n2026-11-23,abc\n", 'Row 2: Run miles on Mon must be a number.'];
        yield 'too many miles' => [self::header() . "\n2026-11-23,400\n", 'Row 2: Run miles on Mon must be between 0 and 300'];
        yield 'week twice' => [self::header() . "\n2026-11-23,5\n11/23/2026,6\n", 'Row 3: Week 2026-11-23 is listed twice.'];
        yield 'shifted row' => [self::header() . "\n2026-11-23,5" . str_repeat(',', 28) . "20.5,Easy\n", 'Row 2: There are more cells than columns'];
        yield 'notes too long' => [self::header() . "\n2026-11-23" . str_repeat(',', 29) . str_repeat('a', 2001) . "\n", 'Row 2: Notes must be at most 2000 characters.'];
    }

    #[DataProvider('invalidCsv')]
    public function testParseRejectsInvalidCsv(string $csv, string $message): void
    {
        $this->expectException(InvalidPlan::class);
        $this->expectExceptionMessage($message);

        (new WeeklyPlanCsv())->parse($csv);
    }

    private static function header(): string
    {
        return trim((new WeeklyPlanCsv())->export([]));
    }
}
