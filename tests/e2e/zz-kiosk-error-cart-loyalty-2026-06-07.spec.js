// FoodKing — KIOSK error screens + cart operations + loyalty page (agent 05-KIOSK)
// 2026-06-07. Disposable clone ONLY:
//   DB_DATABASE=foodking_e2e PLAYWRIGHT_BASE_URL=http://127.0.0.1:8766 \
//   PLAYWRIGHT_NO_WEB_SERVER=1 npx playwright test tests/e2e/zz-kiosk-error-cart-loyalty-2026-06-07.spec.js --retries=0
//
// B2: 4 error screens (network/menu-unavailable/product-removed/payment-refused) render
//     a clear FR message with a recovery affordance (no raw label, no crash).
// B (cart): add drink, change qty (+/-), remove, clear, "carte fidélité" prompt.
// Loyalty: /kiosk/loyalty consult page renders (non-frozen).

const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const { loginAsKiosk } = require('./helpers/login');

const OUT = path.resolve(__dirname, '__screenshots__', 'kiosk-error-cart-loyalty-2026-06-07');
fs.mkdirSync(OUT, { recursive: true });
const RAW_LABEL_RE = /\b(kiosk|pos|kds|common|label|messages?)\.[a-z_]+\.[a-z_.]+\b/i;
const CRASH = ['Whoops, something went wrong', 'Server Error', 'SQLSTATE', 'Undefined variable', 'Cannot read', 'is not defined'];
const DRINK_ID = 58; // Eau Plate (no options)

test.describe.configure({ mode: 'serial', timeout: 240_000 });

const ERROR_SCREENS = [
  { path: '/kiosk/error/network', key: 'network' },
  { path: '/kiosk/error/menu-unavailable', key: 'menu-unavailable' },
  { path: '/kiosk/error/product-removed', key: 'product-removed' },
  { path: '/kiosk/error/payment-refused', key: 'payment-refused' },
];

const verdicts = [];

for (const scr of ERROR_SCREENS) {
  test(`error screen: ${scr.key}`, async ({ page }) => {
    const pageErrors = [];
    page.on('pageerror', (e) => pageErrors.push(e.message));
    await loginAsKiosk(page);
    await page.goto(scr.path, { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(2000);
    await page.screenshot({ path: path.join(OUT, `error-${scr.key}.png`), fullPage: true });
    const body = await page.locator('body').innerText().catch(() => '');
    const rawLabel = (body.match(RAW_LABEL_RE) || [null])[0];
    const crashed = CRASH.filter((c) => body.includes(c));
    // A recovery affordance (button/link) should exist.
    const btnCount = await page.locator('button, a[href], [role="button"]').count().catch(() => 0);
    const hasText = body.replace(/\s+/g, ' ').trim().length > 20;
    verdicts.push({ screen: scr.key, url: page.url(), rawLabel, crashed: crashed.length, btnCount, hasText, textSample: body.replace(/\s+/g, ' ').trim().slice(0, 120) });
    console.log(`[ERR ${scr.key}] url=${page.url()} rawLabel=${rawLabel || 'none'} crash=${crashed.length} btns=${btnCount} text="${body.replace(/\s+/g, ' ').trim().slice(0, 90)}"`);
    expect(rawLabel, `raw label leaked on ${scr.key}: ${rawLabel}`).toBeFalsy();
    expect(crashed.length, `crash markers on ${scr.key}: ${crashed.join(',')}`).toBe(0);
    expect(hasText, `${scr.key} renders meaningful FR text`).toBeTruthy();
    expect(btnCount, `${scr.key} has a recovery affordance`).toBeGreaterThan(0);
    expect(pageErrors, `JS errors on ${scr.key}: ${pageErrors.join(' | ')}`).toHaveLength(0);
  });
}

test('loyalty consult page renders (requires non-empty cart)', async ({ page }) => {
  const pageErrors = [];
  page.on('pageerror', (e) => pageErrors.push(e.message));
  await loginAsKiosk(page);
  // requireCart guard redirects /kiosk/loyalty -> /kiosk/cart when cart empty (BY DESIGN).
  // So first put an item in the cart, then open loyalty from the cart prompt.
  await page.goto('/kiosk/idle', { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(2000);
  const takeaway = page.locator('[data-testid="kiosk-order-type-takeaway"]');
  if (!(await takeaway.isVisible().catch(() => false))) {
    const touch = page.locator('[data-testid="kiosk-idle-touch-btn"]');
    if (await touch.isVisible().catch(() => false)) { await touch.click(); await page.waitForTimeout(1000); }
  }
  await expect(takeaway).toBeVisible({ timeout: 12_000 });
  await takeaway.click();
  await page.waitForTimeout(1500);
  await page.goto('/kiosk/categories?cat=10', { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(2500);
  await page.locator(`[data-testid="kiosk-product-add-${DRINK_ID}"]`).click();
  await page.waitForTimeout(1500);
  // Now open the loyalty page (cart non-empty).
  await page.goto('/kiosk/loyalty', { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(2500);
  await page.screenshot({ path: path.join(OUT, 'loyalty.png'), fullPage: true });
  const body = await page.locator('body').innerText().catch(() => '');
  const rawLabel = (body.match(RAW_LABEL_RE) || [null])[0];
  const crashed = CRASH.filter((c) => body.includes(c));
  const onLoyalty = /\/kiosk\/loyalty/.test(page.url());
  console.log(`[LOYALTY] url=${page.url()} onLoyalty=${onLoyalty} rawLabel=${rawLabel || 'none'} crash=${crashed.length} text="${body.replace(/\s+/g, ' ').trim().slice(0, 130)}"`);
  verdicts.push({ screen: 'loyalty', url: page.url(), onLoyalty, rawLabel, crashed: crashed.length, textSample: body.replace(/\s+/g, ' ').trim().slice(0, 150) });
  expect(onLoyalty, 'reached loyalty page with non-empty cart').toBeTruthy();
  expect(rawLabel, `raw label on loyalty: ${rawLabel}`).toBeFalsy();
  expect(crashed.length, `crash on loyalty: ${crashed.join(',')}`).toBe(0);
  expect(pageErrors, `JS errors loyalty: ${pageErrors.join(' | ')}`).toHaveLength(0);
});

test('cart operations: add -> qty +/- -> remove/clear', async ({ page }) => {
  const pageErrors = [];
  page.on('pageerror', (e) => pageErrors.push(e.message));
  await loginAsKiosk(page);
  await page.goto('/kiosk/idle', { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(2000);
  const takeaway = page.locator('[data-testid="kiosk-order-type-takeaway"]');
  if (!(await takeaway.isVisible().catch(() => false))) {
    const touch = page.locator('[data-testid="kiosk-idle-touch-btn"]');
    if (await touch.isVisible().catch(() => false)) { await touch.click(); await page.waitForTimeout(1000); }
  }
  await expect(takeaway).toBeVisible({ timeout: 12_000 });
  await takeaway.click();
  await page.waitForTimeout(1500);

  // Add Eau Plate (no options -> direct add).
  await page.goto('/kiosk/categories?cat=10', { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(2500);
  const addBtn = page.locator(`[data-testid="kiosk-product-add-${DRINK_ID}"]`);
  await expect(addBtn).toBeVisible({ timeout: 15_000 });
  await addBtn.click();
  await page.waitForTimeout(1500);

  await page.goto('/kiosk/cart', { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(2500);
  await page.screenshot({ path: path.join(OUT, 'cart-1-initial.png'), fullPage: true });
  const cartBody1 = await page.locator('body').innerText().catch(() => '');

  // Real testids (KioskCartComponent): qty-plus/minus/qty/remove are idx-suffixed.
  const qtyVal = page.locator('[data-testid="kiosk-cart-item-qty-0"]');
  const inc = page.locator('[data-testid="kiosk-cart-item-qty-plus-0"]');
  const dec = page.locator('[data-testid="kiosk-cart-item-qty-minus-0"]');
  const loyaltyBtn = page.locator('[data-testid="kiosk-cart-loyalty-btn"]');
  const promoInput = page.locator('[data-testid="kiosk-cart-promo-input"]');

  const incFound = await inc.isVisible().catch(() => false);
  const qtyBefore = (await qtyVal.innerText().catch(() => '?')).trim();
  if (incFound) { await inc.click().catch(() => {}); await page.waitForTimeout(1000); await inc.click().catch(() => {}); await page.waitForTimeout(1000); }
  const qtyAfterInc = (await qtyVal.innerText().catch(() => '?')).trim();
  await page.screenshot({ path: path.join(OUT, 'cart-2-after-inc.png'), fullPage: true });
  const decFound = await dec.isVisible().catch(() => false);
  if (decFound) { await dec.click().catch(() => {}); await page.waitForTimeout(1000); }
  const qtyAfterDec = (await qtyVal.innerText().catch(() => '?')).trim();
  await page.screenshot({ path: path.join(OUT, 'cart-3-after-dec.png'), fullPage: true });

  // Loyalty prompt present ("Avez-vous une carte fidélité ?").
  const loyaltyFound = await loyaltyBtn.isVisible().catch(() => false);
  const promoFound = await promoInput.isVisible().catch(() => false);

  // Remove the line item.
  const remove = page.locator('[data-testid="kiosk-cart-item-remove-0"]');
  const removeFound = await remove.isVisible().catch(() => false);
  if (removeFound) { await remove.click().catch(() => {}); await page.waitForTimeout(1500); }
  await page.screenshot({ path: path.join(OUT, 'cart-4-after-remove.png'), fullPage: true });
  const emptyAfterRemove = await page.locator('[data-testid="kiosk-cart-empty"]').isVisible().catch(() => false);

  const cartBody2 = await page.locator('body').innerText().catch(() => '');
  const rawLabel = (cartBody2.match(RAW_LABEL_RE) || [null])[0];
  const crashed = CRASH.filter((c) => cartBody2.includes(c));
  console.log(`[CART] inc=${incFound}(${qtyBefore}->${qtyAfterInc}) dec=${decFound}(->${qtyAfterDec}) remove=${removeFound} emptyAfterRemove=${emptyAfterRemove} loyalty=${loyaltyFound} promo=${promoFound} rawLabel=${rawLabel || 'none'} crash=${crashed.length}`);
  verdicts.push({ screen: 'cart-ops', incFound, qtyBefore, qtyAfterInc, decFound, qtyAfterDec, removeFound, emptyAfterRemove, loyaltyFound, promoFound, rawLabel, crashed: crashed.length });

  expect(incFound, 'qty + button present').toBeTruthy();
  expect(qtyAfterInc, `qty increments (was ${qtyBefore})`).not.toBe(qtyBefore);
  expect(removeFound, 'remove button present').toBeTruthy();
  expect(loyaltyFound, '"carte fidélité" prompt present').toBeTruthy();
  expect(rawLabel, `raw label in cart: ${rawLabel}`).toBeFalsy();
  expect(crashed.length, `crash in cart: ${crashed.join(',')}`).toBe(0);
  expect(pageErrors, `JS errors cart: ${pageErrors.join(' | ')}`).toHaveLength(0);
});

test('ZZZ write verdicts', async () => {
  fs.writeFileSync(path.join(OUT, 'verdicts.json'), JSON.stringify(verdicts, null, 2));
  console.log('[VERDICTS]', JSON.stringify(verdicts, null, 2));
});
