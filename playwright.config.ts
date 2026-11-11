import { defineConfig, devices } from '@playwright/test';
import { resolve } from 'node:path';

const port = 8010;

// One shared database, reset before every test, so tests run one at a time.
export default defineConfig({
  testDir: './tests/e2e',
  fullyParallel: false,
  workers: 1,
  retries: 0,
  timeout: 60000,
  reporter: [['list'], ['html', { open: 'never' }]],
  use: {
    baseURL: `http://127.0.0.1:${port}`,
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
  },
  projects: [{ name: 'chromium', use: { ...devices['Desktop Chrome'] } }],
  webServer: {
    command: 'node tests/e2e/server.mjs',
    url: `http://127.0.0.1:${port}/masuk`,
    reuseExistingServer: false,
    timeout: 120000,
    env: {
      APP_ENV: 'local',
      APP_URL: `http://127.0.0.1:${port}`,
      DB_CONNECTION: 'sqlite',
      DB_DATABASE: resolve('database/e2e.sqlite'),
      E2E_PORT: String(port),
      CACHE_STORE: 'array',
      BCRYPT_ROUNDS: '4', // fast sign-in for seeded test accounts
      HYTRACK_DEMO_CONTROLS: 'true',
    },
  },
});
