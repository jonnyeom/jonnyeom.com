<?php

declare(strict_types=1);

namespace App\Service\Strava;

use App\Entity\StravaWeeklyPlan;
use App\Enum\SportType;
use App\Exception\Strava\InvalidPlan;
use DateTimeImmutable;

use function array_filter;
use function array_key_exists;
use function fgetcsv;
use function fopen;
use function fputcsv;
use function fwrite;
use function implode;
use function preg_match;
use function rewind;
use function sprintf;
use function str_replace;
use function stream_get_contents;
use function strtolower;
use function trim;

/**
 * Weekly plans as CSV, to edit them in a spreadsheet (Google Sheets, Excel, Numbers).
 *
 * One row per week: Week (the Monday), then the miles of each day and sport ("Mon Run", "Mon Bike",
 * ... "Sun Tennis"), then Notes. Only the planned activities: no Strava actuals or other computed
 * values. Import ignores unknown columns, but needs all of these: a row replaces its week's plan, so
 * a missing column would silently clear data.
 *
 * @phpstan-import-type WeekPlanView from WeeklyPlanManager
 * @phpstan-type ImportedPlan array{days: array<string, array<string, float>>, notes: string|null}
 */
class WeeklyPlanCsv
{
    private const string WEEK  = 'Week';
    private const string NOTES = 'Notes';

    /** @param list<WeekPlanView> $weeks */
    public function export(array $weeks): string
    {
        $stream = fopen('php://temp', 'r+');
        if ($stream === false) {
            return '';
        }

        fputcsv($stream, [self::WEEK, ...self::dayColumns(), self::NOTES], escape: '');

        foreach ($weeks as $week) {
            $row = [$week['weekStart']];
            foreach (StravaWeeklyPlan::DAYS as $day) {
                foreach (SportType::cases() as $sport) {
                    $row[] = self::formatMiles($week['days'][$day][$sport->value] ?? 0.0);
                }
            }

            $row[] = $week['notes'] ?? '';

            fputcsv($stream, $row, escape: '');
        }

        rewind($stream);

        return (string) stream_get_contents($stream);
    }

    /**
     * A two-week example to start a CSV from: the weeks from $firstWeek (a Monday), with a mix of
     * days and sports, empty (rest) days and notes.
     */
    public function example(DateTimeImmutable $firstWeek): string
    {
        return $this->export([
            [
                'weekStart' => $firstWeek->format('Y-m-d'),
                'days' => [
                    'Tue' => ['Run' => 5.0],
                    'Wed' => ['Bike' => 20.0],
                    'Thu' => ['Run' => 6.0, 'Swim' => 1.0],
                    'Sat' => ['Run' => 10.0, 'Tennis' => 2.0],
                    'Sun' => ['Bike' => 30.0],
                ],
                'notes' => 'Base week: easy pace. Empty cells are rest.',
                'actual' => null,
            ],
            [
                'weekStart' => $firstWeek->modify('+1 week')->format('Y-m-d'),
                'days' => [
                    'Mon' => ['Swim' => 1.5],
                    'Tue' => ['Run' => 6.0],
                    'Wed' => ['Bike' => 25.0],
                    'Thu' => ['Run' => 7.0],
                    'Sat' => ['Run' => 12.0],
                    'Sun' => ['Bike' => 35.0, 'Tennis' => 1.5],
                ],
                'notes' => 'Build week: long run 12 mi.',
                'actual' => null,
            ],
        ]);
    }

    /**
     * Parses and validates a CSV. Every row replaces that week's plan; an empty row clears it.
     *
     * @return array<string, ImportedPlan> keyed by week start (Y-m-d)
     *
     * @throws InvalidPlan with the row number of the first problem.
     */
    public function parse(string $csv): array
    {
        // Spreadsheet apps may add a UTF-8 byte order mark.
        $csv = str_replace("\u{FEFF}", '', $csv);

        $stream = fopen('php://temp', 'r+');
        if ($stream === false) {
            throw new InvalidPlan('Could not read the CSV.');
        }

        fwrite($stream, $csv);
        rewind($stream);

        $header = fgetcsv($stream, escape: '');
        if ($header === false) {
            throw new InvalidPlan('The CSV is empty.');
        }

        // Column index by lower-cased header name.
        $columns = [];
        foreach ($header as $index => $name) {
            $columns[strtolower(trim((string) $name))] = $index;
        }

        $missing = array_filter(
            [self::WEEK, ...self::dayColumns(), self::NOTES],
            static fn (string $name): bool => ! array_key_exists(strtolower($name), $columns),
        );
        if ($missing !== []) {
            throw new InvalidPlan(sprintf('The CSV is missing these columns: %s.', implode(', ', $missing)));
        }

        $plans = [];
        $line  = 1;
        while (($row = fgetcsv($stream, escape: '')) !== false) {
            $line++;
            $cell = static fn (string $name): string => trim((string) ($row[$columns[strtolower($name)]] ?? ''));

            $weekCell = $cell(self::WEEK);
            if ($weekCell === '') {
                continue; // Blank line.
            }

            try {
                $weekStart = self::parseWeek($weekCell);
                $key       = $weekStart->format('Y-m-d');
                if (array_key_exists($key, $plans)) {
                    throw new InvalidPlan(sprintf('Week %s is listed twice.', $key));
                }

                $days = [];
                foreach (StravaWeeklyPlan::DAYS as $day) {
                    foreach (SportType::cases() as $sport) {
                        $days[$day][$sport->value] = $cell($day . ' ' . $sport->value);
                    }
                }

                // Validates the row (distances, notes) like a PUT of the week would.
                $plan = new StravaWeeklyPlan('0', $weekStart);
                $plan->update($days, $cell(self::NOTES));

                $plans[$key] = ['days' => $plan->getDays(), 'notes' => $plan->getNotes()];
            } catch (InvalidPlan $e) {
                throw new InvalidPlan(sprintf('Row %d: %s', $line, $e->getMessage()), 0, $e);
            }
        }

        return $plans;
    }

    /**
     * The week as YYYY-MM-DD, or as a spreadsheet may reformat it: M/D/YYYY or YYYY/M/D.
     *
     * @throws InvalidPlan
     */
    private static function parseWeek(string $value): DateTimeImmutable
    {
        if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', $value, $match) === 1) {
            $value = sprintf('%04d-%02d-%02d', $match[3], $match[1], $match[2]);
        } elseif (preg_match('#^(\d{4})[/-](\d{1,2})[/-](\d{1,2})$#', $value, $match) === 1) {
            $value = sprintf('%04d-%02d-%02d', $match[1], $match[2], $match[3]);
        }

        return WeeklyPlanManager::parseWeekStart($value);
    }

    /** @return list<string> "Mon Run", "Mon Bike", ... "Sun Tennis" */
    private static function dayColumns(): array
    {
        $columns = [];
        foreach (StravaWeeklyPlan::DAYS as $day) {
            foreach (SportType::cases() as $sport) {
                $columns[] = $day . ' ' . $sport->value;
            }
        }

        return $columns;
    }

    /** 0 is an empty cell; other values without trailing zeros (5, 6.7). */
    private static function formatMiles(float $miles): string
    {
        return $miles > 0.0 ? (string) $miles : '';
    }
}
