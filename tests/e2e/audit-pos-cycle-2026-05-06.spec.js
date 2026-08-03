// FoodKing E2E — AUDIT POS MASSIF 2026-05-06
// Captures + assertions douces par étape POS (focus 6→27 du master plan).
// Le wizard popup est CAPTURÉ mais NON MODIFIÉ — design protégé (cf. feedback memory).
//
// Sortie : tests/e2e/screenshots/audit-pos-2026-05-06/{NN}-{slug}-{state}.png
// Rapport : agréger via reports/antigravity/playwright-latest.json

const fs = require('fs');
const path = require('path');
const { test, expect } = require('@playwright/test');
const { loginAsPosOperator } = require('./helpers/login');

const SHOT_DIR = path.join(process.cwd(), 'tests/e2e/screenshots/audit-pos-2026-05-06');
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
    console.warn(`[audit] snap ${filename} failed: ${e.message}`);
  });
  return path.relative(process.cwd(), fullPath);
}

async function captureJsErrors(page) {
  const errors = [];
  page.on('pageerror', (err) => errors.push(err.message));
  page.on('console', (msg) => {
    if (msg.type() === 'error') errors.push(`[console] ${msg.text()}`);
  });
  return errors;
}

test.describe.configure({ mode: 'serial' });

test.describe('AUDIT POS — Cycle massif 2026-05-06', () => {
  test.setTimeout(120_000);

  test('Step 02 — Surface POS chargée + design tokens V5', async ({ page }) => {
    const errors = await captureJsErrors(page);
    await loginAsPosOperator(page);
    await page.waitForTimeout(2_500);

    const shot = await snap(page, 2, 'surface-load', 'initial');

    // Présence shell V5
    const shell = page.locator('[data-pos-v5-shell], .pos-v5-shell').first();
    const shellVisible = await shell.isVisible().catch(() => false);
    if (!shellVisible) {
      recordFinding(2, 'surface-load', 'initial', 'P1', 'Shell .pos-v5-shell non visible — DS V5 peut-être pas appliqué', shot);
    }

    // Tokens DS V5 (CSS vars)
    const tokens = await page.evaluate(() => {
      const cs = getComputedStyle(document.documentElement);
      return {
        bgApp: cs.getPropertyValue('--pos-v5-bg-app').trim(),
        brandRed: cs.getPropertyValue('--pos-v5-brand-red').trim(),
        border: cs.getPropertyValue('--pos-v5-border').trim(),
      };
    });
    if (!tokens.bgApp || !tokens.brandRed) {
      recordFinding(2, 'surface-load', 'initial', 'P1', `Tokens V5 absents au runtime : bgApp="${tokens.bgApp}" brandRed="${tokens.brandRed}"`, shot);
    } else {
      recordFinding(2, 'surface-load', 'initial', 'OK', `Tokens V5 OK : bgApp=${tokens.bgApp}, brandRed=${tokens.brandRed}`, shot);
    }

    // Erreurs JS
    if (errors.length > 0) {
      const critical = errors.filter((m) => /TypeError|ReferenceError|Cannot read|is not a function|is not defined|Uncaught/i.test(m));
      if (critical.length > 0) {
        recordFinding(2, 'surface-load', 'js-errors', 'P0', `Erreurs JS critiques: ${critical.slice(0, 3).join(' | ')}`, shot);
      }
    }
  });

  test('Step 06 — Strip catégories visible + interactive', async ({ page }) => {
    await loginAsPosOperator(page);
    await page.waitForTimeout(2_500);

    const strip = page.locator('.pos-v5-category-strip, .pos-v4-category-strip').first();
    const stripVisible = await strip.isVisible({ timeout: 8_000 }).catch(() => false);
    const shot = await snap(page, 6, 'categories-strip', stripVisible ? 'visible' : 'missing');

    if (!stripVisible) {
      recordFinding(6, 'categories-strip', 'missing', 'P0', 'Strip catégories invisible après login POS — bloque la prise de commande', shot);
      return;
    }

    const categories = page.locator('.pos-v5-category, .pos-v4-category-pill');
    const count = await categories.count();
    recordFinding(6, 'categories-strip', 'visible', count >= 2 ? 'OK' : 'P2', `${count} catégorie(s) affichée(s)`, shot);

    // Click sur 2e catégorie si disponible
    if (count >= 2) {
      try {
        await categories.nth(1).click({ timeout: 3_000 });
        await page.waitForTimeout(800);
        const shot2 = await snap(page, 6, 'categories-strip', 'after-click-2nd');
        recordFinding(6, 'categories-strip', 'click-2nd', 'OK', 'Clic 2e catégorie réussi (filtrage menu)', shot2);
      } catch (e) {
        recordFinding(6, 'categories-strip', 'click-2nd-fail', 'P2', `Clic 2e catégorie échoué : ${e.message}`, shot);
      }
    }
  });

  test('Step 07 — Grille produits .pos-v5-grid + tuiles', async ({ page }) => {
    await loginAsPosOperator(page);
    await page.waitForTimeout(2_500);

    const grid = page.locator('.pos-v5-grid').first();
    const gridVisible = await grid.isVisible({ timeout: 8_000 }).catch(() => false);
    const shot = await snap(page, 7, 'items-grid', gridVisible ? 'visible' : 'missing');

    if (!gridVisible) {
      recordFinding(7, 'items-grid', 'missing', 'P0', 'Grille .pos-v5-grid invisible — pas de produits affichés', shot);
      return;
    }

    const tiles = page.locator('.pos-v5-tile');
    const count = await tiles.count();
    recordFinding(7, 'items-grid', 'visible', count >= 1 ? 'OK' : 'P0', `${count} tuile(s) produit affichée(s)`, shot);

    // Vérifier indicateurs rupture (overlay 86)
    const unavailable = await page.locator('.pos-item-86-badge, .pos-v5-tile__overlay').count();
    if (unavailable > 0) {
      recordFinding(7, 'items-grid', 'unavailable-detected', 'INFO', `${unavailable} item(s) marqué(s) indispo (overlay 86)`, shot);
    }
  });

  test('Step 08 — Add simple item to cart', async ({ page }) => {
    await loginAsPosOperator(page);
    await page.waitForTimeout(2_500);

    const tiles = page.locator('.pos-v5-tile');
    const tileCount = await tiles.count();
    if (tileCount === 0) {
      const shot = await snap(page, 8, 'add-simple-item', 'no-tiles');
      recordFinding(8, 'add-simple-item', 'no-tiles', 'P0', 'Aucune tuile produit — impossible de tester ajout', shot);
      return;
    }

    const cartChipBefore = await page.locator('[data-testid="pos-cart-stat-chip"]').first().innerText().catch(() => 'n/a');
    const shotBefore = await snap(page, 8, 'add-simple-item', 'before-click');

    // Cliquer la première tuile non-indispo
    let clicked = false;
    for (let i = 0; i < Math.min(tileCount, 5); i++) {
      const tile = tiles.nth(i);
      const isUnavail = await tile.locator('.pos-item-86-badge').count();
      if (isUnavail === 0) {
        try {
          await tile.click({ timeout: 3_000 });
          clicked = true;
          break;
        } catch (_) {}
      }
    }

    await page.waitForTimeout(1_500);
    const shotAfter = await snap(page, 8, 'add-simple-item', clicked ? 'after-click' : 'click-failed');

    // Si modal wizard s'ouvre, c'est un item composé/variation — fermer pour ne pas affecter le cart
    const wizardOpen = await page.locator('#item-variation-modal').isVisible().catch(() => false);
    if (wizardOpen) {
      recordFinding(8, 'add-simple-item', 'wizard-opened', 'INFO', 'Premier item testé est composé (wizard ouvert) — capture wizard côté Step 09', shotAfter);
      // Fermer wizard
      await page.keyboard.press('Escape').catch(() => {});
      await page.waitForTimeout(800);
    } else {
      const cartChipAfter = await page.locator('[data-testid="pos-cart-stat-chip"]').first().innerText().catch(() => 'n/a');
      recordFinding(8, 'add-simple-item', 'cart-chip', clicked ? 'OK' : 'P1', `Cart chip avant=${cartChipBefore} | après=${cartChipAfter}`, shotAfter);
    }
  });

  test('Step 09 — Wizard popup item à variations (CAPTURE ONLY, design protégé)', async ({ page }) => {
    await loginAsPosOperator(page);
    await page.waitForTimeout(2_500);

    // Chercher tuile avec badge composer/variations
    const tiles = page.locator('.pos-v5-tile');
    const tileCount = await tiles.count();
    let wizardOpened = false;

    for (let i = 0; i < Math.min(tileCount, 8); i++) {
      try {
        await tiles.nth(i).click({ timeout: 2_000 });
        await page.waitForTimeout(1_000);
        const isOpen = await page.locator('#item-variation-modal').isVisible().catch(() => false);
        if (isOpen) {
          wizardOpened = true;
          break;
        }
      } catch (_) {}
    }

    const shot = await snap(page, 9, 'wizard-popup', wizardOpened ? 'opened-PROTECTED' : 'no-wizard-found');

    if (wizardOpened) {
      // Vérifier structure wizard sans modifier
      const hasFooter = await page.locator('.pos-v4-item-wizard-footer').isVisible().catch(() => false);
      const hasAddCta = await page.locator('.pos-v5-item-add-cta').isVisible().catch(() => false);
      recordFinding(9, 'wizard-popup', 'opened-PROTECTED', 'OK', `Wizard ouvert (DESIGN PROTÉGÉ — audit capture only). Footer=${hasFooter}, CTA=${hasAddCta}`, shot);
      // Fermer
      await page.keyboard.press('Escape').catch(() => {});
      await page.waitForTimeout(500);
    } else {
      recordFinding(9, 'wizard-popup', 'no-wizard-found', 'INFO', 'Aucun item à wizard trouvé sur 8 premières tuiles — possible absence de produits composés en seed', shot);
    }
  });

  test('Step 11 — Cart panier visible (sidebar/aside)', async ({ page }) => {
    await loginAsPosOperator(page);
    await page.waitForTimeout(2_500);

    // Cart aside (recherché via selectors flexibles)
    const cartCandidates = [
      '#pos-cart',
      '[data-testid="pos-cart"]',
      '.pos-v5-cart',
      '.pos-v4-cart',
      'aside[aria-label*="cart" i]',
      'aside[aria-label*="panier" i]',
    ];

    let visibleSel = null;
    for (const sel of cartCandidates) {
      if (await page.locator(sel).first().isVisible().catch(() => false)) {
        visibleSel = sel;
        break;
      }
    }

    const shot = await snap(page, 11, 'cart-panel', visibleSel ? 'visible' : 'not-found');
    if (visibleSel) {
      recordFinding(11, 'cart-panel', 'visible', 'OK', `Cart panel détecté via selector: ${visibleSel}`, shot);
    } else {
      recordFinding(11, 'cart-panel', 'not-found', 'P2', `Aucun cart panel détecté (sélecteurs testés : ${cartCandidates.join(', ')}). Possible cart inline dans PosComponent ou nom selector différent`, shot);
    }
  });

  test('Step 13 — Park / Parked orders boutons', async ({ page }) => {
    await loginAsPosOperator(page);
    await page.waitForTimeout(2_500);

    const parkBtns = page.locator('button, [role="button"]').filter({ hasText: /park|garder|parquer/i });
    const parkCount = await parkBtns.count();
    const shot = await snap(page, 13, 'parked-buttons', parkCount > 0 ? 'visible' : 'missing');
    recordFinding(13, 'parked-buttons', parkCount > 0 ? 'visible' : 'missing', parkCount > 0 ? 'OK' : 'P2', `${parkCount} bouton(s) park trouvé(s)`, shot);
  });

  test('Step 16 — Surface paiement (PaymentComponent)', async ({ page }) => {
    await loginAsPosOperator(page);
    await page.waitForTimeout(2_500);

    // Check si bouton paiement présent
    const payBtn = page.locator('[data-testid="pos-payment-confirm"], button:has-text("paiement"), button:has-text("payer")').first();
    const payVisible = await payBtn.isVisible({ timeout: 4_000 }).catch(() => false);
    const shot1 = await snap(page, 16, 'payment-trigger', payVisible ? 'visible' : 'missing');

    if (!payVisible) {
      recordFinding(16, 'payment-trigger', 'missing', 'INFO', 'Bouton paiement non visible (cart probablement vide — normal si aucun item ajouté)', shot1);
      return;
    }

    // Tenter d'ouvrir paiement (peut nécessiter cart non vide)
    try {
      await payBtn.click({ timeout: 2_000 });
      await page.waitForTimeout(1_200);

      // Vérifier méthodes paiement V5 visibles
      const cashMethod = await page.locator('.pos-v5-payment-method').filter({ hasText: /cash|espèce/i }).first().isVisible().catch(() => false);
      const cardMethod = await page.locator('.pos-v5-payment-method').filter({ hasText: /card|carte/i }).first().isVisible().catch(() => false);
      const shot2 = await snap(page, 16, 'payment-methods', 'opened');
      recordFinding(16, 'payment-methods', 'opened', cashMethod && cardMethod ? 'OK' : 'P2', `Méthodes V5 visibles : cash=${cashMethod}, card=${cardMethod}`, shot2);
    } catch (e) {
      recordFinding(16, 'payment-trigger', 'click-failed', 'P2', `Clic paiement échoué : ${e.message}`, shot1);
    }
  });

  test('Step 22-24 — Tracker post-paiement + receipt buttons', async ({ page }) => {
    await loginAsPosOperator(page);
    await page.waitForTimeout(2_500);

    // Bouton tracker
    const trackerBtn = page.locator('[data-testid="pos-tracker-open"]');
    const trackerVisible = await trackerBtn.isVisible().catch(() => false);
    const shot = await snap(page, 22, 'tracker-button', trackerVisible ? 'visible' : 'missing');
    recordFinding(22, 'tracker-button', trackerVisible ? 'visible' : 'missing', trackerVisible ? 'OK' : 'P2', `Bouton tracker visible : ${trackerVisible}`, shot);

    // Bouton drawer no-sale
    const noSaleBtn = page.locator('[data-testid="pos-no-sale"]');
    const noSaleVisible = await noSaleBtn.isVisible().catch(() => false);
    recordFinding(22, 'no-sale-button', noSaleVisible ? 'visible' : 'missing', noSaleVisible ? 'OK' : 'P2', `Bouton no-sale (drawer) visible : ${noSaleVisible}`, shot);
  });

  test('Step 26 — Network audit : appels /api/pos/quote (SSOT pricing)', async ({ page }) => {
    const apiCalls = [];
    page.on('request', (req) => {
      const url = req.url();
      if (/\/api\/pos\/(quote|walk-in)/.test(url) || /\/api\/pos$/.test(url)) {
        apiCalls.push({ method: req.method(), url, ts: Date.now() });
      }
    });

    await loginAsPosOperator(page);
    await page.waitForTimeout(3_000);

    // Tenter un add item pour potentiellement déclencher quote
    const tiles = page.locator('.pos-v5-tile');
    if (await tiles.count() > 0) {
      try {
        await tiles.first().click({ timeout: 2_000 });
        await page.waitForTimeout(1_500);
      } catch (_) {}
    }

    const shot = await snap(page, 26, 'network-audit', 'capture');
    fs.writeFileSync(path.join(SHOT_DIR, '26-network-pos-api-calls.json'), JSON.stringify(apiCalls, null, 2));

    const quoteCalls = apiCalls.filter((c) => /\/quote/.test(c.url));
    recordFinding(26, 'network-audit', 'pos-api', 'INFO', `${apiCalls.length} appel(s) POS API capturés (dont ${quoteCalls.length} quote)`, shot);
  });

  test('Step 99 — Synthèse + write findings index', async ({ page }) => {
    await loginAsPosOperator(page);
    await page.waitForTimeout(1_500);
    const shot = await snap(page, 99, 'synthesis', 'final');

    const indexPath = path.join(SHOT_DIR, 'INDEX.md');
    const lines = ['# AUDIT POS Captures Index — 2026-05-06', '', `Total findings: ${findings.length}`, '', '| Step | Slug | State | Severity | Note | Screenshot |', '| --- | --- | --- | --- | --- | --- |'];
    for (const f of findings) {
      lines.push(`| ${f.step} | ${f.slug} | ${f.state} | ${f.severity} | ${String(f.note).replace(/\|/g, '\\|').slice(0, 200)} | \`${f.screenshot || '—'}\` |`);
    }
    lines.push('', '## Severity legend', '- **OK** : étape validée', '- **INFO** : observation neutre', '- **P0** : bug bloquant (régression critique, design cassé, JS error fatale)', '- **P1** : bug important (UX dégradée, design partiellement KO)', '- **P2** : nice-to-have / mineur', '');
    fs.writeFileSync(indexPath, lines.join('\n'));

    expect(findings.length).toBeGreaterThan(0);
  });
});
