// tests/web-e2e/playwright.config.js
// [GOAL LONG-TERM 2026-05-16] Le Cayenne website Playwright config.
//
// Website lives at /Users/1millnonstop/Downloads/web/ (separate from app mobile).
// Standalone SPA React+Babel-standalone, no build. Multi-viewport responsive testing.
//
// Boot manually before running tests :
//   python3 -m http.server 8082 --directory /Users/1millnonstop/Downloads/web
//
// Run :
//   npx playwright test --config=tests/web-e2e/playwright.config.js \
//     tests/e2e/test-e2e-website-realignment-2026-05-16.spec.js \
//     --reporter=list --workers=1 --timeout=120000

const { defineConfig, devices } = require('@playwright/test');
const path = require('path');

module.exports = defineConfig({
  testDir: path.resolve(__dirname, '../..'),
  testMatch: [
    'tests/web-e2e/*.spec.js',
    'tests/e2e/test-e2e-website-*.spec.js',
    'tests/e2e/test-e2e-fullflow-*.spec.js', // [FULL-FLOW 2026-05-18] new spec covering end-to-end
    'tests/e2e/test-real-e2e-pagebypage-*.spec.js', // [REAL-E2E 2026-05-18] page-by-page visual capture
    'tests/e2e/test-real-e2e-round2-*.spec.js', // [REAL-E2E R2 2026-05-18] extended coverage + 4 viewports
    'tests/e2e/test-e2e-web-z7-*.spec.js', // [Z-7-WEB COMPLEMENT 2026-05-18] coverage gaps for account/loyalty/orders/funnel/axe
  ],
  workers: 1,
  timeout: 120_000,
  retries: 0,
  reporter: [['list']],
  use: {
    baseURL: 'http://127.0.0.1:8082',
    headless: true,
    screenshot: 'only-on-failure',
    trace: 'retain-on-failure',
  },
  projects: [
    // mobile = Chromium with iPhone 13 viewport (WebKit not installed locally — Chromium suffices for visual + data parity)
    { name: 'mobile',  use: { browserName: 'chromium', viewport: { width: 390, height: 844 }, isMobile: false, hasTouch: true, deviceScaleFactor: 2 } },
    { name: 'tablet',  use: { browserName: 'chromium', viewport: { width: 768, height: 1024 } } },
    { name: 'desktop', use: { browserName: 'chromium', viewport: { width: 1280, height: 800 } } },
    { name: 'wide',    use: { browserName: 'chromium', viewport: { width: 1920, height: 1080 } } },
  ],
});
