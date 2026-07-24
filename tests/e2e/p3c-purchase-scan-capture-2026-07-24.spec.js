// [ARCH_STOCK_INTELLIGENT_BOM P3c] Capture visuelle de l'écran /admin/purchasing/scan.
// Login admin → écran vide (bandeau démo + upload) → upload fixture → propositions.
// Desktop + viewport mobile (l'owner scanne au tél). Sauve dans __screenshots__/p3c-scan/.
const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const BASE = process.env.PLAYWRIGHT_BASE_URL || 'http://127.0.0.1:8000';
const OUT = path.resolve(__dirname, '__screenshots__/p3c-scan');
fs.mkdirSync(OUT, { recursive: true });
const FIXTURE = process.env.P3C_FIXTURE
  || '/private/tmp/claude-501/-Users-1millnonstop-Downloads-projet-foodking-web-web-testttt/6079a090-84ff-4beb-94bd-ddd39a7c9664/scratchpad/facture-demo.png';

async function login(page) {
  await page.goto(`${BASE}/login`, { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(1200);
  await page.fill('#formEmail', 'admin@lecayenne.fr');
  await page.fill('#formPassword', '123456');
  await page.getByRole('button', { name: /connexion/i }).click().catch(async () => {
    await page.evaluate(() => {
      const b = [...document.querySelectorAll('button[type=submit]')].find(x => /connexion/i.test(x.innerText || ''));
      if (b) b.click();
    });
  });
  await page.waitForTimeout(3000);
}

test('P3c — écran de scan de facture rend + upload → propositions', async ({ page }) => {
  const report = {};
  await login(page);
  report.after_login = await page.evaluate(() => location.pathname);
  expect(report.after_login.includes('/login'), 'login réussi').toBeFalsy();

  await page.goto(`${BASE}/admin/purchasing/scan`, { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(3500);

  // État vide : titre + bandeau démo (dev sans clé OpenAI) + zone upload.
  const empty = await page.evaluate(() => {
    const txt = document.body.innerText || '';
    return {
      url: location.pathname,
      hasTitle: /Scan de facture/i.test(txt),
      demoBanner: !!document.querySelector('[data-testid="demo-banner"]'),
      uploadZone: !!document.querySelector('[data-testid="file-input"]'),
      // aucun label brut non résolu
      rawLeak: /menu\.purchasing|purchasing\.scan\.|Label\.|0undefined|\{\{/.test(txt),
    };
  });
  report.empty = empty;
  await page.screenshot({ path: path.join(OUT, '01-empty-state.png'), fullPage: false });

  expect(empty.url, 'sur /admin/purchasing/scan').toContain('/admin/purchasing/scan');
  expect(empty.hasTitle, 'titre rendu').toBeTruthy();
  expect(empty.uploadZone, 'zone upload rendue').toBeTruthy();
  expect(empty.rawLeak, 'aucun label brut').toBeFalsy();

  // Upload de la fixture → scan (mock lit la facture métro : 4 lignes).
  await page.setInputFiles('[data-testid="file-input"]', FIXTURE);
  await page.waitForTimeout(400);
  await page.click('[data-testid="scan-btn"]');
  await page.waitForTimeout(4000);

  const scanned = await page.evaluate(() => {
    const rows = document.querySelectorAll('[data-testid="proposal-row"]');
    const txt = document.body.innerText || '';
    return {
      rowCount: rows.length,
      hasAiBadge: !!document.querySelector('[data-testid="ai-badge"]'),
      hasTargetSelect: !!document.querySelector('[data-testid="target-type"]'),
      hasValidateBtn: !!document.querySelector('[data-testid="validate-btn"]'),
      errorBanner: !!document.querySelector('[data-testid="error-banner"]'),
      rawLeak: /menu\.purchasing|purchasing\.scan\.|Label\.|0undefined|\{\{|NaN/.test(txt),
    };
  });
  report.scanned = scanned;
  await page.screenshot({ path: path.join(OUT, '02-proposals.png'), fullPage: true });

  // Viewport mobile (l'owner scanne au tél) — cartes empilées.
  await page.setViewportSize({ width: 390, height: 844 });
  await page.waitForTimeout(600);
  await page.screenshot({ path: path.join(OUT, '03-proposals-mobile.png'), fullPage: false });

  fs.writeFileSync(path.join(OUT, 'report.json'), JSON.stringify(report, null, 2));

  // Le scan a produit des lignes (ou, si re-run idempotent, un état cohérent).
  expect(scanned.errorBanner, 'pas de bandeau erreur après scan').toBeFalsy();
  expect(scanned.rawLeak, 'aucun label brut après scan').toBeFalsy();
  expect(scanned.rowCount, 'au moins une proposition rendue').toBeGreaterThan(0);
  expect(scanned.hasTargetSelect && scanned.hasValidateBtn, 'dropdown cible + bouton valider').toBeTruthy();
});
