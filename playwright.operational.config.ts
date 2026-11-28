import { defineConfig, devices } from '@playwright/test';

const port = 8020;

/*
 * Operational mode, two browsers: a patient session and a staff session (with a fake camera)
 * against the separate MySQL database hytrack_laravel_test. Demo controls are off.
 *
 *   PW_CHANNEL=msedge npx playwright test -c playwright.operational.config.ts
 */
export default defineConfig({
  testDir: './tests/e2e',
  testMatch: 'operational.spec.ts',
  fullyParallel: false,
  workers: 1,
  retries: 0,
  timeout: 180000,
  reporter: [['list']],
  use: {
    baseURL: `http://127.0.0.1:${port}`,
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
  },
  projects: [
    { name: 'chromium', use: { ...devices['Desktop Chrome'], channel: process.env.PW_CHANNEL } },
  ],
  webServer: {
    command: 'node tests/e2e/operational-server.mjs',
    url: `http://127.0.0.1:${port}/masuk`,
    reuseExistingServer: false,
    timeout: 180000,
    env: {
      APP_ENV: 'local',
      APP_URL: `http://127.0.0.1:${port}`,
      DB_CONNECTION: 'mysql',
      DB_URL: '',
      DB_DATABASE: 'hytrack_laravel_test',
      SESSION_DRIVER: 'database',
      CACHE_STORE: 'array',
      // Pending sign-ups must outlive one request of PHP's built-in server.
      HYTRACK_SIGNUP_STORE: 'database',
      E2E_PORT: String(port),
      HYTRACK_DEMO_CONTROLS: 'false',
      // A short interval keeps the test quick; the rule checked is "within one interval".
      HYTRACK_POLL_SECONDS: '3',
    },
  },
});
