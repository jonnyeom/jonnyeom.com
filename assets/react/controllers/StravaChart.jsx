import React, { useEffect, useMemo, useState } from 'react';
import PlanChart from '../components/PlanChart.jsx';
import useChartWeeks from '../components/useChartWeeks.js';
import { chartWeeks } from '../plan.js';

/**
 * The chart of the Plan page on the Progress page: planned and actual weekly miles and their 5-week
 * averages. Reloads after Refresh / Reset (the WeeklyStatsTable component dispatches strava:refreshed).
 *
 * Props: apiUrl (the plan API), currentWeek.
 */
export default function StravaChart({ apiUrl, currentWeek }) {
    const [version, setVersion] = useState(0);
    const loaded = useChartWeeks(apiUrl, currentWeek, version);

    useEffect(() => {
        const reload = () => setVersion((current) => current + 1);
        window.addEventListener('strava:refreshed', reload);
        return () => window.removeEventListener('strava:refreshed', reload);
    }, []);

    const weeks = useMemo(() => (loaded === null ? [] : chartWeeks(loaded, [], currentWeek)), [loaded, currentWeek]);

    return <PlanChart weeks={weeks} currentWeek={currentWeek} />;
}
