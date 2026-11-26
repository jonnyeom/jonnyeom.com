import { useEffect, useState } from 'react';
import { chartRange, normalizeWeek } from '../plan.js';

/**
 * Loads the weeks of the chart (see chartRange()) from the plan API, again whenever `version` changes.
 * Null until they are loaded; [] when they could not be (the chart then shows what else it has); a
 * failed reload keeps the weeks loaded before.
 */
export default function useChartWeeks(apiUrl, currentWeek, version = 0) {
    const [weeks, setWeeks] = useState(null);

    useEffect(() => {
        if (!currentWeek) {
            return undefined;
        }
        let cancelled = false;
        const { from, weeks: count } = chartRange(currentWeek);
        fetch(`${apiUrl}?from=${from}&weeks=${count}`, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
            .then((response) => (response.ok ? response.json() : Promise.reject(new Error(`${response.status}`))))
            .then((body) => body.weeks.map(normalizeWeek))
            .then((loaded) => {
                if (!cancelled) {
                    setWeeks(loaded);
                }
            }, () => {
                // Keeps the weeks loaded before, if any.
                if (!cancelled) {
                    setWeeks((current) => current ?? []);
                }
            });

        return () => {
            cancelled = true;
        };
    // oxlint-disable-next-line react/exhaustive-effect-dependencies -- version is what reloads the weeks.
    }, [apiUrl, currentWeek, version]);

    return weeks;
}
