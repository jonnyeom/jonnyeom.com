import { defineConfig, devices } from '@playwright/test';

// End-to-end tests of the real app (production build, PHP and Postgres), on a phone screen by default.
// Needs `pnpm build` and DATABASE_URL set to a throwaway database: the tests migrate and write to it.
// Required explicitly, so they never run against the database in .env.local.
if (!process.env.DATABASE_URL) {
    throw new Error('Set DATABASE_URL to a throwaway Postgres database to run the end-to-end tests.');
}

// Picked up by the server below, tests/E2E/setup.php and the tests.
Object.assign(process.env, {
    APP_ENV: 'prod',
    APP_DEBUG: '0',
    APP_SECRET: 'e2e',
    STRAVA_ATHLETE_ID: '12345',
    E2E_SESSION_ID: 'e2esession',
});

const url = 'http://127.0.0.1:8765';

export default defineConfig({
    testDir: './e2e',
    globalSetup: './e2e/setup.js',
    forbidOnly: !!process.env.CI,
    // The specs share the database, and saving replaces a whole week: one at a time.
    workers: 1,
    use: { ...devices['iPhone 13'], browserName: 'chromium', baseURL: url },
    webServer: {
        // variables_order: without E, PHP's server ignores DATABASE_URL and uses the one in the .env files.
        command: 'php bin/console cache:clear && php bin/console doctrine:migrations:migrate --no-interaction && php -d variables_order=EGPCS -S 127.0.0.1:8765 -t public',
        url: `${url}/strava/plan`,
        timeout: 120_000,
    },
});
