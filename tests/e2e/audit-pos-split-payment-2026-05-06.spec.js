// FoodKing E2E — AUDIT POS SPLIT PAYMENT (2026-05-06)
// Mission : CV1-POS-SPLIT-PAYMENT-001
// Couvre : ouverture modal paiement, toggle "Multi-paiement", ajout 2 tranches
//          (cash 5€ tendered 10€ + card reste), vérif canConfirm UI et reste dû.
//          Le POST /api/admin/pos n'est PAS encore conscient de payment_breakdown[]
//          (frozen-zone — voir PLAN_P12). On valide donc l'UI ; le submit éventuel
//          est capturé en INFO si le backend renvoie 422 / accepte legacy fallback.
//
// Sortie : tests/e2e/screenshots/audit-pos-split-payment-2026-05-06/

const fs = require('fs');
const path = require('path');
const { test, expect } = require('@playwright/test');
const { loginAsPosOperator } = require('./helpers/login');
const { clearFoodKingRateLimits } = require('./helpers/rate-limit');

const SHOT_DIR = path.join(process.cwd(), 'tests/e2e/screenshots/audit-pos-split-payment-2026-05-06');
const FINDINGS_FILE = path.join(SHOT_DIR, 'findings.json');
const INDEX_FILE = path.join(SHOT_DIR, 'index.md');

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
    console.warn(`[split] snap ${filename} failed: ${e.message}`);
  });
  return path.relative(process.cwd(), fullPath);
}

async function clickFirstAvailableTile(page) {
  const tiles = page.locator('.pos-v5-tile');
  const count = await tiles.count();
  for (let i = 0; i < Math.min(count, 10); i++) {
    const tile = tiles.nth(i);
    const isUnavail = await tile.locator('.pos-item-86-badge').count();
    if (isUnavail === 0) {
      try {
        await tile.click({ timeout: 3_000 });
        await page.waitForTimeout(1_200);
        const wizardOpen = await page.locator('#item-variation-modal').isVisible().catch(() => false);
        return { clicked: true, index: i, wizardOpen };
      } catch (_) { /* keep trying */ }
    }
  }
  return { clicked: false, wizardOpen: false };
}

async function confirmWizardAddToCart(page) {
  const addBtn = page.locator('#item-variation-modal [data-action="add-to-cart"], #item-variation-modal .wizard-btn-cart').first();
  const visible = await addBtn.isVisible({ timeout: 4_000 }).catch(() => false);
  if (!visible) return { ok: false, reason: 'add-to-cart button not visible' };
  const isDisabled = await addBtn.isDisabled().catch(() => false);
  if (isDisabled) {
    const dispatched = await page.evaluate(() => {
      const root = document.getElementById('item-variation-modal');
      if (!root) return false;
      root.dispatchEvent(new CustomEvent('wizard:add-to-cart', { bubbles: false }));
      return true;
    });
    return { ok: dispatched, reason: dispatched ? 'dispatched-custom-event' : 'modal-not-found' };
  }
  await addBtn.click();
  return { ok: true, reason: 'clicked' };
}

async function openPaymentModal(page) {
  const payBtn = page.locator('[data-testid="pos-v5-pay"]').first();
  const visible = await payBtn.isVisible({ timeout: 4_000 }).catch(() => false);
  if (!visible) return { ok: false, reason: 'pos-v5-pay button not visible' };
  await payBtn.click();
  await page.waitForTimeout(1_000);
  const modalVisible = await page.locator('#orderpayment').isVisible().catch(() => false);
  return { ok: modalVisible, reason: modalVisible ? 'modal-open' : 'modal-not-rendered' };
}

test.describe.configure({ mode: 'serial' });

test.describe('AUDIT POS SPLIT PAYMENT (2026-05-06)', () => {
  test.setTimeout(180_000);

  test.beforeEach(async () => {
    try { clearFoodKingRateLimits(); } catch (_) {}
  });

  test('SP-01 — Modal paiement : toggle 3 modes (cash | card | multi)', async ({ page }) => {
    await loginAsPosOperator(page);
    await page.waitForTimeout(2_500);

    // Add an item via the wizard so the cart has total > 0
    const tile = await clickFirstAvailableTile(page);
    if (!tile.clicked) {
      const shot = await snap(page, 1, 'no-tile-available', 'precondition-failed');
      recordFinding('SP-01', 'precondition', 'failed', 'P2', 'Aucune tile POS cliquable', shot);
      return;
    }
    if (tile.wizardOpen) {
      const w = await confirmWizardAddToCart(page);
      if (!w.ok) {
        const shot = await snap(page, 1, 'wizard-add-fail', 'failed');
        recordFinding('SP-01', 'wizard-add', 'failed', 'P2', `Wizard add failed: ${w.reason}`, shot);
        return;
      }
      await page.waitForTimeout(1_000);
    }

    const modal = await openPaymentModal(page);
    if (!modal.ok) {
      const shot = await snap(page, 2, 'modal-open', 'failed');
      recordFinding('SP-01', 'modal-open', 'failed', 'P1', `Modal paiement non ouvert: ${modal.reason}`, shot);
      return;
    }
    const shotInitial = await snap(page, 2, 'modal-open', 'success');

    // 3 modes visible ?
    const cashBtn = page.locator('[data-testid="pos-payment-mode-cash"]');
    const cardBtn = page.locator('[data-testid="pos-payment-mode-card"]');
    const multiBtn = page.locator('[data-testid="pos-payment-mode-multi"]');

    const cashVisible = await cashBtn.isVisible().catch(() => false);
    const cardVisible = await cardBtn.isVisible().catch(() => false);
    const multiVisible = await multiBtn.isVisible().catch(() => false);

    recordFinding('SP-01', 'three-modes-visible', 'observed',
      (cashVisible && cardVisible && multiVisible) ? 'OK' : 'P1',
      `cash=${cashVisible} card=${cardVisible} multi=${multiVisible}`, shotInitial);

    // Switch to multi
    if (multiVisible) {
      await multiBtn.click();
      await page.waitForTimeout(800);
      const splitBlock = await page.locator('[data-testid="pos-payment-split-block"]').isVisible().catch(() => false);
      const shot = await snap(page, 3, 'multi-mode-active', splitBlock ? 'success' : 'block-missing');
      recordFinding('SP-01', 'multi-block-visible', 'observed',
        splitBlock ? 'OK' : 'P1',
        `Bloc split visible: ${splitBlock}`, shot);
    }
  });

  test('SP-02 — Multi : ajout 2 tranches (cash + card) → reste dû = 0', async ({ page }) => {
    await loginAsPosOperator(page);
    await page.waitForTimeout(2_500);

    const tile = await clickFirstAvailableTile(page);
    if (!tile.clicked) { return; }
    if (tile.wizardOpen) {
      await confirmWizardAddToCart(page);
      await page.waitForTimeout(1_000);
    }

    const modal = await openPaymentModal(page);
    if (!modal.ok) { return; }

    // Switch to multi
    const multiBtn = page.locator('[data-testid="pos-payment-mode-multi"]');
    if (!(await multiBtn.isVisible().catch(() => false))) {
      const shot = await snap(page, 1, 'multi-btn-missing', 'failed');
      recordFinding('SP-02', 'multi-btn', 'missing', 'P1', 'Bouton multi non visible', shot);
      return;
    }
    await multiBtn.click();
    await page.waitForTimeout(600);

    // Read total from hero
    const totalText = await page.locator('.pos-v5-payment-total-value').first().innerText().catch(() => '');
    recordFinding('SP-02', 'total-read', 'observed', 'INFO', `Total hero: ${totalText}`, '');

    // Add tranche 1 — keep default mode CASH, set amount to 5€, tendered to 10€
    const addBtn = page.locator('[data-testid="pos-payment-tranche-add"]');
    await addBtn.click();
    await page.waitForTimeout(400);

    // Set amount of tranche 0 to 5
    const amount0 = page.locator('[data-testid="pos-payment-tranche-amount-0"]');
    await amount0.fill('5');
    await amount0.dispatchEvent('input');
    const tendered0 = page.locator('[data-testid="pos-payment-tranche-tendered-0"]');
    await tendered0.fill('10');
    await tendered0.dispatchEvent('input');
    await page.waitForTimeout(300);

    const shot1 = await snap(page, 1, 'tranche-1-cash-5-tendered-10', 'success');
    recordFinding('SP-02', 'tranche-1', 'added', 'OK', 'Tranche cash 5€ / tendered 10€', shot1);

    // Add tranche 2 — switch mode to CARD, amount = remaining
    await addBtn.click();
    await page.waitForTimeout(400);

    const modeSel1 = page.locator('#tranche-mode-1');
    await modeSel1.selectOption({ value: '2' }); // CARD = 2
    await page.waitForTimeout(200);

    // The remaining amount is auto-filled by addTranche. Read it from input.
    const amount1 = page.locator('[data-testid="pos-payment-tranche-amount-1"]');
    const remainingValue = await amount1.inputValue().catch(() => '');
    recordFinding('SP-02', 'tranche-2-amount', 'observed', 'INFO', `Tranche 2 amount: ${remainingValue}`, '');

    // Read remaining due
    const remainingDueText = await page.locator('[data-testid="pos-payment-remaining-due"]').innerText().catch(() => '');
    const totalCoveredText = await page.locator('[data-testid="pos-payment-total-covered"]').innerText().catch(() => '');
    const shot2 = await snap(page, 2, 'two-tranches-balanced', 'success');
    recordFinding('SP-02', 'remaining-due-zero', 'observed',
      remainingDueText.includes('0.00') || remainingDueText.includes('0,00') ? 'OK' : 'P1',
      `Reste dû: ${remainingDueText} | Couvert: ${totalCoveredText}`, shot2);

    // Confirm button enabled ?
    const confirmBtn = page.locator('[data-testid="pos-payment-confirm"]');
    const confirmDisabled = await confirmBtn.isDisabled().catch(() => true);
    recordFinding('SP-02', 'confirm-enabled', 'observed',
      !confirmDisabled ? 'OK' : 'P1',
      `Confirmer disabled: ${confirmDisabled}`, shot2);

    // Total change visible (cash 10 - 5 = 5)
    const changeText = await page.locator('[data-testid="pos-payment-total-change"]').innerText().catch(() => 'no-change');
    recordFinding('SP-02', 'total-change', 'observed', 'INFO',
      `Monnaie totale rendue: ${changeText}`, shot2);

    // Optional submit attempt — backend may 422 because payment_breakdown[]
    // is not yet wired (PLAN_P12 frozen-zone). We capture as INFO either way.
    if (!confirmDisabled) {
      const responses = [];
      page.on('response', (r) => {
        if (/\/api\/admin\/pos(?:\?|$|\/)/.test(r.url()) && r.request().method() === 'POST') {
          responses.push({ status: r.status(), url: r.url() });
        }
      });
      try {
        await confirmBtn.click({ timeout: 2_000 });
      } catch (_) { /* button might race */ }
      await page.waitForTimeout(3_000);
      const shot3 = await snap(page, 3, 'after-submit', 'captured');
      const lastResp = responses[responses.length - 1];
      const note = lastResp
        ? `POST /api/admin/pos status=${lastResp.status}. ${lastResp.status === 200 ? 'Backend accepte single-tender legacy fallback' : (lastResp.status === 422 ? 'Backend ignore payment_breakdown — attendu PLAN_P12' : 'Réponse inattendue')}`
        : 'Pas de réponse capturée (rate-limit ou flow interrompu)';
      const sev = lastResp && lastResp.status >= 500 ? 'P1' : 'INFO';
      recordFinding('SP-02', 'submit-attempt', 'captured', sev, note, shot3);
    }
  });

  test('SP-03 — Multi : diviser entre 3 personnes (parts égales)', async ({ page }) => {
    await loginAsPosOperator(page);
    await page.waitForTimeout(2_500);

    const tile = await clickFirstAvailableTile(page);
    if (!tile.clicked) { return; }
    if (tile.wizardOpen) {
      await confirmWizardAddToCart(page);
      await page.waitForTimeout(1_000);
    }

    const modal = await openPaymentModal(page);
    if (!modal.ok) { return; }

    const multiBtn = page.locator('[data-testid="pos-payment-mode-multi"]');
    if (!(await multiBtn.isVisible().catch(() => false))) { return; }
    await multiBtn.click();
    await page.waitForTimeout(600);

    // Set splitCount=3 then click "Diviser"
    const countInput = page.locator('[data-testid="pos-payment-split-count"]');
    await countInput.fill('3');
    await page.waitForTimeout(150);

    const splitBtn = page.locator('[data-testid="pos-payment-split-equal"]');
    await splitBtn.click();
    await page.waitForTimeout(500);

    const shot = await snap(page, 1, 'split-3-tranches', 'success');

    // Count rows
    const rowCount = await page.locator('[data-testid^="pos-payment-tranche-row-"]').count();
    recordFinding('SP-03', 'split-3-rows', 'observed',
      rowCount === 3 ? 'OK' : 'P1',
      `${rowCount} tranches générées (attendu 3)`, shot);

    // The 3 tranches are CASH-default; tendered fields are empty → confirm
    // should be disabled because tranches are invalid.
    const confirmDisabled = await page.locator('[data-testid="pos-payment-confirm"]').isDisabled().catch(() => true);
    recordFinding('SP-03', 'confirm-disabled-when-tendered-missing', 'observed',
      confirmDisabled ? 'OK' : 'P1',
      `Confirmer disabled: ${confirmDisabled} (attendu true car tendered manquant)`, shot);
  });

  test('SP-04 — A11y : aria-live / labels présents sur le bloc multi', async ({ page }) => {
    await loginAsPosOperator(page);
    await page.waitForTimeout(2_500);

    const tile = await clickFirstAvailableTile(page);
    if (!tile.clicked) { return; }
    if (tile.wizardOpen) {
      await confirmWizardAddToCart(page);
      await page.waitForTimeout(1_000);
    }

    const modal = await openPaymentModal(page);
    if (!modal.ok) { return; }

    await page.locator('[data-testid="pos-payment-mode-multi"]').click();
    await page.waitForTimeout(500);
    await page.locator('[data-testid="pos-payment-tranche-add"]').click();
    await page.waitForTimeout(400);

    // aria-live on remaining-due
    const ariaLive = await page.locator('[data-testid="pos-payment-remaining-due-row"]').getAttribute('aria-live').catch(() => null);
    // label on amount input
    const amountLabel = await page.locator('label[for="tranche-amount-0"]').count();

    const shot = await snap(page, 1, 'a11y-check', 'captured');
    recordFinding('SP-04', 'aria-live', 'observed',
      ariaLive === 'polite' ? 'OK' : 'P2',
      `aria-live on remaining-due-row: ${ariaLive}`, shot);
    recordFinding('SP-04', 'amount-label', 'observed',
      amountLabel >= 1 ? 'OK' : 'P2',
      `Label for tranche-amount-0: ${amountLabel}`, shot);
  });

  test.afterAll(async () => {
    // Build index.md summary
    const lines = [];
    lines.push('# AUDIT POS SPLIT PAYMENT — 2026-05-06\n');
    lines.push('Mission : CV1-POS-SPLIT-PAYMENT-001\n');
    lines.push(`## Résumé findings : ${findings.length}\n`);
    const bySev = findings.reduce((acc, f) => { acc[f.severity] = (acc[f.severity] || 0) + 1; return acc; }, {});
    Object.entries(bySev).forEach(([k, v]) => lines.push(`- ${k}: ${v}`));
    lines.push('\n## Détail\n');
    findings.forEach((f) => {
      lines.push(`### ${f.step} — ${f.slug} (${f.severity})\n`);
      lines.push(`- État : ${f.state}`);
      lines.push(`- Note : ${f.note}`);
      if (f.screenshot) lines.push(`- Capture : ${f.screenshot}`);
      lines.push('');
    });
    fs.writeFileSync(INDEX_FILE, lines.join('\n'));
  });
});
