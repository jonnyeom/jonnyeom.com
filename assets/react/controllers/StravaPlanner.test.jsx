import { fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import React from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import StravaPlanner from './StravaPlanner.jsx';

// Chart.js needs a real canvas, which jsdom doesn't have.
vi.mock('../components/PlanChart.jsx', () => ({ default: () => null }));

const week = (weekStart, days = [], notes = null, actual = null) => ({ weekStart, days, notes, actual });

const WEEKS = [
    week('2026-11-16', { Mon: { Run: 5 }, Sat: { Bike: 30 } }, null, { totalMiles: 14, fiveWeekAverage: 12 }),
    week('2026-11-23', { Wed: { Run: 4 } }),
    week('2026-11-30'),
];

const json = (body, status = 200) => Promise.resolve(new Response(JSON.stringify(body), { status }));

let fetchMock;

const mockApi = ({ editable = true } = {}) => {
    fetchMock = vi.fn((url, options = {}) => {
        if (options.method === 'PUT') {
            const weekStart = url.split('/').pop();
            const body = JSON.parse(options.body);
            return json({ ...WEEKS.find((w) => w.weekStart === weekStart), days: body.days, notes: body.notes });
        }
        return json({ currentWeek: '2026-11-23', editable, weeks: WEEKS });
    });
    vi.stubGlobal('fetch', fetchMock);
};

const puts = () => fetchMock.mock.calls
    .filter(([, options]) => options?.method === 'PUT')
    .map(([url, options]) => [url, JSON.parse(options.body)]);

// The pinned "Week" column cell of a week.
const weekCell = (weekStart) => document.querySelector(`.ag-pinned-left-cols-container .ag-row[row-id="${weekStart}"] [col-id="weekStart"]`);
const dayCell = (weekStart, day) => document.querySelector(`.ag-center-cols-container .ag-row[row-id="${weekStart}"] [col-id="${day}"]`);

// A click as a browser sends it: AG Grid only starts single-click editing for a click count
// (`detail`) of 1.
const tap = (element) => {
    fireEvent.mouseDown(element, { detail: 1 });
    fireEvent.mouseUp(element, { detail: 1 });
    fireEvent.click(element, { detail: 1 });
};

const planDay = async (weekStart, name, miles) => {
    tap(dayCell(weekStart, name));
    fireEvent.change(await screen.findByLabelText('Run'), { target: { value: miles } });
    fireEvent.click(screen.getByRole('button', { name: 'Done' }));
};

const importFile = () => fireEvent.change(document.querySelector('input[type="file"]'), {
    target: { files: [{ name: 'plan.csv', text: () => Promise.resolve('csv') }] },
});

const renderPlanner = async () => {
    render(<StravaPlanner apiUrl="/api/strava/plans" connectUrl="/strava/connect/entry" />);
    await waitFor(() => expect(weekCell('2026-11-16')).not.toBeNull());
};

describe('StravaPlanner', () => {
    beforeEach(() => {
        vi.stubGlobal('confirm', vi.fn(() => true));
    });

    it('lists the weeks newest first, with the planned relative miles', async () => {
        mockApi();
        await renderPlanner();

        const rows = [...document.querySelectorAll('.ag-pinned-left-cols-container .ag-row')];
        expect(rows.map((row) => row.getAttribute('row-id'))).toEqual(['2026-11-30', '2026-11-23', '2026-11-16']);
        // 30 miles of biking count as 10 relative miles.
        expect(dayCell('2026-11-16', 'Sat')).toHaveTextContent('10');
        expect(fetchMock).toHaveBeenCalledWith('/api/strava/plans', expect.anything());
    });

    it('plans a day in the popup and saves the week', async () => {
        mockApi();
        await renderPlanner();

        tap(dayCell('2026-11-30', 'Tue'));
        fireEvent.change(await screen.findByLabelText('Swim'), { target: { value: '1.5' } });
        fireEvent.click(screen.getByRole('button', { name: 'Done' }));

        await waitFor(() => expect(puts()).toEqual([
            ['/api/strava/plans/2026-11-30', { days: { Tue: { Swim: 1.5 } }, notes: null }],
        ]));
        await waitFor(() => expect(dayCell('2026-11-30', 'Tue')).toHaveTextContent('6'));
    });

    it('restores the week when saving fails', async () => {
        mockApi();
        await renderPlanner();
        fetchMock.mockImplementation((url, options = {}) => (options.method === 'PUT'
            ? json({ error: 'Run miles on Tue must be between 0 and 300.' }, 422)
            : json({ currentWeek: '2026-11-23', editable: true, weeks: WEEKS })));

        tap(dayCell('2026-11-30', 'Tue'));
        fireEvent.change(await screen.findByLabelText('Run'), { target: { value: '5' } });
        fireEvent.click(screen.getByRole('button', { name: 'Done' }));

        expect(await screen.findByText('Run miles on Tue must be between 0 and 300.')).toBeInTheDocument();
        await waitFor(() => expect(dayCell('2026-11-30', 'Tue').textContent.trim()).toBe(''));
    });

    it('restores the last confirmed week when several saves fail', async () => {
        mockApi();
        await renderPlanner();
        const rejects = [];
        fetchMock.mockImplementation(() => new Promise((resolve, reject) => {
            rejects.push(() => reject(new Error('offline')));
        }));

        await planDay('2026-11-30', 'Mon', '3');
        await planDay('2026-11-30', 'Tue', '4');
        await waitFor(() => expect(dayCell('2026-11-30', 'Tue')).toHaveTextContent('4'));
        rejects[0]();
        await waitFor(() => expect(rejects).toHaveLength(2));
        rejects[1]();

        await waitFor(() => expect(dayCell('2026-11-30', 'Tue').textContent.trim()).toBe(''));
        expect(dayCell('2026-11-30', 'Mon').textContent.trim()).toBe('');
        expect(screen.getByText('offline')).toBeInTheDocument();
    });

    it("sends what was typed, so the server can reject a typo", async () => {
        mockApi();
        await renderPlanner();

        await planDay('2026-11-30', 'Mon', '500');
        await waitFor(() => expect(puts()).toEqual([
            ['/api/strava/plans/2026-11-30', { days: { Mon: { Run: 500 } }, notes: null }],
        ]));
    });

    it('imports after the pending saves, then reloads the weeks', async () => {
        mockApi();
        await renderPlanner();
        const respond = fetchMock.getMockImplementation();
        let releaseSave;
        const imported = [WEEKS[0], WEEKS[1], week('2026-11-30', { Sun: { Run: 20 } })];
        fetchMock.mockImplementation((url, options = {}) => {
            if (options.method === 'PUT') {
                return new Promise((resolve) => {
                    releaseSave = () => resolve(respond(url, options));
                });
            }
            return options.method === 'POST' ? json({ imported: 1 }) : json({ weeks: imported });
        });
        const posts = () => fetchMock.mock.calls.filter(([, options]) => options?.method === 'POST');

        await planDay('2026-11-30', 'Mon', '3');
        await waitFor(() => expect(puts()).toHaveLength(1));
        importFile();
        await new Promise((resolve) => {
            setTimeout(resolve, 50);
        });
        expect(posts()).toHaveLength(0);

        releaseSave();
        expect(await screen.findByText('Imported 1 week.')).toBeInTheDocument();
        expect(posts()).toHaveLength(1);
        expect(fetchMock.mock.lastCall[0]).toBe('/api/strava/plans?from=2026-11-16&weeks=3');
        await waitFor(() => expect(dayCell('2026-11-30', 'Sun')).toHaveTextContent('20'));
        expect(dayCell('2026-11-30', 'Mon').textContent.trim()).toBe('');
    });

    it('says when only reloading after an import failed', async () => {
        mockApi();
        await renderPlanner();
        fetchMock.mockImplementation((url, options = {}) => (options.method === 'POST'
            ? json({ imported: 1 })
            : json({ error: 'offline' }, 500)));

        importFile();
        expect(await screen.findByText('Imported, but the weeks could not be reloaded: offline')).toBeInTheDocument();
    });

    it('loads later and earlier weeks', async () => {
        mockApi();
        await renderPlanner();
        fetchMock.mockImplementation(() => json({ weeks: [] }));

        fireEvent.click(screen.getByRole('button', { name: 'Later weeks' }));
        await waitFor(() => expect(fetchMock.mock.lastCall[0]).toBe('/api/strava/plans?from=2026-12-07&weeks=8'));
        await waitFor(() => expect(screen.getByRole('button', { name: 'Load more' })).toBeEnabled());
        fireEvent.click(screen.getByRole('button', { name: 'Load more' }));
        await waitFor(() => expect(fetchMock.mock.lastCall[0]).toBe('/api/strava/plans?from=2026-09-21&weeks=8'));
    });

    it('saves edits of the same week in order', async () => {
        mockApi();
        await renderPlanner();
        // The first save is slow: the second must wait for it.
        let releaseFirst;
        const respond = fetchMock.getMockImplementation();
        fetchMock.mockImplementation((url, options = {}) => {
            if (options.method === 'PUT' && !releaseFirst) {
                return new Promise((resolve) => {
                    releaseFirst = () => resolve(respond(url, options));
                });
            }
            return respond(url, options);
        });

        tap(dayCell('2026-11-30', 'Mon'));
        fireEvent.change(await screen.findByLabelText('Run'), { target: { value: '3' } });
        fireEvent.click(screen.getByRole('button', { name: 'Done' }));
        tap(dayCell('2026-11-30', 'Tue'));
        fireEvent.change(await screen.findByLabelText('Run'), { target: { value: '4' } });
        fireEvent.click(screen.getByRole('button', { name: 'Done' }));

        // Both edits show at once; only the first save has been sent.
        await waitFor(() => expect(dayCell('2026-11-30', 'Tue')).toHaveTextContent('4'));
        expect(dayCell('2026-11-30', 'Mon')).toHaveTextContent('3');
        expect(puts()).toHaveLength(1);

        releaseFirst();
        await waitFor(() => expect(puts()).toHaveLength(2));
        expect(puts()[1]).toEqual(['/api/strava/plans/2026-11-30', { days: { Mon: { Run: 3 }, Tue: { Run: 4 } }, notes: null }]);
    });

    it('planning a day selects nothing, so another week can be copied next', async () => {
        mockApi();
        await renderPlanner();

        tap(dayCell('2026-11-30', 'Tue'));
        fireEvent.change(await screen.findByLabelText('Run'), { target: { value: '4' } });
        fireEvent.click(screen.getByRole('button', { name: 'Done' }));
        await waitFor(() => expect(puts()).toHaveLength(1));
        expect(document.querySelector('.strava-plan-actions')).toBeNull();

        tap(weekCell('2026-11-16'));
        expect(await screen.findByRole('button', { name: 'Copy' })).toBeEnabled();
    });

    it('copies a week and pastes it into several weeks', async () => {
        mockApi();
        await renderPlanner();

        tap(weekCell('2026-11-16'));
        fireEvent.click(await screen.findByRole('button', { name: 'Copy' }));
        // Copy clears the selection: the weeks tapped next are the paste targets.
        expect(screen.queryByRole('button', { name: 'Paste' })).not.toBeInTheDocument();

        tap(weekCell('2026-11-23'));
        await screen.findByText('Week of 11.23');
        tap(weekCell('2026-11-30'));
        const bar = (await screen.findByText('2 weeks selected')).closest('.strava-plan-actions');
        fireEvent.click(within(bar).getByRole('button', { name: 'Paste' }));

        // Only 11.23 has a plan to replace.
        expect(window.confirm).toHaveBeenCalledWith('Replace the plan of 11.23 with 11.16?');
        await waitFor(() => expect(puts()).toHaveLength(2));
        expect(puts()).toEqual(expect.arrayContaining([
            ['/api/strava/plans/2026-11-23', { days: WEEKS[0].days, notes: null }],
            ['/api/strava/plans/2026-11-30', { days: WEEKS[0].days, notes: null }],
        ]));
        expect(await screen.findByText('Pasted 11.16 into 11.30, 11.23.')).toBeInTheDocument();
    });

    it('copies and pastes with Ctrl/⌘+C and Ctrl/⌘+V', async () => {
        mockApi();
        await renderPlanner();
        const copied = { days: WEEKS[0].days, notes: null };

        // Onto the focused week.
        fireEvent.keyDown(weekCell('2026-11-16'), { key: 'c', ctrlKey: true });
        await screen.findByText(/Copied 11.16/);
        fireEvent.keyDown(weekCell('2026-11-30'), { key: 'v', metaKey: true });
        await waitFor(() => expect(puts()).toEqual([['/api/strava/plans/2026-11-30', copied]]));

        // Into every selected week.
        tap(weekCell('2026-11-23'));
        await screen.findByText('Week of 11.23');
        tap(weekCell('2026-11-30'));
        await screen.findByText('2 weeks selected');
        fireEvent.keyDown(weekCell('2026-11-30'), { key: 'v', ctrlKey: true });
        await waitFor(() => expect(puts()).toHaveLength(3));
        expect(puts().slice(1)).toEqual([
            ['/api/strava/plans/2026-11-30', copied],
            ['/api/strava/plans/2026-11-23', copied],
        ]);
    });

    it("doesn't paste when the replacement isn't confirmed", async () => {
        mockApi();
        await renderPlanner();
        window.confirm.mockReturnValue(false);

        tap(weekCell('2026-11-16'));
        fireEvent.click(await screen.findByRole('button', { name: 'Copy' }));
        tap(weekCell('2026-11-23'));
        fireEvent.click(await screen.findByRole('button', { name: 'Paste' }));

        // Saves are sent a moment later.
        await new Promise((resolve) => {
            setTimeout(resolve, 50);
        });
        expect(window.confirm).toHaveBeenCalled();
        expect(puts()).toEqual([]);
    });

    it('is read-only without a Strava connection', async () => {
        mockApi({ editable: false });
        await renderPlanner();

        expect(screen.getByRole('link', { name: 'Connect to edit' })).toHaveAttribute('href', '/strava/connect/entry');
        expect(screen.queryByRole('button', { name: 'Import' })).not.toBeInTheDocument();

        tap(weekCell('2026-11-16'));
        tap(dayCell('2026-11-16', 'Mon'));
        expect(screen.queryByRole('button', { name: 'Copy' })).not.toBeInTheDocument();
        expect(screen.queryByLabelText('Run')).not.toBeInTheDocument();
    });

    it('exports the weeks shown', async () => {
        mockApi();
        await renderPlanner();

        expect(screen.getByRole('link', { name: 'Export' }))
            .toHaveAttribute('href', '/api/strava/plans.csv?from=2026-11-16&weeks=3');
    });
});
