// FoodKing E2E — AUDIT KIOSK UX/UI (cart → payment → cash → confirmation → ticket)
// Date : 2026-05-07
// Use   : run twice — once before code changes (KIOSK_UX_PHASE=before),
//         once after  (KIOSK_UX_PHASE=after). Default = "after".
//
// Why a single dual-phase spec?
//   - Captures les MÊMES vues dans le MÊME flux logique → diff visuel propre.
//   - Évite la duplication d'orchestration login/seed (DRY).
//   - Le sous-dossier de capture est piloté par env, pas de modif du test entre les passes.

const fs = require('fs');
const path = require('path');
const { test, expect } = require('@playwright/test');
const { loginAsKiosk } = require('./helpers/login');
const { clearFoodKingRateLimits } = require('./helpers/rate-limit');

const PHASE = process.env.KIOSK_UX_PHASE === 'before' ? 'before' : 'after';
const SHOT_DIR = path.join(
  process.cwd(),
  'tests/e2e/screenshots/audit-kiosk-ux-2026-05-07',
  PHASE,
);
if (!fs.existsSync(SHOT_DIR)) fs.mkdirSync(SHOT_DIR, { recursive: true });

const findings = [];
function record(slug, ok, note) {
  findings.push({ slug, phase: PHASE, ok, note, ts: new Date().toISOString() });
  fs.writeFileSync(
    path.join(SHOT_DIR, 'findings.json'),
    JSON.stringify(findings, null, 2),
  );
}

async function snap(page, name) {
  const fp = path.join(SHOT_DIR, `${name}.png`);
  await page.screenshot({ path: fp, fullPage: true }).catch(() => {});
  return path.relative(process.cwd(), fp);
}

/**
 * Seed Vuex store with a fake kiosk cart so the payment / cash / confirmation
 * screens render real data (not the empty-cart redirect).
 */
async function seedKioskCart(page) {
  // Visit any kiosk surface so app is mounted + store is bootstrapped
  await page.goto('/kiosk/categories', { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(2_000);

  await page.evaluate(() => {
    // Locate Vuex store from the live Vue app instance
    let store = null;
    const el = document.querySelector('#app');
    const inst = el && (el.__vue_app__ || el.__vueParentComponent);
    if (inst && inst.config && inst.config.globalProperties) {
      store = inst.config.globalProperties.$store;
    }
    if (!store) {
      // Vue 3 newer: __vue_app__ exposed via _instance.appContext
      const appComp = el && el.__vue_app__;
      if (appComp && appComp._context && appComp._context.config) {
        store = appComp._context.config.globalProperties.$store;
      }
    }
    if (!store || !store.dispatch) {
      window.__seedFailed = 'no_store';
      return;
    }
    const items = [
      {
        item_id: 9001,
        name: 'Burger Big King',
        convert_price: 9.5,
        price: 9.5,
        quantity: 2,
        item_variations: [{ id: 1, name: 'Pain brioché' }],
        item_extras: [{ id: 11, name: 'Fromage', price: 1.0 }],
        item_variation_total: 0,
        item_extra_total: 1.0,
        total: (9.5 + 1.0) * 2,
        image: null,
      },
      {
        item_id: 9002,
        name: 'Frites maison',
        convert_price: 3.5,
        price: 3.5,
        quantity: 1,
        item_variations: [],
        item_extras: [],
        item_variation_total: 0,
        item_extra_total: 0,
        total: 3.5,
        image: null,
      },
    ];
    try {
      // Reset puis injecter manuellement (commit direct si action add absente)
      store.dispatch('kioskCart/reset');
      const cartState = store.state && store.state.kioskCart;
      if (cartState) {
        cartState.items = items;
        cartState.queueNumber = 'A042';
        cartState.lastOrderId = 9999;
        cartState.paymentMethod = 'card';
      }
    } catch (e) {
      window.__seedFailed = String(e.message || e);
    }
  });
}

test.describe(`AUDIT KIOSK UX — phase=${PHASE}`, () => {
  test.setTimeout(180_000);

  test.beforeEach(async () => {
    try { clearFoodKingRateLimits(); } catch (_) {}
  });

  test('UX-01 — Cart screen with seeded items (1080×1920)', async ({ page }) => {
    await page.setViewportSize({ width: 1080, height: 1920 });
    await loginAsKiosk(page);
    await seedKioskCart(page);
    await page.goto('/kiosk/cart', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(2_000);

    const root = page.locator('[data-testid="kiosk-cart-root"]');
    await expect(root).toBeVisible({ timeout: 10_000 });

    // Touch target audit — edit button must be ≥44px (WCAG 2.5.8)
    const editBtnSize = await page
      .locator('[data-testid="kiosk-cart-item-edit-0"]')
      .first()
      .evaluate((el) => {
        const r = el.getBoundingClientRect();
        return { w: Math.round(r.width), h: Math.round(r.height) };
      })
      .catch(() => ({ w: 0, h: 0 }));

    // Tabular nums on the grand total
    const grandTotalFV = await page
      .locator('[data-testid="kiosk-cart-total"]')
      .first()
      .evaluate((el) => getComputedStyle(el).fontVariantNumeric)
      .catch(() => '');

    // aria-live presence on summary block
    const summaryAriaLive = await page
      .locator('[data-testid="kiosk-cart-summary"]')
      .first()
      .evaluate((el) => el.getAttribute('aria-live'))
      .catch(() => null);

    const shot = await snap(page, '01-cart-screen');
    record('cart-screen', true,
      `editBtn=${editBtnSize.w}x${editBtnSize.h}, grandTotal fontVariantNumeric="${grandTotalFV}", summary aria-live="${summaryAriaLive}", shot=${shot}`);
  });

  test('UX-02 — Cart empty state (clear cart)', async ({ page }) => {
    await page.setViewportSize({ width: 1080, height: 1920 });
    await loginAsKiosk(page);
    await page.goto('/kiosk/cart', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(1_500);
    const empty = page.locator('[data-testid="kiosk-cart-empty"]');
    const visible = await empty.isVisible().catch(() => false);
    const shot = await snap(page, '02-cart-empty');
    record('cart-empty', visible, `empty visible=${visible}, shot=${shot}`);
  });

  test('UX-03 — Payment screen with seeded items', async ({ page }) => {
    await page.setViewportSize({ width: 1080, height: 1920 });
    await loginAsKiosk(page);
    await seedKioskCart(page);
    await page.goto('/kiosk/payment', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(2_000);

    // Si le router a redirigé vers /cart (cart vide pas seedé), on snap quand même
    const url = page.url();
    const isPay = /\/kiosk\/payment/.test(url);
    const shot = await snap(page, '03-payment-screen');
    record('payment-screen', isPay,
      `url=${url}, isPaymentRoute=${isPay}, shot=${shot}`);
  });

  test('UX-04 — Cash instruction screen (route with query)', async ({ page }) => {
    await page.setViewportSize({ width: 1080, height: 1920 });
    await loginAsKiosk(page);
    await seedKioskCart(page);
    await page.goto('/kiosk/cash-instruction?number=A042&total=23.5&timeout=45',
      { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(1_500);
    const title = page.locator('[data-testid="kiosk-cash-title"]');
    const visible = await title.isVisible().catch(() => false);
    const shot = await snap(page, '04-cash-instruction');
    record('cash-instruction', visible, `title visible=${visible}, shot=${shot}`);
  });

  test('UX-05 — Confirmation screen (route with query)', async ({ page }) => {
    await page.setViewportSize({ width: 1080, height: 1920 });
    await loginAsKiosk(page);
    await seedKioskCart(page);
    await page.goto('/kiosk/confirmation?orderNumber=A042&orderTotal=23.5',
      { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(2_500);
    const title = page.locator('[data-testid="kiosk-confirmation-title"]');
    const visible = await title.isVisible().catch(() => false);

    // KsButton class sentinel — la conversion doit produire .ks-btn--primary sur le CTA home.
    const homeCtaClasses = await page
      .locator('[data-testid="kiosk-confirmation-cta-home"]')
      .first()
      .evaluate((el) => el.className)
      .catch(() => '');

    const shot = await snap(page, '05-confirmation-screen');
    record('confirmation-screen', visible,
      `title visible=${visible}, home CTA classes="${homeCtaClasses}", shot=${shot}`);
  });

  test('UX-06 — INDEX synthèse', async ({ page }) => {
    const idx = path.join(SHOT_DIR, 'INDEX.md');
    const lines = [
      `# AUDIT KIOSK UX — phase=${PHASE} — 2026-05-07`,
      '',
      `Total findings: ${findings.length}`,
      '',
      '| Slug | OK | Note |',
      '| --- | --- | --- |',
    ];
    for (const f of findings) {
      lines.push(`| ${f.slug} | ${f.ok} | ${String(f.note).slice(0, 240).replace(/\|/g, '\\|')} |`);
    }
    fs.writeFileSync(idx, lines.join('\n'));
    expect(findings.length).toBeGreaterThan(0);
  });
});
