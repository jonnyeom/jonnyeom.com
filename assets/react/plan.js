/**
 * Weekly plan helpers shared by the planner components. Distances are miles.
 */

export const DAYS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

// Same weighting as the stats (App\Enum\SportType::relativeDistance()).
export const SPORTS = [
    { type: 'Run', icon: 'person-running', factor: 1 },
    { type: 'Bike', icon: 'person-biking', factor: 1 / 3 },
    { type: 'Swim', icon: 'person-swimming', factor: 4 },
    { type: 'Tennis', icon: 'table-tennis-paddle-ball', factor: 2 },
];

export const MAX_MILES = 300;

export const round1 = (value) => Math.round(value * 10) / 10;

export const addWeeks = (weekStart, weeks) => {
    const date = new Date(`${weekStart}T00:00:00Z`);
    date.setUTCDate(date.getUTCDate() + weeks * 7);
    return date.toISOString().slice(0, 10);
};

// "2026-11-23" -> "11.23", like the stats table.
export const weekLabel = (weekStart) => {
    const [, month, day] = weekStart.split('-');
    return `${Number(month)}.${day}`;
};

export const relativeMiles = (sports) =>
    SPORTS.reduce((total, sport) => total + (Number(sports?.[sport.type]) || 0) * sport.factor, 0);

export const weekTotal = (days) => DAYS.reduce((total, day) => total + relativeMiles(days?.[day]), 0);

export const hasPlan = (week) => DAYS.some((day) => relativeMiles(week.days?.[day]) > 0);

// Whether two days plan the same miles (the order of the sports doesn't matter).
export const sameSports = (a, b) => SPORTS.every(({ type }) => (Number(a?.[type]) || 0) === (Number(b?.[type]) || 0));

/** The week's days with one day replaced; a day without sports is removed. */
export const withDay = (days, day, sports) => {
    const { [day]: _replaced, ...others } = days;
    return Object.keys(sports ?? {}).length === 0 ? others : { ...others, [day]: sports };
};

// The items from a to b (in either order), both included.
const between = (list, a, b) => {
    const [start, end] = [list.indexOf(a), list.indexOf(b)].toSorted((x, y) => x - y);
    return start < 0 ? [] : list.slice(start, end + 1);
};

/** The cells of the rectangle between two cells ({ weekStart, day }), in the order of the rows. */
export const cellsBetween = (rows, from, to) => {
    const days = between(DAYS, from.day, to.day);
    return between(rows.map((week) => week.weekStart), from.weekStart, to.weekStart)
        .flatMap((weekStart) => days.map((day) => ({ weekStart, day })));
};

export const cellLabel = ({ weekStart, day }) => `${day} ${weekLabel(weekStart)}`;

// The API returns [] for a week without plan; the grid always works with an object.
export const normalizeWeek = (week) => ({ ...week, days: Array.isArray(week.days) ? {} : { ...week.days } });

// Inputs of at least 16px, so phones don't zoom in when one is focused.
export const inputSize = (compact) => (compact ? '' : ' is-small');

