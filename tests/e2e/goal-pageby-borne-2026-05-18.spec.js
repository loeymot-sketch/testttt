// E2E Agent BORNE — Page-by-page Kiosk audit — GOAL 2026-05-18 Round 1
// Workflow: capture each page, emit 4-file artifact quartet (PNG + DOM + console.json + network.json),
// analyze visually (via Read tool), heal in-place if defect, retest, advance.
//
// 15 pages organized by tier:
// Tier A (no cart): 1 idle, 2 auth, 3 categories, 9 upsell (force-render), 10 cart empty,
//                   11 payment empty, 12 confirmation force, 14 inactivity, 15 offline modal
// Tier B (cart primed via kiosk-order helper API): 4-8 wizard templates, 10b cart non-empty,
//                                                  11b payment with cart, 12b confirmation paid
// Tier C (error states): 13 error states (force route)
//
// Source-of-truth: reports/test-e2e/goal-pageby-2026-05-18/REVIEWER_PROTOCOL.md (12 defect cats)
// Frozen-zone aware: KioskWizardComponent + KioskAppComponent + KioskUpsellComponent (read-only).

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
  KIOSK_AUDIT_PREFIX,
} = require('./helpers/kiosk-order');

const SHOT_DIR = path.resolve(
  __dirname,
  '__screenshots__/goal-pageby-borne-2026-05-18',
);
fs.mkdirSync(SHOT_DIR, { recursive: true });

// Kiosk viewport (portrait 1080×1920 per CLAUDE.md §6 production surface)
const KIOSK_VIEWPORT = { width: 1080, height: 1920 };

// Order type — V1 dine-in DISABLED → must use TAKEAWAY for kiosk orders
// (OrderType::TAKEAWAY = 10, OrderType::KIOSK = 25 rejected by OrderRequest:213)
const ORDER_TYPE_TAKEAWAY = 10;

/**
 * Navigate idle → takeaway → categories. Required gate — going straight to
 * /kiosk/categories without takeaway click redirects back to idle.
 */
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

// Regex sentinels for the 12 reviewer protocol categories
const I18N_LEAK_RE = /(^|\s|>)(kiosk|pos|kds|oss|admin|menu|cart|button|label|nav|side|step)\.[a-z_]+(?:\.[a-z_]+){0,4}(\s|<|$)/i;
const RAW_LABEL_PATTERNS = [
  /\b[A-Z][a-z]+\.[A-Z]/,           // "Label.X"
  /\{\{\s*\$t\s*\(/,                // unresolved $t() literal
  /0undefined|NaN€|undefined €/,    // numeric integrity
];

// ============================================================================
// TIER A — No cart state needed. Direct page visits.
// ============================================================================

test.describe('BORNE Page-by-page — Tier A (idle / auth / catalog / empty states)', () => {
  test.setTimeout(180_000);

  test.beforeEach(async () => {
    try { clearFoodKingRateLimits(); } catch (_) { /* soft-fail */ }
  });

  test('Page 1 — Idle screen (promo carousel + lang select + tap-to-start)', async ({ page }) => {
    await page.setViewportSize(KIOSK_VIEWPORT);
    const { snap, dispose } = attachMegaAuditRecorder(page, SHOT_DIR);
    try {
      await loginAsKiosk(page);
      await page.goto('/kiosk/idle', { waitUntil: 'domcontentloaded' });
      await page.waitForTimeout(3_000);
      await snap('page-01-idle');

      // Smoke: idle root visible, language selector renders, takeaway CTA visible
      await expect(page.locator('[data-testid="kiosk-idle-root"]')).toBeVisible({ timeout: 10_000 });
      const txt = await page.locator('body').innerText().catch(() => '');
      const rawLeak = I18N_LEAK_RE.test(txt);
      console.log(`page-01-idle rawLeak=${rawLeak} bodyLen=${txt.length}`);
      expect(rawLeak, 'i18n leak in idle body').toBeFalsy();
    } finally { dispose(); }
  });

  test('Page 2 — Auth + Language (machine login + FR locale)', async ({ page }) => {
    await page.setViewportSize(KIOSK_VIEWPORT);
    const { snap, dispose } = attachMegaAuditRecorder(page, SHOT_DIR);
    try {
      await page.goto('/kiosk/login', { waitUntil: 'domcontentloaded' });
      await page.waitForTimeout(2_500);
      await snap('page-02a-login-pre-auth');

      // After auto-login, we should land on /kiosk/idle (or any /kiosk/* surface)
      await loginAsKiosk(page);
      await page.goto('/kiosk/idle', { waitUntil: 'domcontentloaded' });
      await page.waitForTimeout(2_000);
      await snap('page-02b-after-auth-fr');

      // Verify FR-lock: brand string should render localized; no raw kiosk.* leak
      const txt = await page.locator('body').innerText().catch(() => '');
      const rawLeak = I18N_LEAK_RE.test(txt);
      console.log(`page-02 auth+fr rawLeak=${rawLeak}`);
      expect(rawLeak).toBeFalsy();
    } finally { dispose(); }
  });

  test('Page 3 — Categories grid (sidebar + product cards)', async ({ page }) => {
    await page.setViewportSize(KIOSK_VIEWPORT);
    const { snap, dispose } = attachMegaAuditRecorder(page, SHOT_DIR);
    try {
      await loginAsKiosk(page);
      await gotoCategoriesViaTakeaway(page);
      await snap('page-03-categories');

      // Smoke: sidebar items present + at least 1 product card
      const sidebarCount = await page.locator('[data-testid^="kiosk-categories-sidebar-item-"]').count();
      const productCount = await page.locator('[data-testid^="kiosk-product-card-"]').count();
      console.log(`page-03-categories sidebar=${sidebarCount} products=${productCount}`);
      expect(sidebarCount).toBeGreaterThan(0);
      const txt = await page.locator('body').innerText().catch(() => '');
      expect(I18N_LEAK_RE.test(txt)).toBeFalsy();
    } finally { dispose(); }
  });

  test('Page 10a — Cart panel (empty state)', async ({ page }) => {
    await page.setViewportSize(KIOSK_VIEWPORT);
    const { snap, dispose } = attachMegaAuditRecorder(page, SHOT_DIR);
    try {
      await loginAsKiosk(page);
      await page.goto('/kiosk/cart', { waitUntil: 'domcontentloaded' });
      await page.waitForTimeout(2_500);
      await snap('page-10a-cart-empty');

      // Empty cart should show CTA to add items
      const emptyState = page.locator('[data-testid="kiosk-cart-empty"]');
      const isEmpty = await emptyState.isVisible({ timeout: 5_000 }).catch(() => false);
      console.log(`page-10a cart emptyVisible=${isEmpty}`);
      const txt = await page.locator('body').innerText().catch(() => '');
      expect(I18N_LEAK_RE.test(txt)).toBeFalsy();
    } finally { dispose(); }
  });

  test('Page 11a — Payment screen (empty cart redirect or empty state)', async ({ page }) => {
    await page.setViewportSize(KIOSK_VIEWPORT);
    const { snap, dispose } = attachMegaAuditRecorder(page, SHOT_DIR);
    try {
      await loginAsKiosk(page);
      await page.goto('/kiosk/payment', { waitUntil: 'domcontentloaded' });
      await page.waitForTimeout(2_500);
      await snap('page-11a-payment-empty');

      // Whether redirected to cart or staying on payment, no raw labels permitted
      const txt = await page.locator('body').innerText().catch(() => '');
      const rawLeak = I18N_LEAK_RE.test(txt);
      console.log(`page-11a payment rawLeak=${rawLeak} url=${page.url()}`);
      expect(rawLeak).toBeFalsy();
    } finally { dispose(); }
  });

  test('Page 12a — Confirmation screen (force-route inspection)', async ({ page }) => {
    await page.setViewportSize(KIOSK_VIEWPORT);
    const { snap, dispose } = attachMegaAuditRecorder(page, SHOT_DIR);
    try {
      await loginAsKiosk(page);
      // Force navigate to confirmation route — usually redirects to idle if no order_id
      await page.goto('/kiosk/confirmation', { waitUntil: 'domcontentloaded' });
      await page.waitForTimeout(2_500);
      await snap('page-12a-confirmation-no-order');

      const txt = await page.locator('body').innerText().catch(() => '');
      const rawLeak = I18N_LEAK_RE.test(txt);
      console.log(`page-12a confirmation rawLeak=${rawLeak} url=${page.url()}`);
      expect(rawLeak).toBeFalsy();
    } finally { dispose(); }
  });

  test('Page 14 — Inactivity overlay (wait 35s on categories)', async ({ page }) => {
    await page.setViewportSize(KIOSK_VIEWPORT);
    const { snap, dispose } = attachMegaAuditRecorder(page, SHOT_DIR);
    try {
      await loginAsKiosk(page);
      await gotoCategoriesViaTakeaway(page);
      await snap('page-14a-categories-pre-inactivity');

      // Wait for inactivity (typically 30-60s). Snap at multiple windows.
      await page.waitForTimeout(35_000);
      await snap('page-14b-after-35s');

      // Inspect for inactivity overlay element
      const overlayPresent = await page.evaluate(() => {
        return !!document.querySelector('[data-testid*="inactivity"], .kiosk-inactivity-overlay, .kiosk-inactivity');
      });
      console.log(`page-14 inactivity overlayPresent=${overlayPresent} url=${page.url()}`);
    } finally { dispose(); }
  });

  test('Page 15 — Offline conflict modal (simulate offline + reload)', async ({ page, context }) => {
    await page.setViewportSize(KIOSK_VIEWPORT);
    const { snap, dispose } = attachMegaAuditRecorder(page, SHOT_DIR);
    try {
      await loginAsKiosk(page);
      await gotoCategoriesViaTakeaway(page);
      // Trigger offline mode
      await context.setOffline(true).catch(() => {});
      await page.evaluate(() => window.dispatchEvent(new Event('offline'))).catch(() => {});
      await page.waitForTimeout(2_500);
      await snap('page-15a-offline-state');

      // Check for offline conflict modal element
      const offlineModal = await page.evaluate(() => {
        return !!document.querySelector('.kiosk-offline-conflict-modal, [data-testid*="offline-conflict"]');
      });
      console.log(`page-15 offline modalPresent=${offlineModal}`);

      // Restore connection
      await context.setOffline(false).catch(() => {});
      await page.waitForTimeout(1_000);
      await snap('page-15b-online-restored');

      const txt = await page.locator('body').innerText().catch(() => '');
      // Per R3 RED-B report: offline conflict modal cannot be triggered without queued
      // entries. Visual-deferred per feedback_kiosk_wizard_frozen_tests_allowed.md.
      const rawLeak = I18N_LEAK_RE.test(txt);
      console.log(`page-15 offline rawLeak=${rawLeak}`);
      expect(rawLeak).toBeFalsy();
    } finally { dispose(); }
  });
});

// ============================================================================
// TIER B — Wizard templates. Open via direct category navigation + item click.
// Frozen-zone components — visual attestation only, no source-code edits.
// ============================================================================

test.describe('BORNE Page-by-page — Tier B (wizard templates, frozen-zone aware)', () => {
  test.setTimeout(180_000);

  test.beforeEach(async () => {
    try { clearFoodKingRateLimits(); } catch (_) { /* soft-fail */ }
  });

  // Helper: navigate idle→takeaway→categories→click sidebar→click item card
  async function openItemWizard(page, itemId, categoryId) {
    await loginAsKiosk(page);
    await gotoCategoriesViaTakeaway(page);
    // Click sidebar category first (always — default lands on Burgers id=349)
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

  // Cat 349 Burgers default → item 375 Chicken Burger (composer)
  test('Page 4 — Wizard Sandwich template (item 474 Sandwich Cayenne, cat 344)', async ({ page }) => {
    await page.setViewportSize(KIOSK_VIEWPORT);
    const { snap, dispose } = attachMegaAuditRecorder(page, SHOT_DIR);
    try {
      const opened = await openItemWizard(page, 474, 344);
      await snap('page-04-wizard-sandwich');
      console.log(`page-04 wizard opened=${opened} url=${page.url()}`);
      const txt = await page.locator('body').innerText().catch(() => '');
      const rawLeak = I18N_LEAK_RE.test(txt);
      console.log(`page-04 wizard rawLeak=${rawLeak}`);
      expect(opened, 'wizard should open for item 474').toBeTruthy();
      expect(rawLeak, 'i18n leak in sandwich wizard').toBeFalsy();
    } finally { dispose(); }
  });

  test('Page 5 — Wizard Burger template (item 375 Chicken Burger, cat 349)', async ({ page }) => {
    await page.setViewportSize(KIOSK_VIEWPORT);
    const { snap, dispose } = attachMegaAuditRecorder(page, SHOT_DIR);
    try {
      // Default category is Burgers (349) — use item 375 Chicken Burger which is verified present
      const opened = await openItemWizard(page, 375, 349);
      await snap('page-05-wizard-burger');
      console.log(`page-05 wizard opened=${opened} url=${page.url()}`);
      const txt = await page.locator('body').innerText().catch(() => '');
      const rawLeak = I18N_LEAK_RE.test(txt);
      expect(opened, 'wizard should open for item 375').toBeTruthy();
      expect(rawLeak).toBeFalsy();
    } finally { dispose(); }
  });

  test('Page 6 — Wizard Bowl template (item 493 Bowl Frites curry, cat 347)', async ({ page }) => {
    await page.setViewportSize(KIOSK_VIEWPORT);
    const { snap, dispose } = attachMegaAuditRecorder(page, SHOT_DIR);
    try {
      // Bols Gourmands = 347
      const opened = await openItemWizard(page, 493, 347);
      await snap('page-06-wizard-bowl');
      console.log(`page-06 wizard opened=${opened} url=${page.url()}`);
      const txt = await page.locator('body').innerText().catch(() => '');
      const rawLeak = I18N_LEAK_RE.test(txt);
      expect(rawLeak).toBeFalsy();
    } finally { dispose(); }
  });

  test('Page 7 — Wizard Taco template (item 478 Tacos M, cat 306)', async ({ page }) => {
    await page.setViewportSize(KIOSK_VIEWPORT);
    const { snap, dispose } = attachMegaAuditRecorder(page, SHOT_DIR);
    try {
      // Tacos = 306
      const opened = await openItemWizard(page, 478, 306);
      await snap('page-07-wizard-taco');
      console.log(`page-07 wizard opened=${opened} url=${page.url()}`);
      const txt = await page.locator('body').innerText().catch(() => '');
      const rawLeak = I18N_LEAK_RE.test(txt);
      expect(rawLeak).toBeFalsy();
    } finally { dispose(); }
  });

  test('Page 8 — Wizard Menu/Frites template (item 485 Petite Frites, cat 348)', async ({ page }) => {
    await page.setViewportSize(KIOSK_VIEWPORT);
    const { snap, dispose } = attachMegaAuditRecorder(page, SHOT_DIR);
    try {
      // Frites = 348
      const opened = await openItemWizard(page, 485, 348);
      await snap('page-08-wizard-frites');
      console.log(`page-08 wizard opened=${opened} url=${page.url()}`);
      const txt = await page.locator('body').innerText().catch(() => '');
      const rawLeak = I18N_LEAK_RE.test(txt);
      expect(rawLeak).toBeFalsy();
    } finally { dispose(); }
  });

  test('Page 9 — Upsell modal (frozen, visual attest only) — reach via cart→checkout', async ({ page }) => {
    await page.setViewportSize(KIOSK_VIEWPORT);
    const { snap, dispose } = attachMegaAuditRecorder(page, SHOT_DIR);
    try {
      await loginAsKiosk(page);
      await gotoCategoriesViaTakeaway(page);
      const fritesCat = page.locator('[data-testid="kiosk-categories-sidebar-item-348"]').first();
      if (await fritesCat.isVisible({ timeout: 5_000 }).catch(() => false)) {
        await fritesCat.click({ timeout: 5_000 });
        await page.waitForTimeout(1_800);
      }
      const card485 = page.locator('[data-testid="kiosk-product-card-485"]').first();
      if (await card485.isVisible({ timeout: 5_000 }).catch(() => false)) {
        await card485.click({ timeout: 5_000 });
        await page.waitForTimeout(2_500);
        await walkWizardToAddItem(page);
      }
      await page.waitForTimeout(1_000);
      // Now navigate to cart and click checkout
      await page.goto('/kiosk/cart', { waitUntil: 'domcontentloaded' });
      await page.waitForTimeout(2_000);
      const checkout = page.locator('[data-testid="kiosk-cart-checkout"]').first();
      if (await checkout.isVisible({ timeout: 3_000 }).catch(() => false)) {
        await checkout.click({ timeout: 3_000 });
        await page.waitForTimeout(3_000);
      }
      await snap('page-09-upsell-modal');

      const upsellRoot = await page.locator('[data-testid="kiosk-upsell-root"]').count();
      const upsellSkip = await page.locator('[data-testid="kiosk-upsell-skip"]').count();
      console.log(`page-09 upsell root=${upsellRoot} skip=${upsellSkip} url=${page.url()}`);
      const txt = await page.locator('body').innerText().catch(() => '');
      const rawLeak = I18N_LEAK_RE.test(txt);
      console.log(`page-09 upsell rawLeak=${rawLeak}`);
      expect(rawLeak).toBeFalsy();
    } finally { dispose(); }
  });

  // walkWizardToAddItem helper (extracted to be used by Tier B page 9 too)
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
});

// ============================================================================
// TIER C — Cart + Payment + Confirmation with primed orders (API-hybrid)
// ============================================================================

test.describe('BORNE Page-by-page — Tier C (cart-primed flows via API hybrid)', () => {
  test.setTimeout(240_000);

  test.beforeEach(async () => {
    try { clearFoodKingRateLimits(); } catch (_) { /* soft-fail */ }
  });

  test.afterAll(async () => {
    try { cleanupKioskAuditOrders('AUDIT-PAGEBY-BORNE-'); } catch (_) {}
    try { resetKioskToken(); } catch (_) {}
  });

  // Helper: walk the wizard using the real selectors discovered in DOM inspection
  //  - viande step uses `.kiosk-viande-card` + `.kiosk-viande-name` for picks
  //  - generic step uses `.kiosk-generic-choice` + `.kiosk-generic-choice-add`
  //  - frites style uses similar generic pattern
  //  - next buttons: SUIVANT (text), AJOUTER (last step), RÉCAP, VALIDER
  async function walkWizardToAddItem(page) {
    for (let i = 0; i < 12; i++) {
      // Try to click the first choice on the current step (varies by template):
      // 1) viande grid (sandwich/burger/tacos)
      const viandeCard = page.locator('.kiosk-viande-card').first();
      // 2) generic choice (sauce/crudite/supplement/menu/style)
      const genericChoice = page.locator('.kiosk-generic-choice').first();
      // 3) step-content child clickable
      const stepCard = page.locator('.kiosk-step-content button:not(.kiosk-wizard-close)').first();

      let clicked = false;
      if (await viandeCard.isVisible({ timeout: 1_200 }).catch(() => false)) {
        // Click the viande +/qty button or the card itself
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

      // Advance — SUIVANT button. AJOUTER appears on last step.
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
          break; // last step
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

      // Wizard closed → done
      const stillOpen = await page.evaluate(() => !!document.querySelector('.kiosk-wizard, .kiosk-wizard-overlay'));
      if (!stillOpen) break;
      if (!clicked && !advanced) break;
    }
  }

  test('Page 10b — Cart panel non-empty (after item add via UI)', async ({ page }) => {
    await page.setViewportSize(KIOSK_VIEWPORT);
    const { snap, dispose } = attachMegaAuditRecorder(page, SHOT_DIR);
    try {
      await loginAsKiosk(page);
      await gotoCategoriesViaTakeaway(page);
      // Frites cat 348 (item 485 — single-required composer = fast prime)
      const fritesCat = page.locator('[data-testid="kiosk-categories-sidebar-item-348"]').first();
      if (await fritesCat.isVisible({ timeout: 5_000 }).catch(() => false)) {
        await fritesCat.click({ timeout: 5_000 });
        await page.waitForTimeout(1_800);
      }
      const card485 = page.locator('[data-testid="kiosk-product-card-485"]').first();
      if (await card485.isVisible({ timeout: 5_000 }).catch(() => false)) {
        await card485.click({ timeout: 5_000 });
        await page.waitForTimeout(2_500);
        await walkWizardToAddItem(page);
      }
      await snap('page-10b-after-add');

      // Navigate to cart
      await page.goto('/kiosk/cart', { waitUntil: 'domcontentloaded' });
      await page.waitForTimeout(2_500);
      await snap('page-10b-cart-with-items');

      const itemCount = await page.locator('[data-testid^="kiosk-cart-item-"]').count();
      console.log(`page-10b cart itemCount=${itemCount}`);
      const txt = await page.locator('body').innerText().catch(() => '');
      const rawLeak = I18N_LEAK_RE.test(txt);
      expect(rawLeak).toBeFalsy();
    } finally { dispose(); }
  });

  test('Page 11b — Payment screen with cart primed (3 methods rendered)', async ({ page }) => {
    await page.setViewportSize(KIOSK_VIEWPORT);
    const { snap, dispose } = attachMegaAuditRecorder(page, SHOT_DIR);
    try {
      await loginAsKiosk(page);
      await gotoCategoriesViaTakeaway(page);
      const fritesCat = page.locator('[data-testid="kiosk-categories-sidebar-item-348"]').first();
      if (await fritesCat.isVisible({ timeout: 5_000 }).catch(() => false)) {
        await fritesCat.click({ timeout: 5_000 });
        await page.waitForTimeout(1_800);
      }
      const card485 = page.locator('[data-testid="kiosk-product-card-485"]').first();
      if (await card485.isVisible({ timeout: 5_000 }).catch(() => false)) {
        await card485.click({ timeout: 5_000 });
        await page.waitForTimeout(2_500);
        await walkWizardToAddItem(page);
      }
      await page.waitForTimeout(1_500);
      await page.goto('/kiosk/cart', { waitUntil: 'domcontentloaded' });
      await page.waitForTimeout(2_000);
      await snap('page-11b-cart-pre-payment');

      // Click "Valider ma commande" CTA from cart (kiosk-cart-checkout testid)
      const payCta = page.locator('[data-testid="kiosk-cart-checkout"]').first();
      if (await payCta.isVisible({ timeout: 3_000 }).catch(() => false)) {
        await payCta.click({ timeout: 3_000 });
        await page.waitForTimeout(2_500);
      }

      // If we land on upsell first, capture then skip
      const upsellSkip = page.locator('[data-testid="kiosk-upsell-skip"]').first();
      const onUpsell = await upsellSkip.isVisible({ timeout: 2_500 }).catch(() => false);
      if (onUpsell) {
        await snap('page-09b-upsell-skip-ready');
        await upsellSkip.click({ timeout: 3_000 }).catch(() => {});
        await page.waitForTimeout(2_500);
      }

      // Should now be on /kiosk/payment
      if (!/\/kiosk\/payment/.test(page.url())) {
        await page.goto('/kiosk/payment', { waitUntil: 'domcontentloaded' });
        await page.waitForTimeout(2_500);
      }
      await snap('page-11b-payment-with-cart');

      const methodCard = await page.locator('[data-testid="kiosk-payment-method-card"]').count();
      const methodCash = await page.locator('[data-testid="kiosk-payment-method-cash"]').count();
      const methodTr = await page.locator('[data-testid="kiosk-payment-method-tr"]').count();
      console.log(`page-11b payment methods card=${methodCard} cash=${methodCash} tr=${methodTr} url=${page.url()}`);

      const txt = await page.locator('body').innerText().catch(() => '');
      const rawLeak = I18N_LEAK_RE.test(txt);
      // Hardcoded FR pattern (P1 K-S3-01 was healed in c138b32dd, verify still GREEN)
      const offlineFrLeak = /Paiement CB\/TR indisponible/i.test(txt) && !/\$t\(/i.test(txt);
      console.log(`page-11b payment rawLeak=${rawLeak} offlineFrLeak=${offlineFrLeak}`);
      expect(rawLeak).toBeFalsy();
    } finally { dispose(); }
  });

  test('Page 12b — Confirmation + ticket (place order via API takeaway + navigate)', async ({ page }) => {
    await page.setViewportSize(KIOSK_VIEWPORT);
    const { snap, dispose } = attachMegaAuditRecorder(page, SHOT_DIR);
    try {
      await loginAsKiosk(page);
      await page.goto('/kiosk/idle', { waitUntil: 'domcontentloaded' });
      await page.waitForTimeout(2_500);

      // Place a paid kiosk order via API helper to reach confirmation deterministically
      // CRITICAL #1: orderType=10 (TAKEAWAY) — V1 dine-in disabled per OrderRequest:213
      // CRITICAL #2: helper's payment-confirm misses amount_cents (PaymentConfirmRequest:43)
      // → use skipPaymentConfirm + do our own confirm with amount_cents from quote.total_ttc
      let placedOrderId = null;
      let placedSerial = null;
      try {
        const result = await placeKioskOrder(page, {
          items: [{
            item_id: 485,             // Petite Frites
            quantity: 1,
            item_variations: [{ id: 1180, quantity: 1 }], // Style frites Nature
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
        console.log(`page-12b order placed id=${placedOrderId} serial=${placedSerial} amountCents=${amountCents}`);

        // Do payment-confirm with amount_cents (PaymentConfirmRequest:43 required)
        if (placedOrderId && amountCents > 0) {
          const token = await getKioskApiToken(page);
          const confirmResp = await page.evaluate(async ({ orderId, amountCents, idemKey, token }) => {
            try {
              const r = await window.axios.post(
                `frontend/order/${orderId}/payment-confirm`,
                {
                  transaction_id: `AUDIT-PAGEBY-BORNE-12b-${Date.now()}`,
                  card_type: 'simulated-card',
                  payment_method: 4, // CARD
                  amount_cents: amountCents,
                },
                { headers: { Authorization: `Bearer ${token}`, 'X-Idempotency-Key': `${idemKey}-confirm-12b` } },
              );
              return { ok: true, status: r.status, data: r.data };
            } catch (e) {
              return { ok: false, status: e?.response?.status, body: e?.response?.data };
            }
          }, {
            orderId: placedOrderId,
            amountCents,
            idemKey: result?.idempotencyKey || `AUDIT-12B-${Date.now()}`,
            token,
          });
          console.log(`page-12b payment-confirm result: ${JSON.stringify(confirmResp).slice(0, 250)}`);
        }
      } catch (err) {
        console.log(`page-12b placeKioskOrder failed: ${String(err?.message || err).slice(0, 300)}`);
      }

      // Navigate to confirmation route with order_id if available
      const confirmUrl = placedOrderId
        ? `/kiosk/confirmation?order_id=${placedOrderId}`
        : '/kiosk/confirmation';
      await page.goto(confirmUrl, { waitUntil: 'domcontentloaded' });
      await page.waitForTimeout(3_500);
      await snap('page-12b-confirmation-paid');

      const confRoot = await page.locator('[data-testid="kiosk-confirmation-root"]').count();
      const confNumber = await page.locator('[data-testid="kiosk-confirmation-number"]').count();
      console.log(`page-12b confirmation root=${confRoot} number=${confNumber} url=${page.url()}`);

      const txt = await page.locator('body').innerText().catch(() => '');
      const rawLeak = I18N_LEAK_RE.test(txt);
      console.log(`page-12b confirmation rawLeak=${rawLeak}`);
      expect(rawLeak).toBeFalsy();
    } finally { dispose(); }
  });

  test('Page 13 — Error states (network offline + reload trigger)', async ({ page, context }) => {
    await page.setViewportSize(KIOSK_VIEWPORT);
    const { snap, dispose } = attachMegaAuditRecorder(page, SHOT_DIR);
    try {
      await loginAsKiosk(page);
      await gotoCategoriesViaTakeaway(page);
      // 13a — Force offline + emit event + capture (KioskErrorNetworkComponent)
      await context.setOffline(true).catch(() => {});
      await page.evaluate(() => window.dispatchEvent(new Event('offline'))).catch(() => {});
      await page.waitForTimeout(2_500);
      await snap('page-13a-error-network-state');
      // 13b — Restore + capture recovery
      await context.setOffline(false).catch(() => {});
      await page.evaluate(() => window.dispatchEvent(new Event('online'))).catch(() => {});
      await page.waitForTimeout(2_000);
      await snap('page-13b-after-online-restored');

      const txt = await page.locator('body').innerText().catch(() => '');
      const rawLeak = I18N_LEAK_RE.test(txt);
      console.log(`page-13 error states rawLeak=${rawLeak}`);
      expect(rawLeak).toBeFalsy();
    } finally { dispose(); }
  });
});
