// RED-Visual A — POS visual gate
// Captures the POS surfaces affected by Impl A's heal (commits 606b7aaa7 + 5e52b3125)
import { test, expect } from '@playwright/test';

const BASE = 'http://127.0.0.1:8000';
const SHOT_DIR = '/tmp/foodking-goal-round3/red-a-pos';

test.use({ viewport: { width: 1440, height: 900 } });

test('R3-RED-A POS visual capture suite', async ({ page }) => {
  const consoleErrors = [];
  page.on('console', (msg) => {
    if (msg.type() === 'error') consoleErrors.push(msg.text());
  });

  // 1) Login surface (raw-label sanity)
  await page.goto(`${BASE}/login`, { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(800);
  await page.screenshot({ path: `${SHOT_DIR}/01-login.png`, fullPage: true });

  // Auth as admin
  await page.fill('input[name="email"]', 'admin@lecayenne.fr');
  await page.fill('input[name="password"]', '123456');
  await Promise.all([
    page.waitForURL(/dashboard|admin/, { timeout: 15000 }).catch(() => null),
    page.click('button[type="submit"]'),
  ]);
  await page.waitForTimeout(1500);
  await page.screenshot({ path: `${SHOT_DIR}/02-post-login.png`, fullPage: true });

  // 2) POS main shell (V4 wizard host)
  await page.goto(`${BASE}/admin/pos`, { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(3000);
  await page.screenshot({ path: `${SHOT_DIR}/03-pos-main.png`, fullPage: true });

  // 3) try opening a product (best effort)
  const firstProduct = page.locator('[data-product-id], .pos-product, .product-card').first();
  if (await firstProduct.count()) {
    await firstProduct.click().catch(() => null);
    await page.waitForTimeout(2000);
    await page.screenshot({ path: `${SHOT_DIR}/04-pos-wizard-popup.png`, fullPage: true });
  }

  // Persist console errors
  const fs = require('fs');
  fs.writeFileSync(`${SHOT_DIR}/console-errors.txt`, consoleErrors.join('\n') || 'NONE');
});
