// tests/mobile-e2e/playwright.config.js
//
// Separate Playwright config for the Le Cayenne mobile app. The root config
// defaults to Laravel; this project serves the standalone mobile app from
// mobile/. MOBILE_URL may override the host for CI or a manually started server.
//
// Run :
//   npx playwright test --config=tests/mobile-e2e/playwright.config.js
//
// Reference : Agent-6 §1.1 in reports/review/mobile-loyalty-audit-2026-05-10/06_tester.md
const { defineConfig } = require('@playwright/test');
const path = require('path');
const mobileUrl = process.env.MOBILE_URL || 'http://127.0.0.1:8087';

module.exports = defineConfig({
  testDir: path.resolve(__dirname, '../..'),
  // [GOAL-SYNC 2026-07-08] testDir = racine repo ⇒ la découverte descendait dans
  // .claude/worktrees/* (clones avec leur PROPRE node_modules → « Requiring
  // @playwright/test second time »). Clones + deps explicitement ignorés.
  testIgnore: ['**/.claude/**', '**/node_modules/**', '**/vendor/**'],
  testMatch: [
    'tests/mobile-e2e/*.spec.js',
    'tests/e2e/test-e2e-mobile-design-full-wave-A.spec.js',
    'tests/e2e/test-e2e-mobile-design-full-wave-B.spec.js',
    'tests/e2e/test-e2e-mobile-design-full-wave-C.spec.js',
    'tests/e2e/test-e2e-mobile-design-full-wave-D.spec.js',
    'tests/e2e/test-e2e-mobile-design-perfect-wave-fluidity.spec.js',
    'tests/e2e/test-e2e-mobile-design-perfect-wave-surfaces.spec.js',
  ],
  workers: 1,                     // localStorage shared state per worker
  timeout: 90_000,
  retries: 0,                     // V0 should be deterministic; retries hide flakiness
  reporter: [
    ['list'],
    ['json', { outputFile: '../../reports/antigravity/mobile-loyalty-latest.json' }],
    [path.resolve(__dirname, 'reporters/antigravity-markdown.js')],
  ],
  use: {
    baseURL: mobileUrl,
    viewport: { width: 390, height: 844 },   // iPhone 13
    deviceScaleFactor: 2,
    isMobile: true,
    hasTouch: true,
    headless: true,
    screenshot: 'only-on-failure',
    trace: 'retain-on-failure',
  },
  // With MOBILE_URL set, an external server is expected and no second local
  // server is started implicitly.
  ...(process.env.MOBILE_URL ? {} : {
    webServer: {
      command: 'php -S 127.0.0.1:8087 -t mobile/',
      url: 'http://127.0.0.1:8087/index.html',
      reuseExistingServer: true,
      timeout: 30_000,
      cwd: require('path').resolve(__dirname, '../..'),
    },
  }),
});
