<?php

declare(strict_types=1);

namespace App\Service\Strava;

use App\Entity\StravaWeeklyPlan;
use App\Enum\SportType;
use App\Exception\Strava\InvalidPlan;
use DateTimeImmutable;

use function array_key_exists;
use function count;
use function fclose;
use function fgetcsv;
use function fopen;
use function fputcsv;
use function fwrite;
use function is_numeric;
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
 * ... "Sun Tennis"), then Notes. Export also adds the computed "Plan" and "Actual" totals, which
 * import ignores, like any other unknown column.
 *
 * @phpstan-import-type WeekPlanView from WeeklyPlanManager
 * @phpstan-type ImportedPlan array{days: array<string, array<string, float>>, notes: string|null}
 */
class WeeklyPlanCsv
{
    public const int MAX_ROWS = 200;

    private const string WEEK      = 'Week';
    private const string NOTES     = 'Notes';
    private const string PLAN      = 'Plan (rel. mi)';
    private const string ACTUAL    = 'Actual (mi)';
    private const string SEPARATOR = ',';
    private const string ENCLOSURE = '"';
    private const string ESCAPE    = '';

    /** @param list<WeekPlanView> $weeks */
    public function export(array $weeks): string
    {
        $stream = fopen('php://temp', 'r+');
        if ($stream === false) {
            return '';
        }

        fputcsv($stream, [self::WEEK, ...self::dayColumns(), self::PLAN, self::ACTUAL, self::NOTES], self::SEPARATOR, self::ENCLOSURE, self::ESCAPE);

        foreach ($weeks as $week) {
            $row = [$week['weekStart']];
            foreach (StravaWeeklyPlan::DAYS as $day) {
                foreach (SportType::cases() as $sport) {
                    $row[] = self::formatMiles($week['days'][$day][$sport->value] ?? 0.0);
                }
            }

            $row[] = self::formatMiles($week['totalMiles']);
            $row[] = $week['actual'] === null ? '' : self::formatMiles($week['actual']['totalMiles']);
            $row[] = $week['notes'] ?? '';

            fputcsv($stream, $row, self::SEPARATOR, self::ENCLOSURE, self::ESCAPE);
        }

        rewind($stream);
        $csv = (string) stream_get_contents($stream);
        fclose($stream);

        return $csv;
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

        try {
            return $this->parseRows($stream);
        } finally {
            fclose($stream);
        }
    }

    /**
     * @param resource $stream
     *
     * @return array<string, ImportedPlan>
     *
     * @throws InvalidPlan
     */
    private function parseRows($stream): array
    {
        $header = fgetcsv($stream, null, self::SEPARATOR, self::ENCLOSURE, self::ESCAPE);
        if ($header === false) {
            throw new InvalidPlan('The CSV is empty.');
        }

        // Column index by lower-cased header name.
        $columns = [];
        foreach ($header as $index => $name) {
            $columns[strtolower(trim((string) $name))] = $index;
        }

        if (! array_key_exists(strtolower(self::WEEK), $columns)) {
            throw new InvalidPlan(sprintf('The CSV needs a "%s" column (the Monday of each week).', self::WEEK));
        }

        $plans = [];
        $line  = 1;
        while (($row = fgetcsv($stream, null, self::SEPARATOR, self::ENCLOSURE, self::ESCAPE)) !== false) {
            $line++;
            $cell = static fn (string $name): string => trim((string) ($row[$columns[strtolower($name)] ?? -1] ?? ''));

            $weekCell = $cell(self::WEEK);
            if ($weekCell === '') {
                continue; // Blank line.
            }

            if (count($plans) >= self::MAX_ROWS) {
                throw new InvalidPlan(sprintf('At most %d weeks can be imported at once.', self::MAX_ROWS));
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
                        $value = $cell($day . ' ' . $sport->value);
                        if ($value === '') {
                            continue;
                        }

                        if (! is_numeric($value)) {
                            throw new InvalidPlan(sprintf('"%s %s" must be a number of miles, "%s" was given.', $day, $sport->value, $value));
                        }

                        $days[$day][$sport->value] = (float) $value;
                    }
                }

                $notes = $cell(self::NOTES);

                // Validates the row (distances, notes) like a PUT of the week would.
                $plan = new StravaWeeklyPlan('0', $weekStart);
                $plan->update($days, $notes);

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
