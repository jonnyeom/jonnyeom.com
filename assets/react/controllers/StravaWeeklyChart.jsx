import { max } from 'd3-array';
import { scaleBand, scaleLinear } from 'd3-scale';
import { line } from 'd3-shape';
import React, { useCallback, useEffect, useRef, useState } from 'react';

import './StravaWeeklyChart.css';

/*
 * Weekly mileage (columns) and the 5-week average (line) for the last N stored weeks.
 *
 * Data: GET {url}?weeks=N (src/Controller/StravaStatsController.php::chart), oldest week first.
 * Reloads when the strava-stats Stimulus controller dispatches "strava-stats:updated"
 * (after Refresh / Reset).
 */

const HEIGHT = 240;
const MARGIN = { top: 24, right: 56, bottom: 28, left: 40 };
const MAX_BAR_WIDTH = 24;
const BAR_RADIUS = 4;
const MIN_LABEL_SPACING = 40;
const TOOLTIP_WIDTH = 216;
const TOOLTIP_GAP = 12;

// Same format as the weeks in the table below (e.g. "11.16").
const formatWeek = (weekStart) => {
    const [, month, day] = weekStart.split('-');

    return `${Number(month)}.${day}`;
};

const formatMiles = (miles) => `${miles.toFixed(1)} mi`;

// Column with a 4px rounded data-end, square at the baseline.
const columnPath = (x, y, width, height) => {
    const r = Math.min(BAR_RADIUS, width / 2, height);

    return `M${x},${y + height}`
        + `V${y + r}Q${x},${y} ${x + r},${y}`
        + `H${x + width - r}Q${x + width},${y} ${x + width},${y + r}`
        + `V${y + height}Z`;
};

function useWidth(ref) {
    const [width, setWidth] = useState(0);

    useEffect(() => {
        if (!ref.current) {
            return undefined;
        }

        const observer = new ResizeObserver(([entry]) => setWidth(Math.floor(entry.contentRect.width)));
        observer.observe(ref.current);

        return () => observer.disconnect();
    }, [ref]);

    return width;
}

export default function StravaWeeklyChart({ url, weeks = 10 }) {
    const containerRef = useRef(null);
    const width = useWidth(containerRef);

    const [data, setData] = useState(null);
    const [error, setError] = useState(null);
    const [loading, setLoading] = useState(true);
    const [activeIndex, setActiveIndex] = useState(null);

    const load = useCallback(async (signal) => {
        setLoading(true);
        try {
            const requestUrl = new URL(url, window.location.origin);
            requestUrl.searchParams.set('weeks', String(weeks));

            const response = await fetch(requestUrl, { headers: { Accept: 'application/json' }, signal });
            const body = await response.json().catch(() => ({}));

            if (!response.ok) {
                setError(body.error ?? 'Could not load the chart.');

                return;
            }

            setError(null);
            setData(body.weeks ?? []);
        } catch (e) {
            if (e.name !== 'AbortError') {
                setError('Could not load the chart.');
            }
        } finally {
            if (!signal?.aborted) {
                setLoading(false);
            }
        }
    }, [url, weeks]);

    useEffect(() => {
        let controller = new AbortController();
        load(controller.signal);

        const reload = () => {
            controller.abort();
            controller = new AbortController();
            load(controller.signal);
        };
        window.addEventListener('strava-stats:updated', reload);

        return () => {
            controller.abort();
            window.removeEventListener('strava-stats:updated', reload);
        };
    }, [load]);

    const title = <h2 className="strava-chart__title">Weekly mileage, last {weeks} weeks</h2>;

    if (error) {
        return (
            <div className="strava-chart" ref={containerRef}>
                {title}
                <div className="notification is-danger is-light">{error}</div>
            </div>
        );
    }

    if (data !== null && data.length === 0) {
        return (
            <div className="strava-chart" ref={containerRef}>
                {title}
                <p className="strava-chart__empty">No stored weeks yet. Click Refresh or Reset to load them.</p>
            </div>
        );
    }

    const innerWidth = Math.max(0, width - MARGIN.left - MARGIN.right);
    const innerHeight = HEIGHT - MARGIN.top - MARGIN.bottom;
    const rows = data ?? [];

    const x = scaleBand().domain(rows.map((d) => d.weekStart)).range([0, innerWidth]).padding(0.3);
    const yMax = max(rows, (d) => Math.max(d.totalMiles, d.fiveWeekAverageMiles)) || 1;
    const y = scaleLinear().domain([0, yMax]).nice().range([innerHeight, 0]);
    const barWidth = Math.min(MAX_BAR_WIDTH, x.bandwidth());
    const center = (d) => (x(d.weekStart) ?? 0) + x.bandwidth() / 2;

    const averagePath = line()
        .x(center)
        .y((d) => y(d.fiveWeekAverageMiles))(rows);

    const latest = rows[rows.length - 1];
    const active = activeIndex !== null ? rows[activeIndex] : null;

    // Thin the week labels when they don't fit; always keep the latest week.
    const labelEvery = Math.max(1, Math.ceil(MIN_LABEL_SPACING / (x.step() || 1)));
    const showLabel = (i) => (rows.length - 1 - i) % labelEvery === 0;

    // The tooltip sits beside the crosshair (right, or left near the edge) so it never covers the week.
    let tooltipStyle = null;
    if (active) {
        const crosshairX = MARGIN.left + center(active);
        tooltipStyle = crosshairX + TOOLTIP_GAP + TOOLTIP_WIDTH <= width
            ? { left: crosshairX + TOOLTIP_GAP }
            : { left: Math.max(0, crosshairX - TOOLTIP_GAP - TOOLTIP_WIDTH) };
    }

    return (
        <div className="strava-chart" ref={containerRef}>
            <div className="strava-chart__header">
                {title}
                <ul className="strava-chart__legend" aria-label="Legend">
                    <li><span className="strava-chart__key strava-chart__key--bar" aria-hidden="true" />Weekly miles</li>
                    <li><span className="strava-chart__key strava-chart__key--line" aria-hidden="true" />5-week average</li>
                </ul>
            </div>

            <div className={`strava-chart__plot${loading ? ' is-reloading' : ''}`} aria-busy={loading}>
                {width > 0 && rows.length > 0 && (
                    <svg
                        width={width}
                        height={HEIGHT}
                        role="img"
                        aria-label={`Weekly miles and 5-week average for the last ${rows.length} weeks. The table below lists every value.`}
                        onPointerLeave={() => setActiveIndex(null)}
                    >
                        <g transform={`translate(${MARGIN.left},${MARGIN.top})`}>
                            {y.ticks(4).map((tick) => (
                                <g key={tick} transform={`translate(0,${y(tick)})`}>
                                    <line className={tick === 0 ? 'strava-chart__baseline' : 'strava-chart__grid'} x2={innerWidth} />
                                    <text className="strava-chart__tick" x={-8} dy="0.32em" textAnchor="end">{tick}</text>
                                </g>
                            ))}

                            {rows.map((d, i) => showLabel(i) && (
                                <text
                                    key={d.weekStart}
                                    className="strava-chart__tick"
                                    x={center(d)}
                                    y={innerHeight + 18}
                                    textAnchor="middle"
                                >
                                    {formatWeek(d.weekStart)}
                                </text>
                            ))}

                            {active && (
                                <line className="strava-chart__crosshair" x1={center(active)} x2={center(active)} y2={innerHeight} />
                            )}

                            {rows.map((d, i) => (
                                <path
                                    key={d.weekStart}
                                    className={`strava-chart__bar${activeIndex === i ? ' is-active' : ''}`}
                                    d={columnPath(center(d) - barWidth / 2, y(d.totalMiles), barWidth, innerHeight - y(d.totalMiles))}
                                />
                            ))}

                            <path className="strava-chart__line" d={averagePath ?? ''} />
                            {rows.map((d) => (
                                <circle
                                    key={d.weekStart}
                                    className="strava-chart__dot"
                                    cx={center(d)}
                                    cy={y(d.fiveWeekAverageMiles)}
                                    r={4}
                                />
                            ))}

                            {/* Selective direct labels: the latest week only. */}
                            {latest && (
                                <>
                                    <text
                                        className="strava-chart__value"
                                        x={center(latest)}
                                        y={y(latest.totalMiles) - 8}
                                        textAnchor="middle"
                                    >
                                        {latest.totalMiles.toFixed(1)}
                                    </text>
                                    <text
                                        className="strava-chart__value strava-chart__value--secondary"
                                        x={center(latest) + 10}
                                        y={y(latest.fiveWeekAverageMiles)}
                                        dy="0.32em"
                                    >
                                        avg {latest.fiveWeekAverageMiles.toFixed(1)}
                                    </text>
                                </>
                            )}

                            {/* Hit targets: the whole week band, bigger than the marks. */}
                            {rows.map((d, i) => (
                                <rect
                                    key={d.weekStart}
                                    className="strava-chart__hit"
                                    x={x(d.weekStart) - (x.step() - x.bandwidth()) / 2}
                                    width={x.step()}
                                    height={innerHeight}
                                    tabIndex={0}
                                    aria-label={`Week of ${formatWeek(d.weekStart)}: ${formatMiles(d.totalMiles)}, 5-week average ${formatMiles(d.fiveWeekAverageMiles)}`}
                                    onPointerEnter={() => setActiveIndex(i)}
                                    onFocus={() => setActiveIndex(i)}
                                    onBlur={() => setActiveIndex(null)}
                                />
                            ))}
                        </g>
                    </svg>
                )}

                {active && (
                    <div className="strava-chart__tooltip" style={{ ...tooltipStyle, width: TOOLTIP_WIDTH }} role="status">
                        <div className="strava-chart__tooltip-title">Week of {formatWeek(active.weekStart)}</div>
                        <div className="strava-chart__tooltip-row">
                            <span className="strava-chart__key strava-chart__key--bar-line" aria-hidden="true" />
                            <strong>{formatMiles(active.totalMiles)}</strong>
                            <span>Weekly miles</span>
                        </div>
                        <div className="strava-chart__tooltip-row">
                            <span className="strava-chart__key strava-chart__key--line" aria-hidden="true" />
                            <strong>{formatMiles(active.fiveWeekAverageMiles)}</strong>
                            <span>5-week average</span>
                        </div>
                    </div>
                )}
            </div>
        </div>
    );
}
