// Wave P-1 POS — E2E Audit + Heal Loop spec
// Owner mandate: capture every page in cashier journey, never bail early.
// Each step ALWAYS screenshots before continuing — even on failure — so the
// audit can read the actual state (empty/broken/whatever).
//
// Pages captured (in order):
//   1. /login                     — login form
//   2. /admin/pos                 — main POS landing
//   3. POS with cash drawer open  — Caisse dialog
//   4. POS with cart filled       — 3+ items added
//   5. POS checkout / order type  — À emporter / Livraison
//   6. POS payment dialog         — Cash + TPE selections
//   7. POS payment success        — receipt screen
//   8. POS after order placed     — returns to landing
//
// Frozen-zone STRICT: this spec reads UI state only, never writes to:
//   - PaymentComponent.vue
//   - PosV5TrancheRow.vue
//   - public/js/pos-wizard.js

const { test, expect } = require('@playwright/test');
const { loginAsAdmin } = require('./helpers/login');
const fs = require('fs');
const path = require('path');

const SHOT_DIR = 'reports/test-e2e/wave-p-2026-05-20/pos';

let AxeBuilder = null;
try {
  AxeBuilder = require('@axe-core/playwright').default;
} catch (_e) {
  AxeBuilder = null;
}

test.use({ viewport: { width: 1440, height: 900 } });

test.describe.configure({ mode: 'serial' });

test.describe('Wave P-1 POS E2E Audit', () => {
  test.setTimeout(420_000);

  test('POS full cashier journey capture suite', async ({ page }) => {
    const consoleErrors = [];
    const networkErrors = [];
    const pageErrors = [];
    const findings = { console: [], network: [], pageErrors: [], steps: [] };

    page.on('console', (msg) => {
      if (msg.type() === 'error') {
        consoleErrors.push({ url: page.url(), text: msg.text() });
      }
    });
    page.on('pageerror', (err) => pageErrors.push({ url: page.url(), text: err.message }));
    page.on('response', (resp) => {
      const s = resp.status();
      const u = resp.url();
      if (s >= 400 && !u.includes('favicon')) {
        networkErrors.push({ status: s, url: u });
      }
    });

    const shot = async (n, name) => {
      try {
        const filePath = `${SHOT_DIR}/pos-${n}-${name}.png`;
        await page.screenshot({ path: filePath, fullPage: true });
        findings.steps.push({ step: n, name, file: filePath, ok: true });
      } catch (e) {
        findings.steps.push({ step: n, name, ok: false, error: String(e).slice(0, 200) });
      }
    };

    // ----- Step 1 — /login form (pre-auth) -----
    try {
      await page.goto('/login', { waitUntil: 'domcontentloaded' });
      await page.waitForTimeout(900);
    } catch (e) {
      findings.steps.push({ step: 1, name: 'login-goto', ok: false, error: String(e).slice(0, 200) });
    }
    await shot(1, 'login');

    // ----- Auth as admin -----
    try {
      await loginAsAdmin(page);
      await page.waitForTimeout(800);
    } catch (e) {
      findings.steps.push({ step: 'auth', name: 'login-admin', ok: false, error: String(e).slice(0, 200) });
    }

    // ----- Step 2 — /admin/pos landing (after auth) -----
    try {
      await page.goto('/admin/pos', { waitUntil: 'domcontentloaded' });
      await page.waitForTimeout(2500);
    } catch (e) {
      findings.steps.push({ step: 2, name: 'pos-goto', ok: false, error: String(e).slice(0, 200) });
    }
    await shot(2, 'landing');

    // ----- Step 3 — Cash drawer dialog (auto-open on /admin/pos mount) -----
    // Dialog auto-opens with "Aucune caisse ouverte" + opening amount input + chips (5€/10€/20€/50€).
    // Visible "Ouvrir la caisse" button submits the session.
    await shot(3, 'cash-drawer-dialog');

    try {
      // Type 50 in the visible opening amount input
      const openingInput = page.locator(
        '[data-testid="cash-session-opening-input"], input[placeholder*="50" i], input[type="text"]:visible, input[type="number"]:visible'
      ).first();
      if (await openingInput.isVisible({ timeout: 3000 }).catch(() => false)) {
        await openingInput.fill('50');
        await page.waitForTimeout(400);
      }
      // Submit via visible button "Ouvrir la caisse" (CTA in dialog)
      const submitBtn = page.locator(
        '[data-testid="cash-session-open-submit"], button:has-text("Ouvrir la caisse"), button:has-text("Ouvrir")'
      ).first();
      if (await submitBtn.isVisible({ timeout: 2000 }).catch(() => false)) {
        await submitBtn.click({ timeout: 4000 }).catch(() => {});
        await page.waitForTimeout(2500);
      }
      // Escape any lingering modal
      await page.keyboard.press('Escape').catch(() => {});
      await page.waitForTimeout(600);
    } catch (_e) {}
    await shot(3, 'cash-drawer-after-submit');

    // ----- Step 4 — Cart with 3+ items added -----
    // Vanilla POS wizard popup selectors (verified from public/js/pos-wizard.js):
    //   - Tile open: .pos-v5-tile / .pos-item-tile
    //   - Wizard root: #pos-wizard-root
    //   - Viande +: .viande-btn.plus[data-action="plus"]
    //   - Add to cart: [data-action="add-to-cart"]
    //   - Cancel: [data-action="cancel-wizard"]
    //
    // Strategy:
    //   1. Click tile → wizard popup opens
    //   2. If Viande section present → click first .viande-btn.plus (mandatory)
    //   3. Click [data-action="add-to-cart"]
    //   4. Repeat for 5 different products spanning featured + drinks/frites
    const tilesAdded = [];
    async function addItem(tileLocator) {
      const txt = (await tileLocator.innerText().catch(() => '')).slice(0, 60).replace(/\s+/g, ' ');
      try {
        await tileLocator.click({ timeout: 4000 });
      } catch (_e) {
        return { ok: false, txt, reason: 'tile-click-failed' };
      }
      await page.waitForTimeout(1100);

      // Wizard popup detection
      const wizardRoot = page.locator('#pos-wizard-root');
      const wizardOpen = await wizardRoot.isVisible({ timeout: 1500 }).catch(() => false);

      if (wizardOpen) {
        // Click first available .viande-btn.plus (not disabled) to satisfy 0/N rule
        const plusBtns = page.locator('#pos-wizard-root .viande-btn.plus:not(.disabled)');
        const plusCount = await plusBtns.count();
        if (plusCount > 0) {
          await plusBtns.first().click({ timeout: 2000 }).catch(() => {});
          await page.waitForTimeout(400);
        }
        // Click Ajouter au panier
        const addBtn = page.locator('#pos-wizard-root [data-action="add-to-cart"]').first();
        if (await addBtn.isVisible({ timeout: 1500 }).catch(() => false)) {
          await addBtn.click({ timeout: 3000 }).catch(() => {});
          await page.waitForTimeout(1100);
        } else {
          // Stuck wizard → cancel
          const cancelBtn = page.locator('#pos-wizard-root [data-action="cancel-wizard"]').first();
          if (await cancelBtn.isVisible({ timeout: 800 }).catch(() => false)) {
            await cancelBtn.click({ timeout: 1500 }).catch(() => {});
            await page.waitForTimeout(500);
          }
          return { ok: false, txt, reason: 'add-btn-not-visible' };
        }
        // Wait for wizard close
        await wizardRoot.waitFor({ state: 'hidden', timeout: 4000 }).catch(() => {});
        return { ok: true, txt, wizard: true };
      }

      // Non-wizard product (boisson/frites direct add) — tile click already added
      return { ok: true, txt, wizard: false };
    }

    try {
      // Wave 1: featured first page (max 3)
      const featuredTiles = page.locator('.pos-v5-tile, .pos-item-tile').filter({
        hasNot: page.locator('.pos-item-86-badge'),
      });
      const featCount = await featuredTiles.count();
      const maxFeatured = Math.min(featCount, 5);
      for (let i = 0; i < maxFeatured && tilesAdded.filter(x => x.ok).length < 3; i++) {
        const r = await addItem(featuredTiles.nth(i));
        tilesAdded.push(r);
      }

      // Wave 2: "Toutes" tab → reach Boissons + Frites (non-featured)
      const toutesTab = page.locator('button:has-text("Toutes les catégories"), button:has-text("Toutes"), [data-testid="pos-cat-all"]').first();
      if (await toutesTab.isVisible({ timeout: 1500 }).catch(() => false)) {
        await toutesTab.click({ timeout: 2000 }).catch(() => {});
        await page.waitForTimeout(1500);
        const allTiles = page.locator('.pos-v5-tile, .pos-item-tile').filter({
          hasNot: page.locator('.pos-item-86-badge'),
        });
        const allCount = await allTiles.count();
        const seenTxt = new Set(tilesAdded.map(x => x.txt));
        for (let j = 0; j < Math.min(allCount, 15) && tilesAdded.filter(x => x.ok).length < 5; j++) {
          const t = allTiles.nth(j);
          const tx = (await t.innerText().catch(() => '')).slice(0, 60).replace(/\s+/g, ' ');
          if (seenTxt.has(tx)) continue;
          seenTxt.add(tx);
          // Prefer Boissons/Frites by name
          if (!/boisson|frite|coca|eau|cola|jus/i.test(tx) && tilesAdded.filter(x => x.ok).length >= 3) continue;
          const r = await addItem(t);
          tilesAdded.push(r);
        }
      }
    } catch (e) {
      findings.steps.push({ step: 4, name: 'cart-fill-error', ok: false, error: String(e).slice(0, 200) });
    }
    findings.cartItems = tilesAdded;
    await shot(4, 'cart-filled');

    // ----- Step 5 — Checkout / order type selector -----
    try {
      // Click Payer button
      const payBtn = page.locator('[data-testid="pos-v5-pay"], button:has-text("Payer"), button:has-text("Encaisser")').first();
      if (await payBtn.isVisible({ timeout: 3_000 }).catch(() => false)) {
        await payBtn.click({ timeout: 5_000 });
        await page.waitForTimeout(1_500);
      }
    } catch (e) { /* still capture */ }
    await shot(5, 'checkout-order-type');

    // Try clicking À emporter (takeaway)
    try {
      const takeawayBtn = page.locator(
        'button:has-text("À emporter"), button:has-text("Emporter"), [data-testid*="takeaway" i]'
      ).first();
      if (await takeawayBtn.isVisible({ timeout: 2000 }).catch(() => false)) {
        await takeawayBtn.click({ timeout: 3000 }).catch(() => {});
        await page.waitForTimeout(800);
      }
    } catch (_e) {}
    await shot(5, 'checkout-takeaway-selected');

    // ----- Step 6 — Payment dialog (cash + TPE selections) -----
    try {
      const cashModeBtn = page.locator('[data-testid="pos-payment-mode-cash"], button:has-text("Espèces"), button:has-text("Cash")').first();
      if (await cashModeBtn.isVisible({ timeout: 3000 }).catch(() => false)) {
        await cashModeBtn.click({ timeout: 3000 }).catch(() => {});
        await page.waitForTimeout(800);
      }
    } catch (_e) {}
    await shot(6, 'payment-cash-selected');

    // Try TPE
    try {
      const cardModeBtn = page.locator('[data-testid="pos-payment-mode-card"], button:has-text("TPE"), button:has-text("Carte")').first();
      if (await cardModeBtn.isVisible({ timeout: 2000 }).catch(() => false)) {
        await cardModeBtn.click({ timeout: 2000 }).catch(() => {});
        await page.waitForTimeout(700);
      }
    } catch (_e) {}
    await shot(6, 'payment-tpe-selected');

    // Switch back to cash, type via input (NOT keypad which has €-chip collisions),
    // wait for rate-limit window, attempt confirm.
    try {
      await page.waitForTimeout(2500);
      const cashBtnB = page.locator('[data-testid="pos-payment-mode-cash"], button:has-text("Espèces"), button:has-text("Cash")').first();
      if (await cashBtnB.isVisible({ timeout: 2000 }).catch(() => false)) {
        await cashBtnB.click({ timeout: 2000 }).catch(() => {});
        await page.waitForTimeout(700);
      }
      // Type 50 via input element only (avoid keypad/chip collisions)
      const tenderedInput = page.locator(
        'input[type="number"], input[name*="tendered" i], input[name*="received" i], input[name*="montant" i]'
      ).first();
      if (await tenderedInput.isVisible({ timeout: 1500 }).catch(() => false)) {
        await tenderedInput.fill('50');
        await page.waitForTimeout(400);
      }
      // Wait before confirm to fully avoid rate-limit
      await page.waitForTimeout(3500);
      const confirmPay = page.locator(
        '[data-testid="pos-payment-confirm"], button:has-text("Confirmer & Imprimer"), button:has-text("Confirmer & imprimer"), button:has-text("Confirmer"), button:has-text("Valider")'
      ).first();
      if (await confirmPay.isVisible({ timeout: 2500 }).catch(() => false)) {
        await confirmPay.click({ timeout: 4000 }).catch(() => {});
        await page.waitForTimeout(5000); // give server time + receipt to render
      }
    } catch (_e) {}

    // ----- Step 7 — Payment success / receipt -----
    await shot(7, 'payment-success-or-receipt');

    // ----- Step 8 — After order placed (back to landing) -----
    try {
      // Dismiss any receipt modal
      const closeReceipt = page.locator(
        'button:has-text("Fermer"), button:has-text("OK"), button:has-text("Nouveau"), button:has-text("Close")'
      ).first();
      if (await closeReceipt.isVisible({ timeout: 2000 }).catch(() => false)) {
        await closeReceipt.click({ timeout: 2000 }).catch(() => {});
      }
      await page.waitForTimeout(1500);
    } catch (_e) {}
    await shot(8, 'after-order-landing');

    // ----- Axe accessibility scan on the landing -----
    if (AxeBuilder) {
      try {
        const axeResults = await new AxeBuilder({ page }).analyze();
        fs.writeFileSync(
          `${SHOT_DIR}/axe-results.json`,
          JSON.stringify({
            url: page.url(),
            violations: axeResults.violations.map((v) => ({
              id: v.id,
              impact: v.impact,
              help: v.help,
              nodes: v.nodes.length,
            })),
          }, null, 2)
        );
      } catch (e) {
        fs.writeFileSync(`${SHOT_DIR}/axe-error.txt`, String(e).slice(0, 600));
      }
    } else {
      fs.writeFileSync(`${SHOT_DIR}/axe-results.json`, JSON.stringify({ skipped: 'axe-core not installed' }));
    }

    // ----- Persist JSON report -----
    findings.console = consoleErrors;
    findings.network = networkErrors;
    findings.pageErrors = pageErrors;
    fs.writeFileSync(`${SHOT_DIR}/report.json`, JSON.stringify(findings, null, 2));

    // Soft assertion — ensure at least login + landing captured
    expect(findings.steps.find((s) => s.step === 1 && s.ok)).toBeTruthy();
    expect(findings.steps.find((s) => s.step === 2 && s.ok)).toBeTruthy();
  });
});
