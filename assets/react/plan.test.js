import { describe, expect, it } from 'vitest';
import {
    addWeeks,
    cellsBetween,
    chartRange,
    chartWeeks,
    hasPlan,
    normalizeWeek,
    relativeMiles,
    round1,
    sameSports,
    weekLabel,
    weekTotal,
    withDay,
} from './plan.js';

describe('relativeMiles', () => {
    it('weights each sport like the stats (bike ÷3, swim ×4, tennis ×2)', () => {
        expect(relativeMiles({ Run: 5 })).toBe(5);
        expect(relativeMiles({ Bike: 30 })).toBe(10);
        expect(relativeMiles({ Swim: 1 })).toBe(4);
        expect(relativeMiles({ Tennis: 1.5 })).toBe(3);
        expect(relativeMiles({ Run: 5, Bike: 30, Swim: 1, Tennis: 1.5 })).toBe(22);
    });

    it('treats a missing day, missing sports and junk as 0', () => {
        expect(relativeMiles(undefined)).toBe(0);
        expect(relativeMiles({})).toBe(0);
        expect(relativeMiles({ Run: 'abc', Hike: 10 })).toBe(0);
    });
});

describe('weekTotal', () => {
    it('adds the relative miles of every day', () => {
        expect(weekTotal({ Mon: { Run: 5 }, Wed: { Bike: 30 }, Sun: { Swim: 1 } })).toBe(19);
        expect(weekTotal({})).toBe(0);
    });
});

describe('hasPlan', () => {
    it('is true when any day has miles', () => {
        expect(hasPlan({ days: { Tue: { Run: 3 } } })).toBe(true);
        expect(hasPlan({ days: {} })).toBe(false);
        expect(hasPlan({ days: { Tue: { Run: 0 } } })).toBe(false);
    });
});

describe('weeks', () => {
    it('labels a week like the stats table', () => {
        expect(weekLabel('2026-11-23')).toBe('11.23');
        expect(weekLabel('2027-01-04')).toBe('1.04');
    });

    it('adds weeks across months and years', () => {
        expect(addWeeks('2026-11-23', 1)).toBe('2026-11-30');
        expect(addWeeks('2026-12-28', 1)).toBe('2027-01-04');
        expect(addWeeks('2026-11-23', -8)).toBe('2026-09-28');
    });

    it('normalizes the empty days of the API ([]) to an object', () => {
        expect(normalizeWeek({ weekStart: '2026-11-23', days: [] }).days).toEqual({});
        const days = { Mon: { Run: 5 } };
        const week = normalizeWeek({ weekStart: '2026-11-23', days });
        expect(week.days).toEqual(days);
        expect(week.days).not.toBe(days);
    });

    it('rounds to one decimal', () => {
        expect(round1(21.666)).toBe(21.7);
        expect(round1(5)).toBe(5);
    });
});

describe('sameSports', () => {
    it('ignores the order of the sports and sports without miles', () => {
        expect(sameSports({ Run: 5, Bike: 10 }, { Bike: 10, Run: 5, Swim: 0 })).toBe(true);
        expect(sameSports(undefined, {})).toBe(true);
        expect(sameSports({ Run: 5 }, { Run: 6 })).toBe(false);
    });
});

describe('withDay', () => {
    it('replaces a day, or removes it when nothing is planned', () => {
        const days = { Mon: { Run: 5 }, Tue: { Run: 3 } };
        expect(withDay(days, 'Tue', { Bike: 10 })).toEqual({ Mon: { Run: 5 }, Tue: { Bike: 10 } });
        expect(withDay(days, 'Tue', {})).toEqual({ Mon: { Run: 5 } });
        expect(days).toEqual({ Mon: { Run: 5 }, Tue: { Run: 3 } });
    });
});

describe('cellsBetween', () => {
    const rows = ['2026-11-30', '2026-11-23', '2026-11-16'].map((weekStart) => ({ weekStart }));

    it('lists the rectangle between two cells, whichever comes first', () => {
        const cells = [
            { weekStart: '2026-11-23', day: 'Mon' },
            { weekStart: '2026-11-23', day: 'Tue' },
            { weekStart: '2026-11-16', day: 'Mon' },
            { weekStart: '2026-11-16', day: 'Tue' },
        ];
        expect(cellsBetween(rows, cells[0], cells[3])).toEqual(cells);
        expect(cellsBetween(rows, cells[2], cells[1])).toEqual(cells);
    });

    it('is empty when a week is not shown', () => {
        expect(cellsBetween(rows, { weekStart: '2020-01-06', day: 'Mon' }, { weekStart: '2026-11-16', day: 'Mon' })).toEqual([]);
    });
});

describe('chartRange', () => {
    it('loads 18 weeks before the current one, 26 after, and 4 more before for the average', () => {
        expect(chartRange('2026-11-23')).toEqual({ from: '2026-06-22', weeks: 49 });
    });
});

const run = (miles) => ({ Mon: { Run: miles } });

// Every week of chartRange('2026-11-23'), as the API returns them; `plans` by week.
const loaded = (plans = {}) => Array.from({ length: 49 }, (_, i) => addWeeks('2026-06-22', i))
    .map((weekStart) => ({ weekStart, days: plans[weekStart] ?? {} }));

const byStart = (weeks) => Object.fromEntries(weeks.map((week) => [week.weekStart, week]));

describe('chartWeeks', () => {
    const CURRENT = '2026-11-23';

    it('keeps the 45 weeks of the chart, oldest first', () => {
        const weeks = chartWeeks(loaded(), [], CURRENT).map((week) => week.weekStart);

        expect(weeks).toHaveLength(45);
        expect(weeks[0]).toBe('2026-07-20');
        expect(weeks[18]).toBe(CURRENT);
        expect(weeks[44]).toBe('2027-05-24');
    });

    it('averages the plans of the week and the 4 before it, a week without plan counting as 0', () => {
        const weeks = byStart(chartWeeks(loaded({
            '2026-06-22': run(10), // Before the chart: only counts for the average.
            '2026-07-20': run(5),
            '2026-11-02': run(4),
            '2026-11-16': run(6),
        }), [], CURRENT));

        expect(weeks['2026-07-20'].plannedAverage).toBe(3); // (10 + 0 + 0 + 0 + 5) / 5
        expect(weeks['2026-11-16'].plannedAverage).toBe(2); // (0 + 0 + 4 + 0 + 6) / 5
    });

    it('has no planned average for a week without plan', () => {
        const weeks = byStart(chartWeeks(loaded({ '2026-11-16': run(6) }), [], CURRENT));

        expect(weeks['2026-11-23'].plannedAverage).toBeNull();
    });

    it('has no planned average without the 5 weeks', () => {
        // E.g. the weeks of the chart could not be loaded, only the table's.
        const table = [
            { weekStart: '2026-11-09', days: run(4) },
            { weekStart: '2026-11-16', days: run(6) },
        ];

        expect(chartWeeks([], table, CURRENT).map((week) => week.plannedAverage)).toEqual([null, null]);
    });

    it('uses the latest weeks over the loaded ones', () => {
        const weeks = byStart(chartWeeks(loaded({ '2026-11-16': run(6) }), [{ weekStart: '2026-11-16', days: run(16) }], CURRENT));

        expect(weeks['2026-11-16'].days).toEqual(run(16));
        expect(weeks['2026-11-16'].plannedAverage).toBe(3.2);
    });

    it('leaves out the latest weeks outside the chart', () => {
        const weeks = chartWeeks(loaded(), [{ weekStart: '2026-01-05', days: run(3) }], CURRENT);

        expect(weeks.some((week) => week.weekStart === '2026-01-05')).toBe(false);
    });
});
