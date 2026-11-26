import { act, render, waitFor } from '@testing-library/react';
import React from 'react';
import { describe, expect, it, vi } from 'vitest';
import PlanChart from '../components/PlanChart.jsx';
import StravaChart from './StravaChart.jsx';

// Chart.js needs a real canvas, which jsdom doesn't have.
vi.mock('../components/PlanChart.jsx', () => ({ default: vi.fn(() => null) }));

const API = '/api/strava/plans';
const RANGE = `${API}?from=2026-06-22&weeks=49`;

const json = (body, status = 200) => Promise.resolve(new Response(JSON.stringify(body), { status }));
const weeks = (...starts) => ({ weeks: starts.map((weekStart) => ({ weekStart, days: [], actual: null })) });

// The weeks the chart shows.
const chartWeeks = () => PlanChart.mock.lastCall[0].weeks.map((week) => week.weekStart);

const refreshed = () => act(() => {
    window.dispatchEvent(new CustomEvent('strava:refreshed'));
});

describe('StravaChart', () => {
    it('shows the weeks around the current one', async () => {
        const fetchMock = vi.fn(() => json(weeks('2026-11-16', '2026-11-23')));
        vi.stubGlobal('fetch', fetchMock);

        render(<StravaChart apiUrl={API} currentWeek="2026-11-23" />);

        expect(PlanChart.mock.lastCall[0]).toEqual({ weeks: [], currentWeek: '2026-11-23' });
        await waitFor(() => expect(chartWeeks()).toEqual(['2026-11-16', '2026-11-23']));
        expect(fetchMock).toHaveBeenCalledWith(RANGE, expect.anything());
    });

    it('reloads after Refresh / Reset', async () => {
        const fetchMock = vi.fn()
            .mockReturnValueOnce(json(weeks('2026-11-16')))
            .mockReturnValueOnce(json(weeks('2026-11-16', '2026-11-23')));
        vi.stubGlobal('fetch', fetchMock);
        render(<StravaChart apiUrl={API} currentWeek="2026-11-23" />);
        await waitFor(() => expect(chartWeeks()).toEqual(['2026-11-16']));

        await refreshed();

        await waitFor(() => expect(chartWeeks()).toEqual(['2026-11-16', '2026-11-23']));
        expect(fetchMock).toHaveBeenCalledTimes(2);
    });

    it('keeps the weeks when a reload fails', async () => {
        const fetchMock = vi.fn()
            .mockReturnValueOnce(json(weeks('2026-11-16')))
            .mockReturnValueOnce(json({ error: 'offline' }, 500));
        vi.stubGlobal('fetch', fetchMock);
        render(<StravaChart apiUrl={API} currentWeek="2026-11-23" />);
        await waitFor(() => expect(chartWeeks()).toEqual(['2026-11-16']));

        await refreshed();
        await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(2));
        await act(() => Promise.resolve());

        expect(chartWeeks()).toEqual(['2026-11-16']);
    });

    it('shows no weeks when they cannot be loaded', async () => {
        vi.stubGlobal('fetch', vi.fn(() => Promise.reject(new Error('offline'))));
        render(<StravaChart apiUrl={API} currentWeek="2026-11-23" />);
        await act(() => Promise.resolve());

        expect(chartWeeks()).toEqual([]);
    });

    it('ignores the answer for an earlier current week', async () => {
        let answerFirst;
        const fetchMock = vi.fn()
            .mockReturnValueOnce(new Promise((resolve) => {
                answerFirst = resolve;
            }))
            .mockReturnValueOnce(json(weeks('2026-11-30')));
        vi.stubGlobal('fetch', fetchMock);
        const { rerender } = render(<StravaChart apiUrl={API} currentWeek="2026-11-23" />);

        rerender(<StravaChart apiUrl={API} currentWeek="2026-11-30" />);
        await waitFor(() => expect(chartWeeks()).toEqual(['2026-11-30']));
        await act(async () => answerFirst(await json(weeks('2026-11-23'))));

        expect(chartWeeks()).toEqual(['2026-11-30']);
    });

    it('stops listening once unmounted', async () => {
        const fetchMock = vi.fn(() => json(weeks('2026-11-16')));
        vi.stubGlobal('fetch', fetchMock);
        const { unmount } = render(<StravaChart apiUrl={API} currentWeek="2026-11-23" />);
        await waitFor(() => expect(chartWeeks()).toEqual(['2026-11-16']));

        unmount();
        await refreshed();

        expect(fetchMock).toHaveBeenCalledTimes(1);
    });
});
