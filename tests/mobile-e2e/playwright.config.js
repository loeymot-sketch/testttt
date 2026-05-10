// tests/mobile-e2e/playwright.config.js
//
// Separate Playwright config for the V0 mobile prototype. The root config
// (./playwright.config.js) defaults to baseURL=localhost:8000 and boots Laravel
// via globalSetup — that's wrong for the standalone mobile app which runs at
// 127.0.0.1:8081 (php -S serving mobile/).
//
// Run :
//   npx playwright test --config=tests/mobile-e2e/playwright.config.js
//
// Reference : Agent-6 §1.1 in reports/review/mobile-loyalty-audit-2026-05-10/06_tester.md
const { defineConfig } = require('@playwright/test');

module.exports = defineConfig({
  testDir: __dirname,
  testMatch: '*.spec.js',
  workers: 1,                     // localStorage shared state per worker
  timeout: 90_000,
  retries: 0,                     // V0 should be deterministic; retries hide flakiness
  reporter: [
    ['list'],
    ['json', { outputFile: '../../reports/antigravity/mobile-loyalty-latest.json' }],
  ],
  use: {
    baseURL: 'http://127.0.0.1:8081',
    viewport: { width: 390, height: 844 },   // iPhone 13
    deviceScaleFactor: 2,
    isMobile: true,
    hasTouch: true,
    headless: true,
    screenshot: 'only-on-failure',
    trace: 'retain-on-failure',
  },
  webServer: {
    command: 'php -S 127.0.0.1:8081 -t mobile/',
    url: 'http://127.0.0.1:8081/index.html',
    reuseExistingServer: true,
    timeout: 30_000,
    cwd: require('path').resolve(__dirname, '../..'),
  },
});
