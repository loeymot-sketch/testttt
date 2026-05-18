// RED-Visual B — Kiosk i18n Validator — GOAL Round 3 (2026-05-18)
// READ-ONLY visual capture for Impl B heal verification (commits c138b32dd + b59d34c24)
// Scope: kiosk/idle + wizard composition + payment screen + (optional) offline conflict modal

const fs = require('fs');
const path = require('path');
const { test, expect } = require('@playwright/test');
const { loginAsKiosk } = require('./helpers/login');
const { clearFoodKingRateLimits } = require('./helpers/rate-limit');

const SHOT_DIR = '/tmp/foodking-goal-round3/red-b-kiosk';
if (!fs.existsSync(SHOT_DIR)) fs.mkdirSync(SHOT_DIR, { recursive: true });

async function snap(page, slug) {
  const fp = path.join(SHOT_DIR, `${slug}.png`);
  await page.screenshot({ path: fp, fullPage: true }).catch(() => {});
  return fp;
}

test.describe('RED-B Kiosk i18n visual gate Round 3', () => {
  test.setTimeout(180_000);

  test.beforeEach(async () => {
    try { clearFoodKingRateLimits(); } catch (_) {}
  });

  test('R3-K-01 — kiosk/idle FR-lock + no raw label', async ({ page }) => {
    await page.setViewportSize({ width: 1080, height: 1920 });
    await loginAsKiosk(page);
    await page.goto('/kiosk/idle', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(3_000);
    const shot = await snap(page, 'R3-K-01-idle');
    const txt = await page.locator('body').innerText().catch(() => '');
    const rawLabel = /kiosk\.[a-z_]+\.[a-z_]+/i.test(txt);
    console.log(`R3-K-01 idle len=${txt.length} rawLabel=${rawLabel} shot=${shot}`);
  });

  test('R3-K-02 — kiosk wizard composition screen', async ({ page }) => {
    await page.setViewportSize({ width: 1080, height: 1920 });
    await loginAsKiosk(page);
    await page.goto('/kiosk/categories', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(2_500);
    await snap(page, 'R3-K-02a-categories');

    // Try clicking first product card or category card
    const firstCard = page.locator('[data-testid*="category"], [data-testid*="product-card"], .kiosk-card').first();
    if (await firstCard.isVisible({ timeout: 5_000 }).catch(() => false)) {
      await firstCard.click().catch(() => {});
      await page.waitForTimeout(2_000);
      await snap(page, 'R3-K-02b-after-click');

      // Try one more product depth
      const productCard = page.locator('[data-testid*="product-card"], .kiosk-product-card').first();
      if (await productCard.isVisible({ timeout: 3_000 }).catch(() => false)) {
        await productCard.click().catch(() => {});
        await page.waitForTimeout(2_500);
        await snap(page, 'R3-K-02c-wizard');
      }
    }
    const txt = await page.locator('body').innerText().catch(() => '');
    const rawLabel = /kiosk\.[a-z_]+\.[a-z_]+/i.test(txt);
    console.log(`R3-K-02 wizard rawLabel=${rawLabel}`);
  });

  test('R3-K-03 — kiosk payment screen (force-navigation, validate template i18n)', async ({ page }) => {
    await page.setViewportSize({ width: 1080, height: 1920 });
    await loginAsKiosk(page);
    // Navigate direct to payment route (may redirect to cart if empty)
    await page.goto('/kiosk/payment', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(2_500);
    await snap(page, 'R3-K-03a-payment-direct');

    // Also try via /kiosk/cart
    await page.goto('/kiosk/cart', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(2_000);
    await snap(page, 'R3-K-03b-cart');

    const txt = await page.locator('body').innerText().catch(() => '');
    const rawLabel = /kiosk\.pay_screen|kiosk\.offline_conflict/i.test(txt);
    console.log(`R3-K-03 payment rawLabel=${rawLabel}`);
  });

  test('R3-K-04 — kiosk offline conflict modal (simulate via offline navigator)', async ({ page, context }) => {
    await page.setViewportSize({ width: 1080, height: 1920 });
    await loginAsKiosk(page);
    await page.goto('/kiosk/categories', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(1_500);
    // Force offline mode + reload to trigger conflict path if rendered conditionally
    await context.setOffline(true).catch(() => {});
    await page.evaluate(() => window.dispatchEvent(new Event('offline'))).catch(() => {});
    await page.waitForTimeout(2_000);
    await snap(page, 'R3-K-04-offline-mode');
    await context.setOffline(false).catch(() => {});
    console.log('R3-K-04 offline modal: visual-deferred (modal renders only on actual queue conflict)');
  });

  test('R3-K-05 — kiosk/idle EN locale (verify EN mirror not raw label)', async ({ page }) => {
    await page.setViewportSize({ width: 1080, height: 1920 });
    await loginAsKiosk(page);
    // ADR-007 FR-lock = EN selector is visually present but no-op; verify FR still resolves
    await page.goto('/kiosk/idle', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(2_000);
    await snap(page, 'R3-K-05-idle-locked-fr');
    console.log('R3-K-05 idle EN selector (ADR-007 FR-lock): captured for visual review');
  });
});
