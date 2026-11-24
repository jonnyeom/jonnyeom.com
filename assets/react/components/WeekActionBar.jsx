import React, { useState } from 'react';
import { hasPlan, inputSize, weekLabel } from '../plan.js';

const BarButton = ({ label, icon, onClick, disabled, extra = '' }) => (
    <button type="button" className={`button is-small mb-0${extra}`} onClick={onClick} disabled={disabled}>
        <span className="icon"><i className={`fas fa-${icon}`} /></span>
        {label && <span>{label}</span>}
    </button>
);

/**
 * Bar acting on the selected weeks (tap / click weeks to select them): copy a week, paste the
 * copied week into every selected week, clear them, notes of a single week. Only shown while weeks
 * are selected; it floats at the bottom of the screen, so it doesn't move the grid and stays in
 * reach while scrolling.
 */
export default function WeekActionBar({ selection, copied, compact, onCopy, onPaste, onClear, onSaveNotes, onClose }) {
    const week = selection.length === 1 ? selection[0] : null;
    // The parent gives the bar a key per selection, so this state starts fresh for each selection.
    const [notesOpen, setNotesOpen] = useState(false);
    const [notes, setNotes] = useState(week?.notes ?? '');

    const isSource = (candidate) => copied?.weekStart === candidate.weekStart;
    const pasteTargets = selection.filter((candidate) => !isSource(candidate));
    const clearTargets = selection.filter(hasPlan);

    if (selection.length === 0) {
        return null;
    }

    const title = week ? `Week of ${weekLabel(week.weekStart)}` : `${selection.length} weeks selected`;

    return (
        <div className="strava-plan-actions box px-3 py-2">
            <div className="is-flex is-align-items-center is-flex-wrap-wrap" style={{ gap: '0.5rem' }}>
                <span className="has-text-weight-semibold mr-auto">{title}</span>
                <div className="buttons are-small mb-0 is-flex-wrap-nowrap" style={{ gap: '0.25rem' }}>
                    {selection.length <= 1 && (
                        <BarButton
                            label="Copy"
                            icon="copy"
                            onClick={() => onCopy(week)}
                            disabled={!week || !hasPlan(week)}
                            extra={week && isSource(week) ? ' is-link is-light' : ''}
                        />
                    )}
                    <BarButton label="Paste" icon="paste" onClick={() => onPaste(pasteTargets)} disabled={!copied || pasteTargets.length === 0} />
                    <BarButton label={compact ? '' : 'Clear'} icon="eraser" onClick={() => onClear(clearTargets)} disabled={clearTargets.length === 0} />
                    {selection.length <= 1 && (
                        <BarButton
                            label={compact ? '' : 'Notes'}
                            icon="note-sticky"
                            onClick={() => setNotesOpen((open) => !open)}
                            disabled={!week}
                            extra={notesOpen ? ' is-link is-light' : ''}
                        />
                    )}
                    <button type="button" className="delete is-medium ml-1" aria-label="Unselect the weeks" onClick={onClose} />
                </div>
            </div>
            {week && notesOpen && (
                <div className="mt-2">
                    <textarea
                        className={`textarea${inputSize(compact)} mb-2`}
                        rows={3}
                        maxLength={2000}
                        placeholder="Notes (e.g. long run Saturday, recovery week)"
                        value={notes}
                        onChange={(e) => setNotes(e.target.value)}
                    />
                    <div className="buttons is-right mb-0">
                        <button type="button" className="button is-small mb-0" onClick={() => setNotesOpen(false)}>Cancel</button>
                        <button
                            type="button"
                            className="button is-small is-link mb-0"
                            onClick={() => {
                                setNotesOpen(false);
                                if (notes !== (week.notes ?? '')) {
                                    onSaveNotes(week, notes);
                                }
                            }}
                        >
                            Save notes
                        </button>
                    </div>
                </div>
            )}
        </div>
    );
}
