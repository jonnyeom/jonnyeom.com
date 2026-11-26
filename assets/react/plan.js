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

export const isSameCell = (a, b) => a.weekStart === b.weekStart && a.day === b.day;

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

// The chart (Plan and Progress pages): 25 weeks fit in its view (12 on phones; see .strava-weekly-chart__inner),
// opening with the current week a third in, so 8 weeks before it. 10 more weeks can be scrolled to on
// either side.
export const CHART_WEEKS_BEFORE = 18;
export const CHART_WEEKS = 45;

// The 5-week averages: of the week and the 4 before it.
const AVERAGE_WEEKS = 5;

/** The weeks to load for the chart: its weeks and the 4 before them, for the planned average. */
export const chartRange = (currentWeek) => ({
    from: addWeeks(currentWeek, -(CHART_WEEKS_BEFORE + AVERAGE_WEEKS - 1)),
    weeks: CHART_WEEKS + AVERAGE_WEEKS - 1,
});

/**
 * The weeks of the chart, oldest first, each with its planned 5-week average (`plannedAverage`, null for a
 * week without plan). `loaded` are the weeks of chartRange(); `latest` overrides them (e.g. the weeks
 * being edited).
 */
export const chartWeeks = (loaded, latest, currentWeek) => {
    const byStart = new Map([...loaded, ...latest].map((week) => [week.weekStart, week]));
    const all = [...byStart.values()].toSorted((a, b) => a.weekStart.localeCompare(b.weekStart));
    const first = addWeeks(currentWeek, -CHART_WEEKS_BEFORE);
    const last = addWeeks(first, CHART_WEEKS - 1);

    return all
        .map((week) => {
            const plan = weekTotal(week.days);
            if (plan <= 0) {
                return { ...week, plannedAverage: null };
            }
            // A week without plan counts as 0; without all 5 weeks loaded (the chart's weeks could not be),
            // there is no average.
            const since = addWeeks(week.weekStart, -(AVERAGE_WEEKS - 1));
            const plans = all
                .filter((other) => other.weekStart >= since && other.weekStart <= week.weekStart)
                .map((other) => weekTotal(other.days));
            const average = plans.length < AVERAGE_WEEKS ? null : round1(plans.reduce((sum, miles) => sum + miles, 0) / AVERAGE_WEEKS);
            return { ...week, plannedAverage: average };
        })
        .filter((week) => week.weekStart >= first && week.weekStart <= last);
};
