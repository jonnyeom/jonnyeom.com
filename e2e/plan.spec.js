import { expect, test } from '@playwright/test';
import { SESSION_ID } from './setup.js';

test('plans a day and copies the week to another week', async ({ page, context, baseURL }) => {
    // Nothing external: no analytics hits, no dependency on other sites.
    await context.route((url) => url.origin !== baseURL, (route) => route.abort());
    await context.addCookies([{ name: 'PHPSESSID', value: SESSION_ID, url: baseURL }]);
    const week = (id) => page.locator(`.ag-pinned-left-cols-container .ag-row[row-id="${id}"] [col-id="weekStart"]`);
    const day = (id, name) => page.locator(`.ag-center-cols-container .ag-row[row-id="${id}"] [col-id="${name}"]`);

    await page.goto('/strava/plan');
    const rows = page.locator('.ag-pinned-left-cols-container .ag-row');
    await expect(rows.first()).toBeVisible();
    const [target, source] = await rows.evaluateAll((elements) => elements
        .map((element) => element.getAttribute('row-id'))
        .toSorted()
        .toReversed());

    await day(source, 'Tue').tap();
    const bike = page.getByLabel('Bike');
    // Smaller text makes iPhones zoom in on focus.
    await expect(bike).toHaveCSS('font-size', '16px');
    await bike.fill('30');
    await page.getByRole('button', { name: 'Done' }).tap();
    await expect(day(source, 'Tue')).toHaveText('10'); // Relative miles.
    await expect(page.locator('.strava-plan-actions')).toBeHidden();

    await week(source).tap();
    await page.getByRole('button', { name: 'Copy' }).tap();
    await week(target).tap();
    await page.getByRole('button', { name: 'Paste' }).tap();
    await expect(page.getByText(/^Pasted /)).toBeVisible();
    await page.getByRole('button', { name: 'Unselect the weeks' }).tap();
    await expect(page.locator('.strava-plan-actions')).toBeHidden();

    await page.reload();
    await expect(day(source, 'Tue')).toHaveText('10');
    await expect(day(target, 'Tue')).toHaveText('10');
});
