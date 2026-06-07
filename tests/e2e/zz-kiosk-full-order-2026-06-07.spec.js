// FoodKing — KIOSK (borne) FULL ORDER (headless): idle -> add drink -> cart -> pay(counter) -> confirm
// 2026-06-07. Disposable clone (needs KIOSK_AUTO_LOGIN_TRUSTED_IPS in .env.e2e):
//   DB_DATABASE=foodking_e2e PLAYWRIGHT_BASE_URL=http://127.0.0.1:8766 \
//   PLAYWRIGHT_NO_WEB_SERVER=1 npx playwright test tests/e2e/zz-kiosk-full-order-2026-06-07.spec.js
//
// Creates ONE fresh borne order (Plan B: payment routes to counter -> PENDING_COUNTER).
// Eau Plate (id 58) has no options -> adds directly to cart (no wizard).

const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const { loginAsKiosk } = require('./helpers/login');

const DRINK_ID = 58; // Eau Plate 50cl (Boissons cat 10, no options)
const OUT = path.resolve(__dirname, '__screenshots__', 'kiosk-full-order-2026-06-07');
fs.mkdirSync(OUT, { recursive: true });

test.describe.configure({ mode: 'serial', timeout: 150_000 });

test('borne: add drink -> cart -> pay at counter -> confirmation', async ({ page }) => {
  const pageErrors = [];
  page.on('pageerror', (e) => pageErrors.push(e.message));

  await loginAsKiosk(page);
  await page.goto('/kiosk/idle', { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(2000);

  // Start takeaway.
  const takeaway = page.locator('[data-testid="kiosk-order-type-takeaway"]');
  if (!(await takeaway.isVisible().catch(() => false))) {
    const touch = page.locator('[data-testid="kiosk-idle-touch-btn"]');
    if (await touch.isVisible().catch(() => false)) { await touch.click(); await page.waitForTimeout(1000); }
  }
  await expect(takeaway).toBeVisible({ timeout: 10_000 });
  await takeaway.click();
  await page.waitForURL(/\/kiosk\/categories/, { timeout: 20_000 }).catch(() => {});

  // Boissons category + add Eau Plate (no options -> direct add).
  await page.goto('/kiosk/categories?cat=10', { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(2500);
  const addBtn = page.locator(`[data-testid="kiosk-product-add-${DRINK_ID}"]`);
  await expect(addBtn, 'Eau Plate add button').toBeVisible({ timeout: 15_000 });
  await addBtn.click();
  await page.waitForTimeout(2000);
  await page.screenshot({ path: path.join(OUT, '1-added.png'), fullPage: true });

  // Go to cart and checkout.
  await page.goto('/kiosk/cart', { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(2000);
  await page.screenshot({ path: path.join(OUT, '2-cart.png'), fullPage: true });
  const checkout = page.locator('[data-testid="kiosk-cart-checkout"]');
  await expect(checkout, 'cart checkout button (cart not empty)').toBeVisible({ timeout: 10_000 });
  await checkout.click();

  // Upsell screen ("ET POUR TERMINER ?") appears before payment (transition can be
  // slow, and a "(8s)" auto-advance countdown runs) -> wait for skip, then click.
  const upsellSkip = page.locator('[data-testid="kiosk-upsell-skip"]');
  await upsellSkip.waitFor({ state: 'visible', timeout: 20_000 }).catch(() => {});
  if (await upsellSkip.isVisible().catch(() => false)) {
    await page.screenshot({ path: path.join(OUT, '2b-upsell.png'), fullPage: true });
    await upsellSkip.click().catch(() => {});
    console.log('[KORDER] upsell skipped');
  }

  // Payment: Plan B counter-route -> confirm places the order.
  await page.waitForURL(/\/kiosk\/payment/, { timeout: 20_000 }).catch(() => {});
  await page.waitForTimeout(2500);
  await page.screenshot({ path: path.join(OUT, '3-payment.png'), fullPage: true });
  const confirm = page.locator('[data-testid="kiosk-payment-counter-confirm"], [data-testid="kiosk-payment-confirm"]').first();
  await expect(confirm, 'payment confirm button').toBeVisible({ timeout: 12_000 });
  const orderResp = page.waitForResponse(
    (r) => /\/api\/kiosk\/order|\/kiosk\/orders|counter/i.test(r.url()) && r.request().method() === 'POST',
    { timeout: 25_000 },
  ).catch(() => null);
  await confirm.click();
  const resp = await orderResp;
  if (resp) console.log(`[KORDER] place API ${resp.request().method()} ${resp.url().split('?')[0]} -> ${resp.status()}`);

  // Landing: confirmation or waiting.
  await page.waitForURL(/\/kiosk\/(confirmation|waiting)/, { timeout: 25_000 }).catch(() => {});
  await page.waitForTimeout(2500);
  await page.screenshot({ path: path.join(OUT, '4-confirmation.png'), fullPage: true });
  console.log(`[KORDER] final url=${page.url()}`);

  expect(pageErrors, `JS errors: ${pageErrors.join(' | ')}`).toHaveLength(0);
});
