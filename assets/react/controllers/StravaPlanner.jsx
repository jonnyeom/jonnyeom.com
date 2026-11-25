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
import React, { useCallback, useEffect, useMemo, useRef, useState, useSyncExternalStore } from 'react';
import PlanChart from '../components/PlanChart.jsx';
import WeekActionBar from '../components/WeekActionBar.jsx';
import {
    addWeeks,
    cellLabel,
    cellsBetween,
    DAYS,
    hasPlan,
    inputSize,
    isSameCell,
    MAX_MILES,
    normalizeWeek,
    relativeMiles,
    round1,
    sameSports,
    SPORTS,
    weekLabel,
    weekTotal,
    withDay,
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

/**
 * State with a ref that always holds its latest value: the grid's handlers may run before the next render
 * (e.g. tapping Paste also saves an open day popup), or with the handlers of the previous one.
 */
const useLatestState = (initial) => {
    const ref = useRef(initial);
    const [value, setValue] = useState(initial);
    const set = useCallback((update) => {
        ref.current = typeof update === 'function' ? update(ref.current) : update;
        setValue(ref.current);
    }, []);

    return [value, set, ref];
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
const DayCellRenderer = ({ value, compact, editable }) => {
    const miles = relativeMiles(value);
    if (miles <= 0) {
        return null;
    }
    const planned = SPORTS.filter((sport) => value?.[sport.type] > 0);

    return (
        <>
            {compact ? <span>{round1(miles)}{planned.length > 1 && '*'}</span> : (
                <span className="icon-text is-flex-wrap-nowrap">
                    <span>{round1(miles)}</span>
                    {planned.map((sport) => (
                        <span key={sport.type} className="icon is-small ml-1">
                            <i className={`fas fa-${sport.icon}`} />
                        </span>
                    ))}
                </span>
            )}
            {editable && <span className="strava-plan-fill" />}
        </>
    );
};

const isDay = (colDef) => DAYS.includes(colDef.colId);

// Ctrl/⌘/Shift+click selects days instead of opening their popup.
const isSelectClick = (event) => event.type === 'click' && (event.ctrlKey || event.metaKey || event.shiftKey);

const isFillHandle = (event) => Boolean(event.target.closest?.('.strava-plan-fill'));

// The week of the row under the pointer, if any.
const weekAt = ({ clientX, clientY }) =>
    document.elementFromPoint(clientX, clientY)?.closest('.strava-plan-grid .ag-row')?.getAttribute('row-id');

const cellSelector = ({ weekStart, day }) => `.strava-plan-grid .ag-row[row-id="${weekStart}"] [col-id="${day}"]`;

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
            .map((sport) => [sport.type, Number(next[sport.type]) || 0])
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
    const gridRef = useRef(null);
    const saveQueue = useRef({});
    const [weeks, setWeeks, weeksRef] = useLatestState([]);
    const [currentWeek, setCurrentWeek] = useState(null);
    const [editable, setEditable] = useState(false);
    // What was copied last: { week } or { cell, sports } (a day).
    const [copied, setCopied, copiedRef] = useLatestState(null);
    const [selectedCells, setSelectedCells, selectedCellsRef] = useLatestState([]);
    // Where Shift+click selects from.
    const anchorCell = useRef(null);
    const [selectedWeeks, setSelectedWeeks, selectedWeeksRef] = useLatestState([]);
    // Dragging a day's corner: { from, to }, the cells it is dragged from and to.
    const [fill, setFill] = useState(null);
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
                setWeeks(() => body.weeks.map(normalizeWeek));
                setCurrentWeek(body.currentWeek);
                setEditable(body.editable);
            })
            .catch((e) => setError(e.message))
            .finally(() => setLoading(false));
    }, [apiUrl, setWeeks]);

    const replaceWeek = (to) => setWeeks((current) => current.map((week) => (week.weekStart === to.weekStart ? to : week)));

    // Shows the change at once, then saves the week; resolves to whether that worked. Saves of the same
    // week are queued, so they reach the server in order. After the last one, the week is shown as the
    // server last confirmed it, so a failed change is undone.
    const updateWeek = async (weekStart, changes) => {
        const current = weeksRef.current.find((week) => week.weekStart === weekStart);
        const next = { ...current, ...changes(current) };
        replaceWeek(next);
        setError(null);

        const confirmed = saveQueue.current[weekStart] ?? Promise.resolve(current);
        const save = confirmed.then(() => api(`${apiUrl}/${weekStart}`, {
            method: 'PUT',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ days: next.days, notes: next.notes ?? null }),
        })).then(normalizeWeek);
        const settled = save.catch(() => confirmed);
        saveQueue.current[weekStart] = settled;

        const failure = await save.then(() => null, (e) => e);
        if (failure) {
            setError(failure.message);
        }
        if (saveQueue.current[weekStart] === settled) {
            replaceWeek(await settled);
        }
        return !failure;
    };

    const copyWeek = (week) => {
        setCopied({ week });
        setSelectedCells([]);
        // Start a fresh selection: the weeks tapped next are where it gets pasted.
        setSelectedWeeks([]);
        setNotice(`Copied ${weekLabel(week.weekStart)}. Select the weeks to paste it into.`);
    };

    const copyDay = (cell) => {
        const sports = sportsOf(cell);
        // Pasting nothing would delete the plans of the days it is pasted into.
        if (relativeMiles(sports) <= 0) {
            setNotice(`Nothing is planned on ${cellLabel(cell)} to copy.`);
            return;
        }
        setCopied({ cell, sports });
        // Like copyWeek(): the days selected next are where it gets pasted.
        setSelectedCells([]);
        anchorCell.current = cell;
        setNotice(`Copied ${cellLabel(cell)}. Ctrl/⌘/Shift+click the days to paste it into.`);
    };

    // Pastes the activities of a copied week into each target week (keeping their notes).
    const pasteWeeks = async (source, targets) => {
        const planned = targets.filter(hasPlan).map((week) => weekLabel(week.weekStart));
        if (planned.length > 0 && !window.confirm(`Replace the plan of ${planned.join(', ')} with ${weekLabel(source.weekStart)}?`)) {
            return;
        }
        const saved = await Promise.all(targets.map((week) => updateWeek(week.weekStart, () => ({ days: source.days }))));
        const pasted = targets.filter((week, index) => saved[index]).map((week) => weekLabel(week.weekStart));
        if (pasted.length > 0) {
            setNotice(`Pasted ${weekLabel(source.weekStart)} into ${pasted.join(', ')}.`);
        }
    };

    const isShown = ({ weekStart }) => weeksRef.current.some((week) => week.weekStart === weekStart);

    const sportsOf = ({ weekStart, day }) => weeksRef.current.find((week) => week.weekStart === weekStart)?.days[day] ?? {};

    // Copies the sports of a day into each cell ({ weekStart, day }); saves every changed week once.
    const fillCells = async (source, sports, cells) => {
        const changed = cells.filter((cell) => isShown(cell) && !sameSports(sportsOf(cell), sports));
        if (changed.length === 0) {
            return;
        }
        const planned = changed.filter((cell) => relativeMiles(sportsOf(cell)) > 0).map(cellLabel);
        if (planned.length > 0 && !window.confirm(`Replace ${planned.join(', ')} with ${cellLabel(source)}?`)) {
            return;
        }
        const weekStarts = [...new Set(changed.map((cell) => cell.weekStart))];
        const saved = await Promise.all(weekStarts.map((weekStart) => updateWeek(weekStart, (week) => ({
            days: changed
                .filter((cell) => cell.weekStart === weekStart)
                .reduce((days, { day }) => withDay(days, day, sports), week.days),
        }))));
        const filled = changed.filter((cell) => saved[weekStarts.indexOf(cell.weekStart)]).map(cellLabel);
        if (filled.length > 0) {
            setNotice(`Copied ${cellLabel(source)} to ${filled.join(', ')}.`);
        }
    };

    const clearWeeks = (targets) => {
        if (window.confirm(`Clear the plan of ${targets.map((week) => weekLabel(week.weekStart)).join(', ')}?`)) {
            targets.forEach((week) => updateWeek(week.weekStart, () => ({ days: {} })));
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
        let failed = 'Import failed';
        try {
            // Pending saves first, or they would overwrite the import.
            await Promise.all(Object.values(saveQueue.current));
            saveQueue.current = {};
            const { imported } = await api(`${apiUrl}/import`, {
                method: 'POST',
                headers: { 'Content-Type': 'text/csv' },
                body: await file.text(),
            });
            failed = 'Imported, but the weeks could not be reloaded';
            const shown = weeksRef.current;
            const body = await api(`${apiUrl}?from=${shown[0].weekStart}&weeks=${shown.length}`);
            setWeeks(() => body.weeks.map(normalizeWeek));
            setNotice(`Imported ${imported} week${imported === 1 ? '' : 's'}.`);
        } catch (e) {
            setError(`${failed}: ${e.message}`);
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
            cellRendererParams: {
                compact,
                editable,
                // Dragging the corner of a day or selecting days doesn't open the popup.
                suppressMouseEventHandling: ({ event }) => isFillHandle(event) || isSelectClick(event),
            },
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
        updateWeek(data.weekStart, (week) => ({ days: withDay(week.days, colDef.colId, newValue) }));
    };

    // Newest first, like the stats table.
    const rows = useMemo(() => weeks.toReversed(), [weeks]);
    const selection = rows.filter((week) => selectedWeeks.includes(week.weekStart));

    // Ctrl/⌘+click adds a day to / removes it from the selected days; Shift+click selects up to it.
    const selectCell = (cell, event) => {
        if (event.shiftKey && anchorCell.current) {
            setSelectedCells(cellsBetween(rows, anchorCell.current, cell));
            return;
        }
        anchorCell.current = cell;
        setSelectedCells((current) => (current.some((other) => isSameCell(other, cell))
            ? current.filter((other) => !isSameCell(other, cell))
            : [...current, cell]));
    };

    // Tapping a week adds it to / removes it from the selection; tapping a day edits it.
    const onCellClicked = ({ colDef, data, event, api: grid }) => {
        if (!editable || isFillHandle(event)) {
            return;
        }
        const { weekStart } = data;
        if (isDay(colDef)) {
            const cell = { weekStart, day: colDef.colId };
            if (isSelectClick(event)) {
                // Saves an open popup; it would stay open otherwise.
                grid.stopEditing();
                selectCell(cell, event);
            } else {
                anchorCell.current = cell;
                setSelectedCells([]);
            }
            return;
        }
        if (colDef.field !== 'weekStart') {
            return;
        }
        setSelectedWeeks((current) => (current.includes(weekStart)
            ? current.filter((week) => week !== weekStart)
            : [...current, weekStart]));
    };

    // Where a copied week gets pasted: the selected weeks, else the weeks of the selected days, else the
    // focused week.
    const pasteTargets = (focused) => {
        const selectedDays = selectedCellsRef.current.map((cell) => cell.weekStart);
        let targets = [focused];
        if (selectedWeeksRef.current.includes(focused)) {
            targets = selectedWeeksRef.current;
        } else if (selectedDays.length > 0) {
            targets = selectedDays;
        }
        return weeksRef.current.toReversed().filter((week) => targets.includes(week.weekStart));
    };

    // Ctrl/⌘+C copies the focused day, or the focused week in the other columns. Ctrl/⌘+V pastes what was
    // copied last: a day into the selected days (or the focused day when none are), a week as above.
    // Escape unselects the days and forgets what was copied. Left alone in an open editor, where they
    // copy and paste text.
    const onCellKeyDown = ({ event, data, colDef, api: grid }) => {
        if (!editable || grid.getEditingCells().length > 0) {
            return;
        }
        if (event.key === 'Escape') {
            setSelectedCells([]);
            setCopied(null);
            setNotice(null);
            return;
        }
        // E.g. Ctrl+Shift+C opens the developer tools; a key held down pastes once.
        if (!(event.ctrlKey || event.metaKey) || event.shiftKey || event.altKey || event.repeat) {
            return;
        }
        const { weekStart } = data;
        const cell = isDay(colDef) ? { weekStart, day: colDef.colId } : null;
        const key = event.key.toLowerCase();
        if (key === 'c' && cell) {
            copyDay(cell);
        } else if (key === 'c') {
            const week = weeksRef.current.find((other) => other.weekStart === weekStart);
            if (week && hasPlan(week)) {
                copyWeek(week);
            }
        } else if (key === 'v' && copiedRef.current?.week) {
            const { week: source } = copiedRef.current;
            pasteWeeks(source, pasteTargets(weekStart).filter((week) => week.weekStart !== source.weekStart));
        } else if (key === 'v' && copiedRef.current) {
            const { cell: source, sports } = copiedRef.current;
            const focused = cell ? [cell] : [];
            fillCells(source, sports, selectedCellsRef.current.length > 0 ? selectedCellsRef.current : focused);
        }
    };

    // Dragging the corner of a day up or down copies it to the weeks on the way. The corner captures the
    // pointer, so all these events reach it (and no cell is clicked), wherever the pointer is.
    const onFillStart = (event) => {
        if (event.button !== 0 || !isFillHandle(event)) {
            return;
        }
        // Saves an open popup; it would stay open otherwise.
        gridRef.current.api.stopEditing();
        event.target.setPointerCapture(event.pointerId);
        const from = {
            weekStart: event.target.closest('.ag-row').getAttribute('row-id'),
            day: event.target.closest('[col-id]').getAttribute('col-id'),
        };
        setFill({ from, to: from });
    };

    const onFillMove = (event) => {
        // Without the button down, the end of the drag was missed.
        if (event.buttons === 0) {
            setFill(null);
            return;
        }
        const weekStart = weekAt(event);
        // Only a change of week renders again.
        setFill((current) => (!current || !weekStart || weekStart === current.to.weekStart
            ? current
            : { ...current, to: { ...current.to, weekStart } }));
    };

    const onFillEnd = (event) => {
        if (!fill) {
            return;
        }
        setFill(null);
        const sports = sportsOf(fill.from);
        // Empty when the day was cleared in its popup just before the drag.
        if (relativeMiles(sports) > 0) {
            const to = { ...fill.to, weekStart: weekAt(event) ?? fill.to.weekStart };
            fillCells(fill.from, sports, cellsBetween(rows, fill.from, to));
        }
    };

    // Escape cancels the drag, and so does losing the corner (when its day is cleared).
    const dragging = fill !== null;
    useEffect(() => {
        if (!dragging) {
            return undefined;
        }
        const cancel = () => setFill(null);
        const onKeyDown = (event) => {
            if (event.key === 'Escape') {
                // Only the drag: the copied and selected days stay.
                event.stopPropagation();
                cancel();
            }
        };
        document.addEventListener('keydown', onKeyDown, true);
        document.addEventListener('lostpointercapture', cancel);
        return () => {
            document.removeEventListener('keydown', onKeyDown, true);
            document.removeEventListener('lostpointercapture', cancel);
        };
    }, [dragging]);

    if (loading && weeks.length === 0) {
        return <div className="has-text-centered py-5"><i className="fas fa-cog fa-spin fa-2x" /></div>;
    }

    if (error && weeks.length === 0) {
        return <div className="notification is-danger">{error}</div>;
    }

    const highlightedCells = fill ? cellsBetween(rows, fill.from, fill.to) : selectedCells;

    return (
        <div>
            <PlanChart weeks={weeks} currentWeek={currentWeek} />
            {error && <div className="notification is-danger is-light py-2">{error}</div>}
            <div className="is-flex is-align-items-center is-justify-content-space-between mb-3">
                <span className="is-size-7 has-text-grey">
                    {notice ?? (editable
                        ? (compact
                            ? 'Tap a day to plan it, or a week to copy and paste it.'
                            : 'Click a day to plan it, or drag its corner up or down to copy it. Click a week to copy and paste it. Ctrl/⌘/Shift+click selects days; Ctrl/⌘+C, Ctrl/⌘+V copy and paste days or weeks.')
                        : 'Relative miles: bike ÷3, swim ×4, tennis ×2.')}
                </span>
                {!editable && <a href={connectUrl} className="button is-small mb-0 ml-2">Connect to edit</a>}
            </div>
            <div className="has-text-centered pb-3">
                <button type="button" className="button is-small" onClick={() => loadMore('later')} disabled={loading}>
                    Later weeks
                </button>
            </div>
            {selection.length > 0 && (
                <>
                    <WeekActionBar
                        key={selectedWeeks.join(',')}
                        selection={selection}
                        copied={copied?.week}
                        compact={compact}
                        onCopy={copyWeek}
                        onPaste={(targets) => pasteWeeks(copied.week, targets)}
                        onClear={clearWeeks}
                        onSaveNotes={(week, notes) => updateWeek(week.weekStart, () => ({ notes }))}
                        onClose={() => setSelectedWeeks([])}
                    />
                    {/* Highlights the selected rows without redrawing the grid (which would close an open editor). */}
                    <style>
                        {`${selection.map((week) => `.strava-plan-grid .ag-row[row-id="${week.weekStart}"]`).join(', ')} { background-color: rgba(57, 135, 229, 0.28); }`}
                    </style>
                </>
            )}
            {highlightedCells.length > 0 && (
                <style>
                    {`${highlightedCells.map(cellSelector).join(', ')} { background-color: rgba(57, 135, 229, 0.28); }`}
                </style>
            )}
            {copied?.cell && (
                <style>
                    {`${cellSelector(copied.cell)} { outline: 2px dashed #3987e5; outline-offset: -2px; }`}
                </style>
            )}
            <div
                className="strava-plan-grid"
                onPointerDown={onFillStart}
                onPointerMove={onFillMove}
                onPointerUp={onFillEnd}
                onPointerCancel={() => setFill(null)}
            >
                <AgGridReact
                    ref={gridRef}
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
                            disabled={loading}
                        >
                            <span className="icon"><i className="fas fa-file-arrow-up" /></span>
                            <span>Import</span>
                        </button>
                        <input ref={importInput} type="file" accept=".csv,text/csv" hidden onChange={importCsv} />
                        <a
                            className="button is-small mb-0"
                            href={`${apiUrl}/example.csv`}
                            title="Download a two-week example CSV to fill in and import"
                            download
                        >
                            <span className="icon"><i className="fas fa-file-csv" /></span>
                            <span>Example import</span>
                        </a>
                    </>
                )}
            </div>
            {/* Room for the floating week bar, so it doesn't cover the last rows. */}
            {selection.length > 0 && <div style={{ height: '6rem' }} />}
        </div>
    );
}
