const { defineConfig } = require('@playwright/test');
module.exports = defineConfig({
  testDir: '/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/reports/goal-web-app-sync/w6r1',
  testMatch: ['verif-web2.spec.js'],
  workers: 1, timeout: 150000, retries: 0, reporter: [['list']],
  use: { baseURL: 'http://127.0.0.1:8096', channel: 'chrome', headless: true, viewport: { width: 1360, height: 900 } },
});
