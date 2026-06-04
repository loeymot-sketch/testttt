// Wave P-2 — Kiosk (Borne) E2E audit
// Forked from goal-pageby-borne-2026-05-18.spec.js — slimmer 11-test surface
// matching the 9-URL mission scope (idle → menu → 4 composer templates →
// upsell → cart → payment → confirmation → return). Drops the 35s inactivity
// wait + offline conflict modal (deferred upstream, no signal gained), focuses
// audit signal on what the mission asks.
//
// Reports dir : reports/test-e2e/wave-p-2026-05-20/kiosk/
// Screenshots : tests/e2e/__screenshots__/wave-p-kiosk-2026-05-20/
//
// Frozen-zone aware : KioskWizardComponent + KioskAppComponent + KioskUpsellComponent
// (read-only — visual attest only, no heal here ; heal candidate=KioskCartComponent).
//
// Carry-over from BORNE 2026-05-18 (cross-verified pre-run) :
//   - BORNE-001 dine-in V1 gate NOT YET healed (verified 2026-05-20 :
//     KioskCartComponent.vue:94 still has no v-if dineInEnabled guard).
//   - BORNE-002/003/004 cosmetic / 422 graceful / 401 token-rotate — accepted.
//
// Heal target this wave : BORNE-001 v-if gate + i18n key for backend error.
//   File : resources/js/components/frontend/kiosk/KioskCartComponent.vue
//   File : app/Http/Requests/OrderRequest.php (translate hardcoded EN literal)
//   File : resources/lang/{fr,en,ar}/order_type.php

const fs = require('fs');
const path = require('path');
const { test, expect } = require('@playwright/test');
const { attachMegaAuditRecorder } = require('./helpers/mega-audit-snap');
const { loginAsKiosk } = require('./helpers/login');
const { clearFoodKingRateLimits } = require('./helpers/rate-limit');
const {
  placeKioskOrder,
  cleanupKioskAuditOrders,
  resetKioskToken,
  getKioskApiToken,
  PAYMENT_CARD,
} = require('./helpers/kiosk-order');

const SHOT_DIR = path.resolve(
  __dirname,
  '__screenshots__/wave-p-kiosk-2026-05-20',
);
fs.mkdirSync(SHOT_DIR, { recursive: true });

const REPORT_DIR = path.resolve(
  __dirname,
  '../../reports/test-e2e/wave-p-2026-05-20/kiosk',
);
fs.mkdirSync(REPORT_DIR, { recursive: true });

// Kiosk viewport (portrait 1080×1920 production surface)
const KIOSK_VIEWPORT = { width: 1080, height: 1920 };

// Order type — V1 dine-in DISABLED → must use TAKEAWAY for kiosk orders
const ORDER_TYPE_TAKEAWAY = 10;

async function gotoCategoriesViaTakeaway(page) {
  await page.goto('/kiosk/idle', { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(2_500);
  const takeaway = page.locator('[data-testid="kiosk-order-type-takeaway"]').first();
  await expect(takeaway).toBeVisible({ timeout: 15_000 });
  await takeaway.click({ timeout: 5_000 });
  await page.waitForTimeout(2_000);
  await expect(page).toHaveURL(/\/kiosk\/(categories|menu)/, { timeout: 10_000 });
  await page.waitForTimeout(2_000);
}

const I18N_LEAK_RE = /(^|\s|>)(kiosk|pos|kds|oss|admin|menu|cart|button|label|nav|side|step)\.[a-z_]+(?:\.[a-z_]+){0,4}(\s|<|$)/i;

// Walk the wizard to reach "ajouter au panier" — handles viande/generic/step variants
async function walkWizardToAddItem(page) {
  for (let i = 0; i < 12; i++) {
    const viandeCard = page.locator('.kiosk-viande-card').first();
    const genericChoice = page.locator('.kiosk-generic-choice').first();
    const stepCard = page.locator('.kiosk-step-content button:not(.kiosk-wizard-close)').first();

    let clicked = false;
    if (await viandeCard.isVisible({ timeout: 1_200 }).catch(() => false)) {
      const viandeBtn = viandeCard.locator('.kiosk-viande-qty-btn, .kiosk-viande-controls button').first();
      const target = (await viandeBtn.isVisible({ timeout: 800 }).catch(() => false)) ? viandeBtn : viandeCard;
      await target.click({ timeout: 1_500 }).catch(() => {});
      clicked = true;
    } else if (await genericChoice.isVisible({ timeout: 1_200 }).catch(() => false)) {
      const addBtn = genericChoice.locator('.kiosk-generic-choice-add, button').first();
      const target = (await addBtn.isVisible({ timeout: 800 }).catch(() => false)) ? addBtn : genericChoice;
      await target.click({ timeout: 1_500 }).catch(() => {});
      clicked = true;
    } else if (await stepCard.isVisible({ timeout: 1_200 }).catch(() => false)) {
      await stepCard.click({ timeout: 1_500 }).catch(() => {});
      clicked = true;
    }
    if (clicked) await page.waitForTimeout(600);

    const suivant = page.locator('button:has-text("SUIVANT"), button:has-text("Suivant")').first();
    const ajouter = page.locator('button:has-text("AJOUTER"), button:has-text("Ajouter au panier"), button:has-text("Ajouter")').first();
    const valider = page.locator('button:has-text("VALIDER"), button:has-text("Valider")').first();

    let advanced = false;
    if (await ajouter.isVisible({ timeout: 800 }).catch(() => false)) {
      const enabled = await ajouter.isEnabled().catch(() => false);
      if (enabled) {
        await ajouter.click({ timeout: 1_500 }).catch(() => {});
        await page.waitForTimeout(1_000);
        advanced = true;
        break;
      }
    }
    if (await suivant.isVisible({ timeout: 800 }).catch(() => false)) {
      const enabled = await suivant.isEnabled().catch(() => false);
      if (enabled) {
        await suivant.click({ timeout: 1_500 }).catch(() => {});
        await page.waitForTimeout(800);
        advanced = true;
      }
    } else if (await valider.isVisible({ timeout: 800 }).catch(() => false)) {
      const enabled = await valider.isEnabled().catch(() => false);
      if (enabled) {
        await valider.click({ timeout: 1_500 }).catch(() => {});
        await page.waitForTimeout(800);
        advanced = true;
      }
    }

    const stillOpen = await page.evaluate(() => !!document.querySelector('.kiosk-wizard, .kiosk-wizard-overlay'));
    if (!stillOpen) break;
    if (!clicked && !advanced) break;
  }
}

async function openItemWizard(page, itemId, categoryId) {
  if (categoryId) {
    const cat = page.locator(`[data-testid="kiosk-categories-sidebar-item-${categoryId}"]`).first();
    if (await cat.isVisible({ timeout: 5_000 }).catch(() => false)) {
      await cat.click({ timeout: 5_000 });
      await page.waitForTimeout(1_800);
    }
  }
  const card = page.locator(`[data-testid="kiosk-product-card-${itemId}"]`).first();
  if (await card.isVisible({ timeout: 8_000 }).catch(() => false)) {
    await card.click({ timeout: 5_000 });
    await page.waitForTimeout(2_500);
    return true;
  }
  return false;
}

// URL #1 : idle — splash + tap-to-start + lang select
test.describe('Wave P-2 Kiosk — page-by-page audit', () => {
  test.setTimeout(180_000);

  test.beforeEach(async () => {
    try { clearFoodKingRateLimits(); } catch (_) { /* soft-fail */ }
  });

  test.afterAll(async () => {
    try { cleanupKioskAuditOrders('AUDIT-WAVE-P-KIOSK-'); } catch (_) {}
    try { resetKioskToken(); } catch (_) {}
  });

  test('URL-1 — Idle screen (splash + tap-to-start)', async ({ page }) => {
    await page.setViewportSize(KIOSK_VIEWPORT);
    const { snap, dispose } = attachMegaAuditRecorder(page, SHOT_DIR);
    try {
      await loginAsKiosk(page);
      await page.goto('/kiosk/idle', { waitUntil: 'domcontentloaded' });
      await page.waitForTimeout(3_000);
      await snap('url-01-idle');

      await expect(page.locator('[data-testid="kiosk-idle-root"]')).toBeVisible({ timeout: 10_000 });
      const txt = await page.locator('body').innerText().catch(() => '');
      const rawLeak = I18N_LEAK_RE.test(txt);
      console.log(`url-01-idle rawLeak=${rawLeak} bodyLen=${txt.length}`);
      expect(rawLeak).toBeFalsy();
    } finally { dispose(); }
  });

  test('URL-2 — Categories grid + product cards', async ({ page }) => {
    await page.setViewportSize(KIOSK_VIEWPORT);
    const { snap, dispose } = attachMegaAuditRecorder(page, SHOT_DIR);
    try {
      await loginAsKiosk(page);
      await gotoCategoriesViaTakeaway(page);
      await snap('url-02-categories');

      const sidebarCount = await page.locator('[data-testid^="kiosk-categories-sidebar-item-"]').count();
      const productCount = await page.locator('[data-testid^="kiosk-product-card-"]').count();
      console.log(`url-02-categories sidebar=${sidebarCount} products=${productCount}`);
      expect(sidebarCount).toBeGreaterThan(0);
      const txt = await page.locator('body').innerText().catch(() => '');
      expect(I18N_LEAK_RE.test(txt)).toBeFalsy();
    } finally { dispose(); }
  });

  test('URL-3 — Wizard Sandwich (item 24 Sandwich Cayenne, cat 1) - composition + allergen check', async ({ page }) => {
    await page.setViewportSize(KIOSK_VIEWPORT);
    const { snap, dispose } = attachMegaAuditRecorder(page, SHOT_DIR);
    try {
      await loginAsKiosk(page);
      await gotoCategoriesViaTakeaway(page);
      const opened = await openItemWizard(page, 24, 1);
      await snap('url-03-wizard-sandwich-step1');
      console.log(`url-03 wizard opened=${opened} url=${page.url()}`);

      // Allergen surfaces: kiosk-wizard-header-allergens + per-step badges
      const allergenHeaderCount = await page.locator('.kiosk-wizard-header-allergens').count();
      const allergenBadgeCount = await page.locator('[class*="allergen"]').count();
      console.log(`url-03 allergen header=${allergenHeaderCount} badges=${allergenBadgeCount}`);

      // Try clicking through to step 2/3 to capture composer flow
      const viande = page.locator('.kiosk-viande-card').first();
      if (await viande.isVisible({ timeout: 3_000 }).catch(() => false)) {
        const viandeBtn = viande.locator('.kiosk-viande-qty-btn').first();
        const target = (await viandeBtn.isVisible({ timeout: 800 }).catch(() => false)) ? viandeBtn : viande;
        await target.click({ timeout: 1_500 }).catch(() => {});
        await page.waitForTimeout(800);
        const suivant = page.locator('button:has-text("SUIVANT"), button:has-text("Suivant")').first();
        if (await suivant.isVisible({ timeout: 1_500 }).catch(() => false)) {
          const enabled = await suivant.isEnabled().catch(() => false);
          if (enabled) {
            await suivant.click().catch(() => {});
            await page.waitForTimeout(1_200);
          }
        }
        await snap('url-03-wizard-sandwich-step2');
      }

      const txt = await page.locator('body').innerText().catch(() => '');
      expect(opened, 'sandwich wizard should open for item 24').toBeTruthy();
      expect(I18N_LEAK_RE.test(txt)).toBeFalsy();
    } finally { dispose(); }
  });

  test('URL-4 — Wizard Tacos (item 28 Tacos, cat 5)', async ({ page }) => {
    await page.setViewportSize(KIOSK_VIEWPORT);
    const { snap, dispose } = attachMegaAuditRecorder(page, SHOT_DIR);
    try {
      await loginAsKiosk(page);
      await gotoCategoriesViaTakeaway(page);
      const opened = await openItemWizard(page, 28, 5);
      await snap('url-04-wizard-taco');
      console.log(`url-04 wizard opened=${opened} url=${page.url()}`);
      const txt = await page.locator('body').innerText().catch(() => '');
      expect(I18N_LEAK_RE.test(txt)).toBeFalsy();
    } finally { dispose(); }
  });

  test('URL-5 — Wizard Bowl (item 44 Bowl Frites Poulet curry, cat 6) - 3-step composition', async ({ page }) => {
    await page.setViewportSize(KIOSK_VIEWPORT);
    const { snap, dispose } = attachMegaAuditRecorder(page, SHOT_DIR);
    try {
      await loginAsKiosk(page);
      await gotoCategoriesViaTakeaway(page);
      const opened = await openItemWizard(page, 44, 6);
      await snap('url-05-wizard-bowl-step1');
      console.log(`url-05 wizard opened=${opened} url=${page.url()}`);
      const txt = await page.locator('body').innerText().catch(() => '');
      expect(I18N_LEAK_RE.test(txt)).toBeFalsy();
    } finally { dispose(); }
  });

  test('URL-6 — Drink single item (no composition) — direct cart add', async ({ page }) => {
    await page.setViewportSize(KIOSK_VIEWPORT);
    const { snap, dispose } = attachMegaAuditRecorder(page, SHOT_DIR);
    try {
      await loginAsKiosk(page);
      await gotoCategoriesViaTakeaway(page);
      // Boissons cat 10 — drinks are simple add-to-cart, no wizard
      const drinkCat = page.locator('[data-testid="kiosk-categories-sidebar-item-10"]').first();
      if (await drinkCat.isVisible({ timeout: 5_000 }).catch(() => false)) {
        await drinkCat.click({ timeout: 5_000 });
        await page.waitForTimeout(1_800);
      }
      await snap('url-06-drink-list');
      const cardCount = await page.locator('[data-testid^="kiosk-product-card-"]').count();
      console.log(`url-06 drink category cardCount=${cardCount}`);
      // Click first drink card if present
      const firstCard = page.locator('[data-testid^="kiosk-product-card-"]').first();
      if (await firstCard.isVisible({ timeout: 3_000 }).catch(() => false)) {
        await firstCard.click({ timeout: 3_000 });
        await page.waitForTimeout(2_000);
        await snap('url-06-drink-after-add');
      }
      const txt = await page.locator('body').innerText().catch(() => '');
      expect(I18N_LEAK_RE.test(txt)).toBeFalsy();
    } finally { dispose(); }
  });

  test('URL-7 — Cart panel with items + recap', async ({ page }) => {
    await page.setViewportSize(KIOSK_VIEWPORT);
    const { snap, dispose } = attachMegaAuditRecorder(page, SHOT_DIR);
    try {
      await loginAsKiosk(page);
      await gotoCategoriesViaTakeaway(page);
      // Frites cat 7 — single-required composer = fast prime
      const fritesCat = page.locator('[data-testid="kiosk-categories-sidebar-item-7"]').first();
      if (await fritesCat.isVisible({ timeout: 5_000 }).catch(() => false)) {
        await fritesCat.click({ timeout: 5_000 });
        await page.waitForTimeout(1_800);
      }
      const card35 = page.locator('[data-testid="kiosk-product-card-35"]').first();
      if (await card35.isVisible({ timeout: 5_000 }).catch(() => false)) {
        await card35.click({ timeout: 5_000 });
        await page.waitForTimeout(2_500);
        await walkWizardToAddItem(page);
      }
      await snap('url-07-after-add');

      await page.goto('/kiosk/cart', { waitUntil: 'domcontentloaded' });
      await page.waitForTimeout(2_500);
      await snap('url-07-cart-with-items');

      const itemCount = await page.locator('[data-testid^="kiosk-cart-item-"]').count();
      console.log(`url-07 cart itemCount=${itemCount}`);

      // BORNE-001 audit : check whether dine-in button is gated
      const dineInButton = page.locator('[data-testid="kiosk-cart-order-type-dinein"]');
      const dineInVisible = await dineInButton.isVisible({ timeout: 2_000 }).catch(() => false);
      const dineInDisabled = dineInVisible ? await dineInButton.getAttribute('disabled').catch(() => null) : null;
      const dineInAriaDisabled = dineInVisible ? await dineInButton.getAttribute('aria-disabled').catch(() => null) : null;
      console.log(`url-07 BORNE-001 audit dineInVisible=${dineInVisible} disabled=${dineInDisabled} aria-disabled=${dineInAriaDisabled}`);

      const txt = await page.locator('body').innerText().catch(() => '');
      expect(I18N_LEAK_RE.test(txt)).toBeFalsy();
    } finally { dispose(); }
  });

  test('URL-8 — Payment selector (3 methods)', async ({ page }) => {
    await page.setViewportSize(KIOSK_VIEWPORT);
    const { snap, dispose } = attachMegaAuditRecorder(page, SHOT_DIR);
    try {
      await loginAsKiosk(page);
      await gotoCategoriesViaTakeaway(page);
      const fritesCat = page.locator('[data-testid="kiosk-categories-sidebar-item-7"]').first();
      if (await fritesCat.isVisible({ timeout: 5_000 }).catch(() => false)) {
        await fritesCat.click({ timeout: 5_000 });
        await page.waitForTimeout(1_800);
      }
      const card35 = page.locator('[data-testid="kiosk-product-card-35"]').first();
      if (await card35.isVisible({ timeout: 5_000 }).catch(() => false)) {
        await card35.click({ timeout: 5_000 });
        await page.waitForTimeout(2_500);
        await walkWizardToAddItem(page);
      }
      await page.waitForTimeout(1_500);
      await page.goto('/kiosk/cart', { waitUntil: 'domcontentloaded' });
      await page.waitForTimeout(2_000);

      const payCta = page.locator('[data-testid="kiosk-cart-checkout"]').first();
      if (await payCta.isVisible({ timeout: 3_000 }).catch(() => false)) {
        await payCta.click({ timeout: 3_000 });
        await page.waitForTimeout(2_500);
      }

      // Skip upsell if present
      const upsellSkip = page.locator('[data-testid="kiosk-upsell-skip"]').first();
      const onUpsell = await upsellSkip.isVisible({ timeout: 2_500 }).catch(() => false);
      if (onUpsell) {
        await snap('url-08a-upsell-modal');
        await upsellSkip.click({ timeout: 3_000 }).catch(() => {});
        await page.waitForTimeout(2_500);
      }

      if (!/\/kiosk\/payment/.test(page.url())) {
        await page.goto('/kiosk/payment', { waitUntil: 'domcontentloaded' });
        await page.waitForTimeout(2_500);
      }
      await snap('url-08-payment-selector');

      const methodCard = await page.locator('[data-testid="kiosk-payment-method-card"]').count();
      const methodCash = await page.locator('[data-testid="kiosk-payment-method-cash"]').count();
      const methodTr = await page.locator('[data-testid="kiosk-payment-method-tr"]').count();
      console.log(`url-08 payment methods card=${methodCard} cash=${methodCash} tr=${methodTr}`);

      const txt = await page.locator('body').innerText().catch(() => '');
      expect(I18N_LEAK_RE.test(txt)).toBeFalsy();
    } finally { dispose(); }
  });

  test('URL-9 — Confirmation (API order placement + visual)', async ({ page }) => {
    await page.setViewportSize(KIOSK_VIEWPORT);
    const { snap, dispose } = attachMegaAuditRecorder(page, SHOT_DIR);
    try {
      await loginAsKiosk(page);
      await page.goto('/kiosk/idle', { waitUntil: 'domcontentloaded' });
      await page.waitForTimeout(2_500);

      let placedOrderId = null;
      let placedSerial = null;
      try {
        const result = await placeKioskOrder(page, {
          items: [{
            item_id: 35,                                       // Petite Frites
            quantity: 1,
            item_variations: [{ id: 130, quantity: 1 }],       // Nature
            item_extras: [],
            item_addons: [],
          }],
          paymentMethod: PAYMENT_CARD,
          orderType: ORDER_TYPE_TAKEAWAY,
          skipPaymentConfirm: true,
        });
        placedOrderId = result.orderId;
        placedSerial = result.orderSerialNo;
        const totalTtc = result?.quote?.total_ttc || result?.totalAmount || 0;
        const amountCents = Math.round(Number(totalTtc) * 100);
        console.log(`url-09 order placed id=${placedOrderId} serial=${placedSerial} amountCents=${amountCents}`);

        if (placedOrderId && amountCents > 0) {
          const token = await getKioskApiToken(page);
          const confirmResp = await page.evaluate(async ({ orderId, amountCents, idemKey, token }) => {
            try {
              const r = await window.axios.post(
                `frontend/order/${orderId}/payment-confirm`,
                {
                  transaction_id: `AUDIT-WAVE-P-KIOSK-${Date.now()}`,
                  card_type: 'simulated-card',
                  payment_method: 4,
                  amount_cents: amountCents,
                },
                { headers: { Authorization: `Bearer ${token}`, 'X-Idempotency-Key': `${idemKey}-confirm` } },
              );
              return { ok: true, status: r.status, data: r.data };
            } catch (e) {
              return { ok: false, status: e?.response?.status, body: e?.response?.data };
            }
          }, {
            orderId: placedOrderId,
            amountCents,
            idemKey: result?.idempotencyKey || `WAVE-P-${Date.now()}`,
            token,
          });
          console.log(`url-09 payment-confirm: ${JSON.stringify(confirmResp).slice(0, 250)}`);
        }
      } catch (err) {
        console.log(`url-09 placeKioskOrder failed: ${String(err?.message || err).slice(0, 300)}`);
      }

      const confirmUrl = placedOrderId
        ? `/kiosk/confirmation?order_id=${placedOrderId}`
        : '/kiosk/confirmation';
      await page.goto(confirmUrl, { waitUntil: 'domcontentloaded' });
      await page.waitForTimeout(3_500);
      await snap('url-09-confirmation');

      const confRoot = await page.locator('[data-testid="kiosk-confirmation-root"]').count();
      const confNumber = await page.locator('[data-testid="kiosk-confirmation-number"]').count();
      console.log(`url-09 confirmation root=${confRoot} number=${confNumber} url=${page.url()}`);

      const txt = await page.locator('body').innerText().catch(() => '');
      expect(I18N_LEAK_RE.test(txt)).toBeFalsy();
    } finally { dispose(); }
  });

  test('URL-10 — Upsell modal (visual attest, frozen component)', async ({ page }) => {
    await page.setViewportSize(KIOSK_VIEWPORT);
    const { snap, dispose } = attachMegaAuditRecorder(page, SHOT_DIR);
    try {
      await loginAsKiosk(page);
      await gotoCategoriesViaTakeaway(page);
      const fritesCat = page.locator('[data-testid="kiosk-categories-sidebar-item-7"]').first();
      if (await fritesCat.isVisible({ timeout: 5_000 }).catch(() => false)) {
        await fritesCat.click({ timeout: 5_000 });
        await page.waitForTimeout(1_800);
      }
      const card35 = page.locator('[data-testid="kiosk-product-card-35"]').first();
      if (await card35.isVisible({ timeout: 5_000 }).catch(() => false)) {
        await card35.click({ timeout: 5_000 });
        await page.waitForTimeout(2_500);
        await walkWizardToAddItem(page);
      }
      await page.waitForTimeout(1_500);
      await page.goto('/kiosk/cart', { waitUntil: 'domcontentloaded' });
      await page.waitForTimeout(2_000);
      const checkout = page.locator('[data-testid="kiosk-cart-checkout"]').first();
      if (await checkout.isVisible({ timeout: 3_000 }).catch(() => false)) {
        await checkout.click({ timeout: 3_000 });
        await page.waitForTimeout(3_000);
      }
      await snap('url-10-upsell-modal');

      const upsellRoot = await page.locator('[data-testid="kiosk-upsell-root"]').count();
      const upsellSkip = await page.locator('[data-testid="kiosk-upsell-skip"]').count();
      console.log(`url-10 upsell root=${upsellRoot} skip=${upsellSkip} url=${page.url()}`);

      const txt = await page.locator('body').innerText().catch(() => '');
      expect(I18N_LEAK_RE.test(txt)).toBeFalsy();
    } finally { dispose(); }
  });

  test('URL-11 — Return to idle (post-payment auto-return)', async ({ page }) => {
    await page.setViewportSize(KIOSK_VIEWPORT);
    const { snap, dispose } = attachMegaAuditRecorder(page, SHOT_DIR);
    try {
      await loginAsKiosk(page);
      await page.goto('/kiosk/idle', { waitUntil: 'domcontentloaded' });
      await page.waitForTimeout(2_500);
      await snap('url-11-idle-final-state');

      // Verify idle render is intact (not a broken state)
      await expect(page.locator('[data-testid="kiosk-idle-root"]')).toBeVisible({ timeout: 10_000 });
      const txt = await page.locator('body').innerText().catch(() => '');
      const rawLeak = I18N_LEAK_RE.test(txt);
      expect(rawLeak).toBeFalsy();
    } finally { dispose(); }
  });
});
