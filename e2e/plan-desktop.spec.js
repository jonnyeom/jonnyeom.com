import { devices, expect, test } from '@playwright/test';

const { defaultBrowserType: _unused, ...desktop } = devices['Desktop Chrome'];
test.use(desktop);

test('drags the corner of a day to copy it', async ({ page, context, baseURL }) => {
    await context.route((url) => url.origin !== baseURL, (route) => route.abort());
    await context.addCookies([{ name: 'PHPSESSID', value: process.env.E2E_SESSION_ID, url: baseURL }]);
    const day = (id, name) => page.locator(`.ag-center-cols-container .ag-row[row-id="${id}"] [col-id="${name}"]`);
    const popup = page.getByLabel('Run');

    await page.goto('/strava/plan');
    const rows = page.locator('.ag-center-cols-container .ag-row');
    await expect(rows.first()).toBeVisible();
    const weeks = await rows.evaluateAll((elements) => elements
        .map((element) => element.getAttribute('row-id'))
        .toSorted()
        .toReversed()
        .slice(4)); // plan.spec.js uses the latest weeks.

    await day(weeks[3], 'Sun').click();
    await popup.fill('6');
    await page.getByRole('button', { name: 'Done' }).click();
    await expect(day(weeks[3], 'Sun')).toHaveText('6');

    const corner = day(weeks[3], 'Sun').locator('.strava-plan-fill');
    const drag = async (to) => {
        await day(weeks[3], 'Sun').hover();
        await corner.hover();
        await page.mouse.down();
        // Over the target and back: it is still a drag, not a click on the day.
        await day(to, 'Sun').hover();
        await expect(day(to, 'Sun')).toHaveCSS('background-color', 'rgba(57, 135, 229, 0.28)');
    };

    // Dropped where it started: nothing happens.
    await drag(weeks[1]);
    await day(weeks[3], 'Sun').hover();
    await page.mouse.up();
    await expect(popup).toBeHidden();
    await expect(day(weeks[1], 'Sun')).toHaveText('');

    // Up, to the later weeks.
    await drag(weeks[1]);
    await page.mouse.up();
    await expect(day(weeks[1], 'Sun')).toHaveText('6');
    await expect(day(weeks[2], 'Sun')).toHaveText('6');
    await expect(day(weeks[0], 'Sun')).toHaveText('');
    await expect(popup).toBeHidden();

    await page.reload();
    await expect(day(weeks[1], 'Sun')).toHaveText('6');
});
