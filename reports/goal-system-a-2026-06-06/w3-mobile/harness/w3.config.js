// W3 mobile heal verification config — points at the W3 harness only.
const { defineConfig } = require('@playwright/test');
const path = require('path');

module.exports = defineConfig({
  testDir: __dirname,
  testMatch: ['w3-verify.spec.js'],
  workers: 1,
  timeout: 90_000,
  retries: 0,
  reporter: [['list']],
  use: {
    baseURL: 'http://127.0.0.1:8087',
    viewport: { width: 390, height: 844 },
    deviceScaleFactor: 2,
    isMobile: true,
    hasTouch: true,
    headless: true,
    screenshot: 'only-on-failure',
  },
  webServer: {
    command: 'php -S 127.0.0.1:8087 -t mobile/',
    url: 'http://127.0.0.1:8087/index.html',
    reuseExistingServer: true,
    timeout: 30_000,
    cwd: path.resolve(__dirname, '../../../..'),
  },
});
