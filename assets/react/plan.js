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

// The API returns [] for a week without plan; the grid always works with an object.
export const normalizeWeek = (week) => ({ ...week, days: Array.isArray(week.days) ? {} : { ...week.days } });

// Inputs of at least 16px, so phones don't zoom in when one is focused.
export const inputSize = (compact) => (compact ? '' : ' is-small');

