// FoodKing E2E — AUDIT POS CYCLE 2 (2026-05-06)
// Flow complet : add simple item → paiement → tracker → ticket + correction faux positifs cycle 1.
// Wizard popup TOUJOURS PROTÉGÉ (cf. feedback memory).
//
// Sortie : tests/e2e/screenshots/audit-pos-2026-05-06/cycle2/{NN}-{slug}-{state}.png

const fs = require('fs');
const path = require('path');
const { test, expect } = require('@playwright/test');
const { loginAsPosOperator } = require('./helpers/login');

const SHOT_DIR = path.join(process.cwd(), 'tests/e2e/screenshots/audit-pos-2026-05-06/cycle2');
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
    console.warn(`[audit-c2] snap ${filename} failed: ${e.message}`);
  });
  return path.relative(process.cwd(), fullPath);
}

// Trouve une tuile NON-composée (pas de wizard) en cliquant et en testant si modal s'ouvre
async function findSimpleItemTile(page) {
  // Stratégie : essayer items dont le nom contient "Frites Seules", "Boisson Seule" en priorité
  const knownSimple = ['Frites Seules', 'Boisson Seule', 'Frites seules', 'Boisson seule'];
  for (const name of knownSimple) {
    const tile = page.locator('.pos-v5-tile', { hasText: name }).first();
    if (await tile.isVisible({ timeout: 1500 }).catch(() => false)) {
      return { tile, name };
    }
  }
  return null;
}

test.describe.configure({ mode: 'serial' });

test.describe('AUDIT POS CYCLE 2 — Flow complet + corrections', () => {
  test.setTimeout(180_000);

  test('C2-01 — Capture par catégorie (15 catégories)', async ({ page }) => {
    await loginAsPosOperator(page);
    await page.waitForTimeout(2_500);

    const cats = page.locator('.pos-v5-category, .pos-v4-category-pill');
    const count = await cats.count();
    recordFinding('C2-01', 'categories-total', 'detected', 'INFO', `${count} catégories détectées`, '');

    for (let i = 0; i < Math.min(count, 15); i++) {
      try {
        const cat = cats.nth(i);
        const label = await cat.locator('.pos-v5-category__label, span').first().innerText().catch(() => `cat-${i}`);
        await cat.click({ timeout: 2_000 });
        await page.waitForTimeout(700);
        const slug = String(label).toLowerCase().replace(/[^a-z0-9]+/g, '-').slice(0, 30) || `cat-${i}`;
        const shot = await snap(page, 'C2-01', `cat-${i}-${slug}`, 'filtered');
        const tilesNow = await page.locator('.pos-v5-tile').count();
        recordFinding('C2-01', `cat-${i}`, slug, tilesNow > 0 ? 'OK' : 'P2', `Catégorie "${label}" → ${tilesNow} produits`, shot);
      } catch (e) {
        recordFinding('C2-01', `cat-${i}`, 'click-fail', 'P2', `Échec clic catégorie ${i}: ${e.message}`, '');
      }
    }
  });

  test('C2-02 — Trouver et cliquer item simple (sans wizard)', async ({ page }) => {
    await loginAsPosOperator(page);
    await page.waitForTimeout(2_500);

    const found = await findSimpleItemTile(page);
    if (!found) {
      const shot = await snap(page, 'C2-02', 'simple-item', 'not-found');
      recordFinding('C2-02', 'simple-item', 'not-found', 'P2', 'Aucun item dont le nom contient "Frites Seules" / "Boisson Seule" trouvé — aucun simple identifiable par nom', shot);
      return;
    }

    const shotBefore = await snap(page, 'C2-02', 'simple-item', `before-click-${found.name.replace(/\s+/g, '-')}`);

    await found.tile.click();
    await page.waitForTimeout(1_500);

    const wizardOpen = await page.locator('#item-variation-modal').isVisible().catch(() => false);
    const shotAfter = await snap(page, 'C2-02', 'simple-item', wizardOpen ? 'wizard-opened-unexpected' : 'cart-added');

    if (wizardOpen) {
      recordFinding('C2-02', 'simple-item', 'wizard-opened-unexpected', 'P2', `Item "${found.name}" a déclenché un wizard — n'était pas vraiment simple. Fermer et passer suite`, shotAfter);
      await page.keyboard.press('Escape');
      await page.waitForTimeout(500);
    } else {
      recordFinding('C2-02', 'simple-item', 'cart-added', 'OK', `Item "${found.name}" ajouté direct au cart sans wizard`, shotAfter);
    }
  });

  test('C2-03 — Cart non-vide → bouton paiement visible', async ({ page }) => {
    const apiCalls = [];
    page.on('request', (req) => {
      const url = req.url();
      if (/\/api\/pos\/(quote|walk-in)/.test(url) || /\/api\/pos$/.test(url) || /\/api\/pos-order/.test(url)) {
        apiCalls.push({ method: req.method(), url, ts: Date.now() });
      }
    });

    await loginAsPosOperator(page);
    await page.waitForTimeout(2_500);

    const found = await findSimpleItemTile(page);
    if (found) {
      await found.tile.click();
      await page.waitForTimeout(1_500);
      const wizardOpen = await page.locator('#item-variation-modal').isVisible().catch(() => false);
      if (wizardOpen) {
        await page.keyboard.press('Escape');
        await page.waitForTimeout(500);
      }
    }

    const shot1 = await snap(page, 'C2-03', 'cart-state', 'after-add');

    const cartChip = await page.locator('[data-testid="pos-cart-stat-chip"]').first().innerText().catch(() => 'n/a');
    const payBtn = page.locator('[data-testid="pos-payment-confirm"]').first();
    const payVisible = await payBtn.isVisible().catch(() => false);

    recordFinding('C2-03', 'cart-after-add', 'state', payVisible ? 'OK' : 'P2', `Cart chip="${cartChip}", bouton paiement visible=${payVisible}`, shot1);
    recordFinding('C2-03', 'pos-api-calls', 'count', 'INFO', `${apiCalls.length} appel(s) POS API capturés`, shot1);

    fs.writeFileSync(path.join(SHOT_DIR, 'C2-03-pos-api-calls.json'), JSON.stringify(apiCalls, null, 2));

    if (!payVisible) return;

    // Ouvrir paiement
    await payBtn.click();
    await page.waitForTimeout(1_500);
    const shot2 = await snap(page, 'C2-03', 'payment', 'opened');
    recordFinding('C2-03', 'payment-modal', 'opened', 'OK', 'Modal paiement ouvert après clic confirm', shot2);

    // Capturer méthodes paiement (cash + card visibles)
    const cashMethod = page.locator('.pos-v5-payment-method').filter({ hasText: /cash|espèce|esp/i }).first();
    const cardMethod = page.locator('.pos-v5-payment-method').filter({ hasText: /card|carte/i }).first();
    const cashVisible = await cashMethod.isVisible().catch(() => false);
    const cardVisible = await cardMethod.isVisible().catch(() => false);

    recordFinding('C2-03', 'payment-methods', 'visibility', cashVisible && cardVisible ? 'OK' : 'P2', `cash=${cashVisible}, card=${cardVisible}`, shot2);

    if (cashVisible) {
      await cashMethod.click();
      await page.waitForTimeout(800);
      const shot3 = await snap(page, 'C2-03', 'payment-cash', 'selected');
      recordFinding('C2-03', 'payment-cash', 'selected', 'OK', 'Méthode cash sélectionnée', shot3);

      // Tenter saisie montant (input cashInput)
      const cashInput = page.locator('#cashInput, input[name="received_amount"]').first();
      if (await cashInput.isVisible().catch(() => false)) {
        await cashInput.fill('20.00').catch(() => {});
        await page.waitForTimeout(500);
        const shot4 = await snap(page, 'C2-03', 'payment-cash', 'amount-filled');
        recordFinding('C2-03', 'payment-cash', 'amount-filled', 'OK', 'Montant 20.00€ saisi', shot4);
      }
    }
  });

  test('C2-04 — Bouton "Mettre en attente" / "Commandes en attente" (correction faux positif step 13)', async ({ page }) => {
    await loginAsPosOperator(page);
    await page.waitForTimeout(2_500);

    const parkBtns = page.locator('button, [role="button"]').filter({ hasText: /park|garder|parquer|mettre en attente|commandes en attente/i });
    const count = await parkBtns.count();
    const shot = await snap(page, 'C2-04', 'parked-fr', count > 0 ? 'visible' : 'missing');
    recordFinding('C2-04', 'parked-fr', count > 0 ? 'visible' : 'missing', count > 0 ? 'OK' : 'P1', `${count} bouton(s) park (FR + EN) trouvé(s)`, shot);

    // Cliquer "Commandes en attente" pour ouvrir la liste si présente
    const recallBtn = parkBtns.filter({ hasText: /commandes en attente/i }).first();
    if (await recallBtn.isVisible().catch(() => false)) {
      await recallBtn.click();
      await page.waitForTimeout(1_000);
      const shot2 = await snap(page, 'C2-04', 'parked-list', 'opened');
      recordFinding('C2-04', 'parked-list', 'opened', 'OK', 'Liste commandes en attente ouverte', shot2);
    }
  });

  test('C2-05 — Tracker post-paiement (capture liste live)', async ({ page }) => {
    await loginAsPosOperator(page);
    await page.waitForTimeout(2_500);

    const trackerBtn = page.locator('[data-testid="pos-tracker-open"]');
    if (!(await trackerBtn.isVisible().catch(() => false))) {
      const shot = await snap(page, 'C2-05', 'tracker', 'btn-missing');
      recordFinding('C2-05', 'tracker', 'btn-missing', 'P2', 'Bouton tracker invisible', shot);
      return;
    }

    await trackerBtn.click();
    await page.waitForTimeout(1_500);
    const shot = await snap(page, 'C2-05', 'tracker', 'opened');
    recordFinding('C2-05', 'tracker', 'opened', 'OK', 'Vue tracker ouverte', shot);
  });

  test('C2-06 — Synthèse cycle 2', async ({ page }) => {
    await loginAsPosOperator(page);
    await page.waitForTimeout(1_500);

    const indexPath = path.join(SHOT_DIR, 'INDEX.md');
    const lines = ['# AUDIT POS CYCLE 2 — Captures Index 2026-05-06', '', `Total findings cycle 2: ${findings.length}`, '', '| Step | Slug | State | Severity | Note | Screenshot |', '| --- | --- | --- | --- | --- | --- |'];
    for (const f of findings) {
      lines.push(`| ${f.step} | ${f.slug} | ${f.state} | ${f.severity} | ${String(f.note).replace(/\|/g, '\\|').slice(0, 200)} | \`${f.screenshot || '—'}\` |`);
    }
    fs.writeFileSync(indexPath, lines.join('\n'));

    expect(findings.length).toBeGreaterThan(0);
  });
});
