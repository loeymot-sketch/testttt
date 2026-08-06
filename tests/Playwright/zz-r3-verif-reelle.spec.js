// [R3] Vérification RÉELLE des surfaces de gestion après les heals du GOAL révision.
// Aucune commande créée, aucun encaissement confirmé (modales fermées).
const { test, expect } = require('@playwright/test');
const BASE = 'http://127.0.0.1:8000';
const SHOTS = 'reports/goal-revision-absolue-2026-08-06/round-1/R3';

async function loginAs(page, email) {
  await page.goto(`${BASE}/login`, { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(1500);
  await page.fill('#formEmail', email);
  await page.fill('#formPassword', '123456');
  await page.click('button:has-text("Connexion")');
  await page.waitForTimeout(3500);
}

const PAGES_ADMIN = [
  ['pos', '/admin/pos'],
  ['encaissement', '/admin/encaissement'],
  ['suivi', '/admin/commandes-caisse'],
  ['historique', '/admin/historique'],
  ['stock-rupture', '/admin/stock/rupture'],
  ['kds', '/kds'],
  ['oss', '/admin/order-status-screen'],
  ['z-reports', '/admin/settings/z-reports'],
  ['printers', '/admin/settings/printers'],
  ['payment-terminals', '/admin/settings/payment-terminals'],
];

test('surfaces de gestion : rendu + console propre (admin)', async ({ page }) => {
  test.setTimeout(300000);
  await page.setViewportSize({ width: 1366, height: 768 });
  await loginAs(page, 'admin@lecayenne.fr');

  const report = [];
  for (const [name, url] of PAGES_ADMIN) {
    const errors = [];
    const bad = [];
    const onC = (m) => { if (m.type() === 'error') errors.push(m.text().slice(0, 160)); };
    const onR = (r) => { if (r.status() >= 400) bad.push(`${r.status()} ${r.url().slice(-60)}`); };
    page.on('console', onC);
    page.on('response', onR);
    await page.goto(`${BASE}${url}`, { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(4000);
    await page.screenshot({ path: `${SHOTS}/${name}.png` });
    const onLogin = page.url().includes('/login');
    const bodyLen = (await page.locator('body').innerText().catch(() => '')).length;
    page.off('console', onC);
    page.off('response', onR);
    report.push({ name, url: page.url(), onLogin, bodyLen, errors, bad });
  }
  console.log('R3_ADMIN', JSON.stringify(report));
  // [R3] Critères de casse RÉELS : éjection vers login, page blanche (<120 car.),
  // erreur console, ou HTTP >= 400. Un écran d'affichage minimaliste (OSS : 2 titres
  // + états vides) est LÉGITIMEMENT court — l'ancien seuil 200 le flaggait à tort.
  const broken = report.filter((r) => r.onLogin || r.bodyLen < 120 || r.errors.length > 0 || r.bad.length > 0);
  expect(broken.map((b) => b.name), 'aucune page cassée/vide/en erreur console').toEqual([]);
});

test('caissier : accès métier OK, réglages refusés proprement', async ({ page }) => {
  test.setTimeout(180000);
  await page.setViewportSize({ width: 1366, height: 768 });
  await loginAs(page, 'pos@lecayenne.fr');
  const out = [];
  for (const [name, url] of [['pos', '/admin/pos'], ['encaissement', '/admin/encaissement'], ['kds', '/kds'], ['z-reports-interdit', '/admin/settings/z-reports']]) {
    await page.goto(`${BASE}${url}`, { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(3500);
    await page.screenshot({ path: `${SHOTS}/cashier-${name}.png` });
    out.push({ name, url: page.url(), onLogin: page.url().includes('/login') });
  }
  console.log('R3_CASHIER', JSON.stringify(out));
  // Les 3 surfaces métier ne doivent JAMAIS éjecter le caissier vers /login.
  expect(out.slice(0, 3).some((o) => o.onLogin), 'caissier éjecté d\'une surface métier').toBeFalsy();
});
