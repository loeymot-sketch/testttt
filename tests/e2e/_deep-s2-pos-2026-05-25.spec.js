// GSTACK AGENT S2 POS Caisse — deep test post-restore page-by-page capture
// (2026-05-25). Adversarial post-DB-restore visual + technical + sync audit.
//
// Mission (from agent brief):
//   Login admin (admin@lecayenne.fr / 123456) via helper login.js pattern
//   (#formEmail / #formPassword / Connexion button). Navigate POS surface
//   exhaustively. Minimum 12 states captured (quartet PNG + DOM + console +
//   network per state). NO frozen-zone file mutation (PaymentComponent.vue,
//   PosV5TrancheRow.vue, pos-wizard.js/css). UI traversal + JS-evaluate audits.
//
// States:
//   S2-01 POS mount (login OK + catalog visible)
//   S2-02 Open caisse modal (Ouvrir la caisse + numpad)
//   S2-03 Caisse opened (fond 50€) → grid produits ready
//   S2-04 Click category (Burgers) → products filtered
//   S2-05 Click a product → wizard popup or add-to-cart
//   S2-06 Compose (sauces/variations) — may be no-op if no composer
//   S2-07 Add to cart (right panel shows item)
//   S2-08 Add 3+ items to cart (total computed)
//   S2-09 Click Encaisser → PaymentComponent or PosCounterCollectModal
//   S2-10 Select CASH mode → numpad
//   S2-11 Empty notifications panels (Prêt à livrer + À encaisser borne)
//   S2-12 Click Suivi commandes → tracker
//
// Constraints:
//   - Frozen-zones §7 CLAUDE.md : PaymentComponent.vue, PosV5TrancheRow.vue,
//     pos-wizard.js + .css. UI traversal only. Inspect, no edit.
//   - Wave Polish Final 2026-05-21: SSOT modal PosCounterCollectModal expected
//     (X1). Capture which renders.
//   - Catalog post-restore claim: 11 categories + 59 items + IBA 100%. Verify
//     via /api/admin/item?surface=pos directly + Vuex store + DOM tabs.
//   - Output dir : tests/e2e/__screenshots__/deep-S2-pos/
//   - Findings JSON : reports/test-e2e/post-restore-deep-2026-05-25/round-1/
//                     S2-pos-findings.json (writer is the agent — not the spec)

const { test, expect } = require('@playwright/test');
const path = require('path');
const fs = require('fs');
const { loginAsAdmin } = require('./helpers/login');
const { attachMegaAuditRecorder } = require('./helpers/mega-audit-snap');

const SCREENSHOT_DIR = path.resolve(__dirname, '__screenshots__/deep-S2-pos');
const SETTLE_MS = 2500;

async function snapFullPageSidecar(page, name) {
  const base = path.join(SCREENSHOT_DIR, name);
  try {
    await page.screenshot({ path: `${base}.full.png`, fullPage: true });
  } catch (_e) { /* tolerate */ }
}

test.describe('Deep S2 POS — post-restore page-by-page capture 2026-05-25', () => {
  test.setTimeout(420_000); // 7 min — generous.

  test.beforeAll(async () => {
    fs.mkdirSync(SCREENSHOT_DIR, { recursive: true });
  });

  test('S2 POS — capture quartet per state', async ({ page }) => {
    // Desktop POS viewport — caissier station 1366+
    await page.setViewportSize({ width: 1440, height: 900 });

    const recorder = attachMegaAuditRecorder(page, SCREENSHOT_DIR);
    const snap = recorder.snap;

    // ------------------------------------------------------------------------
    // Login admin and land on /admin/pos
    // ------------------------------------------------------------------------
    await loginAsAdmin(page); // E2E_ADMIN_USER default admin@lecayenne.fr / 123456
    // loginAsAdmin lands on /admin or /admin/dashboard. Push to POS explicitly.
    await page.goto('/admin/pos', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(SETTLE_MS);

    // ------------------------------------------------------------------------
    // S2-01 — POS mounted, catalog visible. Caisse modal may auto-open if no
    //         session is active — capture whatever state initially renders.
    // ------------------------------------------------------------------------
    await snap('S2-01-pos-mount');
    await snapFullPageSidecar(page, 'S2-01-pos-mount');

    // Sanity check: are we on POS, do we see catalog?
    const mountStats = await page.evaluate(() => {
      const url = window.location.pathname;
      const tabs = document.querySelectorAll('[role="tab"]');
      const itemButtons = document.querySelectorAll('button[aria-label^="Ajouter"]');
      const dialog = document.querySelector('[role="dialog"]');
      return {
        url,
        on_pos: /\/admin\/pos/.test(url),
        visible_category_tabs: tabs.length,
        category_names: Array.from(tabs).map((t) => t.innerText.trim().replace(/\s+/g, ' ')).filter(Boolean),
        visible_product_buttons: itemButtons.length,
        product_names: Array.from(itemButtons).map((b) => (b.getAttribute('aria-label') || '').replace(/^Ajouter /, '')),
        has_caisse_modal: !!dialog && /Caisse|caisse|Ouvrir/i.test(dialog.innerText || ''),
      };
    });
    fs.writeFileSync(
      path.join(SCREENSHOT_DIR, '_s2-01-mount-stats.json'),
      JSON.stringify(mountStats, null, 2),
    );

    // ------------------------------------------------------------------------
    // S2-02 — Open caisse modal: ensure visible. If auto-opened, capture as is.
    //         If not, click "Caisse" button to open it.
    // ------------------------------------------------------------------------
    if (!mountStats.has_caisse_modal) {
      const caisseBtn = page.getByRole('button', { name: /^\s*Caisse\s*$/i }).first();
      if (await caisseBtn.count()) {
        await caisseBtn.click({ timeout: 3000 }).catch(() => {});
        await page.waitForTimeout(1500);
      }
    }
    await snap('S2-02-open-caisse-modal');
    await snapFullPageSidecar(page, 'S2-02-open-caisse-modal');

    const modalStats = await page.evaluate(() => {
      const dialog = document.querySelector('[role="dialog"]');
      if (!dialog) return { has_dialog: false };
      const numpadBtns = Array.from(dialog.querySelectorAll('button')).map((b) => b.innerText.trim()).filter(Boolean);
      return {
        has_dialog: true,
        title: dialog.querySelector('h2')?.innerText || null,
        numpad_buttons: numpadBtns,
      };
    });
    fs.writeFileSync(
      path.join(SCREENSHOT_DIR, '_s2-02-modal-stats.json'),
      JSON.stringify(modalStats, null, 2),
    );

    // ------------------------------------------------------------------------
    // S2-03 — Caisse opened with fond 50€ → grid produits ready
    //   Click "Ouvrir la caisse" submit button to commit; if no modal, skip.
    // ------------------------------------------------------------------------
    if (modalStats.has_dialog) {
      const submit = page.locator('[role="dialog"] button:has-text("Ouvrir la caisse")').first();
      if (await submit.count()) {
        await submit.click({ timeout: 3000 }).catch(() => {});
        await page.waitForTimeout(SETTLE_MS);
      } else {
        // Already opened? Close modal via Annuler/Fermer.
        const cancel = page.locator('[role="dialog"] button:has-text("Annuler"), [role="dialog"] button:has-text("Fermer")').first();
        if (await cancel.count()) {
          await cancel.click({ timeout: 2000 }).catch(() => {});
          await page.waitForTimeout(1500);
        }
      }
    }
    await snap('S2-03-caisse-opened-grid');
    await snapFullPageSidecar(page, 'S2-03-caisse-opened-grid');

    // ------------------------------------------------------------------------
    // S2-04 — Click Burgers category → products filtered
    // ------------------------------------------------------------------------
    const burgerTab = page.getByRole('tab', { name: /Burger/i }).first();
    if (await burgerTab.count()) {
      await burgerTab.click({ timeout: 3000 }).catch(() => {});
      await page.waitForTimeout(1500);
    }
    await snap('S2-04-burgers-category');
    await snapFullPageSidecar(page, 'S2-04-burgers-category');

    const burgerProducts = await page.evaluate(() => {
      const btns = document.querySelectorAll('button[aria-label^="Ajouter"]');
      return Array.from(btns).map((b) => (b.getAttribute('aria-label') || '').replace(/^Ajouter /, ''));
    });
    fs.writeFileSync(
      path.join(SCREENSHOT_DIR, '_s2-04-burger-products.json'),
      JSON.stringify({ products: burgerProducts, count: burgerProducts.length }, null, 2),
    );

    // ------------------------------------------------------------------------
    // S2-05 — Click a product (first burger or first product) → wizard popup
    //         or direct add-to-cart
    // ------------------------------------------------------------------------
    const firstProductBtn = page.locator('button[aria-label^="Ajouter"]').first();
    if (await firstProductBtn.count()) {
      await firstProductBtn.scrollIntoViewIfNeeded().catch(() => {});
      await firstProductBtn.click({ timeout: 3000 }).catch(() => {});
      await page.waitForTimeout(SETTLE_MS);
    }
    await snap('S2-05-click-product');
    await snapFullPageSidecar(page, 'S2-05-click-product');

    const wizardCheck = await page.evaluate(() => {
      const wizardEl = document.querySelector('.pos-wizard, [data-testid="pos-wizard"], .wizard-modal, .modal-wizard');
      const dialog = document.querySelector('[role="dialog"]');
      return {
        wizard_present: !!wizardEl,
        dialog_present: !!dialog,
        dialog_title: dialog?.querySelector('h2, h3, h4')?.innerText || null,
      };
    });
    fs.writeFileSync(
      path.join(SCREENSHOT_DIR, '_s2-05-wizard-check.json'),
      JSON.stringify(wizardCheck, null, 2),
    );

    // ------------------------------------------------------------------------
    // S2-06 — Compose with sauces/variations (if wizard open). Or no-op.
    //   We just try to select first available option of every step + click next.
    //   Frozen-zone: pos-wizard.js handles this UI — we ONLY click visible buttons.
    // ------------------------------------------------------------------------
    if (wizardCheck.wizard_present || wizardCheck.dialog_present) {
      // Multiple passes: pick first available option in each step.
      for (let i = 0; i < 6; i++) {
        const optionClicked = await page.evaluate(() => {
          // Try multiple selectors for wizard option cards
          const options = document.querySelectorAll(
            '.pos-wizard__option:not([disabled]), [data-testid="wizard-option"]:not([disabled]), .wizard-option:not([disabled]), .option-card:not([disabled])'
          );
          if (options.length) {
            options[0].click();
            return true;
          }
          // Fallback: next button
          const nextBtn = Array.from(document.querySelectorAll('button')).find(
            (b) => /Suivant|Next|Continuer|Valider/i.test(b.innerText || '') && !b.disabled
          );
          if (nextBtn) { nextBtn.click(); return 'next'; }
          return false;
        });
        await page.waitForTimeout(800);
        if (!optionClicked) break;
      }
    }
    await snap('S2-06-compose-sauces');
    await snapFullPageSidecar(page, 'S2-06-compose-sauces');

    // ------------------------------------------------------------------------
    // S2-07 — Add to cart (commit wizard or confirm direct add)
    // ------------------------------------------------------------------------
    // Click "Ajouter au panier" / "Valider" / "Confirmer" final
    const addCartBtn = page.locator(
      'button:has-text("Ajouter au panier"), button:has-text("Confirmer"), button:has-text("Valider")'
    ).last();
    if (await addCartBtn.count()) {
      await addCartBtn.click({ timeout: 3000 }).catch(() => {});
      await page.waitForTimeout(SETTLE_MS);
    }
    await snap('S2-07-cart-after-add');
    await snapFullPageSidecar(page, 'S2-07-cart-after-add');

    const cartState = await page.evaluate(() => {
      // Probe Vuex pos cart module
      const app = document.getElementById('app');
      const vm = app && app.__vue_app__;
      const store = vm && vm.config?.globalProperties?.$store;
      const posCart = store?.state?.posCart || store?.state?.pos || null;
      const items = posCart?.items || posCart?.cart?.items || [];
      // Also probe DOM cart panel
      const cartPanel = document.querySelector('[aria-label="Panier commande"], [role="region"][aria-label*="anier"]');
      const cartTotal = document.querySelector('[role="status"]')?.innerText || null;
      return {
        store_items: Array.isArray(items) ? items.length : null,
        store_items_data: Array.isArray(items) ? items.map((i) => ({ id: i.item_id || i.id, qty: i.quantity, total: i.total })) : null,
        cart_dom_text: cartPanel?.innerText?.substring(0, 600) || null,
        cart_total_text: cartTotal,
      };
    });
    fs.writeFileSync(
      path.join(SCREENSHOT_DIR, '_s2-07-cart-state.json'),
      JSON.stringify(cartState, null, 2),
    );

    // ------------------------------------------------------------------------
    // S2-08 — Add 3+ items to cart (total computed). Click 3 more product
    //   buttons in sequence, dismissing any wizard popups via Annuler/Fermer
    //   to keep the flow fast.
    // ------------------------------------------------------------------------
    for (let i = 0; i < 3; i++) {
      const btn = page.locator('button[aria-label^="Ajouter"]').nth(i + 1);
      if (await btn.count()) {
        await btn.click({ timeout: 3000 }).catch(() => {});
        await page.waitForTimeout(1200);
        // If wizard popped, dismiss with Annuler/Fermer
        const cancelBtn = page.locator(
          '.pos-wizard button:has-text("Annuler"), [role="dialog"] button:has-text("Fermer")'
        ).first();
        if (await cancelBtn.count()) {
          await cancelBtn.click({ timeout: 1500 }).catch(() => {});
          await page.waitForTimeout(800);
        }
      }
    }
    await snap('S2-08-cart-multi-items');
    await snapFullPageSidecar(page, 'S2-08-cart-multi-items');

    const multiCartState = await page.evaluate(() => {
      const app = document.getElementById('app');
      const vm = app && app.__vue_app__;
      const store = vm && vm.config?.globalProperties?.$store;
      const posCart = store?.state?.posCart || store?.state?.pos || null;
      const items = posCart?.items || posCart?.cart?.items || [];
      // Look up DOM total
      const totalEl = document.querySelector('[role="status"]');
      return {
        store_items_count: Array.isArray(items) ? items.length : null,
        dom_total_text: totalEl?.innerText?.substring(0, 300) || null,
      };
    });
    fs.writeFileSync(
      path.join(SCREENSHOT_DIR, '_s2-08-multi-cart-state.json'),
      JSON.stringify(multiCartState, null, 2),
    );

    // ------------------------------------------------------------------------
    // S2-09 — Click "Encaisser" → PaymentComponent or PosCounterCollectModal
    // ------------------------------------------------------------------------
    const encaisser = page.getByRole('button', { name: /Encaisser|Payer|Paiement/i }).first();
    if (await encaisser.count()) {
      await encaisser.click({ timeout: 3000 }).catch(() => {});
      await page.waitForTimeout(SETTLE_MS);
    }
    await snap('S2-09-encaisser-modal');
    await snapFullPageSidecar(page, 'S2-09-encaisser-modal');

    const paymentModalCheck = await page.evaluate(() => {
      // Both possible renders
      const payment = document.querySelector('[data-testid="payment-component"], .payment-component, .pos-payment, .payment-modal');
      const counterCollect = document.querySelector('[data-testid="pos-counter-collect-modal"], .pos-counter-collect-modal, .counter-collect-modal');
      const allModals = document.querySelectorAll('[role="dialog"]');
      return {
        payment_component_present: !!payment,
        counter_collect_modal_present: !!counterCollect,
        any_dialog: allModals.length,
        modal_texts: Array.from(allModals).map((m) => (m.innerText || '').substring(0, 300)),
      };
    });
    fs.writeFileSync(
      path.join(SCREENSHOT_DIR, '_s2-09-payment-modal-check.json'),
      JSON.stringify(paymentModalCheck, null, 2),
    );

    // ------------------------------------------------------------------------
    // S2-10 — Select CASH mode → numpad visible
    // ------------------------------------------------------------------------
    const cashModeBtn = page.locator(
      'button:has-text("Espèces"), button:has-text("Cash"), [data-testid="payment-cash"], [data-testid="payment-mode-cash"]'
    ).first();
    if (await cashModeBtn.count()) {
      await cashModeBtn.click({ timeout: 3000 }).catch(() => {});
      await page.waitForTimeout(1500);
    }
    await snap('S2-10-cash-mode-numpad');
    await snapFullPageSidecar(page, 'S2-10-cash-mode-numpad');

    // ------------------------------------------------------------------------
    // S2-11 — Empty notifications panels (Prêt à livrer + À encaisser borne)
    //   These should be VISIBLE on the POS main page (Wave Polish Q10). Close
    //   any payment modal first, then screenshot the main panels.
    // ------------------------------------------------------------------------
    // Close modal — try multiple paths.
    await page.keyboard.press('Escape').catch(() => {});
    await page.waitForTimeout(800);
    const closeBtns = page.locator('[role="dialog"] button:has-text("Annuler"), [role="dialog"] button:has-text("Fermer"), [role="dialog"] [aria-label="Fermer"]');
    const closeCount = await closeBtns.count();
    for (let i = 0; i < closeCount; i++) {
      try { await closeBtns.nth(i).click({ timeout: 1000 }); await page.waitForTimeout(400); } catch (_e) { /* ignore */ }
    }
    await page.waitForTimeout(SETTLE_MS);
    await snap('S2-11-notifications-panels');
    await snapFullPageSidecar(page, 'S2-11-notifications-panels');

    const notifStats = await page.evaluate(() => {
      const ready = document.querySelector('[role="region"][aria-label*="Prêt"]');
      const cash = document.querySelector('[role="region"][aria-label*="À encaisser"], [role="region"][aria-label*="encaisser borne"]');
      return {
        ready_region_present: !!ready,
        ready_text: ready?.innerText?.substring(0, 400) || null,
        cash_collect_region_present: !!cash,
        cash_collect_text: cash?.innerText?.substring(0, 400) || null,
      };
    });
    fs.writeFileSync(
      path.join(SCREENSHOT_DIR, '_s2-11-notif-stats.json'),
      JSON.stringify(notifStats, null, 2),
    );

    // ------------------------------------------------------------------------
    // S2-12 — Click "Suivi commandes" → tracker
    // ------------------------------------------------------------------------
    const suiviBtn = page.locator('[aria-label*="Suivi"]:not([role="dialog"]), button:has-text("Suivi"), :has-text("Suivi commandes")').first();
    if (await suiviBtn.count()) {
      await suiviBtn.click({ timeout: 3000 }).catch(() => {});
      await page.waitForTimeout(SETTLE_MS);
    }
    await snap('S2-12-suivi-commandes');
    await snapFullPageSidecar(page, 'S2-12-suivi-commandes');

    const finalState = await page.evaluate(() => {
      const url = window.location.pathname;
      return { final_url: url };
    });
    fs.writeFileSync(
      path.join(SCREENSHOT_DIR, '_session-rollup.json'),
      JSON.stringify({
        final_state: finalState,
        mount_stats: mountStats,
        captured_at: new Date().toISOString(),
      }, null, 2),
    );
  });
});
