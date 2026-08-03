const { defineConfig } = require('@playwright/test');
module.exports = defineConfig({
  testDir: '/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/reports/goal-web-app-sync/w6r1',
  testMatch: ['verif-forgot.spec.js'],
  workers: 1, timeout: 60000, retries: 0, reporter: [['list']],
  use: { channel: 'chrome', headless: true, viewport: { width: 1360, height: 900 } },
});
