// FoodKing E2E — AUDIT POS CYCLE 5 ULTRA-DEEP (2026-05-06)
// Couvre: Floorplan, cancel-last-line, discount complet, cash-panel-history,
//         tracker page complète, responsive (1280/1920), a11y axe-core,
//         console errors monitoring, pageerror tracking.
// Wizard popup PROTÉGÉ.

const fs = require('fs');
const path = require('path');
const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const { loginAsPosOperator } = require('./helpers/login');
const { clearFoodKingRateLimits } = require('./helpers/rate-limit');

const SHOT_DIR = path.join(process.cwd(), 'tests/e2e/screenshots/audit-pos-2026-05-06/cycle5');
const FINDINGS_FILE = path.join(SHOT_DIR, 'findings.json');

if (!fs.existsSync(SHOT_DIR)) fs.mkdirSync(SHOT_DIR, { recursive: true });

const findings = [];
function recordFinding(step, slug, state, severity, note, screenshotPath) {
  findings.push({ step, slug, state, severity, note, screenshot: screenshotPath, ts: new Date().toISOString() });
  fs.writeFileSync(FINDINGS_FILE, JSON.stringify(findings, null, 2));
}

async function snap(page, step, slug, state) {
  const filename = `${String(step).padStart(2, '0')}-${slug}-${state}.png`;
  const fullPath = path.join(SHOT_DIR, filename);
  await page.screenshot({ path: fullPath, fullPage: true }).catch((e) => {
    console.warn(`[c5] snap ${filename} failed: ${e.message}`);
  });
  return path.relative(process.cwd(), fullPath);
}

function attachConsoleAndErrorMonitor(page) {
  const consoleErrors = [];
  const consoleWarnings = [];
  const pageErrors = [];
  const networkErrors = [];

  page.on('console', (msg) => {
    if (msg.type() === 'error') consoleErrors.push({ text: msg.text(), location: msg.location() });
    if (msg.type() === 'warning') consoleWarnings.push({ text: msg.text() });
  });
  page.on('pageerror', (err) => pageErrors.push({ message: err.message, stack: err.stack?.slice(0, 500) }));
  page.on('response', (resp) => {
    if (resp.status() >= 400) networkErrors.push({ status: resp.status(), url: resp.url(), method: resp.request().method() });
  });

  return { consoleErrors, consoleWarnings, pageErrors, networkErrors };
}

async function clickAndAddItem(page) {
  const tiles = page.locator('.pos-v5-tile');
  const count = await tiles.count();
  for (let i = 0; i < Math.min(count, 5); i++) {
    const tile = tiles.nth(i);
    const isUnavail = await tile.locator('.pos-item-86-badge').count();
    if (isUnavail === 0) {
      try {
        await tile.click({ timeout: 2_000 });
        await page.waitForTimeout(1_500);
        const wizardOpen = await page.locator('#item-variation-modal').isVisible().catch(() => false);
        if (wizardOpen) {
          const addBtn = page.locator('#item-variation-modal [data-action="add-to-cart"]').first();
          if (await addBtn.isVisible({ timeout: 3_000 }).catch(() => false)) {
            await addBtn.click();
            await page.waitForTimeout(1_500);
          }
        }
        return true;
      } catch (_) {}
    }
  }
  return false;
}

// Each test indépendant — un échec ne cascade pas
test.describe('AUDIT POS CYCLE 5 — ULTRA DEEP', () => {
  test.setTimeout(180_000);

  test.beforeEach(async () => {
    try { clearFoodKingRateLimits(); } catch (_) {}
  });

  test('C5-01 — A11y axe-core sur surface POS principale', async ({ page }) => {
    const monitor = attachConsoleAndErrorMonitor(page);
    await loginAsPosOperator(page);
    await page.waitForTimeout(3_000);

    const shot = await snap(page, 1, 'a11y-pos-main', 'initial');

    const a11yResults = await new AxeBuilder({ page })
      .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
      .analyze();

    fs.writeFileSync(path.join(SHOT_DIR, 'C5-01-a11y-pos-main.json'), JSON.stringify({
      violations_count: a11yResults.violations.length,
      passes_count: a11yResults.passes.length,
      incomplete_count: a11yResults.incomplete.length,
      violations: a11yResults.violations.map((v) => ({
        id: v.id, impact: v.impact, description: v.description,
        helpUrl: v.helpUrl, nodes_count: v.nodes.length,
        sample_nodes: v.nodes.slice(0, 3).map((n) => ({ html: n.html?.slice(0, 200), target: n.target })),
      })),
    }, null, 2));

    const critical = a11yResults.violations.filter((v) => v.impact === 'critical' || v.impact === 'serious');
    const sev = critical.length > 0 ? 'P1' : a11yResults.violations.length > 0 ? 'P2' : 'OK';
    recordFinding('C5-01', 'a11y-pos-main', 'analyzed', sev,
      `Violations: ${a11yResults.violations.length} (critical+serious=${critical.length}). Passes: ${a11yResults.passes.length}`, shot);

    fs.writeFileSync(path.join(SHOT_DIR, 'C5-01-monitoring.json'), JSON.stringify({
      console_errors: monitor.consoleErrors.length,
      console_warnings: monitor.consoleWarnings.length,
      page_errors: monitor.pageErrors.length,
      network_errors: monitor.networkErrors.length,
      sample_console_errors: monitor.consoleErrors.slice(0, 5),
      sample_page_errors: monitor.pageErrors.slice(0, 3),
      sample_network_errors: monitor.networkErrors.slice(0, 5),
    }, null, 2));
    recordFinding('C5-01', 'monitoring-pos-main', 'capture', 'INFO',
      `JS errors=${monitor.pageErrors.length}, console errors=${monitor.consoleErrors.length}, network 4xx/5xx=${monitor.networkErrors.length}`, shot);
  });

  test('C5-02 — Floorplan / plan de salle (page dédiée)', async ({ page }) => {
    const monitor = attachConsoleAndErrorMonitor(page);
    await loginAsPosOperator(page);
    await page.waitForTimeout(2_000);

    await page.goto('/admin/pos/floorplan', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(3_000);
    const shot = await snap(page, 2, 'floorplan', 'loaded');

    const visibleText = await page.locator('body').innerText().catch(() => '');
    const hasError = /Whoops|Fatal error|Server Error|500/i.test(visibleText);
    recordFinding('C5-02', 'floorplan-page', hasError ? 'error' : 'loaded', hasError ? 'P0' : 'OK',
      `Floorplan: ${hasError ? 'ERREUR' : 'page chargée'}. ${visibleText.slice(0, 100)}`, shot);

    // a11y sur floorplan
    const a11y = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa']).analyze().catch(() => null);
    if (a11y) {
      const critical = a11y.violations.filter((v) => v.impact === 'critical' || v.impact === 'serious');
      recordFinding('C5-02', 'a11y-floorplan', 'analyzed', critical.length > 0 ? 'P1' : 'OK',
        `Violations a11y floorplan: ${a11y.violations.length} (crit+seri=${critical.length})`, shot);
    }

    // Erreurs JS
    if (monitor.pageErrors.length > 0) {
      recordFinding('C5-02', 'floorplan-js-errors', 'detected', 'P1',
        `${monitor.pageErrors.length} JS errors: ${monitor.pageErrors.slice(0, 2).map((e) => e.message).join(' | ')}`, shot);
    }
  });

  test('C5-03 — POS orders tracker (page dédiée live)', async ({ page }) => {
    const monitor = attachConsoleAndErrorMonitor(page);
    await loginAsPosOperator(page);
    await page.waitForTimeout(2_000);

    await page.goto('/admin/pos-orders-tracker', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(3_000);
    const shot = await snap(page, 3, 'tracker', 'loaded');

    const visibleText = await page.locator('body').innerText().catch(() => '');
    const hasError = /Whoops|Fatal error|Server Error|500/i.test(visibleText);
    recordFinding('C5-03', 'tracker-page', hasError ? 'error' : 'loaded', hasError ? 'P0' : 'OK',
      `Tracker: ${hasError ? 'ERREUR' : 'chargée'}`, shot);

    if (monitor.pageErrors.length > 0) {
      recordFinding('C5-03', 'tracker-js-errors', 'detected', 'P1',
        `${monitor.pageErrors.length} JS errors`, shot);
    }
  });

  test('C5-04 — Cancel last line (annuler dernière ligne)', async ({ page }) => {
    const monitor = attachConsoleAndErrorMonitor(page);
    await loginAsPosOperator(page);
    await page.waitForTimeout(2_500);

    // Add item
    const added = await clickAndAddItem(page);
    if (!added) {
      const shot = await snap(page, 4, 'cancel-last-line', 'no-item-added');
      recordFinding('C5-04', 'cancel-line', 'precondition-failed', 'P2', 'Aucun item ajouté pour test', shot);
      return;
    }

    const shotBefore = await snap(page, 4, 'cancel-last-line', 'before');
    const cartChipBefore = await page.locator('[data-testid="pos-cart-stat-chip"]').first().innerText().catch(() => 'n/a');

    const cancelBtn = page.locator('[data-testid="pos-cancel-last-line"]').first();
    if (!(await cancelBtn.isVisible({ timeout: 3_000 }).catch(() => false))) {
      recordFinding('C5-04', 'cancel-button', 'invisible', 'P2', 'Bouton cancel-last-line invisible', shotBefore);
      return;
    }

    await cancelBtn.click();
    await page.waitForTimeout(1_500);

    // Confirmer si modal de confirmation apparaît
    const confirm = page.locator('button').filter({ hasText: /confirmer|oui|ok/i }).first();
    if (await confirm.isVisible({ timeout: 1_500 }).catch(() => false)) {
      await confirm.click();
      await page.waitForTimeout(1_000);
    }

    const shotAfter = await snap(page, 4, 'cancel-last-line', 'after');
    const cartChipAfter = await page.locator('[data-testid="pos-cart-stat-chip"]').first().innerText().catch(() => 'n/a');

    const decremented = cartChipBefore !== cartChipAfter;
    recordFinding('C5-04', 'cancel-line', 'after-click', decremented ? 'OK' : 'P2',
      `Cart chip: "${cartChipBefore}" → "${cartChipAfter}" (decremented=${decremented})`, shotAfter);
  });

  test('C5-05 — Discount manuel (input + apply + reason)', async ({ page }) => {
    const monitor = attachConsoleAndErrorMonitor(page);
    await loginAsPosOperator(page);
    await page.waitForTimeout(2_500);

    await clickAndAddItem(page);

    const shotInitial = await snap(page, 5, 'discount', 'initial');

    const discountInput = page.locator('[data-testid="pos-discount-input"]').first();
    if (!(await discountInput.isVisible({ timeout: 3_000 }).catch(() => false))) {
      recordFinding('C5-05', 'discount-input', 'invisible', 'P2', 'Discount input non visible', shotInitial);
      return;
    }

    // Saisir discount
    await discountInput.click();
    await discountInput.fill('5');
    await page.waitForTimeout(500);
    const shotFilled = await snap(page, 5, 'discount', 'amount-filled');
    recordFinding('C5-05', 'discount-input', 'filled', 'OK', 'Discount 5% saisi', shotFilled);

    // Apply (peut être disabled selon discount %, et nécessite reason ≥ 3 chars d'abord)
    const applyBtn = page.locator('[data-testid="pos-discount-apply"]').first();
    let appliedOk = false;
    if (await applyBtn.isVisible().catch(() => false)) {
      try {
        const isDisabled = await applyBtn.isDisabled().catch(() => false);
        if (isDisabled) {
          recordFinding('C5-05', 'discount-apply', 'disabled', 'INFO',
            'Bouton apply disabled — peut nécessiter reason préalable', shotFilled);
        } else {
          await applyBtn.click({ timeout: 3_000 });
          await page.waitForTimeout(1_000);
          appliedOk = true;
        }
      } catch (e) {
        recordFinding('C5-05', 'discount-apply-click', 'failed', 'P2', `Click apply échoué: ${e.message}`, shotFilled);
      }
    }
    if (appliedOk) {
      const shotApplied = await snap(page, 5, 'discount', 'applied');
      // Vérifier reason field (peut être requis)
      const reasonRequired = await page.locator('[data-testid="pos-discount-reason-required-flag"]').isVisible().catch(() => false);
      const reasonField = page.locator('[data-testid="pos-discount-reason"]').first();
      if (reasonRequired || (await reasonField.isVisible().catch(() => false))) {
        await reasonField.fill('Audit cycle 5 test');
        await page.waitForTimeout(500);
        // Re-apply après reason
        if (await applyBtn.isVisible().catch(() => false)) {
          await applyBtn.click();
          await page.waitForTimeout(1_000);
        }
        const shotReason = await snap(page, 5, 'discount', 'reason-filled');
        recordFinding('C5-05', 'discount-reason', 'filled', 'OK', 'Reason saisi + re-apply', shotReason);
      }
      recordFinding('C5-05', 'discount-apply', 'clicked', 'OK', 'Discount appliqué (vérification visuelle)', shotApplied);
    }

    // Capture grand total après discount
    const grandTotal = await page.locator('[data-testid="pos-grand-total"]').first().innerText().catch(() => 'n/a');
    recordFinding('C5-05', 'grand-total', 'displayed', 'INFO', `Grand total = ${grandTotal}`, shotFilled);
  });

  test('C5-06 — Cash collect kiosk panel history (panneau historique encaissement)', async ({ page }) => {
    const monitor = attachConsoleAndErrorMonitor(page);
    await loginAsPosOperator(page);
    await page.waitForTimeout(2_500);

    const cashOpenBtn = page.locator('[data-testid="kiosk-cash-open"]').first();
    if (!(await cashOpenBtn.isVisible({ timeout: 3_000 }).catch(() => false))) {
      const shot = await snap(page, 6, 'kiosk-cash-panel', 'btn-missing');
      recordFinding('C5-06', 'cash-open-button', 'invisible', 'P2', 'Bouton kiosk-cash-open invisible', shot);
      return;
    }

    await cashOpenBtn.click();
    await page.waitForTimeout(2_000);

    const panelVisible = await page.locator('[data-testid="kiosk-cash-panel-history"]').isVisible().catch(() => false);
    const shot = await snap(page, 6, 'kiosk-cash-panel', panelVisible ? 'opened' : 'closed-or-missing');
    recordFinding('C5-06', 'cash-panel', panelVisible ? 'opened' : 'missing', panelVisible ? 'OK' : 'P2',
      `Panel kiosk-cash-panel-history visible=${panelVisible}`, shot);
  });

  test('C5-07 — Responsive 1280×800 (laptop) + 1920×1080 (desktop)', async ({ page }) => {
    await loginAsPosOperator(page);

    // 1280x800
    await page.setViewportSize({ width: 1280, height: 800 });
    await page.waitForTimeout(2_000);
    const shot1280 = await snap(page, 7, 'responsive', '1280x800');
    const grid1280 = await page.locator('.pos-v5-grid').isVisible().catch(() => false);
    recordFinding('C5-07', 'responsive-1280', 'rendered', grid1280 ? 'OK' : 'P2', `Grid visible at 1280x800: ${grid1280}`, shot1280);

    // 1920x1080
    await page.setViewportSize({ width: 1920, height: 1080 });
    await page.waitForTimeout(1_500);
    const shot1920 = await snap(page, 7, 'responsive', '1920x1080');
    const grid1920 = await page.locator('.pos-v5-grid').isVisible().catch(() => false);
    recordFinding('C5-07', 'responsive-1920', 'rendered', grid1920 ? 'OK' : 'P2', `Grid visible at 1920x1080: ${grid1920}`, shot1920);

    // 768x1024 (tablette portrait)
    await page.setViewportSize({ width: 768, height: 1024 });
    await page.waitForTimeout(1_500);
    const shot768 = await snap(page, 7, 'responsive', '768x1024-tablet');
    const grid768 = await page.locator('.pos-v5-grid').isVisible().catch(() => false);
    recordFinding('C5-07', 'responsive-768', 'rendered', grid768 ? 'OK' : 'INFO', `Grid visible at 768 tablette: ${grid768}`, shot768);
  });

  test('C5-08 — Search bar produit (filtrage en temps réel)', async ({ page }) => {
    await loginAsPosOperator(page);
    await page.waitForTimeout(2_500);

    const searchInput = page.locator('input[type="search"], input[placeholder*="recherch" i], .pos-v5-search input').first();
    if (!(await searchInput.isVisible({ timeout: 3_000 }).catch(() => false))) {
      const shot = await snap(page, 8, 'search', 'input-missing');
      recordFinding('C5-08', 'search-input', 'missing', 'P2', 'Search input non détecté', shot);
      return;
    }

    const shotBefore = await snap(page, 8, 'search', 'before-typing');
    const tilesBefore = await page.locator('.pos-v5-tile').count();

    await searchInput.click();
    await searchInput.fill('Tacos');
    await page.waitForTimeout(1_500);

    const shotAfter = await snap(page, 8, 'search', 'after-typing-tacos');
    const tilesAfter = await page.locator('.pos-v5-tile').count();

    const filtered = tilesAfter < tilesBefore;
    recordFinding('C5-08', 'search-filter', filtered ? 'works' : 'no-filter', filtered ? 'OK' : 'P2',
      `Tuiles avant=${tilesBefore}, après recherche "Tacos"=${tilesAfter} (filtered=${filtered})`, shotAfter);
  });

  test('C5-09 — Type commande Livraison (formulaire delivery inline)', async ({ page }) => {
    await loginAsPosOperator(page);
    await page.waitForTimeout(2_500);

    // Cliquer sur "Livraison" dans le segmented control
    const deliveryBtn = page.locator('button, label').filter({ hasText: /^Livraison$/i }).first();
    if (!(await deliveryBtn.isVisible({ timeout: 3_000 }).catch(() => false))) {
      const shot = await snap(page, 9, 'delivery', 'button-missing');
      recordFinding('C5-09', 'delivery-toggle', 'missing', 'P2', 'Bouton Livraison non détecté', shot);
      return;
    }

    await deliveryBtn.click();
    await page.waitForTimeout(1_500);

    const shot = await snap(page, 9, 'delivery', 'selected');
    recordFinding('C5-09', 'delivery-mode', 'selected', 'OK', 'Mode Livraison activé (vérif visuelle)', shot);
  });

  test('C5-10 — Synthèse cycle 5 + INDEX', async ({ page }) => {
    await loginAsPosOperator(page);
    await page.waitForTimeout(1_000);

    const indexPath = path.join(SHOT_DIR, 'INDEX.md');
    const lines = ['# AUDIT POS CYCLE 5 ULTRA-DEEP — Captures Index 2026-05-06', '', `Total findings cycle 5: ${findings.length}`, '', '| Step | Slug | State | Severity | Note | Screenshot |', '| --- | --- | --- | --- | --- | --- |'];
    for (const f of findings) {
      lines.push(`| ${f.step} | ${f.slug} | ${f.state} | ${f.severity} | ${String(f.note).replace(/\|/g, '\\|').slice(0, 200)} | \`${f.screenshot || '—'}\` |`);
    }
    fs.writeFileSync(indexPath, lines.join('\n'));

    expect(findings.length).toBeGreaterThan(5);
  });
});
