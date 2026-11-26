import { Controller } from '@hotwired/stimulus';

/*
 * A Bulma dropdown shown by a click or tap (touch screens have no hover), as a disclosure: a click on it
 * toggles it, a click anywhere else or Escape closes it.
 *
 * <div class="dropdown" data-controller="dropdown"
 *      data-action="click->dropdown#toggle click@window->dropdown#closeOutside keydown.esc@window->dropdown#close">
 *     <button class="dropdown-trigger" aria-expanded="false" aria-controls="menu" data-dropdown-target="button">…</button>
 *     <div class="dropdown-menu" id="menu">…</div>
 * </div>
 */
export default class extends Controller {
    static targets = ['button'];

    toggle() {
        this.#setOpen(!this.element.classList.contains('is-active'));
    }

    close() {
        this.#setOpen(false);
    }

    closeOutside(event) {
        if (!this.element.contains(event.target)) {
            this.close();
        }
    }

    #setOpen(open) {
        this.element.classList.toggle('is-active', open);
        if (this.hasButtonTarget) {
            this.buttonTarget.setAttribute('aria-expanded', String(open));
        }
    }
}
