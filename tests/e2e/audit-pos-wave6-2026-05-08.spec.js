// FoodKing E2E — WAVE 6 POS CAISSE (2026-05-08)
// Adversarial audit, 2-role internal pipeline (GStack + Hostile Supervisor).
// Surfaces: /login, /admin/pos, wizard popup (frozen, capture only),
//           cart + discount, payment modal, fiscal ticket post-pay,
//           POS orders tracker, responsive 1024 (tablet borne).
// Frozen zones never modified — capture + audit only.

const fs = require('fs');
const path = require('path');
const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const { login, loginAsPosOperator, loginAsAdmin } = require('./helpers/login');
const { clearFoodKingRateLimits } = require('./helpers/rate-limit');
const { ensurePosTacosMenuForE2e } = require('./helpers/pos-tacos-fixture');

const ROUND = process.env.WAVE6_ROUND || 'round-1';
const SHOT_DIR = path.join(process.cwd(), 'tests/captures/wave6-pos-2026-05-08', ROUND);
const FINDINGS_FILE = path.join(SHOT_DIR, 'findings.json');

if (!fs.existsSync(SHOT_DIR)) fs.mkdirSync(SHOT_DIR, { recursive: true });

const findings = [];
function recordFinding(id, surface, severity, note, screenshotPath, extra = {}) {
  findings.push({
    id,
    surface,
    severity, // HIGH | MEDIUM | LOW | INFO | OK
    note,
    screenshot: screenshotPath,
    ts: new Date().toISOString(),
    ...extra,
  });
  fs.writeFileSync(FINDINGS_FILE, JSON.stringify(findings, null, 2));
}

async function snap(page, slug) {
  const filename = `${slug}.png`;
  const fullPath = path.join(SHOT_DIR, filename);
  await page.screenshot({ path: fullPath, fullPage: true }).catch((e) => {
    console.warn(`[wave6] snap ${filename} failed: ${e.message}`);
  });
  return path.relative(process.cwd(), fullPath);
}

function attachMonitor(page) {
  const consoleErrors = [];
  const pageErrors = [];
  const networkErrors = [];
  page.on('console', (msg) => {
    if (msg.type() === 'error') consoleErrors.push({ text: msg.text(), location: msg.location() });
  });
  page.on('pageerror', (err) => pageErrors.push({ message: err.message, stack: err.stack?.slice(0, 400) }));
  page.on('response', (resp) => {
    if (resp.status() >= 400) {
      networkErrors.push({ status: resp.status(), url: resp.url(), method: resp.request().method() });
    }
  });
  return { consoleErrors, pageErrors, networkErrors };
}

function summariseMonitor(m) {
  return {
    js_errors: m.pageErrors.length,
    console_errors: m.consoleErrors.length,
    network_errors: m.networkErrors.length,
    sample_js: m.pageErrors.slice(0, 3),
    sample_console: m.consoleErrors.slice(0, 3),
    sample_network: m.networkErrors.slice(0, 5),
  };
}

async function detectRawLabels(page) {
  // Detect i18n raw keys leaking through (e.g. "Label.foo", "kiosk.bar", "0undefined")
  const text = await page.locator('body').innerText().catch(() => '');
  const rawKeyHits = (text.match(/\b[A-Za-z][A-Za-z0-9_-]*\.[A-Za-z][A-Za-z0-9_-]+(\.[A-Za-z][A-Za-z0-9_-]+)*\b/g) || [])
    .filter((m) => /^(Label|kiosk|pos|admin|kds|oss|wizard|payment|cart|order|item|user|auth)\./i.test(m))
    .filter((m) => !m.includes('.com') && !m.includes('.fr') && !m.includes('.png') && !m.includes('.css') && !m.includes('.js'))
    .slice(0, 20);
  const undefinedHits = (text.match(/\b\d+undefined\b|\bundefinedundefined\b|\bundefined\s*€\b/g) || []).slice(0, 10);
  const nanHits = (text.match(/\bNaN\s*€\b|€\s*NaN\b/g) || []).slice(0, 10);
  return { rawKeyHits, undefinedHits, nanHits };
}

async function tryAddItem(page) {
  // Click the first available, non-86 tile, accept wizard, validate add-to-cart.
  const tiles = page.locator('.pos-v5-tile');
  const count = await tiles.count();
  for (let i = 0; i < Math.min(count, 8); i++) {
    const tile = tiles.nth(i);
    const isUnavail = await tile.locator('.pos-item-86-badge').count();
    if (isUnavail === 0) {
      try {
        await tile.click({ timeout: 2_000 });
        await page.waitForTimeout(1_200);
        const wizardOpen = await page.locator('#item-variation-modal').isVisible().catch(() => false);
        if (wizardOpen) {
          // Capture wizard frozen UI before adding
          await snap(page, 'frozen-wizard-popup-opened');
          // Click any required attribute first to satisfy min_select
          const firstOption = page.locator('#item-variation-modal [data-attribute-option], #item-variation-modal .variation-option, #item-variation-modal input[type="radio"], #item-variation-modal input[type="checkbox"]').first();
          if (await firstOption.isVisible({ timeout: 1_500 }).catch(() => false)) {
            await firstOption.click({ timeout: 1_500 }).catch(() => {});
            await page.waitForTimeout(400);
          }
          const addBtn = page.locator('#item-variation-modal [data-action="add-to-cart"], #item-variation-modal button:has-text("Ajouter")').first();
          if (await addBtn.isVisible({ timeout: 2_500 }).catch(() => false)) {
            // attempt up to 3 times in case validation requires more clicks
            for (let k = 0; k < 3; k++) {
              const disabled = await addBtn.isDisabled().catch(() => false);
              if (!disabled) {
                await addBtn.click().catch(() => {});
                await page.waitForTimeout(800);
                const stillOpen = await page.locator('#item-variation-modal').isVisible().catch(() => false);
                if (!stillOpen) return true;
              }
              // try clicking another option
              const moreOpts = page.locator('#item-variation-modal input[type="radio"], #item-variation-modal input[type="checkbox"]');
              const optCount = await moreOpts.count();
              if (optCount > k + 1) {
                await moreOpts.nth(k + 1).click().catch(() => {});
                await page.waitForTimeout(300);
              }
            }
          }
        }
        return true;
      } catch (_) {}
    }
  }
  return false;
}

test.describe('WAVE 6 — POS CAISSE — Adversarial audit', () => {
  test.setTimeout(180_000);

  test.beforeAll(async () => {
    try {
      const fixture = ensurePosTacosMenuForE2e('pos@lecayenne.fr');
      console.log('[wave6] fixture:', JSON.stringify(fixture));
    } catch (e) {
      console.warn('[wave6] fixture warn:', e.message);
    }
  });

  test.beforeEach(async () => {
    try { clearFoodKingRateLimits(); } catch (_) {}
  });

  // ============================================================
  // S1 — /login surface
  // ============================================================
  test('W6-S1 — /login surface integrity', async ({ page }) => {
    const monitor = attachMonitor(page);
    await page.goto('/login', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(1_500);
    const shot = await snap(page, 'S1-login-initial');

    // Form integrity
    const emailVisible = await page.locator('#formEmail').isVisible().catch(() => false);
    const passVisible = await page.locator('#formPassword').isVisible().catch(() => false);
    const submitVisible = await page.getByRole('button', { name: /^(login|connexion)$/i }).isVisible().catch(() => false);
    const labels = await detectRawLabels(page);

    if (!emailVisible || !passVisible || !submitVisible) {
      recordFinding('W6-S1-001', '/login', 'HIGH', `Form fields missing: email=${emailVisible} pass=${passVisible} submit=${submitVisible}`, shot);
    } else {
      recordFinding('W6-S1-001', '/login', 'OK', 'Form fields present', shot);
    }
    if (labels.rawKeyHits.length > 0) {
      recordFinding('W6-S1-002', '/login', 'MEDIUM', `Raw i18n keys leaking: ${labels.rawKeyHits.join(', ')}`, shot);
    }
    if (labels.undefinedHits.length > 0 || labels.nanHits.length > 0) {
      recordFinding('W6-S1-003', '/login', 'HIGH', `Undefined/NaN visible: ${[...labels.undefinedHits, ...labels.nanHits].join(', ')}`, shot);
    }

    // a11y
    const a11y = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa']).analyze().catch(() => null);
    if (a11y) {
      const critical = a11y.violations.filter((v) => v.impact === 'critical' || v.impact === 'serious');
      const sev = critical.length > 0 ? 'HIGH' : a11y.violations.length > 0 ? 'LOW' : 'OK';
      fs.writeFileSync(path.join(SHOT_DIR, 'S1-a11y.json'), JSON.stringify({
        violations_count: a11y.violations.length,
        critical_count: critical.length,
        violations: a11y.violations.map((v) => ({
          id: v.id, impact: v.impact, description: v.description, nodes: v.nodes.length,
        })),
      }, null, 2));
      recordFinding('W6-S1-004', '/login a11y', sev, `axe violations=${a11y.violations.length} critical=${critical.length}`, shot);
    }

    // Wrong-creds error path
    await page.locator('#formEmail').fill('wrong@test.fr');
    await page.locator('#formPassword').fill('wrong');
    const submit = page.getByRole('button', { name: /^(login|connexion)$/i });
    await submit.click().catch(() => {});
    await page.waitForTimeout(1_500);
    const shotErr = await snap(page, 'S1-login-wrong-creds');
    const errBodyText = await page.locator('body').innerText().catch(() => '');
    const hasUserFacingError = /invalide|invalid|incorrect|erron|n'existe|non trouv|not found/i.test(errBodyText);
    recordFinding('W6-S1-005', '/login error UX', hasUserFacingError ? 'OK' : 'MEDIUM',
      hasUserFacingError ? 'User-facing error after wrong creds' : 'No clear error message after wrong creds', shotErr);

    fs.writeFileSync(path.join(SHOT_DIR, 'S1-monitor.json'), JSON.stringify(summariseMonitor(monitor), null, 2));
  });

  // ============================================================
  // S2 — /admin/pos main surface
  // ============================================================
  test('W6-S2 — /admin/pos main surface', async ({ page }) => {
    const monitor = attachMonitor(page);
    await loginAsPosOperator(page);
    await page.waitForTimeout(2_500);
    const shot = await snap(page, 'S2-pos-main-initial');

    // Surface integrity: catalog grid + cart panel
    const grid = await page.locator('.pos-v5-grid').isVisible().catch(() => false);
    const cart = await page.locator('[data-testid="pos-cart"], .pos-cart, .pos-v5-cart').first().isVisible().catch(() => false);
    if (!grid) {
      recordFinding('W6-S2-001', '/admin/pos', 'HIGH', 'Catalog grid (.pos-v5-grid) not visible', shot);
    } else {
      recordFinding('W6-S2-001', '/admin/pos', 'OK', 'Catalog grid visible', shot);
    }
    if (!cart) {
      recordFinding('W6-S2-002', '/admin/pos', 'MEDIUM', 'Cart panel not visible at landing', shot);
    } else {
      recordFinding('W6-S2-002', '/admin/pos', 'OK', 'Cart panel visible', shot);
    }

    // Tile visual sanity
    const tiles = await page.locator('.pos-v5-tile').count();
    recordFinding('W6-S2-003', '/admin/pos catalog', tiles > 0 ? 'OK' : 'HIGH', `Tiles count = ${tiles}`, shot);

    // Raw labels / undefined
    const labels = await detectRawLabels(page);
    if (labels.rawKeyHits.length > 0) {
      recordFinding('W6-S2-004', '/admin/pos', 'MEDIUM', `Raw i18n leaks: ${labels.rawKeyHits.slice(0, 5).join(', ')}`, shot);
    }
    if (labels.undefinedHits.length > 0 || labels.nanHits.length > 0) {
      recordFinding('W6-S2-005', '/admin/pos', 'HIGH', `Undefined/NaN: ${[...labels.undefinedHits, ...labels.nanHits].join(', ')}`, shot);
    }

    // a11y
    const a11y = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa']).analyze().catch(() => null);
    if (a11y) {
      const critical = a11y.violations.filter((v) => v.impact === 'critical' || v.impact === 'serious');
      fs.writeFileSync(path.join(SHOT_DIR, 'S2-a11y.json'), JSON.stringify({
        violations_count: a11y.violations.length,
        critical_count: critical.length,
        violations: a11y.violations.slice(0, 12).map((v) => ({
          id: v.id, impact: v.impact, description: v.description, nodes: v.nodes.length,
          sample_target: v.nodes[0]?.target,
        })),
      }, null, 2));
      const sev = critical.length > 0 ? 'HIGH' : a11y.violations.length > 0 ? 'LOW' : 'OK';
      recordFinding('W6-S2-006', '/admin/pos a11y', sev, `axe violations=${a11y.violations.length} crit/serious=${critical.length}`, shot);
    }

    fs.writeFileSync(path.join(SHOT_DIR, 'S2-monitor.json'), JSON.stringify(summariseMonitor(monitor), null, 2));
    if (monitor.pageErrors.length > 0) {
      recordFinding('W6-S2-007', '/admin/pos JS', 'HIGH',
        `${monitor.pageErrors.length} JS errors: ${monitor.pageErrors[0]?.message?.slice(0, 200)}`, shot);
    }
    if (monitor.networkErrors.filter((n) => n.status >= 500).length > 0) {
      recordFinding('W6-S2-008', '/admin/pos net', 'HIGH',
        `5xx errors: ${monitor.networkErrors.filter((n) => n.status >= 500).slice(0, 3).map((n) => `${n.status} ${n.url}`).join(' | ')}`, shot);
    }
  });

  // ============================================================
  // S3 — Wizard popup (FROZEN — capture + visual audit only)
  // ============================================================
  test('W6-S3 — Wizard popup frozen Vanilla JS', async ({ page }) => {
    const monitor = attachMonitor(page);
    await loginAsPosOperator(page);
    await page.waitForTimeout(2_500);

    const tiles = page.locator('.pos-v5-tile');
    const count = await tiles.count();
    let opened = false;
    let openTile = null;
    for (let i = 0; i < Math.min(count, 6); i++) {
      const tile = tiles.nth(i);
      const isUnavail = await tile.locator('.pos-item-86-badge').count();
      if (isUnavail === 0) {
        await tile.click({ timeout: 2_000 }).catch(() => {});
        await page.waitForTimeout(1_500);
        if (await page.locator('#item-variation-modal').isVisible().catch(() => false)) {
          opened = true;
          openTile = await tile.innerText().catch(() => '?');
          break;
        }
      }
    }
    if (!opened) {
      const shot = await snap(page, 'S3-wizard-could-not-open');
      recordFinding('W6-S3-001', 'wizard popup', 'MEDIUM', 'Could not open the frozen wizard from any tile', shot);
      return;
    }

    const shot = await snap(page, 'S3-wizard-opened');
    recordFinding('W6-S3-001', 'wizard popup', 'OK', `Wizard opened from tile "${openTile?.slice(0, 40)}"`, shot);

    // Inspect modal: mandatory anchors
    const titleVisible = await page.locator('#item-variation-modal .modal-title, #item-variation-modal h2').first().isVisible().catch(() => false);
    const closeBtnVisible = await page.locator('#item-variation-modal [data-action="close"], #item-variation-modal .close, #item-variation-modal button[aria-label*="close" i]').first().isVisible().catch(() => false);
    const addToCartVisible = await page.locator('#item-variation-modal [data-action="add-to-cart"]').first().isVisible().catch(() => false);
    if (!titleVisible) recordFinding('W6-S3-002', 'wizard popup', 'MEDIUM', 'No clear wizard title', shot);
    if (!closeBtnVisible) recordFinding('W6-S3-003', 'wizard popup', 'MEDIUM', 'No close button visible', shot);
    if (!addToCartVisible) recordFinding('W6-S3-004', 'wizard popup', 'HIGH', 'No add-to-cart button visible — wizard unusable', shot);

    // Visual check on raw labels INSIDE wizard
    const wizardText = await page.locator('#item-variation-modal').innerText().catch(() => '');
    const wizardLabelHits = (wizardText.match(/\b(?:Label|kiosk|pos|admin|wizard|payment|cart)\.[A-Za-z0-9_.-]+\b/g) || []).slice(0, 10);
    if (wizardLabelHits.length > 0) {
      recordFinding('W6-S3-005', 'wizard popup', 'HIGH', `Raw i18n inside frozen wizard: ${wizardLabelHits.join(', ')}`, shot);
    }
    const wizardUndef = (wizardText.match(/\bundefined\b|\bNaN\b/g) || []).slice(0, 5);
    if (wizardUndef.length > 0) {
      recordFinding('W6-S3-006', 'wizard popup', 'HIGH', `undefined/NaN inside wizard: ${wizardUndef.join(', ')}`, shot);
    }

    // Try to add to cart
    const firstOpt = page.locator('#item-variation-modal input[type="radio"], #item-variation-modal input[type="checkbox"]').first();
    if (await firstOpt.isVisible({ timeout: 1_000 }).catch(() => false)) {
      await firstOpt.click().catch(() => {});
      await page.waitForTimeout(400);
    }
    const addBtn = page.locator('#item-variation-modal [data-action="add-to-cart"]').first();
    if (await addBtn.isVisible().catch(() => false)) {
      await addBtn.click({ timeout: 3_000 }).catch(() => {});
      await page.waitForTimeout(1_200);
      const stillOpen = await page.locator('#item-variation-modal').isVisible().catch(() => false);
      const shotAfter = await snap(page, 'S3-wizard-after-add');
      recordFinding('W6-S3-007', 'wizard add-to-cart', stillOpen ? 'MEDIUM' : 'OK',
        stillOpen ? 'Wizard still open after add-to-cart click — possible validation block' : 'Wizard closed after add-to-cart', shotAfter);
    }

    fs.writeFileSync(path.join(SHOT_DIR, 'S3-monitor.json'), JSON.stringify(summariseMonitor(monitor), null, 2));
  });

  // ============================================================
  // S4 — Cart + discount manager
  // ============================================================
  test('W6-S4 — Cart + discount flow', async ({ page }) => {
    const monitor = attachMonitor(page);
    await loginAsPosOperator(page);
    await page.waitForTimeout(2_500);

    // Empty cart baseline
    const shotEmpty = await snap(page, 'S4-cart-empty');
    const cartEmptyText = await page.locator('body').innerText().catch(() => '');
    if (/undefined|NaN/i.test(cartEmptyText.match(/(?:Total|Sous[- ]?total|Grand[- ]?total)[^\n]{0,30}/g)?.join(' ') || '')) {
      recordFinding('W6-S4-000', '/admin/pos cart empty', 'HIGH', 'Empty cart shows undefined/NaN in totals', shotEmpty);
    }

    // Empty-cart discount edge case (Adversarial Round-2)
    const discountInputEmpty = page.locator('[data-testid="pos-discount-input"]').first();
    if (await discountInputEmpty.isVisible({ timeout: 2_000 }).catch(() => false)) {
      await discountInputEmpty.click().catch(() => {});
      await discountInputEmpty.fill('10').catch(() => {});
      await page.waitForTimeout(400);
      const applyBtnEmpty = page.locator('[data-testid="pos-discount-apply"]').first();
      if (await applyBtnEmpty.isVisible().catch(() => false)) {
        const disabledEmpty = await applyBtnEmpty.isDisabled().catch(() => false);
        const shotEmptyDiscount = await snap(page, 'S4-cart-empty-with-discount-attempt');
        if (!disabledEmpty) {
          await applyBtnEmpty.click().catch(() => {});
          await page.waitForTimeout(800);
          const shotAfter = await snap(page, 'S4-cart-empty-discount-applied');
          const txt = await page.locator('body').innerText().catch(() => '');
          const hasNan = /NaN|undefined/i.test(
            txt.match(/(?:Total|Sous[- ]?total|Grand[- ]?total|Remise|Discount)[^\n]{0,40}/g)?.join(' ') || ''
          );
          recordFinding('W6-S4-001', '/admin/pos discount empty cart', hasNan ? 'HIGH' : 'OK',
            hasNan ? 'Discount on empty cart causes NaN/undefined in totals' : 'Discount on empty cart handled gracefully', shotAfter);
        } else {
          recordFinding('W6-S4-001', '/admin/pos discount empty cart', 'OK',
            'Discount apply button disabled on empty cart (expected)', shotEmptyDiscount);
        }
      }
    } else {
      recordFinding('W6-S4-001', '/admin/pos discount empty cart', 'INFO',
        'Discount input not visible at empty-cart (likely shown only when items present)', shotEmpty);
    }

    // Add item via wizard
    const added = await tryAddItem(page);
    if (!added) {
      const shotNoItem = await snap(page, 'S4-cart-no-item-could-add');
      recordFinding('W6-S4-002', '/admin/pos cart', 'MEDIUM', 'Could not add item to cart — fixture or wizard regression', shotNoItem);
      return;
    }

    const shotWithItem = await snap(page, 'S4-cart-with-item');
    recordFinding('W6-S4-002', '/admin/pos cart', 'OK', 'Item added to cart', shotWithItem);

    // Capture totals and check for NaN/undefined
    const totalsText = await page.locator('[data-testid="pos-grand-total"], .pos-cart-total, .pos-total').first().innerText().catch(() => '');
    const labels = await detectRawLabels(page);
    if (labels.undefinedHits.length > 0 || labels.nanHits.length > 0) {
      recordFinding('W6-S4-003', '/admin/pos cart totals', 'HIGH',
        `undefined/NaN in cart: ${[...labels.undefinedHits, ...labels.nanHits].join(', ')}`, shotWithItem);
    } else {
      recordFinding('W6-S4-003', '/admin/pos cart totals', 'OK', `Totals visible: "${totalsText.slice(0, 40)}"`, shotWithItem);
    }

    // Apply discount with item
    const discountInput = page.locator('[data-testid="pos-discount-input"]').first();
    if (await discountInput.isVisible({ timeout: 2_000 }).catch(() => false)) {
      await discountInput.click().catch(() => {});
      await discountInput.fill('10');
      await page.waitForTimeout(400);
      const applyBtn = page.locator('[data-testid="pos-discount-apply"]').first();
      if (await applyBtn.isVisible().catch(() => false)) {
        const disabled = await applyBtn.isDisabled().catch(() => false);
        if (!disabled) {
          await applyBtn.click().catch(() => {});
          await page.waitForTimeout(900);
        }
        // reason field if required
        const reasonField = page.locator('[data-testid="pos-discount-reason"]').first();
        if (await reasonField.isVisible({ timeout: 1_500 }).catch(() => false)) {
          await reasonField.fill('Wave6 audit reason');
          await page.waitForTimeout(300);
          if (await applyBtn.isVisible().catch(() => false) && !(await applyBtn.isDisabled().catch(() => false))) {
            await applyBtn.click().catch(() => {});
            await page.waitForTimeout(800);
          }
        }
        const shotApplied = await snap(page, 'S4-discount-applied');
        const totalsAfter = await page.locator('[data-testid="pos-grand-total"], .pos-cart-total, .pos-total').first().innerText().catch(() => '');
        const hasIssue = /NaN|undefined/.test(totalsAfter);
        recordFinding('W6-S4-004', '/admin/pos discount apply', hasIssue ? 'HIGH' : 'OK',
          `Total after 10% discount: "${totalsAfter.slice(0, 40)}"`, shotApplied);

        // Out-of-range discount (105%) — Adversarial
        await discountInput.fill('105').catch(() => {});
        await page.waitForTimeout(400);
        const applyBtn2 = page.locator('[data-testid="pos-discount-apply"]').first();
        const disabled2 = await applyBtn2.isDisabled().catch(() => false);
        const shot105 = await snap(page, 'S4-discount-105-percent');
        if (disabled2) {
          recordFinding('W6-S4-005', '/admin/pos discount range', 'OK', '105% blocked by disabled apply', shot105);
        } else {
          await applyBtn2.click().catch(() => {});
          await page.waitForTimeout(700);
          const shotAfter105 = await snap(page, 'S4-discount-105-applied');
          const t = await page.locator('body').innerText().catch(() => '');
          const negative = /-\s*\d/.test(t.match(/(?:Total|Grand[- ]?total)[^\n]{0,30}/g)?.join('') || '');
          recordFinding('W6-S4-005', '/admin/pos discount range', negative ? 'HIGH' : 'MEDIUM',
            negative ? '105% discount produced negative total' : '105% accepted but total not visibly negative — verify backend rejects', shotAfter105);
        }
      } else {
        recordFinding('W6-S4-004', '/admin/pos discount', 'MEDIUM', 'Discount input visible but no apply button', shotWithItem);
      }
    } else {
      recordFinding('W6-S4-004', '/admin/pos discount', 'INFO', 'Discount input not visible even with cart populated', shotWithItem);
    }

    fs.writeFileSync(path.join(SHOT_DIR, 'S4-monitor.json'), JSON.stringify(summariseMonitor(monitor), null, 2));
  });

  // ============================================================
  // S5 — Payment modal (open + visual + cancel — no real charge)
  // ============================================================
  test('W6-S5 — Payment modal open + visual + cancel', async ({ page }) => {
    const monitor = attachMonitor(page);
    await loginAsPosOperator(page);
    await page.waitForTimeout(2_500);

    const added = await tryAddItem(page);
    if (!added) {
      const shot = await snap(page, 'S5-payment-no-item');
      recordFinding('W6-S5-001', 'payment modal', 'MEDIUM', 'Cannot test payment without an item in cart', shot);
      return;
    }
    const shotWithItem = await snap(page, 'S5-payment-cart-ready');

    // Look for "Payer" / "Paiement" / "Encaisser" button
    const payBtn = page.locator(
      '[data-testid="pos-v5-pay"], [data-testid="pos-pay-button"], [data-testid="pos-payment-open"], button:has-text("Payer"), button:has-text("Encaisser"), button:has-text("Paiement")'
    ).first();
    if (!(await payBtn.isVisible({ timeout: 4_000 }).catch(() => false))) {
      recordFinding('W6-S5-001', 'payment modal', 'HIGH', 'Pay/Encaisser button not visible', shotWithItem);
      return;
    }
    await payBtn.click().catch(() => {});
    await page.waitForTimeout(2_000);
    const shotOpen = await snap(page, 'S5-payment-modal-opened');

    const modal = page.locator('[data-testid="pos-payment-mode-cash"], [data-testid="pos-payment-mode-card"], .pos-payment-modal, [data-testid="pos-payment-modal"], .modal:has-text("Paiement")').first();
    const modalVisible = await modal.isVisible().catch(() => false);
    if (!modalVisible) {
      // SPA may use different selector
      const anyModal = await page.locator('.modal.show, [role="dialog"]').first().isVisible().catch(() => false);
      if (!anyModal) {
        recordFinding('W6-S5-002', 'payment modal', 'HIGH', 'No payment modal opened', shotOpen);
        return;
      }
    }
    recordFinding('W6-S5-002', 'payment modal', 'OK', 'Payment modal opened', shotOpen);

    // Capture cash + card sub-modes if available
    const labels = await detectRawLabels(page);
    if (labels.rawKeyHits.length > 0) {
      recordFinding('W6-S5-003', 'payment modal i18n', 'MEDIUM', `Raw i18n in payment: ${labels.rawKeyHits.slice(0, 5).join(', ')}`, shotOpen);
    }
    if (labels.undefinedHits.length > 0 || labels.nanHits.length > 0) {
      recordFinding('W6-S5-004', 'payment modal i18n', 'HIGH', `undefined/NaN in payment: ${[...labels.undefinedHits, ...labels.nanHits].join(', ')}`, shotOpen);
    }

    // Look for cash + card buttons (visual check only)
    const cashBtn = await page.locator('button:has-text("Espèces"), button:has-text("Espece"), button:has-text("Cash")').first().isVisible().catch(() => false);
    const cardBtn = await page.locator('button:has-text("Carte"), button:has-text("CB"), button:has-text("Card")').first().isVisible().catch(() => false);
    recordFinding('W6-S5-005', 'payment modes', cashBtn || cardBtn ? 'OK' : 'MEDIUM',
      `cash=${cashBtn} card=${cardBtn}`, shotOpen);

    // Adversarial: try Esc to close
    await page.keyboard.press('Escape');
    await page.waitForTimeout(800);
    const shotEsc = await snap(page, 'S5-payment-after-esc');
    const stillOpen = await page.locator('.pos-payment-modal, [data-testid="pos-payment-modal"], .modal.show').first().isVisible().catch(() => false);
    recordFinding('W6-S5-006', 'payment modal Esc', stillOpen ? 'LOW' : 'OK',
      stillOpen ? 'Payment modal does not close on Esc (UX nuisance)' : 'Esc closes payment modal', shotEsc);

    fs.writeFileSync(path.join(SHOT_DIR, 'S5-monitor.json'), JSON.stringify(summariseMonitor(monitor), null, 2));
  });

  // ============================================================
  // S6 — POS orders tracker / list
  // ============================================================
  test('W6-S6 — POS orders tracker', async ({ page }) => {
    const monitor = attachMonitor(page);
    await loginAsPosOperator(page);
    await page.waitForTimeout(1_800);

    await page.goto('/admin/pos-orders-tracker', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(3_500);
    const shot = await snap(page, 'S6-orders-tracker');

    const bodyText = await page.locator('body').innerText().catch(() => '');
    const hasError = /Whoops|Fatal error|Server Error|500|Stack trace/i.test(bodyText);
    if (hasError) {
      recordFinding('W6-S6-001', '/admin/pos-orders-tracker', 'HIGH',
        `Server/JS error on tracker page: ${bodyText.slice(0, 120).replace(/\n/g, ' ')}`, shot);
    } else {
      recordFinding('W6-S6-001', '/admin/pos-orders-tracker', 'OK', 'Tracker loaded without 500/Whoops', shot);
    }

    const labels = await detectRawLabels(page);
    if (labels.rawKeyHits.length > 0) {
      recordFinding('W6-S6-002', 'tracker i18n', 'MEDIUM', `Raw i18n: ${labels.rawKeyHits.slice(0, 5).join(', ')}`, shot);
    }
    if (labels.undefinedHits.length > 0 || labels.nanHits.length > 0) {
      recordFinding('W6-S6-003', 'tracker i18n', 'HIGH', `undefined/NaN: ${[...labels.undefinedHits, ...labels.nanHits].join(', ')}`, shot);
    }

    if (monitor.pageErrors.length > 0) {
      recordFinding('W6-S6-004', 'tracker JS', 'HIGH',
        `${monitor.pageErrors.length} JS errors: ${monitor.pageErrors[0]?.message?.slice(0, 200)}`, shot);
    }

    fs.writeFileSync(path.join(SHOT_DIR, 'S6-monitor.json'), JSON.stringify(summariseMonitor(monitor), null, 2));
  });

  // ============================================================
  // S7 — Responsive tablet 1024 (POS borne form factor)
  // ============================================================
  test('W6-S7 — Responsive 1024x768 tablet borne', async ({ page }) => {
    await page.setViewportSize({ width: 1024, height: 768 });
    await loginAsPosOperator(page);
    await page.waitForTimeout(2_500);
    const shot = await snap(page, 'S7-pos-tablet-1024');

    const grid = await page.locator('.pos-v5-grid').isVisible().catch(() => false);
    const cart = await page.locator('[data-testid="pos-cart"], .pos-cart, .pos-v5-cart').first().isVisible().catch(() => false);
    const overflowX = await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth);
    if (overflowX) {
      recordFinding('W6-S7-001', 'responsive 1024', 'HIGH', 'Horizontal scrollbar at 1024px — layout broken', shot);
    } else {
      recordFinding('W6-S7-001', 'responsive 1024', 'OK', 'No horizontal overflow at 1024px', shot);
    }
    recordFinding('W6-S7-002', 'responsive 1024', grid && cart ? 'OK' : 'MEDIUM',
      `grid=${grid} cart=${cart} at 1024x768`, shot);
  });

  // ============================================================
  // S8 — Admin login surface (branch_id=0 bypass)
  // ============================================================
  test('W6-S8 — Admin (branch_id=0) on /admin/pos', async ({ page }) => {
    const monitor = attachMonitor(page);
    await loginAsAdmin(page);
    await page.waitForTimeout(1_500);
    await page.goto('/admin/pos', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(3_000);
    const shot = await snap(page, 'S8-admin-on-pos');

    const grid = await page.locator('.pos-v5-grid').isVisible().catch(() => false);
    const bodyText = await page.locator('body').innerText().catch(() => '');
    const denied = /403|forbidden|non autoris|denied/i.test(bodyText);
    if (denied) {
      recordFinding('W6-S8-001', '/admin/pos as admin', 'INFO',
        'Admin user denied access to /admin/pos (RBAC)', shot);
    } else if (!grid) {
      recordFinding('W6-S8-001', '/admin/pos as admin', 'MEDIUM',
        'Admin loaded /admin/pos but catalog grid missing (branch_id=0 may have no items scoped)', shot);
    } else {
      recordFinding('W6-S8-001', '/admin/pos as admin', 'OK', 'Admin can view /admin/pos with grid', shot);
    }

    // Capture any tile to see if admin sees data despite branch_id=0
    const tiles = await page.locator('.pos-v5-tile').count();
    recordFinding('W6-S8-002', '/admin/pos as admin', 'INFO', `Admin tiles count = ${tiles}`, shot);

    fs.writeFileSync(path.join(SHOT_DIR, 'S8-monitor.json'), JSON.stringify(summariseMonitor(monitor), null, 2));
  });

  // ============================================================
  // S9 — Index + manifest
  // ============================================================
  test('W6-S9 — Index manifest', async () => {
    const indexPath = path.join(SHOT_DIR, 'INDEX.md');
    const lines = [
      `# WAVE 6 — POS CAISSE — ${ROUND}`,
      '',
      `Date: ${new Date().toISOString()}`,
      `Total findings: ${findings.length}`,
      '',
      '| ID | Surface | Severity | Note | Screenshot |',
      '| --- | --- | --- | --- | --- |',
    ];
    for (const f of findings) {
      lines.push(
        `| ${f.id} | ${f.surface} | ${f.severity} | ${String(f.note).replace(/\|/g, '\\|').slice(0, 200)} | \`${f.screenshot || '—'}\` |`
      );
    }
    fs.writeFileSync(indexPath, lines.join('\n'));

    expect(findings.length).toBeGreaterThan(0);
  });
});
