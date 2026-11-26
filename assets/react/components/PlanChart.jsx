import {
    BarController,
    BarElement,
    CategoryScale,
    Chart,
    Legend,
    LinearScale,
    LineController,
    LineElement,
    PointElement,
    Tooltip,
} from 'chart.js';
import React, { useEffect, useRef } from 'react';
import { round1, weekLabel, weekTotal } from '../plan.js';

Chart.register(BarController, BarElement, CategoryScale, Legend, LinearScale, LineController, LineElement, PointElement, Tooltip);

// Validated for contrast and colorblind separation on both the light and dark page backgrounds.
const COLOR_ACTUAL = '#3987e5';
const COLOR_AVERAGE = '#d95926';
const COLOR_PLAN = '#199e70';
const COLOR_MUTED = '#898781';
const COLOR_GRID = 'rgba(137, 135, 129, 0.25)';

const LEGEND = [
    { label: 'Planned', color: COLOR_PLAN },
    { label: 'Actual', color: COLOR_ACTUAL },
    { label: 'Planned 5 wk avg', color: COLOR_PLAN, line: 'dashed' },
    { label: 'Actual 5 wk avg', color: COLOR_AVERAGE, line: 'solid' },
];

const BAR = {
    borderRadius: { topLeft: 4, topRight: 4 },
    borderSkipped: 'start',
    maxBarThickness: 16,
    categoryPercentage: 0.7,
    barPercentage: 0.9,
};

/**
 * Planned vs actual weekly miles (columns) and their 5-week averages (lines), oldest first: the chart of
 * the Plan and Progress pages. Scrolls horizontally, opening with the current week a third in; 25 weeks
 * are visible (12 on phones).
 *
 * Props: weeks (oldest first, from chartWeeks()), currentWeek.
 */
export default function PlanChart({ weeks, currentWeek }) {
    const containerRef = useRef(null);
    const canvasRef = useRef(null);
    const chartRef = useRef(null);
    const scrolledRef = useRef(false);

    useEffect(() => {
        chartRef.current = new Chart(canvasRef.current, {
            type: 'bar',
            data: { labels: [], datasets: [] },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                animation: { duration: 200 },
                // One tooltip listing every series of the hovered week.
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    // Drawn in HTML above the chart, so it stays visible while the chart scrolls.
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: (item) => (item.raw === null ? null : `${item.dataset.label}: ${item.raw} mi`),
                        },
                    },
                },
                scales: {
                    x: { grid: { display: false }, ticks: { color: COLOR_MUTED, maxRotation: 0, autoSkipPadding: 2 } },
                    y: {
                        position: 'right',
                        beginAtZero: true,
                        grid: { color: COLOR_GRID },
                        border: { display: false },
                        ticks: { color: COLOR_MUTED, maxTicksLimit: 5 },
                    },
                },
            },
        });

        return () => chartRef.current?.destroy();
    }, []);

    useEffect(() => {
        const chart = chartRef.current;
        chart.data = {
            labels: weeks.map((week) => weekLabel(week.weekStart)),
            datasets: [
                {
                    type: 'line',
                    label: 'Planned 5 wk avg',
                    data: weeks.map((week) => week.plannedAverage ?? null),
                    borderColor: COLOR_PLAN,
                    backgroundColor: COLOR_PLAN,
                    borderWidth: 2,
                    borderDash: [5, 4],
                    pointRadius: 3,
                    pointHoverRadius: 4,
                    tension: 0,
                    order: 0,
                },
                {
                    type: 'line',
                    label: 'Actual 5 wk avg',
                    data: weeks.map((week) => week.actual?.fiveWeekAverage ?? null),
                    borderColor: COLOR_AVERAGE,
                    backgroundColor: COLOR_AVERAGE,
                    borderWidth: 2,
                    pointRadius: 4,
                    pointHoverRadius: 5,
                    tension: 0,
                    order: 1,
                },
                {
                    label: 'Planned',
                    data: weeks.map((week) => round1(weekTotal(week.days)) || null),
                    backgroundColor: COLOR_PLAN,
                    order: 2,
                    ...BAR,
                },
                {
                    label: 'Actual',
                    data: weeks.map((week) => week.actual?.totalMiles ?? null),
                    backgroundColor: COLOR_ACTUAL,
                    order: 3,
                    ...BAR,
                },
            ],
        };
        // The current week's label is bold.
        const currentIndex = weeks.findIndex((week) => week.weekStart === currentWeek);
        chart.options.scales.x.ticks.font = (context) => ({ weight: context.index === currentIndex ? 'bold' : 'normal' });
        chart.update();

        // Open scrolled to the current week, about a third from the left. From the size of the box: the
        // chart itself is resized later (by a ResizeObserver).
        const container = containerRef.current;
        if (!scrolledRef.current && currentIndex >= 0 && container) {
            scrolledRef.current = true;
            const inner = container.firstElementChild.getBoundingClientRect();
            const weekX = inner.left + (inner.width * (currentIndex + 0.5)) / weeks.length;
            const box = container.getBoundingClientRect();
            container.scrollBy({ left: weekX - (box.left + box.width / 3) });
        }
    }, [weeks, currentWeek]);

    return (
        <>
        <div className="is-flex is-justify-content-flex-end is-flex-wrap-wrap is-size-7 mb-1" style={{ gap: '0.25rem 1rem', color: COLOR_MUTED }}>
            {LEGEND.map(({ label: name, color, line }) => (
                <span key={name} className="is-flex is-align-items-center" style={{ gap: '0.375rem' }}>
                    <span
                        aria-hidden="true"
                        style={line
                            ? { width: 14, height: 0, borderTop: `2px ${line} ${color}` }
                            : { width: 10, height: 10, background: color, borderRadius: 2 }}
                    />
                    {name}
                </span>
            ))}
        </div>
        <section
            ref={containerRef}
            className="strava-weekly-chart mb-5"
            // A scrollable region: focusable so it can be scrolled with the keyboard.
            tabIndex={0}
            aria-label="Weekly mileage chart, scroll for other weeks"
        >
            <div className="strava-weekly-chart__inner" style={{ '--chart-weeks': Math.max(weeks.length, 1) }}>
                <canvas
                    ref={canvasRef}
                    // oxlint-disable-next-line jsx-a11y/prefer-tag-over-role -- Chart.js draws on a canvas; role="img" + aria-label describes it.
                    role="img"
                    aria-label={`Planned and actual weekly miles and their 5-week averages for ${weeks.length} weeks. The table below lists the values.`}
                />
            </div>
        </section>
        </>
    );
}
