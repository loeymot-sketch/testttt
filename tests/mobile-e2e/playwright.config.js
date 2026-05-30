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
const path = require('path');

module.exports = defineConfig({
  // [audit mobile-design-full-2026-05-11 round-1] Broaden discovery so the
  // `tests/e2e/test-e2e-mobile-design-full-wave-*.spec.js` family — which lives
  // outside this dir for capture-dir parity with the rest of the test-e2e skill
  // — is reachable via this config (still :8081, still no globalSetup).
  testDir: path.resolve(__dirname, '../..'),
  testMatch: [
    'tests/mobile-e2e/*.spec.js',
    'tests/e2e/test-e2e-mobile-design-full-wave-*.spec.js',
    'tests/e2e/test-e2e-mobile-design-perfect-wave-*.spec.js',
    'tests/e2e/test-e2e-mobile-realignment-*.spec.js', // [MOBILE-REALIGNMENT 2026-05-16] cycle realignment Bols composer + data parity
    'tests/e2e/test-real-e2e-pagebypage-*.spec.js', // [REAL-E2E 2026-05-18] page-by-page visual capture
    'tests/e2e/test-real-e2e-round2-*.spec.js', // [REAL-E2E R2 2026-05-18] extended coverage
  ],
  workers: 1,                     // localStorage shared state per worker
  timeout: 90_000,
  retries: 0,                     // V0 should be deterministic; retries hide flakiness
  reporter: [
    ['list'],
    ['json', { outputFile: '../../reports/antigravity/mobile-loyalty-latest.json' }],
  ],
  use: {
    // [2026-05-30] moved 8081 -> 8087: port 8081 was hijacked by another project's
    // `serve dist -l 8081` (pregnancy-app "Mama & Bébé"), and reuseExistingServer:true
    // would silently reuse the WRONG app. 8087 is Cayenne-mobile-dedicated.
    baseURL: 'http://127.0.0.1:8087',
    viewport: { width: 390, height: 844 },   // iPhone 13
    deviceScaleFactor: 2,
    isMobile: true,
    hasTouch: true,
    headless: true,
    screenshot: 'only-on-failure',
    trace: 'retain-on-failure',
  },
  webServer: {
    command: 'php -S 127.0.0.1:8087 -t mobile/',
    url: 'http://127.0.0.1:8087/index.html',
    reuseExistingServer: true,
    timeout: 30_000,
    cwd: require('path').resolve(__dirname, '../..'),
  },
});
