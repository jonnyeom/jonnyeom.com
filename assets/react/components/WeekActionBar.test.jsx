import { fireEvent, render, screen } from '@testing-library/react';
import React from 'react';
import { describe, expect, it, vi } from 'vitest';
import WeekActionBar from './WeekActionBar.jsx';

const week = (weekStart, days = { Mon: { Run: 5 } }, notes = null) => ({ weekStart, days, notes });

const renderBar = (props) => {
    const handlers = {
        onCopy: vi.fn(),
        onPaste: vi.fn(),
        onClear: vi.fn(),
        onSaveNotes: vi.fn(),
        onClose: vi.fn(),
    };
    render(<WeekActionBar selection={[]} copied={null} compact={false} {...handlers} {...props} />);
    return handlers;
};

const button = (name) => screen.getByRole('button', { name });

describe('WeekActionBar', () => {
    it('is hidden while no week is selected', () => {
        renderBar({ selection: [] });
        expect(screen.queryByRole('button')).not.toBeInTheDocument();
    });

    it('copies the selected week', () => {
        const selected = week('2026-11-23');
        const { onCopy } = renderBar({ selection: [selected] });

        expect(screen.getByText('Week of 11.23')).toBeInTheDocument();
        expect(button('Paste')).toBeDisabled(); // Nothing copied yet.
        fireEvent.click(button('Copy'));
        expect(onCopy).toHaveBeenCalledWith(selected);
    });

    it("can't copy or clear a week without a plan", () => {
        renderBar({ selection: [week('2026-11-23', {})] });
        expect(button('Copy')).toBeDisabled();
        expect(button('Clear')).toBeDisabled();
    });

    it('pastes into every selected week except the copied one', () => {
        const copied = { weekStart: '2026-11-16', days: { Mon: { Run: 5 } } };
        const targets = [week('2026-11-30', {}), week('2026-11-23')];
        const { onPaste } = renderBar({ selection: [...targets, week('2026-11-16')], copied });

        expect(screen.getByText('3 weeks selected')).toBeInTheDocument();
        // Copy and Notes only act on a single week.
        expect(screen.queryByRole('button', { name: 'Copy' })).not.toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Notes' })).not.toBeInTheDocument();

        fireEvent.click(button('Paste'));
        expect(onPaste).toHaveBeenCalledWith(targets);
    });

    it("can't paste a week onto itself", () => {
        renderBar({ selection: [week('2026-11-16')], copied: { weekStart: '2026-11-16', days: {} } });
        expect(button('Paste')).toBeDisabled();
    });

    it('clears only the selected weeks that have a plan', () => {
        const planned = week('2026-11-23');
        const { onClear } = renderBar({ selection: [week('2026-11-30', {}), planned] });

        fireEvent.click(button('Clear'));
        expect(onClear).toHaveBeenCalledWith([planned]);
    });

    it('edits the notes of the week', () => {
        const selected = week('2026-11-23', { Mon: { Run: 5 } }, 'Easy');
        const { onSaveNotes } = renderBar({ selection: [selected] });

        fireEvent.click(button('Notes'));
        const notes = screen.getByPlaceholderText(/Notes/);
        expect(notes).toHaveValue('Easy');
        fireEvent.change(notes, { target: { value: 'Long run Saturday' } });
        fireEvent.click(button('Save notes'));

        expect(onSaveNotes).toHaveBeenCalledWith(selected, 'Long run Saturday');
        expect(screen.queryByPlaceholderText(/Notes/)).not.toBeInTheDocument();
    });

    it("doesn't save unchanged notes", () => {
        const { onSaveNotes } = renderBar({ selection: [week('2026-11-23', { Mon: { Run: 5 } }, 'Easy')] });

        fireEvent.click(button('Notes'));
        fireEvent.click(button('Save notes'));
        expect(onSaveNotes).not.toHaveBeenCalled();
    });

    it('unselects the weeks', () => {
        const { onClose } = renderBar({ selection: [week('2026-11-23')] });
        fireEvent.click(button('Unselect the weeks'));
        expect(onClose).toHaveBeenCalled();
    });
});
