import React, { useCallback, useEffect, useState } from 'react';

const DAYS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

// Same weighting as the stats (App\Enum\SportType::relativeDistance()).
const SPORTS = [
    { type: 'Run', icon: 'person-running', factor: 1 },
    { type: 'Bike', icon: 'person-biking', factor: 1 / 3 },
    { type: 'Swim', icon: 'person-swimming', factor: 4 },
    { type: 'Tennis', icon: 'table-tennis-paddle-ball', factor: 2 },
];

const LOAD_MORE_WEEKS = 8;

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

// Editor state: strings per day and sport, so partially typed numbers ("1.") survive.
const toDraft = (week) => ({
    notes: week.notes ?? '',
    days: Object.fromEntries(DAYS.map((day) => [
        day,
        Object.fromEntries(SPORTS.map((sport) => [sport.type, week.days?.[day]?.[sport.type]?.toString() ?? ''])),
    ])),
});

const fromDraft = (draft) => ({
    notes: draft.notes,
    days: Object.fromEntries(DAYS.map((day) => [
        day,
        Object.fromEntries(SPORTS
            .filter((sport) => draft.days[day][sport.type] !== '')
            .map((sport) => [sport.type, Number(draft.days[day][sport.type])])),
    ])),
});

const DayCell = ({ sports, miles }) => {
    if (!miles) {
        return <td />;
    }

    const planned = SPORTS.filter((sport) => sports?.[sport.type] > 0);

    return (
        <td title={planned.map((sport) => `${sport.type}: ${sports[sport.type]} mi`).join('\n')}>
            <span className="icon-text">
                <span>{round1(miles)}{planned.length > 1 && <span className="is-hidden-tablet">*</span>}</span>
                {planned.map((sport) => (
                    <span key={sport.type} className="icon is-hidden-mobile">
                        <i className={`fas fa-${sport.icon}`} />
                    </span>
                ))}
            </span>
        </td>
    );
};

const WeekEditor = ({ week, onSaved, onCancel, apiUrl }) => {
    const [draft, setDraft] = useState(() => toDraft(week));
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState(null);

    const setMiles = (day, sport, value) => setDraft((current) => ({
        ...current,
        days: { ...current.days, [day]: { ...current.days[day], [sport]: value } },
    }));

    const total = DAYS.reduce((sum, day) => sum + relativeMiles(fromDraft(draft).days[day]), 0);

    const save = async (event) => {
        event.preventDefault();
        setSaving(true);
        setError(null);
        try {
            const response = await fetch(`${apiUrl}/${week.weekStart}`, {
                method: 'PUT',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                credentials: 'same-origin',
                body: JSON.stringify(fromDraft(draft)),
            });
            const body = await response.json().catch(() => ({}));
            if (!response.ok) {
                throw new Error(body.error ?? `Saving failed (${response.status})`);
            }
            onSaved(body);
        } catch (e) {
            setError(e.message);
        } finally {
            setSaving(false);
        }
    };

    return (
        <tr>
            <td colSpan={11} className="has-text-left">
                <form onSubmit={save} className="py-2">
                    {error && <div className="notification is-danger is-light py-2">{error}</div>}
                    <div className="table-container">
                        <table className="table is-narrow is-fullwidth has-text-centered mb-3">
                            <thead>
                                <tr>
                                    <th />
                                    {DAYS.map((day) => <th key={day}>{day}</th>)}
                                </tr>
                            </thead>
                            <tbody>
                                {SPORTS.map((sport) => (
                                    <tr key={sport.type}>
                                        <th title={sport.type}>
                                            <span className="icon"><i className={`fas fa-${sport.icon}`} /></span>
                                            <span className="is-hidden-mobile is-size-7">{sport.type}</span>
                                        </th>
                                        {DAYS.map((day) => (
                                            <td key={day}>
                                                <input
                                                    className="input is-small has-text-centered"
                                                    style={{ minWidth: '3.5rem' }}
                                                    type="number"
                                                    inputMode="decimal"
                                                    min="0"
                                                    max="300"
                                                    step="0.1"
                                                    aria-label={`${sport.type} miles on ${day}`}
                                                    value={draft.days[day][sport.type]}
                                                    onChange={(e) => setMiles(day, sport.type, e.target.value)}
                                                />
                                            </td>
                                        ))}
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                    <div className="field">
                        <div className="control">
                            <textarea
                                className="textarea is-small"
                                rows={2}
                                maxLength={2000}
                                placeholder="Notes (e.g. long run Saturday, recovery week)"
                                value={draft.notes}
                                onChange={(e) => setDraft((current) => ({ ...current, notes: e.target.value }))}
                            />
                        </div>
                    </div>
                    <div className="is-flex is-align-items-center is-justify-content-space-between">
                        <span className="is-size-7 has-text-grey">
                            Relative total: <b>{round1(total)} mi</b> (bike ÷3, swim ×4, tennis ×2)
                        </span>
                        <div className="buttons mb-0">
                            <button type="button" className="button is-small mb-0" onClick={onCancel} disabled={saving}>
                                Cancel
                            </button>
                            <button type="submit" className={`button is-small is-link mb-0${saving ? ' is-loading' : ''}`}>
                                Save
                            </button>
                        </div>
                    </div>
                </form>
            </td>
        </tr>
    );
};

const StravaPlanner = ({ apiUrl, connectUrl }) => {
    const [weeks, setWeeks] = useState([]);
    const [currentWeek, setCurrentWeek] = useState(null);
    const [editable, setEditable] = useState(false);
    const [editing, setEditing] = useState(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);

    const fetchWeeks = useCallback(async (query = '') => {
        const response = await fetch(`${apiUrl}${query}`, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        });
        const body = await response.json().catch(() => ({}));
        if (!response.ok) {
            throw new Error(body.error ?? `Loading the plan failed (${response.status})`);
        }
        return body;
    }, [apiUrl]);

    useEffect(() => {
        fetchWeeks()
            .then((body) => {
                setWeeks(body.weeks);
                setCurrentWeek(body.currentWeek);
                setEditable(body.editable);
            })
            .catch((e) => setError(e.message))
            .finally(() => setLoading(false));
    }, [fetchWeeks]);

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
            const body = await fetchWeeks(`?from=${from}&weeks=${LOAD_MORE_WEEKS}`);
            setWeeks((current) => (direction === 'earlier' ? [...body.weeks, ...current] : [...current, ...body.weeks]));
        } catch (e) {
            setError(e.message);
        } finally {
            setLoading(false);
        }
    };

    const onSaved = (saved) => {
        setWeeks((current) => current.map((week) => (week.weekStart === saved.weekStart ? saved : week)));
        setEditing(null);
    };

    if (error && weeks.length === 0) {
        return <div className="notification is-danger">{error}</div>;
    }

    if (loading && weeks.length === 0) {
        return <div className="has-text-centered py-5"><i className="fas fa-cog fa-spin fa-2x" /></div>;
    }

    // Newest first, like the stats table.
    const rows = [...weeks].reverse();

    return (
        <div>
            {error && <div className="notification is-danger is-light">{error}</div>}
            <div className="is-flex is-align-items-center is-justify-content-space-between mb-3">
                <span className="is-size-7 has-text-grey">
                    Relative miles: bike ÷3, swim ×4, tennis ×2.
                    {editable ? ' Click a week to edit its plan.' : ''}
                </span>
                {!editable && (
                    <a href={connectUrl} className="button is-small mb-0">Connect to edit</a>
                )}
            </div>
            <div className="has-text-centered pb-3">
                <button type="button" className="button is-small" onClick={() => loadMore('later')} disabled={loading}>
                    Later weeks
                </button>
            </div>
            <div className="table-container">
                <table className="table is-hoverable is-striped is-narrow-mobile has-text-centered is-fullwidth">
                    <thead>
                        <tr>
                            <th>Week</th>
                            {DAYS.map((day) => <th key={day}>{day}</th>)}
                            <th>plan</th>
                            <th>actual</th>
                            <th />
                        </tr>
                    </thead>
                    <tbody>
                        {rows.map((week) => {
                            const isCurrent = week.weekStart === currentWeek;
                            const isEditing = editing === week.weekStart;
                            const diff = week.actual && week.totalMiles > 0
                                ? round1(week.actual.totalMiles - week.totalMiles)
                                : null;

                            return (
                                <React.Fragment key={week.weekStart}>
                                    <tr
                                        className={isCurrent ? 'is-selected' : undefined}
                                        style={editable ? { cursor: 'pointer' } : undefined}
                                        onClick={editable ? () => setEditing(isEditing ? null : week.weekStart) : undefined}
                                        title={week.notes ?? undefined}
                                    >
                                        <th>
                                            {weekLabel(week.weekStart)}
                                            {week.notes && <span className="icon is-small ml-1"><i className="fas fa-note-sticky" /></span>}
                                        </th>
                                        {DAYS.map((day) => (
                                            <DayCell key={day} sports={week.days[day]} miles={week.dailyMiles[day]} />
                                        ))}
                                        <td><b>{week.totalMiles > 0 ? round1(week.totalMiles) : ''}<span className="is-hidden-mobile">{week.totalMiles > 0 ? ' mi' : ''}</span></b></td>
                                        <td>{week.actual ? <>{week.actual.totalMiles}<span className="is-hidden-mobile"> mi</span></> : ''}</td>
                                        <td className={diff === null ? '' : diff >= 0 ? 'has-text-success' : 'has-text-danger'}>
                                            {diff === null ? '' : `${diff > 0 ? '+' : ''}${diff}`}
                                        </td>
                                    </tr>
                                    {isEditing && (
                                        <WeekEditor
                                            week={week}
                                            apiUrl={apiUrl}
                                            onSaved={onSaved}
                                            onCancel={() => setEditing(null)}
                                        />
                                    )}
                                </React.Fragment>
                            );
                        })}
                    </tbody>
                </table>
            </div>
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
