import {
    CellStyleModule,
    ClientSideRowModelModule,
    colorSchemeDark,
    CustomEditorModule,
    LargeTextEditorModule,
    ModuleRegistry,
    RenderApiModule,
    RowStyleModule,
    themeQuartz,
    TooltipModule,
    ValidationModule,
} from 'ag-grid-community';
import { AgGridReact, useGridCellEditor } from 'ag-grid-react';
import React, { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import PlanChart from '../components/PlanChart.jsx';

// Only the features this grid uses (clipboard / range selection are Enterprise; copy & paste of
// weeks is implemented below).
ModuleRegistry.registerModules([
    ClientSideRowModelModule,
    CustomEditorModule,
    LargeTextEditorModule,
    RenderApiModule,
    RowStyleModule,
    CellStyleModule,
    TooltipModule,
    ...(import.meta.env?.DEV ? [ValidationModule] : []),
]);

const DAYS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

// Same weighting as the stats (App\Enum\SportType::relativeDistance()).
const SPORTS = [
    { type: 'Run', icon: 'person-running', factor: 1 },
    { type: 'Bike', icon: 'person-biking', factor: 1 / 3 },
    { type: 'Swim', icon: 'person-swimming', factor: 4 },
    { type: 'Tennis', icon: 'table-tennis-paddle-ball', factor: 2 },
];

const LOAD_MORE_WEEKS = 8;
const MAX_MILES = 300;

const round1 = (value) => Math.round(value * 10) / 10;

const addWeeks = (weekStart, weeks) => {
    const date = new Date(`${weekStart}T00:00:00Z`);
    date.setUTCDate(date.getUTCDate() + weeks * 7);
    return date.toISOString().slice(0, 10);
};

// "2026-11-23" -> "11.23", like the stats table.
const weekLabel = (weekStart) => {
    const [, month, day] = weekStart.split('-');
    return `${Number(month)}.${day}`;
};

const relativeMiles = (sports) =>
    SPORTS.reduce((total, sport) => total + (Number(sports?.[sport.type]) || 0) * sport.factor, 0);

const weekTotal = (days) => DAYS.reduce((total, day) => total + relativeMiles(days?.[day]), 0);

const hasPlan = (week) => DAYS.some((day) => relativeMiles(week.days?.[day]) > 0);

// The API returns [] for a week without plan; the grid always works with an object.
const normalizeWeek = (week) => ({ ...week, days: Array.isArray(week.days) ? {} : { ...week.days } });

const useMediaQuery = (media) => {
    const query = useMemo(() => (typeof window !== 'undefined' ? window.matchMedia?.(media) : null), [media]);
    const [matches, setMatches] = useState(query?.matches ?? false);
    useEffect(() => {
        if (!query) {
            return undefined;
        }
        const onChange = (event) => setMatches(event.matches);
        query.addEventListener('change', onChange);
        return () => query.removeEventListener('change', onChange);
    }, [query]);
    return matches;
};

/** Day cell: relative miles and the icons of the planned sports (on phones: "*" for several sports). */
const DayCellRenderer = ({ value, context }) => {
    const miles = relativeMiles(value);
    if (miles <= 0) {
        return null;
    }
    const planned = SPORTS.filter((sport) => value?.[sport.type] > 0);

    if (context.compact) {
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
const WeekCellRenderer = ({ data, context }) => {
    if (!context.compact) {
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

// Inputs of at least 16px, so phones don't zoom in when one is focused.
const inputSize = (compact) => (compact ? '' : ' is-small');

/** Popup editor of one day: miles per sport. Enter saves, Escape cancels. */
const DayEditor = ({ value, onValueChange, stopEditing, colDef, data, context }) => {
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
                        className={`input${inputSize(context.compact)}`}
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

/**
 * Popup of a week (tap / click the week): copy the week, paste a copied week onto it, notes, clear.
 * It acts through the context; the cell value itself is never edited.
 */
const WeekMenu = ({ data, stopEditing, context }) => {
    const { copied, copyWeek, pasteWeek, saveWeek, compact } = context;
    const [notes, setNotes] = useState(data.notes ?? '');
    const isSource = copied?.weekStart === data.weekStart;

    useGridCellEditor({ isPopup: () => true });

    const close = () => stopEditing(true);
    const run = (action) => () => {
        close();
        action();
    };

    return (
        <div className="box p-3 mb-0" style={{ width: '16rem', maxWidth: 'calc(100vw - 2rem)' }}>
            <p className="has-text-weight-semibold mb-2">Week of {weekLabel(data.weekStart)}</p>
            <div className="buttons mb-2">
                <button type="button" className="button is-small mb-0" onClick={run(() => copyWeek(data))} disabled={!hasPlan(data)}>
                    <span className="icon"><i className="fas fa-copy" /></span>
                    <span>Copy</span>
                </button>
                <button type="button" className="button is-small mb-0" onClick={run(() => pasteWeek(data))} disabled={!copied || isSource}>
                    <span className="icon"><i className="fas fa-paste" /></span>
                    <span>{copied && !isSource ? `Paste ${weekLabel(copied.weekStart)}` : 'Paste'}</span>
                </button>
                <button
                    type="button"
                    className="button is-small mb-0"
                    onClick={run(() => window.confirm(`Clear the plan of ${weekLabel(data.weekStart)}?`) && saveWeek(data.weekStart, {}, data.notes))}
                    disabled={!hasPlan(data)}
                >
                    <span className="icon"><i className="fas fa-eraser" /></span>
                    <span>Clear</span>
                </button>
            </div>
            <textarea
                className={`textarea${inputSize(compact)} mb-2`}
                rows={3}
                maxLength={2000}
                placeholder="Notes (e.g. long run Saturday, recovery week)"
                value={notes}
                onChange={(e) => setNotes(e.target.value)}
            />
            <div className="buttons is-right mb-0">
                <button type="button" className="button is-small mb-0" onClick={close}>Cancel</button>
                <button
                    type="button"
                    className="button is-small is-link mb-0"
                    onClick={run(() => notes !== (data.notes ?? '') && saveWeek(data.weekStart, data.days, notes))}
                >
                    Done
                </button>
            </div>
        </div>
    );
};

const StravaPlanner = ({ apiUrl, connectUrl }) => {
    const gridRef = useRef(null);
    const [weeks, setWeeks] = useState([]);
    const [currentWeek, setCurrentWeek] = useState(null);
    const [editable, setEditable] = useState(false);
    const [copied, setCopied] = useState(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);
    const [notice, setNotice] = useState(null);
    const dark = useMediaQuery('(prefers-color-scheme: dark)');
    // Phones: all 7 days fit; totals go under the week, the other columns are hidden.
    const compact = useMediaQuery('(max-width: 768px)');

    const theme = useMemo(() => (dark ? themeQuartz.withPart(colorSchemeDark) : themeQuartz), [dark]);

    const request = useCallback(async (path, options = {}) => {
        const response = await fetch(`${apiUrl}${path}`, {
            credentials: 'same-origin',
            ...options,
            headers: { Accept: 'application/json', ...(options.body ? { 'Content-Type': 'application/json' } : {}) },
        });
        const body = await response.json().catch(() => ({}));
        if (!response.ok) {
            throw new Error(body.error ?? `Request failed (${response.status})`);
        }
        return body;
    }, [apiUrl]);

    useEffect(() => {
        request('')
            .then((body) => {
                setWeeks(body.weeks.map(normalizeWeek));
                setCurrentWeek(body.currentWeek);
                setEditable(body.editable);
            })
            .catch((e) => setError(e.message))
            .finally(() => setLoading(false));
    }, [request]);

    const replaceWeek = (saved) => {
        const week = normalizeWeek(saved);
        setWeeks((current) => current.map((row) => (row.weekStart === week.weekStart ? week : row)));
    };

    const saveWeek = useCallback(async (weekStart, days, notes) => {
        setError(null);
        try {
            replaceWeek(await request(`/${weekStart}`, {
                method: 'PUT',
                body: JSON.stringify({ days, notes: notes ?? null }),
            }));
            return true;
        } catch (e) {
            setError(e.message);
            // Revert the row to what is stored.
            request(`/${weekStart}`).then(replaceWeek).catch(() => {});
            return false;
        }
    }, [request]);

    const copyWeek = useCallback((week) => {
        if (!hasPlan(week)) {
            return;
        }
        setCopied({ weekStart: week.weekStart, days: structuredClone(week.days) });
        setNotice(`Copied ${weekLabel(week.weekStart)}. Paste it on another week.`);
    }, []);

    const pasteWeek = useCallback(async (week) => {
        if (!copied || copied.weekStart === week.weekStart) {
            return;
        }
        if (hasPlan(week) && !window.confirm(`Replace the plan of ${weekLabel(week.weekStart)} with ${weekLabel(copied.weekStart)}?`)) {
            return;
        }
        if (await saveWeek(week.weekStart, copied.days, week.notes)) {
            setNotice(`Pasted ${weekLabel(copied.weekStart)} into ${weekLabel(week.weekStart)}.`);
        }
    }, [copied, saveWeek]);

    // Weeks are kept oldest first; prepend earlier or append later weeks.
    const loadMore = async (direction) => {
        if (weeks.length === 0) {
            return;
        }
        setLoading(true);
        try {
            const from = direction === 'earlier'
                ? addWeeks(weeks[0].weekStart, -LOAD_MORE_WEEKS)
                : addWeeks(weeks[weeks.length - 1].weekStart, 1);
            const body = await request(`?from=${from}&weeks=${LOAD_MORE_WEEKS}`);
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
            width: 80,
            cellClass: compact ? 'has-text-weight-bold px-2' : 'has-text-weight-bold',
            headerClass: compact ? 'px-2' : undefined,
            cellRenderer: WeekCellRenderer,
            editable,
            cellEditor: WeekMenu,
            cellEditorPopup: true,
            cellEditorPopupPosition: 'under',
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
            valueSetter: ({ data, newValue }) => {
                data.days = { ...data.days };
                if (Object.keys(newValue ?? {}).length === 0) {
                    delete data.days[day];
                } else {
                    data.days[day] = newValue;
                }
                return true;
            },
            equals: (a, b) => JSON.stringify(a ?? {}) === JSON.stringify(b ?? {}),
            cellRenderer: DayCellRenderer,
            tooltipValueGetter: dayTooltip,
            cellEditor: DayEditor,
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
        {
            headerName: 'Notes',
            field: 'notes',
            minWidth: 90,
            flex: 1,
            editable,
            tooltipField: 'notes',
            cellEditor: 'agLargeTextCellEditor',
            cellEditorPopup: true,
            cellEditorParams: { maxLength: 2000, rows: 5, cols: 40 },
        }]),
    ], [editable, compact]);

    const onCellValueChanged = useCallback(({ data }) => {
        saveWeek(data.weekStart, data.days, data.notes);
    }, [saveWeek]);

    // Ctrl/⌘+C copies the focused week, Ctrl/⌘+V pastes onto it.
    const onCellKeyDown = useCallback(({ event, data, api }) => {
        if (!editable || !event || !(event.ctrlKey || event.metaKey) || api.getEditingCells().length > 0) {
            return;
        }
        const key = event.key.toLowerCase();
        if (key === 'c') {
            copyWeek(data);
        } else if (key === 'v') {
            event.preventDefault();
            pasteWeek(data);
        }
    }, [editable, copyWeek, pasteWeek]);

    // Newest first, like the stats table.
    const rows = useMemo(() => [...weeks].reverse(), [weeks]);
    const chartWeeks = useMemo(() => weeks.map((week) => ({ ...week, planMiles: round1(weekTotal(week.days)) })), [weeks]);
    const context = useMemo(
        () => ({ copied, copyWeek, pasteWeek, saveWeek, compact }),
        [copied, copyWeek, pasteWeek, saveWeek, compact],
    );

    // Day and week cells read `compact` from the context.
    useEffect(() => {
        gridRef.current?.api?.refreshCells({ force: true });
    }, [compact]);

    if (loading && weeks.length === 0) {
        return <div className="has-text-centered py-5"><i className="fas fa-cog fa-spin fa-2x" /></div>;
    }

    if (error && weeks.length === 0) {
        return <div className="notification is-danger">{error}</div>;
    }

    return (
        <div>
            <PlanChart weeks={chartWeeks} currentWeek={currentWeek} label={weekLabel} />
            {error && <div className="notification is-danger is-light py-2">{error}</div>}
            <div className="is-flex is-align-items-center is-justify-content-space-between mb-3">
                <span className="is-size-7 has-text-grey">
                    {notice ?? (editable
                        ? (compact
                            ? 'Tap a day to plan it. Tap a week to copy, paste or add notes.'
                            : 'Click a day to plan it. Click a week to copy, paste or add notes (or Ctrl/⌘+C, Ctrl/⌘+V).')
                        : 'Relative miles: bike ÷3, swim ×4, tennis ×2.')}
                </span>
                {!editable && <a href={connectUrl} className="button is-small mb-0">Connect to edit</a>}
            </div>
            <div className="has-text-centered pb-3">
                <button type="button" className="button is-small" onClick={() => loadMore('later')} disabled={loading}>
                    Later weeks
                </button>
            </div>
            <AgGridReact
                ref={gridRef}
                theme={theme}
                rowData={rows}
                columnDefs={columnDefs}
                defaultColDef={{ sortable: false, resizable: false, suppressMovable: true }}
                getRowId={({ data }) => data.weekStart}
                rowHeight={compact ? 48 : undefined}
                rowClassRules={{ 'strava-plan-current-week': ({ data }) => data.weekStart === currentWeek }}
                context={context}
                domLayout="autoHeight"
                singleClickEdit
                stopEditingWhenCellsLoseFocus
                popupParent={typeof document !== 'undefined' ? document.body : undefined}
                tooltipShowDelay={300}
                onCellValueChanged={onCellValueChanged}
                onCellKeyDown={onCellKeyDown}
            />
            <div className="has-text-centered py-4">
                <button type="button" className="button" onClick={() => loadMore('earlier')} disabled={loading}>
                    Load more
                </button>
            </div>
        </div>
    );
};

export default function (props) {
    return <StravaPlanner apiUrl={props.apiUrl ?? '/api/strava/plans'} connectUrl={props.connectUrl ?? '/strava/connect/entry'} />;
}
