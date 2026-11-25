import { describe, expect, it } from 'vitest';
import {
    addWeeks,
    cellsBetween,
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
