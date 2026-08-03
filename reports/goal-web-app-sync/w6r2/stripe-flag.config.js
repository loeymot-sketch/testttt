const { defineConfig } = require('@playwright/test');
module.exports = defineConfig({
  testDir: '/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/reports/goal-web-app-sync/w6r2',
  testMatch: ['stripe-flag.spec.js'],
  workers: 1,
  timeout: 120_000,
  retries: 0,
  reporter: [['list']],
  use: { channel: 'chrome', headless: true, viewport: { width: 900, height: 1200 }, screenshot: 'off', trace: 'off', video: 'off' },
});
