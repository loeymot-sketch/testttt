// FoodKing E2E — AUDIT POS CYCLE 3 (2026-05-06)
// Flow paiement complet : add via wizard "Ajouter au panier" → cart → paiement → ticket → tracker
// + corrections faux positifs cycles 1+2 (sélecteurs FR, wizard footer Vanilla JS)
// Wizard popup PROTÉGÉ — interactions seulement, AUCUNE modif design.
//
// Sortie : tests/e2e/screenshots/audit-pos-2026-05-06/cycle3/

const fs = require('fs');
const path = require('path');
const { test, expect } = require('@playwright/test');
const { loginAsPosOperator } = require('./helpers/login');
const { clearFoodKingRateLimits } = require('./helpers/rate-limit');

const SHOT_DIR = path.join(process.cwd(), 'tests/e2e/screenshots/audit-pos-2026-05-06/cycle3');
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
    console.warn(`[c3] snap ${filename} failed: ${e.message}`);
  });
  return path.relative(process.cwd(), fullPath);
}

// Click first non-unavailable tile, return whether wizard opened
async function clickFirstAvailableTile(page) {
  const tiles = page.locator('.pos-v5-tile');
  const count = await tiles.count();
  for (let i = 0; i < Math.min(count, 10); i++) {
    const tile = tiles.nth(i);
    const isUnavail = await tile.locator('.pos-item-86-badge').count();
    if (isUnavail === 0) {
      try {
        await tile.click({ timeout: 3_000 });
        await page.waitForTimeout(1_500);
        const wizardOpen = await page.locator('#item-variation-modal').isVisible().catch(() => false);
        return { clicked: true, index: i, wizardOpen };
      } catch (_) {}
    }
  }
  return { clicked: false, wizardOpen: false };
}

// Confirm wizard "Ajouter au panier" — Vanilla JS button with data-action="add-to-cart"
async function confirmWizardAddToCart(page) {
  // Wait for the wizard footer to render its button
  const addBtn = page.locator('#item-variation-modal [data-action="add-to-cart"], #item-variation-modal .wizard-btn-cart').first();
  const visible = await addBtn.isVisible({ timeout: 4_000 }).catch(() => false);
  if (!visible) return { ok: false, reason: 'add-to-cart button not visible in wizard' };
  // Wizard may require step completion before enabling — try clicking directly,
  // if disabled, fallback to dispatching the custom event the JS listens for.
  const isDisabled = await addBtn.isDisabled().catch(() => false);
  if (isDisabled) {
    // Try dispatching the wizard:add-to-cart custom event directly (bypass step gate for capture purposes)
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

test.describe.configure({ mode: 'serial' });

test.describe('AUDIT POS CYCLE 3 — Flow paiement complet', () => {
  test.setTimeout(180_000);

  test.beforeEach(async () => {
    // Clear rate limits before each test (POS create = 60/min, sentinels can hit cap fast)
    try { clearFoodKingRateLimits(); } catch (_) {}
  });

  test('C3-01 — Add via wizard "Ajouter au panier" → cart non-vide', async ({ page }) => {
    const apiCalls = [];
    page.on('request', (req) => {
      const url = req.url();
      if (/\/api\/(pos|admin\/pos|frontend\/order)/.test(url)) {
        apiCalls.push({ method: req.method(), url, status: 'pending', ts: Date.now() });
      }
    });
    page.on('response', (resp) => {
      const url = resp.url();
      if (/\/api\/(pos|admin\/pos|frontend\/order)/.test(url)) {
        const found = apiCalls.findLast((c) => c.url === url && c.status === 'pending');
        if (found) found.status = resp.status();
      }
    });

    await loginAsPosOperator(page);
    await page.waitForTimeout(2_500);

    const cartChipBefore = await page.locator('[data-testid="pos-cart-stat-chip"]').first().innerText().catch(() => 'n/a');
    const shotInitial = await snap(page, 1, 'cart-empty', 'initial');

    const click = await clickFirstAvailableTile(page);
    if (!click.clicked) {
      recordFinding('C3-01', 'tile-click', 'failed', 'P0', 'Aucune tuile clicable trouvée', shotInitial);
      return;
    }

    if (!click.wizardOpen) {
      // Item simple ajouté directement
      const cartChipAfter = await page.locator('[data-testid="pos-cart-stat-chip"]').first().innerText().catch(() => 'n/a');
      const shot = await snap(page, 1, 'simple-item-added', 'no-wizard');
      recordFinding('C3-01', 'simple-add', 'no-wizard', 'OK', `Item ajouté direct (sans wizard). Cart: avant="${cartChipBefore}" → après="${cartChipAfter}"`, shot);
      return;
    }

    // Wizard ouvert — capturer + valider Add to cart
    const shotWizard = await snap(page, 1, 'wizard-opened', 'before-confirm');

    const result = await confirmWizardAddToCart(page);
    await page.waitForTimeout(1_500);

    const wizardStillOpen = await page.locator('#item-variation-modal').isVisible().catch(() => false);
    const shotAfter = await snap(page, 1, 'wizard', wizardStillOpen ? 'still-open-after-confirm' : 'closed-after-confirm');

    const cartChipAfter = await page.locator('[data-testid="pos-cart-stat-chip"]').first().innerText().catch(() => 'n/a');
    const cartChipChanged = cartChipBefore !== cartChipAfter;

    recordFinding('C3-01', 'wizard-confirm', result.ok ? 'clicked' : 'failed',
      result.ok && cartChipChanged ? 'OK' : 'P1',
      `Confirm result=${result.reason}, wizardClosed=${!wizardStillOpen}, cart="${cartChipBefore}" → "${cartChipAfter}" (changed=${cartChipChanged})`, shotAfter);

    fs.writeFileSync(path.join(SHOT_DIR, 'C3-01-api-calls.json'), JSON.stringify(apiCalls, null, 2));
    recordFinding('C3-01', 'api-calls', 'count', 'INFO', `${apiCalls.length} POS API calls capturés`, shotAfter);
  });

  test('C3-02 — Cart populated → bouton paiement → modal paiement', async ({ page }) => {
    await loginAsPosOperator(page);
    await page.waitForTimeout(2_500);

    // Step 1: add item
    const click = await clickFirstAvailableTile(page);
    if (click.wizardOpen) {
      await confirmWizardAddToCart(page);
      await page.waitForTimeout(1_500);
    }

    const shotCart = await snap(page, 2, 'cart-populated', 'with-item');

    // [FIX] Bouton qui OUVRE le modal paiement = pos-v5-pay (pas pos-payment-confirm qui est DANS le modal)
    const payBtn = page.locator('[data-testid="pos-v5-pay"]').first();
    const payVisible = await payBtn.isVisible({ timeout: 4_000 }).catch(() => false);

    if (!payVisible) {
      recordFinding('C3-02', 'pay-button', 'invisible', 'P1', 'Bouton pos-v5-pay invisible après ajout item — cart probablement vide', shotCart);
      return;
    }

    await payBtn.click();
    await page.waitForTimeout(1_500);

    const shotPayment = await snap(page, 2, 'payment-modal', 'opened');

    // Vérifier sections V5 paiement présentes
    const totalLabel = await page.locator('.pos-v5-payment-total-label').isVisible().catch(() => false);
    const cashIcon = await page.locator('.pos-v5-payment-method-icon').filter({ hasText: '💵' }).isVisible().catch(() => false);
    const cardIcon = await page.locator('.pos-v5-payment-method-icon').filter({ hasText: '💳' }).isVisible().catch(() => false);

    recordFinding('C3-02', 'payment-modal', 'sections',
      totalLabel && (cashIcon || cardIcon) ? 'OK' : 'P2',
      `Total label=${totalLabel}, cash=${cashIcon}, card=${cardIcon}`, shotPayment);
  });

  test('C3-03 — Paiement cash : sélection méthode + saisie montant', async ({ page }) => {
    await loginAsPosOperator(page);
    await page.waitForTimeout(2_500);

    const click = await clickFirstAvailableTile(page);
    if (click.wizardOpen) {
      await confirmWizardAddToCart(page);
      await page.waitForTimeout(1_500);
    }

    const payBtn = page.locator('[data-testid="pos-v5-pay"]').first();
    if (!(await payBtn.isVisible().catch(() => false))) {
      const shot = await snap(page, 3, 'payment', 'pay-button-missing');
      recordFinding('C3-03', 'pay-button', 'missing', 'P1', 'Pas de bouton pos-v5-pay', shot);
      return;
    }
    await payBtn.click();
    await page.waitForTimeout(1_200);

    // Sélectionner cash
    const cashMethod = page.locator('button.pos-v5-payment-method, [data-payment-method="cash"]').filter({ hasText: /cash|espèce|esp/i }).first();
    const cashVisible = await cashMethod.isVisible({ timeout: 3_000 }).catch(() => false);
    if (!cashVisible) {
      const shot = await snap(page, 3, 'payment', 'cash-method-missing');
      recordFinding('C3-03', 'cash-method', 'missing', 'P1', 'Bouton cash non détecté', shot);
      return;
    }
    await cashMethod.click();
    await page.waitForTimeout(700);
    const shotCash = await snap(page, 3, 'payment-cash', 'selected');
    recordFinding('C3-03', 'cash-method', 'selected', 'OK', 'Méthode cash sélectionnée', shotCash);

    // Saisir montant
    const cashInput = page.locator('#cashInput, input[name="received_amount"], input.pos-v5-payment-input').first();
    if (await cashInput.isVisible().catch(() => false)) {
      await cashInput.click();
      await cashInput.fill('20.00');
      await page.waitForTimeout(700);
      const shotAmount = await snap(page, 3, 'payment-cash', 'amount-20');
      recordFinding('C3-03', 'cash-amount', 'filled', 'OK', 'Montant 20.00€ saisi', shotAmount);

      // Vérifier affichage du change si présent
      const change = await page.locator('.pos-v5-payment-change').isVisible().catch(() => false);
      recordFinding('C3-03', 'cash-change', change ? 'visible' : 'not-visible', change ? 'OK' : 'INFO', `Change displayed=${change}`, shotAmount);
    } else {
      recordFinding('C3-03', 'cash-input', 'missing', 'P2', 'Input cash non détecté (peut-être numpad seulement)', shotCash);
    }
  });

  test('C3-04 — Submit paiement (capture flow API)', async ({ page }) => {
    const apiCalls = [];
    page.on('request', (req) => {
      const url = req.url();
      if (/\/api\/(pos|admin\/pos|frontend\/order)/.test(url)) {
        apiCalls.push({ method: req.method(), url, status: 'pending', body: req.postDataBuffer()?.toString().slice(0, 500), ts: Date.now() });
      }
    });
    page.on('response', (resp) => {
      const url = resp.url();
      if (/\/api\/(pos|admin\/pos|frontend\/order)/.test(url)) {
        const found = apiCalls.findLast((c) => c.url === url && c.status === 'pending');
        if (found) found.status = resp.status();
      }
    });

    await loginAsPosOperator(page);
    await page.waitForTimeout(2_500);

    const click = await clickFirstAvailableTile(page);
    if (click.wizardOpen) {
      await confirmWizardAddToCart(page);
      await page.waitForTimeout(1_500);
    }

    const payBtn = page.locator('[data-testid="pos-v5-pay"]').first();
    if (!(await payBtn.isVisible().catch(() => false))) {
      const shot = await snap(page, 4, 'submit', 'no-pay-button');
      recordFinding('C3-04', 'submit', 'no-pay', 'P1', 'Pas de bouton pos-v5-pay', shot);
      return;
    }
    await payBtn.click();
    await page.waitForTimeout(1_200);

    // Cash + 20€
    const cashMethod = page.locator('.pos-v5-payment-method').filter({ hasText: /cash|espèce|esp/i }).first();
    if (await cashMethod.isVisible().catch(() => false)) {
      await cashMethod.click();
      await page.waitForTimeout(500);
    }
    const cashInput = page.locator('#cashInput, input.pos-v5-payment-input').first();
    if (await cashInput.isVisible().catch(() => false)) {
      await cashInput.fill('20.00');
      await page.waitForTimeout(500);
    }

    const shotPreSubmit = await snap(page, 4, 'submit', 'pre-click');

    // [FIX] Le bouton submit final dans le modal paiement = pos-payment-confirm (PaymentComponent.vue:132)
    const submitBtn = page.locator('[data-testid="pos-payment-confirm"]').first();
    let submitted = false;
    if (await submitBtn.isVisible({ timeout: 3_000 }).catch(() => false)) {
      try {
        await submitBtn.click({ timeout: 3_000 });
        submitted = true;
        await page.waitForTimeout(3_500);
      } catch (e) {
        recordFinding('C3-04', 'submit-click', 'failed', 'P2', `Click pos-payment-confirm échoué: ${e.message}`, shotPreSubmit);
      }
    } else {
      // Fallback : rechercher par texte
      const fallback = page.locator('button').filter({ hasText: /valider|confirmer|payer|encaisser/i }).first();
      if (await fallback.isVisible().catch(() => false)) {
        try {
          await fallback.click({ timeout: 3_000 });
          submitted = true;
          await page.waitForTimeout(3_500);
        } catch (e) {
          recordFinding('C3-04', 'submit-fallback', 'failed', 'P2', `Fallback submit failed: ${e.message}`, shotPreSubmit);
        }
      } else {
        recordFinding('C3-04', 'submit-button', 'not-found', 'P2', 'Aucun bouton submit (pos-payment-confirm + fallback texte)', shotPreSubmit);
      }
    }

    const shotPostSubmit = await snap(page, 4, 'submit', submitted ? 'after-submit' : 'no-submit');

    // Capture API calls
    fs.writeFileSync(path.join(SHOT_DIR, 'C3-04-api-calls.json'), JSON.stringify(apiCalls, null, 2));

    const posCreate = apiCalls.find((c) => /\/api\/admin\/pos$/.test(c.url) && c.method === 'POST');
    const posQuote = apiCalls.find((c) => /\/api\/pos\/quote/.test(c.url) && c.method === 'POST');

    recordFinding('C3-04', 'api-flow', 'capture', 'INFO',
      `Total: ${apiCalls.length}. POS create POST: ${posCreate ? `status=${posCreate.status}` : 'absent'}. Quote: ${posQuote ? `status=${posQuote.status}` : 'absent'}`,
      shotPostSubmit);

    // Detect ticket display post-submit
    const receiptVisible = await page.locator('[class*="receipt" i], [data-testid*="receipt"]').isVisible().catch(() => false);
    if (receiptVisible) {
      const shotReceipt = await snap(page, 4, 'receipt', 'displayed');
      recordFinding('C3-04', 'receipt', 'displayed', 'OK', 'Ticket affiché après submit', shotReceipt);
    }
  });

  test('C3-05 — Synthèse cycle 3 + INDEX', async ({ page }) => {
    await loginAsPosOperator(page);
    await page.waitForTimeout(1_500);

    const indexPath = path.join(SHOT_DIR, 'INDEX.md');
    const lines = ['# AUDIT POS CYCLE 3 — Captures Index 2026-05-06', '', `Total findings cycle 3: ${findings.length}`, '', '| Step | Slug | State | Severity | Note | Screenshot |', '| --- | --- | --- | --- | --- | --- |'];
    for (const f of findings) {
      lines.push(`| ${f.step} | ${f.slug} | ${f.state} | ${f.severity} | ${String(f.note).replace(/\|/g, '\\|').slice(0, 200)} | \`${f.screenshot || '—'}\` |`);
    }
    fs.writeFileSync(indexPath, lines.join('\n'));

    expect(findings.length).toBeGreaterThan(0);
  });
});
