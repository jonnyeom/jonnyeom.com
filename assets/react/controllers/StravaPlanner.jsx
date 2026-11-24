import {
    CellStyleModule,
    ClientSideRowModelModule,
    colorSchemeDark,
    CustomEditorModule,
    ModuleRegistry,
    RowStyleModule,
    themeQuartz,
    TooltipModule,
    ValidationModule,
} from 'ag-grid-community';
import { AgGridReact, useGridCellEditor } from 'ag-grid-react';
import React, { useEffect, useMemo, useRef, useState, useSyncExternalStore } from 'react';
import PlanChart from '../components/PlanChart.jsx';
import WeekActionBar from '../components/WeekActionBar.jsx';
import {
    addWeeks,
    DAYS,
    hasPlan,
    inputSize,
    MAX_MILES,
    normalizeWeek,
    relativeMiles,
    round1,
    SPORTS,
    weekLabel,
    weekTotal,
} from '../plan.js';

// Only the features this grid uses.
ModuleRegistry.registerModules([
    ClientSideRowModelModule,
    CustomEditorModule,
    RowStyleModule,
    CellStyleModule,
    TooltipModule,
    ...(import.meta.env?.DEV ? [ValidationModule] : []),
]);

const LOAD_MORE_WEEKS = 8;

const DEFAULT_COL_DEF = { sortable: false, resizable: false, suppressMovable: true };
const getRowId = ({ data }) => data.weekStart;

/** Calls the plan API; throws with the API's error message. */
const api = async (url, options = {}) => {
    const response = await fetch(url, {
        credentials: 'same-origin',
        ...options,
        headers: { Accept: 'application/json', ...options.headers },
    });
    const body = await response.json().catch(() => ({}));
    if (!response.ok) {
        throw new Error(body.error ?? `Request failed (${response.status})`);
    }
    return body;
};

const useMediaQuery = (media) => useSyncExternalStore(
    (onChange) => {
        const query = window.matchMedia(media);
        query.addEventListener('change', onChange);
        return () => query.removeEventListener('change', onChange);
    },
    () => window.matchMedia(media).matches,
);

/** Day cell: relative miles and the icons of the planned sports (on phones: "*" for several sports). */
const DayCellRenderer = ({ value, compact }) => {
    const miles = relativeMiles(value);
    if (miles <= 0) {
        return null;
    }
    const planned = SPORTS.filter((sport) => value?.[sport.type] > 0);

    if (compact) {
        return <span>{round1(miles)}{planned.length > 1 && '*'}</span>;
    }

    return (
        <span className="icon-text is-flex-wrap-nowrap">
            <span>{round1(miles)}</span>
            {planned.map((sport) => (
                <span key={sport.type} className="icon is-small ml-1">
                    <i className={`fas fa-${sport.icon}`} />
                </span>
            ))}
        </span>
    );
};

const dayTooltip = ({ value }) => {
    const planned = SPORTS.filter((sport) => value?.[sport.type] > 0);
    return planned.length ? planned.map((sport) => `${sport.type}: ${value[sport.type]} mi`).join('\n') : undefined;
};

/** Week cell: the week; on phones also "planned/actual" miles (their columns are hidden). */
const WeekCellRenderer = ({ data, compact }) => {
    if (!compact) {
        return <span>{weekLabel(data.weekStart)}{data.notes && <span className="icon is-small ml-1 has-text-grey"><i className="fas fa-note-sticky" /></span>}</span>;
    }
    const plan = round1(weekTotal(data.days));

    return (
        <div className="is-flex is-flex-direction-column is-justify-content-center" style={{ height: '100%', lineHeight: 1.2 }}>
            <span>{weekLabel(data.weekStart)}{data.notes && <span className="has-text-grey"> •</span>}</span>
            <span className="is-size-7 has-text-weight-normal has-text-grey">
                {plan > 0 ? plan : '–'}{data.actual ? `/${data.actual.totalMiles}` : ''}
            </span>
        </div>
    );
};

/** Popup editor of one day: miles per sport. Enter saves, Escape cancels. */
const DayEditor = ({ value, onValueChange, stopEditing, colDef, data, compact }) => {
    const [draft, setDraft] = useState(() =>
        Object.fromEntries(SPORTS.map((sport) => [sport.type, value?.[sport.type]?.toString() ?? ''])));
    const firstInput = useRef(null);

    useGridCellEditor({ isPopup: () => true });

    useEffect(() => {
        firstInput.current?.focus();
        firstInput.current?.select();
    }, []);

    const update = (type, input) => {
        const next = { ...draft, [type]: input };
        setDraft(next);
        onValueChange(Object.fromEntries(SPORTS
            .map((sport) => [sport.type, Math.min(MAX_MILES, Math.max(0, Number(next[sport.type]) || 0))])
            .filter(([, miles]) => miles > 0)));
    };

    const clear = () => {
        setDraft(Object.fromEntries(SPORTS.map((sport) => [sport.type, ''])));
        onValueChange({});
    };

    return (
        <div className="box p-3 mb-0" style={{ width: '16rem', maxWidth: 'calc(100vw - 2rem)' }}>
            <p className="has-text-weight-semibold mb-2">
                {colDef.headerName} {weekLabel(data.weekStart)}
            </p>
            {SPORTS.map((sport, index) => (
                <div key={sport.type} className="is-flex is-align-items-center mb-2">
                    <label className="is-flex is-align-items-center is-flex-shrink-0" style={{ width: '6rem' }} htmlFor={`plan-${sport.type}`}>
                        <span className="icon"><i className={`fas fa-${sport.icon}`} /></span>
                        <span className="is-size-7">{sport.type}</span>
                    </label>
                    <input
                        id={`plan-${sport.type}`}
                        ref={index === 0 ? firstInput : undefined}
                        className={`input${inputSize(compact)}`}
                        type="number"
                        inputMode="decimal"
                        min="0"
                        max={MAX_MILES}
                        step="0.1"
                        placeholder="mi"
                        value={draft[sport.type]}
                        onChange={(e) => update(sport.type, e.target.value)}
                    />
                </div>
            ))}
            <div className="is-flex is-justify-content-space-between is-align-items-center mt-3">
                <span className="is-size-7 has-text-grey">{round1(relativeMiles(draft))} rel. mi</span>
                <div className="buttons mb-0">
                    <button type="button" className="button is-small mb-0" onClick={clear}>Clear</button>
                    <button type="button" className="button is-small is-link mb-0" onClick={() => stopEditing()}>Done</button>
                </div>
            </div>
        </div>
    );
};

export default function StravaPlanner({ apiUrl, connectUrl }) {
    const importInput = useRef(null);
    const saveQueue = useRef({});
    const [weeks, setWeeks] = useState([]);
    const [currentWeek, setCurrentWeek] = useState(null);
    const [editable, setEditable] = useState(false);
    const [copied, setCopied] = useState(null);
    const [selectedWeeks, setSelectedWeeks] = useState([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);
    const [notice, setNotice] = useState(null);
    const dark = useMediaQuery('(prefers-color-scheme: dark)');
    // Phones: all 7 days fit; totals go under the week, the other columns are hidden.
    const compact = useMediaQuery('(max-width: 768px)');

    const theme = useMemo(() => (dark ? themeQuartz.withPart(colorSchemeDark) : themeQuartz), [dark]);

    useEffect(() => {
        api(apiUrl)
            .then((body) => {
                setWeeks(body.weeks.map(normalizeWeek));
                setCurrentWeek(body.currentWeek);
                setEditable(body.editable);
            })
            .catch((e) => setError(e.message))
            .finally(() => setLoading(false));
    }, [apiUrl]);

    // Replaces a week object in the state (by identity, so a stale response can't undo a newer edit).
    const swapWeek = (from, to) => setWeeks((current) => current.map((week) => (week === from ? to : week)));

    // Shows the change at once, then saves the week. Saves of the same week are queued, so they reach
    // the server in order. A failed save restores the week as it was.
    const updateWeek = (previous, changes) => {
        const next = { ...previous, ...changes };
        swapWeek(previous, next);
        setError(null);

        const save = (saveQueue.current[next.weekStart] ?? Promise.resolve()).then(() => api(`${apiUrl}/${next.weekStart}`, {
            method: 'PUT',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ days: next.days, notes: next.notes ?? null }),
        }));
        saveQueue.current[next.weekStart] = save.catch(() => {});

        return save.then(
            (saved) => {
                swapWeek(next, normalizeWeek(saved));
                return true;
            },
            (e) => {
                setError(e.message);
                swapWeek(next, previous);
                return false;
            },
        );
    };

    const copyWeek = (week) => {
        setCopied(week);
        // Start a fresh selection: the weeks tapped next are where it gets pasted.
        setSelectedWeeks([]);
        setNotice(`Copied ${weekLabel(week.weekStart)}. Select the weeks to paste it into.`);
    };

    // Pastes the copied week's activities into each target week (keeping their notes).
    const pasteWeeks = async (targets) => {
        const planned = targets.filter(hasPlan).map((week) => weekLabel(week.weekStart));
        if (planned.length > 0 && !window.confirm(`Replace the plan of ${planned.join(', ')} with ${weekLabel(copied.weekStart)}?`)) {
            return;
        }
        const saved = await Promise.all(targets.map((week) => updateWeek(week, { days: copied.days })));
        const pasted = targets.filter((week, index) => saved[index]).map((week) => weekLabel(week.weekStart));
        if (pasted.length > 0) {
            setNotice(`Pasted ${weekLabel(copied.weekStart)} into ${pasted.join(', ')}.`);
        }
    };

    const clearWeeks = (targets) => {
        if (window.confirm(`Clear the plan of ${targets.map((week) => weekLabel(week.weekStart)).join(', ')}?`)) {
            targets.forEach((week) => updateWeek(week, { days: {} }));
        }
    };

    // Imports a CSV (the format of the export, e.g. edited in Google Sheets): each week in the file
    // replaces that week's plan. Then reloads the weeks shown.
    const importCsv = async (event) => {
        const file = event.target.files?.[0];
        event.target.value = '';
        if (!file || !window.confirm(`Import ${file.name}? Each week in the file replaces that week's plan.`)) {
            return;
        }
        setError(null);
        setLoading(true);
        try {
            const { imported } = await api(`${apiUrl}/import`, {
                method: 'POST',
                headers: { 'Content-Type': 'text/csv' },
                body: await file.text(),
            });
            const body = await api(`${apiUrl}?from=${weeks[0].weekStart}&weeks=${weeks.length}`);
            setWeeks(body.weeks.map(normalizeWeek));
            setNotice(`Imported ${imported} week${imported === 1 ? '' : 's'}.`);
        } catch (e) {
            setError(`Import failed: ${e.message}`);
        } finally {
            setLoading(false);
        }
    };

    // Weeks are kept oldest first; prepend earlier or append later weeks.
    const loadMore = async (direction) => {
        setLoading(true);
        try {
            const from = direction === 'earlier'
                ? addWeeks(weeks[0].weekStart, -LOAD_MORE_WEEKS)
                : addWeeks(weeks[weeks.length - 1].weekStart, 1);
            const body = await api(`${apiUrl}?from=${from}&weeks=${LOAD_MORE_WEEKS}`);
            const loaded = body.weeks.map(normalizeWeek);
            setWeeks((current) => (direction === 'earlier' ? [...loaded, ...current] : [...current, ...loaded]));
        } catch (e) {
            setError(e.message);
        } finally {
            setLoading(false);
        }
    };

    const columnDefs = useMemo(() => [
        {
            headerName: compact ? 'Wk' : 'Week',
            field: 'weekStart',
            pinned: 'left',
            width: compact ? 80 : 100,
            cellClass: compact ? 'has-text-weight-bold px-2' : 'has-text-weight-bold',
            headerClass: compact ? 'px-2' : undefined,
            cellRenderer: WeekCellRenderer,
            cellRendererParams: { compact },
            cellStyle: editable ? { cursor: 'pointer' } : undefined,
        },
        ...DAYS.map((day) => ({
            headerName: day,
            colId: day,
            minWidth: compact ? 38 : 70,
            flex: 1,
            editable,
            cellClass: compact ? 'is-flex is-align-items-center px-1' : 'is-flex is-align-items-center',
            headerClass: compact ? 'px-1' : undefined,
            valueGetter: ({ data }) => data.days[day] ?? {},
            equals: (a, b) => JSON.stringify(a ?? {}) === JSON.stringify(b ?? {}),
            cellRenderer: DayCellRenderer,
            cellRendererParams: { compact },
            tooltipValueGetter: dayTooltip,
            cellEditor: DayEditor,
            cellEditorParams: { compact },
            cellEditorPopup: true,
            cellEditorPopupPosition: 'under',
            // Tab moves between the inputs of the popup, not to the next cell.
            suppressKeyboardEvent: ({ editing, event }) => editing && event.key === 'Tab',
        })),
        ...(compact ? [] : [{
            headerName: 'Plan',
            colId: 'plan',
            width: 75,
            valueGetter: ({ data }) => round1(weekTotal(data.days)),
            valueFormatter: ({ value }) => (value > 0 ? `${value}` : ''),
            cellClass: 'has-text-weight-bold',
        },
        {
            headerName: 'Actual',
            colId: 'actual',
            width: 75,
            valueGetter: ({ data }) => data.actual?.totalMiles ?? null,
            valueFormatter: ({ value }) => (value === null ? '' : `${value}`),
        },
        {
            headerName: '±',
            colId: 'diff',
            width: 65,
            valueGetter: ({ data }) => {
                const plan = weekTotal(data.days);
                return data.actual && plan > 0 ? round1(data.actual.totalMiles - plan) : null;
            },
            valueFormatter: ({ value }) => (value === null ? '' : `${value > 0 ? '+' : ''}${value}`),
            cellClassRules: {
                'has-text-success': ({ value }) => value !== null && value >= 0,
                'has-text-danger': ({ value }) => value !== null && value < 0,
            },
        },
        // Edited from the week bar (select the week, Notes).
        { headerName: 'Notes', field: 'notes', minWidth: 90, flex: 1, tooltipField: 'notes' }]),
    ], [editable, compact]);

    const rowClassRules = useMemo(() => ({ 'strava-plan-current-week': ({ data }) => data.weekStart === currentWeek }), [currentWeek]);

    // The grid doesn't change its row data (readOnlyEdit): an edited day becomes a new week object.
    const onCellEditRequest = ({ data, colDef, newValue }) => {
        const days = { ...data.days };
        if (Object.keys(newValue ?? {}).length === 0) {
            delete days[colDef.colId];
        } else {
            days[colDef.colId] = newValue;
        }
        updateWeek(data, { days });
    };

    // Tapping a week adds it to / removes it from the selection; tapping a day only edits it.
    const onCellClicked = ({ colDef, data }) => {
        if (!editable || colDef.field !== 'weekStart') {
            return;
        }
        const { weekStart } = data;
        setSelectedWeeks((current) => (current.includes(weekStart)
            ? current.filter((week) => week !== weekStart)
            : [...current, weekStart]));
    };

    // Newest first, like the stats table.
    const rows = useMemo(() => weeks.toReversed(), [weeks]);
    const selection = rows.filter((week) => selectedWeeks.includes(week.weekStart));

    // Ctrl/⌘+C copies the focused week; Ctrl/⌘+V pastes into the selected weeks, or the focused week
    // when it isn't selected. Left alone in an open editor, where they copy and paste text.
    const onCellKeyDown = ({ event, data, api: grid }) => {
        if (!editable || !(event.ctrlKey || event.metaKey) || grid.getEditingCells().length > 0) {
            return;
        }
        const key = event.key.toLowerCase();
        if (key === 'c' && hasPlan(data)) {
            copyWeek(data);
        } else if (key === 'v' && copied) {
            const targets = selectedWeeks.includes(data.weekStart) ? selection : [data];
            pasteWeeks(targets.filter((week) => week.weekStart !== copied.weekStart));
        }
    };

    if (loading && weeks.length === 0) {
        return <div className="has-text-centered py-5"><i className="fas fa-cog fa-spin fa-2x" /></div>;
    }

    if (error && weeks.length === 0) {
        return <div className="notification is-danger">{error}</div>;
    }

    return (
        <div>
            <PlanChart weeks={weeks} currentWeek={currentWeek} />
            {error && <div className="notification is-danger is-light py-2">{error}</div>}
            <div className="is-flex is-align-items-center is-justify-content-space-between mb-3">
                <span className="is-size-7 has-text-grey">
                    {notice ?? (editable
                        ? (compact
                            ? 'Tap a day to plan it, or a week to copy and paste it.'
                            : 'Click a day to plan it, or a week to copy and paste it (or Ctrl/⌘+C, Ctrl/⌘+V).')
                        : 'Relative miles: bike ÷3, swim ×4, tennis ×2.')}
                </span>
                {!editable && <a href={connectUrl} className="button is-small mb-0 ml-2">Connect to edit</a>}
            </div>
            <div className="has-text-centered pb-3">
                <button type="button" className="button is-small" onClick={() => loadMore('later')} disabled={loading}>
                    Later weeks
                </button>
            </div>
            {editable && (
                <WeekActionBar
                    key={selectedWeeks.join(',')}
                    selection={selection}
                    copied={copied}
                    compact={compact}
                    onCopy={copyWeek}
                    onPaste={pasteWeeks}
                    onClear={clearWeeks}
                    onSaveNotes={(week, notes) => updateWeek(week, { notes })}
                    onClose={() => setSelectedWeeks([])}
                />
            )}
            {/* Highlights the selected rows without redrawing the grid (which would close an open editor). */}
            {selection.length > 0 && (
                <style>
                    {`${selection.map((week) => `.strava-plan-grid .ag-row[row-id="${week.weekStart}"]`).join(', ')} { background-color: rgba(57, 135, 229, 0.28); }`}
                </style>
            )}
            <div className="strava-plan-grid">
                <AgGridReact
                    theme={theme}
                    rowData={rows}
                    columnDefs={columnDefs}
                    defaultColDef={DEFAULT_COL_DEF}
                    getRowId={getRowId}
                    rowHeight={compact ? 48 : undefined}
                    rowClassRules={rowClassRules}
                    domLayout="autoHeight"
                    singleClickEdit
                    readOnlyEdit
                    stopEditingWhenCellsLoseFocus
                    popupParent={document.body}
                    tooltipShowDelay={300}
                    onCellEditRequest={onCellEditRequest}
                    onCellClicked={onCellClicked}
                    onCellKeyDown={onCellKeyDown}
                />
            </div>
            <div className="has-text-centered py-4">
                <button type="button" className="button" onClick={() => loadMore('earlier')} disabled={loading}>
                    Load more
                </button>
            </div>
            <div className="buttons is-centered are-small">
                <a
                    className="button is-small mb-0"
                    href={`${apiUrl}.csv?from=${weeks[0].weekStart}&weeks=${weeks.length}`}
                    title="Download the weeks shown as a CSV, to open in Google Sheets, Excel or Numbers"
                    download
                >
                    <span className="icon"><i className="fas fa-file-arrow-down" /></span>
                    <span>Export</span>
                </a>
                {editable && (
                    <>
                        <button
                            type="button"
                            className={`button is-small mb-0${loading ? ' is-loading' : ''}`}
                            title="Upload a CSV (e.g. the export, edited in a spreadsheet)"
                            onClick={() => importInput.current.click()}
                        >
                            <span className="icon"><i className="fas fa-file-arrow-up" /></span>
                            <span>Import</span>
                        </button>
                        <input ref={importInput} type="file" accept=".csv,text/csv" hidden onChange={importCsv} />
                    </>
                )}
            </div>
            {/* Room for the floating week bar, so it doesn't cover the last rows. */}
            {selection.length > 0 && <div style={{ height: '6rem' }} />}
        </div>
    );
}
