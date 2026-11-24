import { devices, expect, test } from '@playwright/test';

const { defaultBrowserType: _unused, ...desktop } = devices['Desktop Chrome'];
test.use(desktop);

let weeks;
let day;

test.beforeEach(async ({ page, context, baseURL }) => {
    await context.route((url) => url.origin !== baseURL, (route) => route.abort());
    await context.addCookies([{ name: 'PHPSESSID', value: process.env.E2E_SESSION_ID, url: baseURL }]);
    day = (id, name) => page.locator(`.ag-center-cols-container .ag-row[row-id="${id}"] [col-id="${name}"]`);

    await page.goto('/strava/plan');
    const rows = page.locator('.ag-center-cols-container .ag-row');
    await expect(rows.first()).toBeVisible();
    weeks = await rows.evaluateAll((elements) => elements
        .map((element) => element.getAttribute('row-id'))
        .toSorted()
        .toReversed()
        .slice(4)); // plan.spec.js uses the latest weeks.
});

const plan = async (page, cell, miles) => {
    await cell.click();
    await page.getByLabel('Run').fill(miles);
    await page.getByRole('button', { name: 'Done' }).click();
    await expect(cell).toHaveText(miles);
};

test('drags the corner of a day to copy it', async ({ page }) => {
    const popup = page.getByLabel('Run');

    await plan(page, day(weeks[3], 'Sun'), '6');

    const saves = [];
    const recordSave = (request) => request.method() === 'PUT' && saves.push(request.url());
    page.on('request', recordSave);

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

    // Cancelled with Escape.
    await drag(weeks[1]);
    await page.keyboard.press('Escape');
    await expect(day(weeks[1], 'Sun')).not.toHaveCSS('background-color', 'rgba(57, 135, 229, 0.28)');
    await page.mouse.up();

    // Up, to the later weeks. The first save since: the drags above saved nothing.
    expect(saves).toEqual([]);
    await drag(weeks[1]);
    await page.mouse.up();
    await expect(page.getByText(/^Copied Sun/)).toBeVisible();
    await expect(day(weeks[1], 'Sun')).toHaveText('6');
    await expect(day(weeks[2], 'Sun')).toHaveText('6');
    await expect(day(weeks[0], 'Sun')).toHaveText('');
    await expect(popup).toBeHidden();

    await page.reload();
    await expect(day(weeks[1], 'Sun')).toHaveText('6');
});

test('copies a day into the days selected with the keyboard held', async ({ page }) => {
    await plan(page, day(weeks[3], 'Mon'), '7');

    await day(weeks[3], 'Mon').click({ modifiers: ['ControlOrMeta'] });
    await page.keyboard.press('ControlOrMeta+c');
    await expect(page.getByText(/^Copied Mon/)).toBeVisible();

    // Shift+click: the range from the copied day.
    await day(weeks[1], 'Mon').click({ modifiers: ['Shift'] });
    await expect(day(weeks[2], 'Mon')).toHaveCSS('background-color', 'rgba(57, 135, 229, 0.28)');
    await page.keyboard.press('ControlOrMeta+v');
    await expect(day(weeks[1], 'Mon')).toHaveText('7');
    await expect(day(weeks[2], 'Mon')).toHaveText('7');
    expect(await page.evaluate(() => window.getSelection().toString())).toBe('');

    // Ctrl/⌘+click: single days.
    await page.keyboard.press('Escape');
    await expect(day(weeks[2], 'Mon')).not.toHaveCSS('background-color', 'rgba(57, 135, 229, 0.28)');
    await day(weeks[3], 'Mon').click({ modifiers: ['ControlOrMeta'] });
    await page.keyboard.press('ControlOrMeta+c');
    await day(weeks[0], 'Wed').click({ modifiers: ['ControlOrMeta'] });
    await day(weeks[0], 'Fri').click({ modifiers: ['ControlOrMeta'] });
    const saved = page.waitForResponse((response) => response.request().method() === 'PUT'
        && response.url().endsWith(weeks[0]));
    await page.keyboard.press('ControlOrMeta+v');
    await saved;
    await expect(page.getByLabel('Run')).toBeHidden();

    await page.reload();
    await expect(day(weeks[0], 'Wed')).toHaveText('7');
    await expect(day(weeks[0], 'Fri')).toHaveText('7');
    await expect(day(weeks[0], 'Thu')).toHaveText('');
    await expect(day(weeks[1], 'Mon')).toHaveText('7');
});
