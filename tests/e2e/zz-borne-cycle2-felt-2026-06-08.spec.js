// FoodKing — BORNE cycle-2 (felt-product) targeted verification of the kiosk
// fixes the capture:BORNE army agent died before driving. Disposable clone:
//   DB_DATABASE=foodking_e2e PLAYWRIGHT_BASE_URL=http://127.0.0.1:8766 \
//   PLAYWRIGHT_NO_WEB_SERVER=1 npx playwright test tests/e2e/zz-borne-cycle2-felt-2026-06-08.spec.js --retries=0
//
// Covers (advisor-scoped, BORNE only):
//   FP-01  dead error buttons  -> /kiosk/error/network : retry reloads, callStaff shows ack
//   FP-28  offline auto-return -> /kiosk/waiting/offline_NNN : hint + real auto-return to idle
//   FP-26  cart name 2-line clamp (computed -webkit-line-clamp:2 on the recap)
//   FP-29/30 raw-key scan on the WIZARD cart-recap specifically
//   FP-02  felt number on confirmation/waiting landing (no raw key, total/number render)
//
// Stopping rule (advisor): 0 new P0/P1 -> convergence met. P2/P3 -> document/defer.

const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const { loginAsKiosk } = require('./helpers/login');

const OUT = path.resolve(__dirname, '__screenshots__', 'borne-cycle2-2026-06-08');
fs.mkdirSync(OUT, { recursive: true });

// Raw-key leak detector (same family used by the composer spec) + value glitches.
const RAW_LABEL_RE = /\b(kiosk|pos|kds|common|label|messages?)\.[a-z_]+\.[a-z_.]+\b/i;
const VALUE_GLITCH_RE = /\b(undefined|NaN|null€|0undefined)\b/;
const CRASH = ['Whoops, something went wrong', 'Server Error', 'SQLSTATE', 'Undefined variable'];

const WIZARD_ITEM = { id: 26, name: 'Tacos', cat: 5 }; // opens the composer wizard (item_category_id 5)

test.describe.configure({ mode: 'serial', timeout: 240_000 });

function scanBody(body) {
  return {
    rawLabel: (body.match(RAW_LABEL_RE) || [null])[0],
    glitch: (body.match(VALUE_GLITCH_RE) || [null])[0],
    crash: CRASH.filter((c) => body.includes(c)),
  };
}

// ───────────────────────────────────────────────────────────── FP-01
test('FP-01 borne network-error screen: callStaff shows ack + retry reloads', async ({ page }) => {
  const pageErrors = [];
  page.on('pageerror', (e) => pageErrors.push(e.message));

  await loginAsKiosk(page);
  await page.goto('/kiosk/error/network', { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(1500);

  const retry = page.locator('[data-testid="kiosk-error-network-cta-retry"]');
  const staff = page.locator('[data-testid="kiosk-error-network-cta-staff"]');
  await expect(retry, 'retry CTA visible').toBeVisible({ timeout: 10_000 });
  await expect(staff, 'call-staff CTA visible').toBeVisible();

  // CTAs must render resolved FR text, not raw i18n keys.
  const retryTxt = (await retry.innerText()).trim();
  const staffTxt = (await staff.innerText()).trim();
  expect(RAW_LABEL_RE.test(retryTxt), `retry label raw: ${retryTxt}`).toBeFalsy();
  expect(RAW_LABEL_RE.test(staffTxt), `staff label raw: ${staffTxt}`).toBeFalsy();
  expect(retryTxt.length, 'retry label non-empty').toBeGreaterThan(0);

  // FP-01: callStaff() must give the customer feedback (ack appears).
  const ack = page.locator('[data-testid="kiosk-error-network-staff-ack"]');
  await expect(ack, 'ack hidden before callStaff').toHaveCount(0);
  await staff.click();
  await expect(ack, 'FP-01: staff ack visible after callStaff').toBeVisible({ timeout: 5_000 });
  const ackTxt = (await ack.innerText()).trim();
  expect(RAW_LABEL_RE.test(ackTxt), `ack label raw: ${ackTxt}`).toBeFalsy();
  expect(ackTxt.length, 'ack non-empty').toBeGreaterThan(0);
  await page.screenshot({ path: path.join(OUT, 'fp01-1-staff-ack.png'), fullPage: true });

  const { rawLabel, glitch, crash } = scanBody(await page.locator('body').innerText());
  expect(rawLabel, `raw label leaked: ${rawLabel}`).toBeFalsy();
  expect(glitch, `value glitch: ${glitch}`).toBeFalsy();
  expect(crash.length, `crash markers: ${crash.join(',')}`).toBe(0);

  // FP-01: retry() must trigger a real page reload (self-contained reconnect).
  const reloaded = page.waitForEvent('load', { timeout: 8_000 }).then(() => true).catch(() => false);
  await retry.click();
  expect(await reloaded, 'FP-01: retry must reload the SPA').toBeTruthy();
  await page.waitForTimeout(800);
  await page.screenshot({ path: path.join(OUT, 'fp01-2-after-retry-reload.png'), fullPage: true });

  expect(pageErrors, `JS errors: ${pageErrors.join(' | ')}`).toHaveLength(0);
});

// ───────────────────────────────────────────────────────────── FP-28
test('FP-28 borne offline waiting: shows auto-return hint AND actually returns to idle', async ({ page }) => {
  const pageErrors = [];
  page.on('pageerror', (e) => pageErrors.push(e.message));

  await loginAsKiosk(page);
  // Deep-link an offline-queued order id (guard accepts /^(offline_)?\d+$/).
  await page.goto('/kiosk/waiting/offline_999777', { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(1800);

  const hint = page.locator('[data-testid="kiosk-offline-auto-return"]');
  await expect(hint, 'FP-28: offline auto-return hint visible').toBeVisible({ timeout: 10_000 });
  const hintTxt = (await hint.innerText()).trim();
  expect(RAW_LABEL_RE.test(hintTxt), `hint raw: ${hintTxt}`).toBeFalsy();
  expect(hintTxt.length, 'hint non-empty').toBeGreaterThan(0);
  await page.screenshot({ path: path.join(OUT, 'fp28-1-offline-waiting.png'), fullPage: true });

  const { rawLabel, glitch, crash } = scanBody(await page.locator('body').innerText());
  expect(rawLabel, `raw label leaked: ${rawLabel}`).toBeFalsy();
  expect(glitch, `value glitch: ${glitch}`).toBeFalsy();
  expect(crash.length, `crash markers: ${crash.join(',')}`).toBe(0);

  // FP-28: after OFFLINE_AUTO_REDIRECT_SECONDS (20s) the borne returns to idle so it
  // is freed for the next customer instead of stranding on the syncing spinner.
  await page.waitForURL(/\/kiosk\/idle/, { timeout: 30_000 });
  await page.waitForTimeout(800);
  await page.screenshot({ path: path.join(OUT, 'fp28-2-returned-to-idle.png'), fullPage: true });
  expect(page.url(), 'FP-28: auto-returned to /kiosk/idle').toMatch(/\/kiosk\/idle/);

  expect(pageErrors, `JS errors: ${pageErrors.join(' | ')}`).toHaveLength(0);
});

// ───────────────────────── FP-26 + FP-29/30 + FP-02 (full BORNE cycle)
test('BORNE cycle: wizard -> cart (clamp + recap raw-key) -> pay -> landing felt-number', async ({ page }) => {
  const pageErrors = [];
  const consoleErrors = [];
  page.on('pageerror', (e) => pageErrors.push(e.message));
  page.on('console', (m) => { if (m.type() === 'error') consoleErrors.push(m.text()); });

  await loginAsKiosk(page);

  // Idle -> takeaway -> categories.
  await page.goto('/kiosk/idle', { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(2000);
  const takeaway = page.locator('[data-testid="kiosk-order-type-takeaway"]');
  if (!(await takeaway.isVisible().catch(() => false))) {
    const touch = page.locator('[data-testid="kiosk-idle-touch-btn"]');
    if (await touch.isVisible().catch(() => false)) { await touch.click(); await page.waitForTimeout(1000); }
  }
  await expect(takeaway, 'takeaway tile').toBeVisible({ timeout: 12_000 });
  await takeaway.click();
  await page.waitForURL(/\/kiosk\/categories/, { timeout: 20_000 }).catch(() => {});
  await page.waitForTimeout(1500);

  // Select the Tacos category so its product cards render.
  await page.goto(`/kiosk/categories?cat=${WIZARD_ITEM.cat}`, { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(2500);

  // Open the wizard product (has options) and walk the steps.
  const card = page.locator(
    `[data-testid="kiosk-product-card-${WIZARD_ITEM.id}"], [data-testid="kiosk-product-${WIZARD_ITEM.id}"]`,
  ).first();
  await expect(card, `${WIZARD_ITEM.name} card`).toBeVisible({ timeout: 15_000 });
  await card.click();
  await page.waitForTimeout(1800);
  await expect(page.locator('.kiosk-wizard'), 'wizard mounted').toBeVisible({ timeout: 10_000 });

  const CHOICE_SEL = [
    '.kiosk-viande-card', '.kiosk-option-card', '.kiosk-generic-choice',
    '.kiosk-taille-card', '.kiosk-menu-card',
  ].map((s) => `${s}:not(.kiosk-variation--disabled):not(.is-out-of-stock):not(.unavailable):not([disabled])`).join(', ');
  const nextBtn = page.locator('.kiosk-btn-next');
  let guard = 0; let reachedAdd = false;
  while (guard++ < 14) {
    await page.waitForTimeout(800);
    const menuNone = page.locator('.kiosk-menu-card').filter({ hasText: /sans menu/i }).first();
    let pickedMenuNone = false;
    if (await menuNone.isVisible().catch(() => false)) {
      await menuNone.click().catch(() => {}); pickedMenuNone = true; await page.waitForTimeout(400);
    }
    const choices = page.locator(CHOICE_SEL);
    const n = await choices.count().catch(() => 0);
    if (n > 0 && !pickedMenuNone) { await choices.first().click().catch(() => {}); await page.waitForTimeout(500); }
    const isLast = await page.locator('.kiosk-btn-next--cart').isVisible().catch(() => false);
    const enabled = await nextBtn.first().isEnabled().catch(() => false);
    if (isLast && enabled) {
      reachedAdd = true; await nextBtn.first().click().catch(() => {}); await page.waitForTimeout(1500); break;
    }
    if (enabled) { await nextBtn.first().click().catch(() => {}); await page.waitForTimeout(800); }
    else {
      const still = await choices.count().catch(() => 0);
      if (still === 0) break;
      await choices.first().click().catch(() => {}); await page.waitForTimeout(500);
      if (!(await nextBtn.first().isEnabled().catch(() => false))) break;
    }
  }
  expect(reachedAdd, 'wizard reached "Ajouter au panier"').toBeTruthy();

  // Cart: FP-29/30 raw-key scan on the recap + FP-26 clamp.
  await page.goto('/kiosk/cart', { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(2000);
  const recap = page.locator('[data-testid="kiosk-cart-items"]');
  await expect(recap, 'cart recap not empty').toBeVisible({ timeout: 10_000 });
  await page.screenshot({ path: path.join(OUT, 'cart-recap.png'), fullPage: true });

  const recapText = await recap.innerText();
  expect(RAW_LABEL_RE.test(recapText), `FP-29/30: raw key in cart recap: ${(recapText.match(RAW_LABEL_RE) || [])[0]}`).toBeFalsy();
  expect(VALUE_GLITCH_RE.test(recapText), `FP-29/30: value glitch in cart recap: ${(recapText.match(VALUE_GLITCH_RE) || [])[0]}`).toBeFalsy();

  // FP-26: cart item name must clamp long names to 2 lines (not a hard 1-line ellipsis,
  // not unlimited wrap). The h3 is a flex item so getComputedStyle reports display:flow-root
  // even though display:-webkit-box is set — so we measure the ACTUAL clamp behavior by
  // injecting a long name and checking it clips to ~2 line-boxes.
  const nameEl = page.locator('.kiosk-cart-item-name').first();
  await expect(nameEl).toBeVisible();
  const clamp = await nameEl.evaluate((el) => {
    const orig = el.textContent;
    el.textContent = 'Très Très Long Nom De Produit Personnalisé Avec Beaucoup De Mots Pour Forcer Le Débordement Sur Plus De Deux Lignes Absolument Maximum';
    void el.offsetHeight; // force reflow
    const s = getComputedStyle(el);
    const lh = parseFloat(s.lineHeight) || (parseFloat(s.fontSize) * 1.25);
    const res = {
      lineClamp: s.webkitLineClamp || s.getPropertyValue('-webkit-line-clamp'),
      overflow: s.overflow,
      clientHeight: el.clientHeight,
      scrollHeight: el.scrollHeight,
      approxLines: Math.round(el.clientHeight / lh),
    };
    el.textContent = orig;
    return res;
  });
  expect(String(clamp.lineClamp), `FP-26: -webkit-line-clamp expected 2, got ${clamp.lineClamp}`).toBe('2');
  // Long name overflows (clamp active clips the rest) and is held to at most 2 line-boxes.
  expect(clamp.scrollHeight, `FP-26: long name should overflow the clamp box (clip > visible)`).toBeGreaterThan(clamp.clientHeight);
  expect(clamp.approxLines, `FP-26: visible name should be <= 2 lines, got ~${clamp.approxLines}`).toBeLessThanOrEqual(2);

  // Checkout -> upsell -> payment -> confirm.
  const checkout = page.locator('[data-testid="kiosk-cart-checkout"]');
  await expect(checkout, 'checkout button').toBeVisible({ timeout: 10_000 });
  await checkout.click();

  const upsellSkip = page.locator('[data-testid="kiosk-upsell-skip"]');
  await upsellSkip.waitFor({ state: 'visible', timeout: 20_000 }).catch(() => {});
  if (await upsellSkip.isVisible().catch(() => false)) { await upsellSkip.click().catch(() => {}); }

  await page.waitForURL(/\/kiosk\/payment/, { timeout: 20_000 }).catch(() => {});
  await page.waitForTimeout(2000);
  const confirm = page.locator('[data-testid="kiosk-payment-counter-confirm"], [data-testid="kiosk-payment-confirm"]').first();
  await expect(confirm, 'payment confirm').toBeVisible({ timeout: 12_000 });
  await confirm.click();

  // Landing: confirmation, waiting, or (Plan B counter-route) cash-instruction —
  // felt number must render, no raw key.
  await page.waitForURL(/\/kiosk\/(confirmation|waiting|cash-instruction)/, { timeout: 25_000 }).catch(() => {});
  await page.waitForTimeout(2500);
  await page.screenshot({ path: path.join(OUT, 'landing.png'), fullPage: true });
  const landUrl = page.url();
  const landBody = await page.locator('body').innerText();
  const { rawLabel, glitch, crash } = scanBody(landBody);
  expect(rawLabel, `FP-02: raw key on landing: ${rawLabel}`).toBeFalsy();
  expect(glitch, `FP-02: value glitch on landing: ${glitch}`).toBeFalsy();
  expect(crash.length, `landing crash markers: ${crash.join(',')}`).toBe(0);

  // A queue/order number must be shown (the felt number) on whichever screen we land.
  const numberEl = page.locator(
    '[data-testid="kiosk-confirmation-number"], [data-testid="kiosk-cash-order-number"], [data-testid="kiosk-waiting-root"] .kiosk-waiting-number',
  ).first();
  await expect(numberEl, 'felt order/queue number rendered').toBeVisible({ timeout: 8_000 });
  const numTxt = (await numberEl.innerText()).trim();
  expect(numTxt.replace(/[#\s]/g, '').length, `number non-empty (got "${numTxt}")`).toBeGreaterThan(0);

  console.log(`[BORNE-cycle2] landing=${landUrl} number="${numTxt}" consoleErr=${consoleErrors.length} pageErr=${pageErrors.length}`);
  expect(pageErrors, `JS errors: ${pageErrors.join(' | ')}`).toHaveLength(0);
});
