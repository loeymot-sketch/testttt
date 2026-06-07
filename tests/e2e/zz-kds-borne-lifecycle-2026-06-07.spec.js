// FoodKing — Borne order KDS lifecycle (headless): ACCEPT -> PREPARING -> PREPARED
// 2026-06-07. Disposable clone only:
//   DB_DATABASE=foodking_e2e PLAYWRIGHT_BASE_URL=http://127.0.0.1:8766 \
//   PLAYWRIGHT_NO_WEB_SERVER=1 npx playwright test tests/e2e/zz-kds-borne-lifecycle-2026-06-07.spec.js
//
// Drives the KDS bump CTA for a specific encashed borne order (queue A0043 / id 76),
// advancing it two steps to PRÊT. DB transitions asserted by the Bash harness.

const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const { loginAsAdmin } = require('./helpers/login');

const QUEUE = process.env.KDS_QUEUE || 'A0043';
const OUT = path.resolve(__dirname, '__screenshots__', 'kds-borne-lifecycle-2026-06-07');
fs.mkdirSync(OUT, { recursive: true });

test.describe.configure({ mode: 'serial', timeout: 120_000 });

async function tapCardCta(page, queue, stepLabel) {
  // Re-locate the card each call (it moves columns between taps / re-renders).
  const card = page.locator('.kds-card', { hasText: queue }).first();
  await expect(card, `card ${queue} present for ${stepLabel}`).toBeVisible({ timeout: 20_000 });
  const cta = card.getByTestId('kds-card-cta-ready');
  await expect(cta, `cta on ${queue} for ${stepLabel}`).toBeVisible({ timeout: 10_000 });
  const label = (await cta.innerText().catch(() => '')).trim();
  await cta.click();
  console.log(`[KDS] ${stepLabel}: tapped "${label}" on ${queue}`);
  await page.waitForTimeout(2000); // let the PATCH + re-render settle
}

test(`borne order ${QUEUE} ACCEPT->PREPARING->PREPARED via KDS`, async ({ page }) => {
  const pageErrors = [];
  page.on('pageerror', (e) => pageErrors.push(e.message));

  await loginAsAdmin(page);
  await page.goto('/admin/kitchen-display-system', { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(3500);
  await page.screenshot({ path: path.join(OUT, '1-kds-before.png'), fullPage: true });

  await tapCardCta(page, QUEUE, 'step1 ACCEPT->PREPARING');
  await page.screenshot({ path: path.join(OUT, '2-after-start.png'), fullPage: true });

  await tapCardCta(page, QUEUE, 'step2 PREPARING->PREPARED');
  await page.screenshot({ path: path.join(OUT, '3-after-ready.png'), fullPage: true });

  expect(pageErrors, `JS errors: ${pageErrors.join(' | ')}`).toHaveLength(0);
});
