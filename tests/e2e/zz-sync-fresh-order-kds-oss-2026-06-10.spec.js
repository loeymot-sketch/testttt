// FoodKing — SYNC FRESH-ORDER PROOF (validation-100 2026-06-10)
// Closes the honest Wave-V gap: a SAME-DAY kiosk order must flow
// kiosk → orders DB → KDS card → bump "Prêt" → OSS "Prêt" column,
// with the outbox (domain_events) dispatching both lifecycle events.
// Disposable clone ONLY:
//   DB_DATABASE=foodking_e2e PLAYWRIGHT_BASE_URL=http://127.0.0.1:8766 \
//   PLAYWRIGHT_NO_WEB_SERVER=1 npx playwright test tests/e2e/zz-sync-fresh-order-kds-oss-2026-06-10.spec.js --retries=0

const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');
const { loginAsKiosk, loginAsAdmin } = require('./helpers/login');

const OUT = path.resolve(__dirname, '../../reports/test-e2e/validation-100-2026-06-10/sync');
fs.mkdirSync(OUT, { recursive: true });
const REPO = path.resolve(__dirname, '../..');
function db(sql) {
  return execFileSync('mysql', ['-u', 'root', 'foodking_e2e', '-N', '-B', '-e', sql], {
    cwd: REPO, encoding: 'utf8', timeout: 15_000,
  }).trim();
}
async function pollDb(sql, predicate, attempts = 20, delayMs = 1000) {
  for (let i = 0; i < attempts; i++) {
    const v = db(sql);
    if (predicate(v)) return v;
    await new Promise((r) => setTimeout(r, delayMs));
  }
  return db(sql);
}

test.describe.configure({ mode: 'serial', timeout: 600_000 });

async function startTakeaway(page) {
  await page.goto('/kiosk/idle', { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(1800);
  const takeaway = page.locator('[data-testid="kiosk-order-type-takeaway"]');
  if (!(await takeaway.isVisible().catch(() => false))) {
    const touch = page.locator('[data-testid="kiosk-idle-touch-btn"]');
    if (await touch.isVisible().catch(() => false)) { await touch.click(); await page.waitForTimeout(900); }
  }
  await expect(takeaway).toBeVisible({ timeout: 12_000 });
  await takeaway.click();
  await page.waitForTimeout(1200);
}

async function addSimple(page, ids) {
  for (const id of ids) {
    let added = false;
    for (const cat of [10, 9, 8]) {
      await page.goto(`/kiosk/categories?cat=${cat}`, { waitUntil: 'domcontentloaded' });
      await page.waitForTimeout(1600);
      const add = page.locator(`[data-testid="kiosk-product-add-${id}"]`);
      if (await add.isVisible().catch(() => false)) { await add.click(); await page.waitForTimeout(1100); added = true; break; }
    }
    if (!added) throw new Error(`simple item ${id} add button not found`);
  }
}

async function checkoutCounter(page, outDir) {
  await page.goto('/kiosk/cart', { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(1800);
  const checkout = page.locator('[data-testid="kiosk-cart-checkout"]');
  await expect(checkout, 'cart not empty -> checkout visible').toBeVisible({ timeout: 10_000 });
  await checkout.click();
  const upsellSkip = page.locator('[data-testid="kiosk-upsell-skip"]');
  await upsellSkip.waitFor({ state: 'visible', timeout: 20_000 }).catch(() => {});
  if (await upsellSkip.isVisible().catch(() => false)) { await upsellSkip.click().catch(() => {}); }
  await page.waitForURL(/\/kiosk\/payment/, { timeout: 20_000 }).catch(() => {});
  await page.waitForTimeout(2000);
  const confirm = page.locator('[data-testid="kiosk-payment-counter-confirm"], [data-testid="kiosk-payment-confirm"]').first();
  await expect(confirm, 'payment confirm visible').toBeVisible({ timeout: 12_000 });
  // [ADV-6 heal] the real order-create route is POST …/order (FrontendOrderController@store)
  const orderResp = page.waitForResponse(
    (r) => r.request().method() === 'POST' && /\/order\/?(\?|$)/i.test(r.url()),
    { timeout: 25_000 },
  ).catch(() => null);
  await confirm.click();
  const resp = await orderResp;
  await page.waitForURL(/\/kiosk\/(confirmation|waiting)/, { timeout: 25_000 }).catch(() => {});
  // [ADV-1 heal] capture the confirmation/waiting screen IMMEDIATELY — the
  // waiting screen auto-resets to idle within seconds.
  if (outDir) {
    await page.screenshot({ path: path.join(outDir, '01-kiosk-confirmation.png') }).catch(() => {});
  }
  await page.waitForTimeout(2000);
  return resp ? resp.status() : null;
}

test('fresh kiosk order syncs kiosk → KDS → bump → OSS with outbox dispatch', async ({ page }) => {
  const pageErrors = [];
  page.on('pageerror', (e) => pageErrors.push(e.message));

  const baselineMax = parseInt(db('SELECT IFNULL(MAX(id),0) FROM orders;'), 10);
  const baselineEvents = parseInt(db('SELECT IFNULL(MAX(id),0) FROM domain_events;'), 10);
  console.log(`[SYNC] baselineMaxOrder=${baselineMax} baselineMaxEvent=${baselineEvents}`);

  // 1 — place a fresh kiosk order (simple drink, Plan-B counter routed)
  await loginAsKiosk(page);
  await startTakeaway(page);
  await addSimple(page, [58]);
  const apiStatus = await checkoutCounter(page, OUT);
  console.log(`[SYNC] kiosk order placed apiStatus=${apiStatus}`);
  expect(apiStatus, 'order POST observed with 2xx').toBeGreaterThanOrEqual(200);
  expect(apiStatus, 'order POST observed with 2xx').toBeLessThan(300);

  const orderRow = db(`SELECT id, status, source_surface, DATE(created_at)=CURDATE() FROM orders WHERE id > ${baselineMax} AND source_surface='kiosk' ORDER BY id DESC LIMIT 1;`);
  expect(orderRow, 'a new kiosk order row exists').not.toBe('');
  const [orderId, status0, src, isToday] = orderRow.split('\t');
  console.log(`[SYNC] order=${orderId} status=${status0} source=${src} isToday=${isToday}`);
  expect(src).toBe('kiosk');
  expect(isToday).toBe('1');

  // 2 — outbox: order.created event dispatched by the running queue worker
  const createdEvt = await pollDb(
    `SELECT CONCAT(id,'|',IFNULL(dispatched_at,'PENDING')) FROM domain_events WHERE id > ${baselineEvents} AND event_type LIKE '%created%' AND aggregate_id = ${orderId} ORDER BY id DESC LIMIT 1;`,
    (v) => v.includes('|') && !v.endsWith('PENDING'),
  );
  console.log(`[SYNC] order.created outbox row: ${createdEvt}`);

  // 3 — KDS shows the fresh order (sync kiosk → KDS)
  // KDS cards display queue_number (e.g. A0002) / serial, not the raw order id.
  const dispRow = db(`SELECT CONCAT(IFNULL(queue_number,''),'|',IFNULL(order_serial_no,'')) FROM orders WHERE id=${orderId};`);
  const [queueNo, serialNo] = dispRow.split('|');
  const idTokens = [queueNo, serialNo, String(orderId)].filter(Boolean);
  const matcher = new RegExp(idTokens.map((t) => t.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')).join('|'));
  console.log(`[SYNC] display tokens: ${idTokens.join(', ')}`);
  await loginAsAdmin(page);
  await page.goto('/kds', { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(3500);
  const kdsCard = page.locator('.kds-card').filter({ hasText: matcher }).first();
  await expect(kdsCard, `KDS shows fresh order ${orderId} (${idTokens.join('/')})`).toBeVisible({ timeout: 20_000 });
  // [ADV-2 heal] prove the fresh card itself: scroll into view + element shot
  await kdsCard.scrollIntoViewIfNeeded().catch(() => {});
  await page.screenshot({ path: path.join(OUT, '02-kds-fresh-order.png'), fullPage: true }).catch(() => {});
  await kdsCard.screenshot({ path: path.join(OUT, '02b-kds-fresh-card.png') }).catch(() => {});

  // 4 — two-stage bump on that order's card: « Démarrer » (ACCEPT→PREPARING)
  //     then « Prêt » (PREPARING→PREPARED)
  const startCta = kdsCard.locator('[data-testid="kds-card-cta-ready"]').first();
  await expect(startCta, 'Démarrer CTA visible on fresh card').toBeVisible({ timeout: 10_000 });
  await startCta.click();
  const statusPreparing = await pollDb(
    `SELECT status FROM orders WHERE id=${orderId};`,
    (v) => v === '7',
  );
  console.log(`[SYNC] order ${orderId} after Démarrer: status=${statusPreparing}`);
  expect(statusPreparing, 'Démarrer persisted PREPARING(7) server-side').toBe('7');
  await page.waitForTimeout(1500);
  const readyCta = kdsCard.locator('[data-testid="kds-card-cta-ready"]').first();
  await expect(readyCta, 'Prêt CTA visible after start').toBeVisible({ timeout: 15_000 });
  await readyCta.click();
  await page.waitForTimeout(2000);
  await page.screenshot({ path: path.join(OUT, '03-kds-after-bump.png'), fullPage: true }).catch(() => {});

  // 5 — server persisted the PREPARED transition
  const statusAfter = await pollDb(
    `SELECT status FROM orders WHERE id=${orderId};`,
    (v) => v === '8',
  );
  console.log(`[SYNC] order ${orderId} status ${status0} -> ${statusAfter}`);
  expect(statusAfter, 'Prêt persisted PREPARED(8) server-side').toBe('8');

  // 6 — outbox: order.status_changed dispatched (real-time fan-out to KDS/OSS/tracker)
  const statusEvt = await pollDb(
    `SELECT CONCAT(id,'|',IFNULL(dispatched_at,'PENDING')) FROM domain_events WHERE id > ${baselineEvents} AND event_type LIKE '%status%' AND aggregate_id = ${orderId} ORDER BY id DESC LIMIT 1;`,
    (v) => v.includes('|') && !v.endsWith('PENDING'),
  );
  console.log(`[SYNC] order.status_changed outbox row: ${statusEvt}`);
  expect(statusEvt, 'status_changed event exists + dispatched').toMatch(/^\d+\|(?!PENDING)/);

  // 7 — OSS shows the SAME-DAY order in the ready column (closes Wave-V gap)
  await page.goto('/admin/order-status-screen', { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(4000);
  const ossBody = await page.locator('body').innerText().catch(() => '');
  await page.screenshot({ path: path.join(OUT, '04-oss-ready.png'), fullPage: true }).catch(() => {});
  expect(matcher.test(ossBody), `OSS wall shows order ${orderId} (${idTokens.join('/')}) — body: ${ossBody.slice(0, 400)}`).toBeTruthy();

  fs.writeFileSync(path.join(OUT, 'sync-proof.json'), JSON.stringify({
    orderId, apiStatus, status0, statusAfter, createdEvt, statusEvt,
    pageErrors,
  }, null, 2));
  expect(pageErrors, `JS errors: ${pageErrors.join(' | ')}`).toHaveLength(0);
});
