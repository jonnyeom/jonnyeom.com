import { Application } from '@hotwired/stimulus';
import { fireEvent, waitFor } from '@testing-library/dom';
import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import DropdownController from './dropdown_controller.js';

const dropdown = () => document.querySelector('.dropdown');
const button = () => document.querySelector('button');

describe('dropdown controller', () => {
    let application;

    beforeEach(async () => {
        document.body.innerHTML = `
            <div class="dropdown" data-controller="dropdown"
                 data-action="click->dropdown#toggle click@window->dropdown#closeOutside keydown.esc@window->dropdown#close">
                <button type="button" aria-expanded="false" aria-controls="menu" data-dropdown-target="button">5.2</button>
                <div class="dropdown-menu" id="menu"><div class="dropdown-item">5.2 mi</div></div>
            </div>
            <p>Elsewhere</p>`;
        application = Application.start();
        application.register('dropdown', DropdownController);
        // Stimulus connects the controllers asynchronously.
        await waitFor(() => {
            if (application.controllers.length !== 1) {
                throw new Error('Not connected yet');
            }
        });
    });

    afterEach(() => {
        application.stop();
        document.body.innerHTML = '';
    });

    it('opens and closes when its button is clicked or tapped', () => {
        fireEvent.click(button());
        expect(dropdown()).toHaveClass('is-active');
        expect(button()).toHaveAttribute('aria-expanded', 'true');

        fireEvent.click(button());
        expect(dropdown()).not.toHaveClass('is-active');
        expect(button()).toHaveAttribute('aria-expanded', 'false');
    });

    it('closes on a click elsewhere', () => {
        fireEvent.click(button());
        fireEvent.click(document.querySelector('p'));

        expect(dropdown()).not.toHaveClass('is-active');
        expect(button()).toHaveAttribute('aria-expanded', 'false');
    });

    it('closes with Escape', () => {
        fireEvent.click(button());
        fireEvent.keyDown(button(), { key: 'Escape' });

        expect(dropdown()).not.toHaveClass('is-active');
        expect(button()).toHaveAttribute('aria-expanded', 'false');
    });
});
