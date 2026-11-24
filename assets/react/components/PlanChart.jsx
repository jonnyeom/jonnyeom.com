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
import React, { useEffect, useMemo, useRef } from 'react';

Chart.register(BarController, BarElement, CategoryScale, Legend, LinearScale, LineController, LineElement, PointElement, Tooltip);

// Same colors as the Progress chart (WeeklyStatsTable), plus aqua for the plan. Validated for
// contrast and colorblind separation on both the light and dark page backgrounds.
const COLOR_ACTUAL = '#3987e5';
const COLOR_AVERAGE = '#d95926';
const COLOR_PLAN = '#199e70';
const COLOR_MUTED = '#898781';
const COLOR_GRID = 'rgba(137, 135, 129, 0.25)';

const BAR = {
    borderRadius: { topLeft: 4, topRight: 4 },
    borderSkipped: 'start',
    maxBarThickness: 16,
    categoryPercentage: 0.7,
    barPercentage: 0.9,
};

/**
 * Planned vs actual weekly miles (columns) and the 5-week average (line), oldest first.
 * Scrolls horizontally like the Progress chart, starting at the latest weeks; about 10 are visible.
 *
 * Props: weeks (oldest first, with planMiles), currentWeek, label(weekStart).
 */
export default function PlanChart({ weeks, currentWeek, label }) {
    const canvasRef = useRef(null);
    const chartRef = useRef(null);

    const data = useMemo(() => ({
        labels: weeks.map((week) => label(week.weekStart)),
        datasets: [
            {
                type: 'line',
                label: '5-week average',
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
                data: weeks.map((week) => week.planMiles || null),
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
    }), [weeks, label]);

    const currentIndex = weeks.findIndex((week) => week.weekStart === currentWeek);

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
                    legend: {
                        position: 'top',
                        align: 'end',
                        labels: { color: COLOR_MUTED, boxWidth: 12, boxHeight: 12 },
                    },
                    tooltip: {
                        callbacks: {
                            label: (item) => (item.raw === null ? null : `${item.dataset.label}: ${item.raw} mi`),
                        },
                    },
                },
                scales: {
                    x: { grid: { display: false }, ticks: { color: COLOR_MUTED } },
                    y: {
                        // On the right: the chart opens scrolled to the latest weeks.
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
        if (!chart) {
            return;
        }
        chart.data = data;
        // The current week's label is bold.
        chart.options.scales.x.ticks.font = (context) => ({ weight: context.index === currentIndex ? 'bold' : 'normal' });
        chart.update();
    }, [data, currentIndex]);

    return (
        <div className="strava-weekly-chart mb-5" tabIndex={0} aria-label="Weekly mileage plan chart, scroll left for older weeks">
            <div className="strava-weekly-chart__inner" style={{ '--chart-weeks': Math.max(weeks.length, 1) }}>
                <canvas
                    ref={canvasRef}
                    role="img"
                    aria-label={`Planned and actual weekly miles and the 5-week average for ${weeks.length} weeks. The table below lists every value.`}
                />
            </div>
        </div>
    );
}
