import { Controller } from '@hotwired/stimulus';

/*
 * Loads and updates the weekly Strava stats on /strava.
 *
 *   load():     GET  tableUrl?weeks=N  -> HTML fragment, read from the database (no Strava call)
 *   loadMore(): shows LOAD_MORE_SIZE more weeks
 *   refresh():  POST refreshUrl        -> fetches the latest week(s) from Strava, then reloads the table
 *   reset():    POST resetUrl          -> reloads the whole history from Strava, then reloads the table
 *
 * Endpoints: src/Controller/StravaStatsController.php
 */
const LOAD_MORE_SIZE = 10;

export default class extends Controller {
    static targets = ['table', 'notice', 'button'];

    static values = {
        tableUrl: String,
        refreshUrl: String,
        resetUrl: String,
        weeks: { type: Number, default: 20 },
    };

    connect() {
        this.load();
    }

    disconnect() {
        this.abortController?.abort();
    }

    async load() {
        // Only the latest load wins (e.g. fast "Load more" clicks).
        this.abortController?.abort();
        this.abortController = new AbortController();

        this.tableTarget.setAttribute('aria-busy', 'true');
        try {
            const url = new URL(this.tableUrlValue, window.location.origin);
            url.searchParams.set('weeks', String(this.weeksValue));

            const response = await fetch(url, {
                headers: { Accept: 'text/html' },
                signal: this.abortController.signal,
            });

            // Error responses are rendered fragments too (e.g. "Please connect to Strava").
            this.tableTarget.innerHTML = await response.text();
        } catch (error) {
            if (error.name === 'AbortError') {
                return;
            }

            this.tableTarget.innerHTML = '<div class="notification is-danger">Could not load the stats. Please try again.</div>';
        } finally {
            this.tableTarget.removeAttribute('aria-busy');
        }
    }

    loadMore(event) {
        event.currentTarget.classList.add('is-loading');
        this.weeksValue += LOAD_MORE_SIZE;
        this.load();
    }

    refresh(event) {
        this.runStravaAction(event.currentTarget, this.refreshUrlValue);
    }

    reset(event) {
        if (!window.confirm('Delete the stored stats and reload your whole history from Strava?')) {
            return;
        }

        this.runStravaAction(event.currentTarget, this.resetUrlValue);
    }

    async runStravaAction(button, url) {
        this.setBusy(button, true);
        this.showNotice('');

        try {
            const response = await fetch(url, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    // Required by the endpoint: a cross-site form post cannot send it.
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });
            const data = await response.json().catch(() => ({}));

            if (!response.ok) {
                this.showNotice(data.error ?? 'Something went wrong. Please try again.', true);

                return;
            }

            this.showNotice(data.message ?? 'Updated.');
            await this.load();
        } catch {
            this.showNotice('Could not reach the server. Please try again.', true);
        } finally {
            this.setBusy(button, false);
        }
    }

    setBusy(activeButton, busy) {
        this.buttonTargets.forEach((button) => {
            button.disabled = busy;
        });
        activeButton.classList.toggle('is-loading', busy);
    }

    showNotice(message, isError = false) {
        this.noticeTarget.textContent = message;
        this.noticeTarget.classList.toggle('has-text-success', !isError);
        this.noticeTarget.classList.toggle('has-text-danger', isError);
    }
}
