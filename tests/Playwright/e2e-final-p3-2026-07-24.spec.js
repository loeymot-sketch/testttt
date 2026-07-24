// [E2E-FINAL-P3 2026-07-24] Validation réelle navigateur des 2 nouveaux écrans
// Phase 3 (Scan facture + Conso & Stock unifiée) + non-régression POS/KDS/catalog-hub.
// READ-ONLY sauf : le scan crée un PurchaseDocument draft + la validation applique
// au stock (write ADDITIF hors NF525) — attendu/autorisé par le prompt.
const { test, expect, devices } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const BASE = process.env.PLAYWRIGHT_BASE_URL || 'http://127.0.0.1:8000';
const OUT = path.resolve(__dirname, '../../tests/captures/e2e-final-p3-2026-07-24');
fs.mkdirSync(OUT, { recursive: true });
const shot = (page, n) => page.screenshot({ path: path.join(OUT, n), fullPage: true }).catch(() => {});
// 1x1 PNG — le mock vision ignore le contenu (lit le fixture metro-sample.json).
// Rendu UNIQUE par run (bytes ajoutés) → doc_hash frais → pas de chemin idempotent
// qui masquerait la vraie validation.
const PNG_BASE = Buffer.from(
  'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==',
  'base64'
);
const uniquePng = () => Buffer.concat([PNG_BASE, Buffer.from('e2e' + Date.now() + Math.random())]);

function attachProbes(page, bucket) {
  page.on('console', (m) => {
    if (m.type() === 'error') bucket.console.push(String(m.text()).slice(0, 300));
  });
  page.on('pageerror', (e) => bucket.pageerror.push(String(e.message).slice(0, 300)));
  page.on('response', (r) => {
    const u = r.url();
    if (/\/api\/admin\/(purchasing|stock\/unified)/.test(u)) {
      bucket.net.push({ url: u.replace(BASE, ''), status: r.status() });
    }
  });
}

async function login(page) {
  await page.goto(`${BASE}/login`, { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(2000);
  await page.fill('#formEmail', 'admin@lecayenne.fr');
  await page.fill('#formPassword', '123456');
  await page.click('button:has-text("Connexion")').catch(async () => {
    await page.evaluate(() => {
      const b = [...document.querySelectorAll('button')].find((x) => /connexion/i.test(x.innerText || ''));
      if (b) b.click();
    });
  });
  await page.waitForFunction(() => !location.pathname.includes('/login'), { timeout: 20000 }).catch(() => {});
  await page.waitForTimeout(2500);
}

// Cherche des labels i18n bruts non résolus dans le texte visible.
function rawLabelScan() {
  const txt = document.body.innerText || '';
  const hits = (txt.match(/\b(admin\.[a-z_]+\.[a-z_.]+|label\.[a-z_.]+|[a-z_]+\.[a-z_]+\.[a-z_]+)\b/gi) || [])
    .filter((s) => !/\.(fr|com|json|png|jpg)\b/i.test(s));
  return [...new Set(hits)].slice(0, 15);
}

test.describe.configure({ mode: 'serial' });

test('DESKTOP — scan facture (flux complet) + conso&stock + non-régression', async ({ page }) => {
  const report = { console: [], pageerror: [], net: [] };
  attachProbes(page, report);
  await login(page);
  report.after_login = await page.evaluate(() => location.pathname);
  expect(report.after_login.includes('/login'), 'login admin OK').toBeFalsy();

  // ===== ÉCRAN 1 : SCAN FACTURE =====
  await page.goto(`${BASE}/admin/purchasing/scan`, { waitUntil: 'domcontentloaded' });
  await page.waitForSelector('[data-testid="scan-btn"], .purchase-scan', { timeout: 20000 }).catch(() => {});
  await page.waitForTimeout(1500);
  await shot(page, '01-scan-idle.png');
  report.scan_idle = await page.evaluate(() => ({
    demoBanner: !!document.querySelector('[data-testid="demo-banner"]'),
    demoText: (document.querySelector('[data-testid="demo-banner"]') || {}).innerText || null,
    uploadZone: !!document.querySelector('[data-testid="file-input"]'),
    scanBtn: (document.querySelector('[data-testid="scan-btn"]') || {}).innerText || null,
    scanDisabled: document.querySelector('[data-testid="scan-btn"]')?.disabled ?? null,
    title: (document.querySelector('.ps-title') || {}).innerText || null,
  }));

  // Upload image quelconque → le mock lit le fixture.
  await page.locator('[data-testid="file-input"]').setInputFiles({ name: 'facture.png', mimeType: 'image/png', buffer: uniquePng() });
  await page.waitForTimeout(500);
  await page.click('[data-testid="scan-btn"]');
  await page.waitForSelector('[data-testid="proposals"]', { timeout: 20000 }).catch(() => {});
  await page.waitForTimeout(1200);
  await shot(page, '02-scan-proposals.png');

  report.proposals = await page.evaluate(() => {
    const rows = [...document.querySelectorAll('[data-testid="proposal-row"]')].map((tr) => {
      const type = tr.querySelector('[data-testid="target-type"]');
      const rawSel = tr.querySelector('[data-testid="target-raw"]');
      const itemSel = tr.querySelector('[data-testid="target-item"]');
      return {
        label: (tr.querySelector('.ps-label') || {}).innerText || null,
        qty: tr.querySelector('input[type=number]')?.value ?? null,
        aiBadge: !!tr.querySelector('[data-testid="ai-badge"]'),
        score: (tr.querySelector('[data-testid="score-badge"]') || {}).innerText || null,
        targetType: type ? type.value : null,
        rawOptions: rawSel ? rawSel.options.length : null,
        itemOptions: itemSel ? itemSel.options.length : null,
        unmatched: tr.classList.contains('is-unmatched'),
      };
    });
    return {
      count: rows.length,
      rows,
      docStatus: (document.querySelector('.ps-doc-status') || {}).innerText || null,
      validateBtn: !!document.querySelector('[data-testid="validate-btn"]'),
    };
  });
  expect(report.proposals.count, 'propositions affichées').toBeGreaterThan(0);

  report.validate_enabled_before = await page.evaluate(
    () => !document.querySelector('[data-testid="validate-btn"]')?.disabled
  );

  // Rendre chaque ligne non-charge validable SANS casser l'état pré-rempli par l'IA.
  // « Vraie » option = value NUMÉRIQUE (le placeholder « — choisir — » a pour .value
  // son texte, donc non-numérique → exclu). Change explicitement 1 dropdown (evidence).
  report.dropdown_action = await page.evaluate(() => {
    const fire = (el) => { el.dispatchEvent(new Event('change', { bubbles: true })); el.dispatchEvent(new Event('input', { bubbles: true })); };
    const num = (o) => /^\d+$/.test(o.value);
    let changed = null;
    for (const tr of document.querySelectorAll('[data-testid="proposal-row"]')) {
      const type = tr.querySelector('[data-testid="target-type"]');
      if (!type) continue;
      if (type.value === 'raw_material' || type.value === 'stock_item') {
        const sel = tr.querySelector('[data-testid="target-raw"], [data-testid="target-item"]');
        const real = sel ? [...sel.options].filter(num) : [];
        if (real.length === 0) { type.value = 'charge'; fire(type); continue; }
        // 1er dropdown : choisir DÉLIBÉRÉMENT une option ≠ courante (preuve de changement).
        if (!changed && real.length >= 2) {
          const alt = real.find((o) => o.value !== sel.value) || real[0];
          const from = (sel.selectedOptions[0] || {}).text || null;
          sel.value = alt.value; fire(sel);
          changed = { label: (tr.querySelector('.ps-label') || {}).innerText, from, to: alt.text };
        } else if (!num(sel)) {
          sel.value = real[0].value; fire(sel);
        }
      }
    }
    return changed || { note: 'aucune ligne matière/produit avec ≥2 options — dropdown non changé' };
  });
  await page.waitForTimeout(600);
  await shot(page, '03-scan-target-changed.png');

  // Valider l'entrée en stock.
  const canValidate = await page.evaluate(() => {
    const b = document.querySelector('[data-testid="validate-btn"]');
    return b ? !b.disabled : false;
  });
  report.validate_enabled = canValidate;
  if (canValidate) {
    await page.click('[data-testid="validate-btn"]');
    await page.waitForSelector('[data-testid="success-banner"]', { timeout: 20000 }).catch(() => {});
    await page.waitForTimeout(1000);
  }
  await shot(page, '04-scan-success.png');
  report.scan_result = await page.evaluate(() => ({
    success: (document.querySelector('[data-testid="success-banner"]') || {}).innerText || null,
    error: (document.querySelector('[data-testid="error-banner"]') || {}).innerText || null,
    docStatus: (document.querySelector('.ps-doc-status') || {}).innerText || null,
    inputsDisabled: [...document.querySelectorAll('.ps-input')].every((i) => i.disabled),
    validateBtnGone: !document.querySelector('[data-testid="validate-btn"]'),
  }));
  report.scan_rawlabels = await page.evaluate(rawLabelScan);
  // ATTAQUE « faux succès » : la validation doit réellement appliquer au stock
  // (bandeau succès avec comptes + doc « appliqué » + inputs verrouillés).
  expect(report.scan_result.error, 'validation stock sans erreur').toBeFalsy();
  expect(report.scan_result.success, 'bandeau succès affiché').toBeTruthy();
  expect(/appliqu/i.test(report.scan_result.docStatus || ''), 'document passé à « appliqué »').toBeTruthy();
  expect(report.scan_result.inputsDisabled, 'lignes verrouillées post-validation').toBeTruthy();

  // ===== ÉCRAN 2 : CONSO & STOCK UNIFIÉE =====
  await page.goto(`${BASE}/admin/stock/unified`, { waitUntil: 'domcontentloaded' });
  await page.waitForSelector('[data-testid="unified-stock-view"]', { timeout: 20000 }).catch(() => {});
  await page.waitForTimeout(2000);
  await shot(page, '05-unified-desktop.png');
  report.unified = await page.evaluate(() => {
    const q = (s) => document.querySelector(s);
    return {
      title: (q('.usv-title') || {}).innerText || null,
      hasToBuy: !!q('[data-testid="usv-tobuy"]'),
      hasRaw: !!q('[data-testid="usv-raw"]'),
      rawTitle: (q('[data-testid="usv-raw"] .usv-section-title') || {}).innerText || null,
      resoldTitle: (q('[data-testid="usv-resold"] .usv-section-title') || {}).innerText || null,
      hasTotals: !!q('[data-testid="usv-totals"]'),
      totalValue: (q('[data-testid="usv-total-value"]') || {}).innerText || null,
      toBuyCount: (q('[data-testid="usv-total-tobuy"]') || {}).innerText || null,
      missingCostBanner: (q('[data-testid="usv-missing-cost"]') || {}).innerText || null,
      hasSearch: !!q('[data-testid="usv-search"]'),
      rawRows: document.querySelectorAll('[data-testid^="usv-raw-"]').length,
      colHeaders: [...document.querySelectorAll('[data-testid="usv-raw"] [role=columnheader]')].map((h) => h.innerText),
      error: !!q('[data-testid="usv-error"]'),
    };
  });
  report.unified_rawlabels = await page.evaluate(rawLabelScan);
  // Test recherche (filtre client).
  await page.fill('[data-testid="usv-search"]', 'zzznope').catch(() => {});
  await page.waitForTimeout(600);
  await shot(page, '06-unified-search-empty.png');
  report.unified_search_noMatch = await page.evaluate(
    () => !!document.querySelector('[data-testid="usv-raw-empty"], .usv-empty-inline')
  );
  await page.fill('[data-testid="usv-search"]', '').catch(() => {});

  // ===== NON-RÉGRESSION =====
  await page.goto(`${BASE}/admin/pos`, { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(5000);
  await shot(page, '07-pos.png');
  report.pos = await page.evaluate(() => ({
    url: location.pathname,
    onLogin: location.pathname.includes('login'),
    tiles: document.querySelectorAll('.pos-v5-item-card,[data-item-id],[data-testid^="pos-item"]').length,
    cart: !!document.querySelector('[class*="cart"]'),
    body: (document.body.innerText || '').length,
  }));

  await page.goto(`${BASE}/kds`, { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(4000);
  await shot(page, '08-kds.png');
  report.kds = await page.evaluate(() => ({
    url: location.pathname,
    onLogin: location.pathname.includes('login'),
    board: !!document.querySelector('[class*="kds"],[data-testid*="kds"]'),
  }));

  await page.goto(`${BASE}/admin/catalog-hub`, { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(4000);
  await shot(page, '09-catalog-hub.png');
  report.catalog_hub = await page.evaluate(() => ({
    url: location.pathname,
    onLogin: location.pathname.includes('login'),
    tabs: document.querySelectorAll('[role=tab],.nav-tabs a,.tab,[class*="tab"]').length,
    body: (document.body.innerText || '').length,
  }));

  fs.writeFileSync(path.join(OUT, 'report-desktop.json'), JSON.stringify(report, null, 2));
  console.log('E2E_DESKTOP', JSON.stringify(report));

  // Assertions dures (échec = vrai défaut).
  expect(report.scan_idle.demoBanner, 'bandeau mode démo présent').toBeTruthy();
  expect(report.unified.hasToBuy && report.unified.hasRaw, 'sections à-acheter + matières rendues').toBeTruthy();
  expect(report.unified.error, 'vue unifiée sans erreur de chargement').toBeFalsy();
  expect(report.unified_rawlabels.length, 'aucun label i18n brut (unified)').toBe(0);
  expect(report.pos.onLogin, 'POS accessible').toBeFalsy();
  expect(report.kds.onLogin, 'KDS accessible').toBeFalsy();
});

test('PIXEL 7 — scan facture + conso&stock (mobile cartes)', async ({ browser }) => {
  const device = devices['Pixel 7'] || { viewport: { width: 412, height: 915 }, isMobile: true, hasTouch: true };
  const ctx = await browser.newContext({ ...device });
  const page = await ctx.newPage();
  const report = { console: [], pageerror: [], net: [] };
  attachProbes(page, report);
  await login(page);

  await page.goto(`${BASE}/admin/purchasing/scan`, { waitUntil: 'domcontentloaded' });
  await page.waitForSelector('[data-testid="file-input"]', { timeout: 20000 }).catch(() => {});
  await page.waitForTimeout(1200);
  await page.locator('[data-testid="file-input"]').setInputFiles({ name: 'facture.png', mimeType: 'image/png', buffer: uniquePng() });
  await page.waitForTimeout(400);
  await page.click('[data-testid="scan-btn"]').catch(() => {});
  await page.waitForSelector('[data-testid="proposals"]', { timeout: 20000 }).catch(() => {});
  await page.waitForTimeout(1000);
  await shot(page, '10-scan-pixel7.png');
  report.scan_rows = await page.evaluate(() => document.querySelectorAll('[data-testid="proposal-row"]').length);

  await page.goto(`${BASE}/admin/stock/unified`, { waitUntil: 'domcontentloaded' });
  await page.waitForSelector('[data-testid="unified-stock-view"]', { timeout: 20000 }).catch(() => {});
  await page.waitForTimeout(1800);
  await shot(page, '11-unified-pixel7.png');
  report.unified_mobile = await page.evaluate(() => ({
    title: (document.querySelector('.usv-title') || {}).innerText || null,
    totalsCols: getComputedStyle(document.querySelector('[data-testid="usv-totals"]') || document.body).gridTemplateColumns,
    rawRows: document.querySelectorAll('[data-testid^="usv-raw-"]').length,
    noHScroll: document.documentElement.scrollWidth <= window.innerWidth + 2,
  }));

  fs.writeFileSync(path.join(OUT, 'report-pixel7.json'), JSON.stringify(report, null, 2));
  console.log('E2E_PIXEL7', JSON.stringify(report));
  expect(report.unified_mobile.noHScroll, 'pas de scroll horizontal mobile').toBeTruthy();
  await ctx.close();
});
