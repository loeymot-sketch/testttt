const { test, expect } = require('@playwright/test');
const path = require('path');
const { loginAsChefOperator } = require('./helpers/login');
test('KDS V2 board mounts the new-order chime <audio> element (was 0 before hoist)', async ({ page }) => {
  await loginAsChefOperator(page);
  await page.goto('/kds', { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(2500);
  const audio = page.locator('audio[src="/sounds/kds-new-order.mp3"]');
  const count = await audio.count();
  console.log(`[KDS-CHIME] audio elements in V2 DOM = ${count}`);
  expect(count, 'new-order chime <audio> must mount under the V2 board').toBe(1);
  await page.screenshot({ path: path.resolve('tests/e2e/__screenshots__/uiux-kds-2026-06-08/kds-board-after-fix.png'), fullPage: true });
});
