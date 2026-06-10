const { defineConfig } = require('@playwright/test');

module.exports = defineConfig({
  testDir: './mobile-e2e',
  timeout: 90000,
  fullyParallel: false,
  workers: 1,
  reporter: [['list']],
  use: {
    channel: 'chrome',          // drive system Chrome — no Chromium download
    headless: true,
    viewport: { width: 390, height: 844 },
    baseURL: 'http://127.0.0.1:4173',
    actionTimeout: 20000,
  },
  webServer: {
    command: 'python3 -m http.server -d .. 4173 --bind 127.0.0.1',
    port: 4173,
    reuseExistingServer: true,
    timeout: 20000,
  },
});
