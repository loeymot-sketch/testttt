// FoodKing E2E — POS Page-By-Page audit (GOAL pageby mission 2026-05-18)
// Specialist : E2E Agent POS (round 1, pages 1-10)
//
// Strategy : one flow-based spec covers pages 1-8 (login → grid → wizard → cart →
// payment cash/card/split → parked). A second test covers pages 9-10 (orders list,
// status change). Each visual state emits the mega-audit 4-file quartet
// (PNG + DOM + console + network) for the adversarial supervisor reviewer.
//
// Frozen-zone discipline (CLAUDE.md §7) :
//   - public/js/pos-wizard.js (~296 KB Vanilla JS) — read-only
//   - public/css/pos-wizard.css                    — read-only
//   - resources/views/admin-pos-v4.blade.php       — read-only
// Defects whose only fix would touch those files are tagged in the report as
// `frozen_zone_lock_required` and NOT healed in this loop.
//
// Auth :
//   - pos@lecayenne.fr / 123456 (branch_id=1, POS Operator) — used pages 2-10
//   - admin@lecayenne.fr / 123456 (branch_id=1) — used page 1 attest
//
// Output :
//   tests/e2e/__screenshots__/goal-pageby-pos-2026-05-18/<state>.{png,dom.html,console.json,network.json}

const { test, expect } = require('@playwright/test');
const path = require('path');
const fs = require('fs');
const { login, loginAsPosOperator, loginAsAdmin } = require('./helpers/login');
const { attachMegaAuditRecorder } = require('./helpers/mega-audit-snap');

const SCREENSHOT_DIR = path.resolve(__dirname, '__screenshots__/goal-pageby-pos-2026-05-18');
fs.mkdirSync(SCREENSHOT_DIR, { recursive: true });

// Per-page severity slot. Filled in the test, dumped at the end via wave-findings JSON.
const findings = [];

function pushFinding(id, page_label, category, severity, evidence, fix_hint, frozenZone = false) {
  findings.push({
    id,
    state_artifact: `goal-pageby-pos-2026-05-18/${page_label}`,
    page: page_label,
    category: frozenZone ? `frozen_zone_lock_required:${category}` : category,
    severity,
    evidence,
    fix_hint,
  });
}

test.describe.configure({ mode: 'serial' });

test.describe('POS Page-By-Page — pages 1-8 (login → payment → parked)', () => {
  test.setTimeout(360_000);

  test('flow', async ({ page }) => {
    const { snap, dispose } = attachMegaAuditRecorder(page, SCREENSHOT_DIR);
    try {
      // -----------------------------------------------------------------
      // PAGE 1 — /login (visual attest)
      // -----------------------------------------------------------------
      await page.goto('/login', { waitUntil: 'domcontentloaded' });
      await expect(page.locator('#formEmail')).toBeVisible({ timeout: 20_000 });
      // Wait for branding to settle (logo + button label rendered)
      await page.waitForTimeout(800);
      await snap('01-login-idle');

      const loginHtml = await page.content();
      // Smoke-check: i18n button label rendered (Connexion fr / Login en) — not a raw key
      const hasButtonLabel = /Connexion|Login/i.test(loginHtml);
      if (!hasButtonLabel) {
        pushFinding(
          'PG1-001',
          'page-1-login',
          'i18n_leak',
          'P1',
          'Login submit button label missing or raw key visible. Body did not match /Connexion|Login/i.',
          'Investigate resources/js/languages/{fr,en}.json keys auth.login / auth.signin and LoginComponent template binding.'
        );
      }

      // -----------------------------------------------------------------
      // PAGE 2 — /admin/pos main grid (POS Operator)
      // -----------------------------------------------------------------
      await loginAsPosOperator(page);
      await page.waitForTimeout(2_500);

      // Dismiss cash session dialog auto-open if simulation_hardware exposes it
      const cashClose1 = page.locator('[data-testid="cash-session-close"]').first();
      if (await cashClose1.isVisible({ timeout: 2_000 }).catch(() => false)) {
        await cashClose1.click({ timeout: 3_000 });
        await page.waitForTimeout(600);
      }
      // If open form is present, fill and submit to get past it
      const openForm = page.locator('[data-testid="cash-session-open-form"]').first();
      if (await openForm.isVisible({ timeout: 2_000 }).catch(() => false)) {
        const openingInput = page.locator('[data-testid="cash-session-opening-input"]').first();
        if (await openingInput.isVisible({ timeout: 2_000 }).catch(() => false)) {
          await openingInput.fill('100');
        }
        const submitBtn = page.locator('[data-testid="cash-session-open-submit"]').first();
        if (await submitBtn.isVisible({ timeout: 2_000 }).catch(() => false)) {
          await submitBtn.click({ timeout: 3_000 });
          await page.waitForTimeout(1_500);
        }
        const closeAfterOpen = page.locator('[data-testid="cash-session-close"]').first();
        if (await closeAfterOpen.isVisible({ timeout: 2_000 }).catch(() => false)) {
          await closeAfterOpen.click({ timeout: 2_000 });
          await page.waitForTimeout(500);
        }
      }

      await snap('02-pos-grid-default');

      // Try toggle Toutes to see all categories
      const toggleAll = page.locator('[data-testid="pos-category-toggle-all"]').first();
      const toggleVisible = await toggleAll.isVisible({ timeout: 3_000 }).catch(() => false);
      if (toggleVisible) {
        await toggleAll.click({ timeout: 3_000 });
        await page.waitForTimeout(800);
        await snap('02b-pos-grid-after-toutes');
      }

      // Verify product tile exists and is clickable
      const tiles = page.locator('.pos-v5-tile, .pos-item-tile').filter({
        hasNot: page.locator('.pos-item-86-badge, .pos-v5-tile__overlay'),
      });
      const tilesCount = await tiles.count();
      if (tilesCount === 0) {
        pushFinding(
          'PG2-001',
          'page-2-pos-grid',
          'empty_state_quality',
          'P0',
          'No product tiles rendered on /admin/pos main grid (count=0). Catalog seed missing or tile selector drift.',
          'Verify DB items.status=5 catalog and PosComponent.vue tile classes (.pos-v5-tile / .pos-item-tile).'
        );
      }

      // -----------------------------------------------------------------
      // PAGE 3 — POS Wizard popup (FROZEN — visual attest only)
      // Open Chicken Burger to capture the composed wizard (viande+crudités+sauce
      // visible), then close cleanly via Annuler. The Viande required gate is
      // PRODUCT-CORRECT behaviour, not a test defect — we just attest the
      // wizard renders all sections per Sub 1.1 acceptance.
      // -----------------------------------------------------------------
      let burgerTile = page.locator('.pos-v5-tile, .pos-item-tile').filter({
        has: page.locator('text=/Chicken Burger/i'),
      }).first();
      if (await burgerTile.isVisible({ timeout: 4_000 }).catch(() => false)) {
        await burgerTile.click({ timeout: 5_000 });
        await page.waitForTimeout(1_800);
        await snap('03-pos-wizard-opened');

        // DOM-validate the wizard sections are present (no need to click qty)
        const wizardHtml = await page.content();
        const sectionsPresent = {
          viande: /class="wizard-section viande-section"/.test(wizardHtml),
          crudites: /crudites-section/.test(wizardHtml),
          sauce: /sauce-section/.test(wizardHtml),
        };
        const missing = Object.entries(sectionsPresent).filter(([_, v]) => !v).map(([k]) => k);
        if (missing.length > 0) {
          pushFinding(
            'PG3-001',
            'page-3-pos-wizard',
            'empty_state_quality',
            'P1',
            `Wizard opened for Chicken Burger but missing required sections : ${missing.join(', ')}. Sub 1.1 contract says viande+crudites+sauce+supplements.`,
            'Verify wizard_profiles + wizard_steps DB rows for item Chicken Burger (id from earlier query). Cf 2026-05-18 AlignProfile85ChickenBurgerSeeder pattern.'
          );
        }
        // Close wizard cleanly via Annuler (FROZEN CTA)
        const cancelBtn = page.locator('.wizard-btn-cancel').first();
        if (await cancelBtn.isVisible({ timeout: 2_000 }).catch(() => false)) {
          await cancelBtn.click({ timeout: 3_000, force: true }).catch(() => {});
          await page.waitForTimeout(800);
        }
      } else {
        pushFinding(
          'PG3-002',
          'page-3-pos-wizard',
          'empty_state_quality',
          'P0',
          'Chicken Burger tile not found in POS grid. Catalog seed missing or tile filter regression.',
          'Verify items.name "Chicken Burger" status=5 + PosComponent.vue tile binding.'
        );
      }

      // -----------------------------------------------------------------
      // PAGE 4 — Cart after add — use a SIMPLE-template item (no wizard gate)
      // "Boisson Seule" or "Frites Seules" auto-add without wizard popup
      // -----------------------------------------------------------------
      const simpleTile = page.locator('.pos-v5-tile, .pos-item-tile').filter({
        has: page.locator('text=/Boisson Seule|Frites Seules/i'),
      }).first();
      if (await simpleTile.isVisible({ timeout: 3_000 }).catch(() => false)) {
        await simpleTile.click({ timeout: 5_000 });
        await page.waitForTimeout(1_500);
        // If a wizard popup opened anyway (variation modal for a "simple" item),
        // try the .wizard-btn-cart CTA, else proceed.
        const wizardOpen = await page.locator('.pos-v4-item-wizard-modal.active')
          .first().isVisible({ timeout: 1_500 }).catch(() => false);
        if (wizardOpen) {
          const addCta = page.locator('.wizard-btn-cart').first();
          if (await addCta.isVisible({ timeout: 2_000 }).catch(() => false)) {
            await addCta.click({ timeout: 3_000, force: true }).catch(() => {});
            await page.waitForTimeout(1_200);
          }
        }
      } else {
        pushFinding(
          'PG4-002',
          'page-4-pos-cart',
          'empty_state_quality',
          'P1',
          'No simple-template tile (Boisson Seule / Frites Seules) found to seed cart. POS payment flow cannot proceed.',
          'Verify items seeder for Boisson Seule + Frites Seules (status=5, wizard_template=simple).'
        );
      }
      await snap('04-pos-cart-after-add');

      // Numeric integrity — capture grand total + cart line subtotals
      const grandTotal = page.locator('[data-testid="pos-grand-total"], .pos-v5-grand-total, .pos-grand-total').first();
      const grandTotalVisible = await grandTotal.isVisible({ timeout: 3_000 }).catch(() => false);
      if (!grandTotalVisible) {
        // Grand total may render in a different anchor in V5 — best-effort capture body text
        const bodyText = await page.locator('body').innerText();
        const totalMatch = bodyText.match(/([0-9]+[.,][0-9]{2})\s*[€$]/);
        if (!totalMatch) {
          pushFinding(
            'PG4-001',
            'page-4-pos-cart',
            'empty_state_quality',
            'P2',
            'Post-add cart neither shows pos-grand-total testid nor a numeric total. Manual sweep needed.',
            'Inspect PosComponent.vue grand-total binding; add data-testid="pos-grand-total" for adversarial reproducibility.',
            true // frozen-zone — would need pos-wizard.js or admin-pos-v4.blade.php touch
          );
        }
      }

      // -----------------------------------------------------------------
      // PAGE 5 — Payment modal CASH (overpay → change)
      // -----------------------------------------------------------------
      const payBtn = page.locator('[data-testid="pos-v5-pay"]').first();
      const payBtnVisible = await payBtn.isVisible({ timeout: 5_000 }).catch(() => false);
      if (!payBtnVisible) {
        pushFinding(
          'PG5-001',
          'page-5-pos-payment-cash',
          'silent_error',
          'P1',
          'Pay button [data-testid="pos-v5-pay"] not visible after adding wizard item. Cart may be empty (wizard add silently failed).',
          'Check console for add-to-cart error or PosController quote 422; verify wizard profile DB alignment.',
        );
        await snap('05-pos-payment-NO-PAY-BTN');
      } else {
        await payBtn.click({ timeout: 5_000 });
        await page.waitForTimeout(1_200);
        await snap('05-pos-payment-modal-opened');

        const cashMode = page.locator('[data-testid="pos-payment-mode-cash"]').first();
        if (await cashMode.isVisible({ timeout: 5_000 }).catch(() => false)) {
          await cashMode.click({ timeout: 3_000 });
          await page.waitForTimeout(500);
          await snap('05b-pos-payment-cash-selected');

          // Type an overpay (50€ → expect change displayed)
          const tendered = page.locator('input[type="number"], input[name*="tendered" i], input[name*="received" i], .pos-v5-payment-input').first();
          if (await tendered.isVisible({ timeout: 3_000 }).catch(() => false)) {
            await tendered.fill('50');
            await page.waitForTimeout(500);
            await snap('05c-pos-payment-cash-overpay-50');

            // Look for "monnaie" / "change" / "rendu" indicator
            const bodyText = await page.locator('body').innerText();
            const hasChange = /(monnaie|change|rendu|à\s*rendre)/i.test(bodyText);
            if (!hasChange) {
              pushFinding(
                'PG5-002',
                'page-5-pos-payment-cash',
                'numeric_integrity',
                'P0',
                'Cash overpay 50€ entered but no "monnaie / change / à rendre" indicator visible in modal body. Change calculation may be silent.',
                'Verify PaymentComponent.vue change computation + i18n key pos.payment.change_due / payment.change_label.'
              );
            }
          } else {
            pushFinding(
              'PG5-003',
              'page-5-pos-payment-cash',
              'empty_state_quality',
              'P1',
              'Cash mode active but no tendered input visible. Numpad-only entry path may be required.',
              'Inspect PaymentComponent.vue cash branch; ensure input[type=number] or testid-tagged tendered field.',
            );
          }
        } else {
          pushFinding(
            'PG5-004',
            'page-5-pos-payment-cash',
            'empty_state_quality',
            'P0',
            'Payment modal opened but [data-testid="pos-payment-mode-cash"] mode toggle not visible.',
            'Inspect PaymentComponent.vue mode buttons + ensure data-testid stays consistent post-V5 refactor.'
          );
        }
      }

      // -----------------------------------------------------------------
      // PAGE 6 — Payment modal CARD (capture screen, mode-card click)
      // -----------------------------------------------------------------
      const cardMode = page.locator('[data-testid="pos-payment-mode-card"]').first();
      if (await cardMode.isVisible({ timeout: 3_000 }).catch(() => false)) {
        await cardMode.click({ timeout: 3_000 });
        await page.waitForTimeout(700);
        await snap('06-pos-payment-card-selected');

        // Card flow under simulation_hardware should not actually call Stripe — verify by visual + no 5xx
        const bodyText = await page.locator('body').innerText();
        if (!/Carte|Card|TPE/i.test(bodyText)) {
          pushFinding(
            'PG6-001',
            'page-6-pos-payment-card',
            'i18n_leak',
            'P2',
            'Card mode selected but body does not contain Carte/Card/TPE label. Wording may be inconsistent or i18n missing.',
            'Audit PaymentComponent.vue card branch + resources/js/languages/{fr,en}.json pos.payment.card_*.'
          );
        }
      } else {
        pushFinding(
          'PG6-002',
          'page-6-pos-payment-card',
          'empty_state_quality',
          'P1',
          '[data-testid="pos-payment-mode-card"] not visible. Card payment mode may be hidden under simulation_hardware or feature flag.',
          'Inspect PaymentComponent.vue mode list + config/pos.php POS_SIMULATION_HARDWARE branch.'
        );
        await snap('06-pos-payment-card-NOT-VISIBLE');
      }

      // -----------------------------------------------------------------
      // PAGE 7 — Payment modal SPLIT (equal / mixed)
      // -----------------------------------------------------------------
      const splitMode = page.locator('[data-testid="pos-payment-mode-multi"], [data-testid="pos-payment-mode-split"]').first();
      if (await splitMode.isVisible({ timeout: 3_000 }).catch(() => false)) {
        await splitMode.click({ timeout: 3_000 });
        await page.waitForTimeout(800);
        await snap('07-pos-payment-split-opened');

        // Try the Equal-split helper if visible
        const splitInput = page.locator('.pos-v5-split-divider__input').first();
        const splitBtn = page.locator('.pos-v5-split-divider__btn').first();
        if (
          (await splitInput.isVisible({ timeout: 2_500 }).catch(() => false))
          && (await splitBtn.isVisible({ timeout: 2_000 }).catch(() => false))
        ) {
          await splitInput.fill('2');
          await splitBtn.click({ timeout: 3_000 });
          await page.waitForTimeout(700);
          await snap('07b-pos-payment-split-equal-2');
        }
      } else {
        pushFinding(
          'PG7-001',
          'page-7-pos-payment-split',
          'empty_state_quality',
          'P1',
          'Split / Multi-tender mode toggle not visible. CV1-POS-SPLIT-PAYMENT-001 feature may be hidden.',
          'Verify PaymentComponent.vue split mode unlock condition + config/pos.php split_tender_enabled.'
        );
        await snap('07-pos-payment-split-NOT-VISIBLE');
      }

      // Close payment modal if we can find a close handle (don't actually confirm)
      const payClose = page.locator('.pos-v5-payment-close, [data-testid="pos-payment-close"]').first();
      if (await payClose.isVisible({ timeout: 2_500 }).catch(() => false)) {
        await payClose.click({ timeout: 2_000 });
        await page.waitForTimeout(700);
      }

      // -----------------------------------------------------------------
      // PAGE 8 — Parked orders (park current → resume → re-pay would land back in modal)
      // -----------------------------------------------------------------
      // Look for a "Parquer" / "Park" / "Mettre en attente" affordance
      const parkBtn = page.locator('button').filter({
        hasText: /parquer|park|mettre en attente|mise en attente/i,
      }).first();
      if (await parkBtn.isVisible({ timeout: 3_000 }).catch(() => false)) {
        await parkBtn.click({ timeout: 4_000, force: true }).catch(() => {});
        await page.waitForTimeout(1_200);
        await snap('08-pos-parked-after-park');

        // Try to find the parked list and resume the parked order
        const parkedList = page.locator('[data-testid^="pos-parked-"], .pos-parked-row, .pos-parked-list').first();
        if (await parkedList.isVisible({ timeout: 3_000 }).catch(() => false)) {
          await snap('08b-pos-parked-list-visible');
        } else {
          pushFinding(
            'PG8-001',
            'page-8-pos-parked',
            'empty_state_quality',
            'P2',
            'Order parked but parked-orders list not visible (no testid or row class match). Listing may rely on different anchor.',
            'Inspect PosParkedOrderComponent.vue rendering + add data-testid="pos-parked-list" for adversarial reproducibility.'
          );
        }
      } else {
        pushFinding(
          'PG8-002',
          'page-8-pos-parked',
          'empty_state_quality',
          'P2',
          'No park/parquer button visible after composing cart. Feature may be hidden, behind a sub-menu, or require non-empty cart.',
          'Audit POS shell for park-order CTA; verify it surfaces when cart.length > 0.',
          true // frozen-zone — pos-wizard or admin-pos-v4 button surface
        );
        await snap('08-pos-parked-NO-PARK-BTN');
      }

    } finally {
      dispose();
    }
  });
});

test.describe('POS Page-By-Page — pages 9-10 (orders list + status change)', () => {
  test.setTimeout(180_000);

  test('flow', async ({ page }) => {
    const { snap, dispose } = attachMegaAuditRecorder(page, SCREENSHOT_DIR);
    try {
      // Auth as POS Operator (branch_id=1) — Admin (branch_id=0 in some deploys) would be 403 on parked
      await loginAsPosOperator(page);
      await page.waitForTimeout(1_500);

      // -----------------------------------------------------------------
      // PAGE 9 — /admin/pos-orders order list (SPA route confirmed in resources/js/router/modules/posOrderRoutes.js:13)
      // -----------------------------------------------------------------
      await page.goto('/admin/pos-orders', { waitUntil: 'domcontentloaded' });
      await page.waitForTimeout(2_500);
      await snap('09-pos-orders-list');

      const ordersHtml = await page.content();
      // Light smoke — orders list page either renders a table or an explicit empty state
      const hasOrdersUi = /commande|order|ticket|n[°o]\s*\d+/i.test(ordersHtml);
      if (!hasOrdersUi) {
        pushFinding(
          'PG9-001',
          'page-9-pos-orders-list',
          'empty_state_quality',
          'P1',
          '/admin/pos/orders page does not contain order/commande/ticket markers. Listing may be broken or empty-state regressed.',
          'Verify PosOrderListComponent.vue + axios call /api/admin/pos/orders.'
        );
      }

      // -----------------------------------------------------------------
      // PAGE 10 — Status change on first row (best-effort)
      // -----------------------------------------------------------------
      const firstRowLink = page.locator('table tbody tr a, .pos-order-row a, [data-testid^="pos-order-link-"]').first();
      if (await firstRowLink.isVisible({ timeout: 3_000 }).catch(() => false)) {
        await firstRowLink.click({ timeout: 4_000 });
        await page.waitForTimeout(2_000);
        await snap('10-pos-order-show');

        // Look for status change affordance — selector or buttons
        const statusBtn = page.locator('button').filter({
          hasText: /statut|status|changer|change|valider|confirmer/i,
        }).first();
        if (await statusBtn.isVisible({ timeout: 2_500 }).catch(() => false)) {
          // We don't actually click to mutate — just attest the affordance exists
          await snap('10b-pos-order-status-affordance');
        } else {
          pushFinding(
            'PG10-001',
            'page-10-pos-order-status',
            'empty_state_quality',
            'P2',
            'Order detail page has no status-change CTA visible. Feature may have testid drift or role gating.',
            'Inspect PosOrderShowComponent.vue status change block + check role pos / settings gating.'
          );
        }
      } else {
        pushFinding(
          'PG10-002',
          'page-10-pos-order-status',
          'empty_state_quality',
          'P2',
          'No order row link clickable on /admin/pos/orders. Either empty DB or row link selector drift.',
          'Verify PosOrderListComponent.vue row binding to /admin/pos/orders/:id.'
        );
      }
    } finally {
      dispose();

      // Write findings to *raw* spec artifact (NOT the hand-curated wave-findings.json
      // which lives in reports/.../POS/wave-findings.json). The raw is for re-runs;
      // the curated file requires deeper visual+root-cause analysis by the human/agent.
      const rawPath = path.resolve(__dirname, '../..',
        'reports/test-e2e/goal-pageby-2026-05-18/round-1/POS/spec-raw-findings.json'
      );
      fs.mkdirSync(path.dirname(rawPath), { recursive: true });
      const summary = findings.reduce(
        (acc, f) => {
          acc[f.severity] = (acc[f.severity] || 0) + 1;
          acc[`open_${f.severity}`] = (acc[`open_${f.severity}`] || 0) + 1;
          return acc;
        },
        { P0: 0, P1: 0, P2: 0, P3: 0, open_P0: 0, open_P1: 0, open_P2: 0, open_P3: 0 }
      );
      const wave = {
        wave: 'POS',
        round: 1,
        spec_run_findings_count: findings.length,
        note: 'Raw spec findings only. Hand-curated triaged findings live in wave-findings.json (sibling file).',
        findings,
        summary,
      };
      fs.writeFileSync(rawPath, JSON.stringify(wave, null, 2));
    }
  });
});
