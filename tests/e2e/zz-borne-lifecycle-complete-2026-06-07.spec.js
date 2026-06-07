// FoodKing — COMPLETE borne lifecycle for fresh order #4161 (queue A0002, created today)
// 2026-06-07. Disposable clone only:
//   DB_DATABASE=foodking_e2e PLAYWRIGHT_BASE_URL=http://127.0.0.1:8766 \
//   PLAYWRIGHT_NO_WEB_SERVER=1 npx playwright test tests/e2e/zz-borne-lifecycle-complete-2026-06-07.spec.js
//
// Encash #4161 via the app's own axios (carries auth + CSRF; the FIFO-capped
// encaissement UI list buries this newest order beyond the 200 display cap), then
// prove a FRESH PAID borne order appears on the KDS and advance it to PREPARED.
// DB transitions asserted by the surrounding Bash harness.

const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const { loginAsAdmin } = require('./helpers/login');

const ORDER_ID = Number(process.env.BORNE_ORDER_ID || 4161);
const QUEUE = process.env.BORNE_QUEUE || 'A0002';
const OUT = path.resolve(__dirname, '__screenshots__', 'borne-lifecycle-2026-06-07');
fs.mkdirSync(OUT, { recursive: true });

test.describe.configure({ mode: 'serial', timeout: 150_000 });

test(`encash #${ORDER_ID} via API then advance on KDS`, async ({ page }) => {
  const pageErrors = [];
  page.on('pageerror', (e) => pageErrors.push(e.message));

  await loginAsAdmin(page);
  await page.goto('/admin/encaissement', { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(2000);

  // Encash via the app's axios (CASH=1). Idempotency key per modal convention.
  const result = await page.evaluate(async (id) => {
    try {
      const r = await window.axios.post(`admin/pos/counter-collect/${id}/confirm`,
        { mode: 1, received: 5 },
        { headers: { 'X-Idempotency-Key': `pos-counter-collect-${id}-1-e2e-borne` } });
      return { ok: true, status: r.status, data: r.data };
    } catch (e) {
      return { ok: false, status: e?.response?.status, data: e?.response?.data, msg: e?.message };
    }
  }, ORDER_ID);
  console.log(`[BORNE] encash #${ORDER_ID} -> ${JSON.stringify(result).slice(0, 300)}`);
  expect(result.ok, `encash API ok: ${JSON.stringify(result)}`).toBeTruthy();

  // Now the fresh PAID borne order should be on the KDS (today + visibleStatuses + PAID).
  await page.goto('/admin/kitchen-display-system', { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(4000);
  await page.screenshot({ path: path.join(OUT, '1-kds-has-borne.png'), fullPage: true });

  const card = page.locator('.kds-card', { hasText: QUEUE }).first();
  const onKds = await card.isVisible().catch(() => false);
  console.log(`[BORNE] #${ORDER_ID} (${QUEUE}) on KDS = ${onKds}`);
  expect(onKds, `fresh paid borne order ${QUEUE} visible on KDS`).toBeTruthy();

  // Advance ACCEPT -> PREPARING -> PREPARED (re-locate between taps).
  for (const step of ['start', 'ready']) {
    const c = page.locator('.kds-card', { hasText: QUEUE }).first();
    if (!(await c.isVisible().catch(() => false))) { console.log(`[BORNE] card gone before ${step}`); break; }
    const cta = c.getByTestId('kds-card-cta-ready');
    if (!(await cta.isVisible().catch(() => false))) { console.log(`[BORNE] no cta for ${step}`); break; }
    const label = (await cta.innerText().catch(() => '')).trim();
    await cta.click();
    console.log(`[BORNE] KDS ${step}: tapped "${label}"`);
    await page.waitForTimeout(2500);
  }
  await page.screenshot({ path: path.join(OUT, '2-kds-after.png'), fullPage: true });

  expect(pageErrors, `JS errors: ${pageErrors.join(' | ')}`).toHaveLength(0);
});
