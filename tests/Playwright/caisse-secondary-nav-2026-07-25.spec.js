// [GOAL UX 2026-07-25] Validation e2e : navigation cohérente entre les pages secondaires caisse.
const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const BASE = 'http://127.0.0.1:8766';
const OUT = path.resolve(__dirname, '../../tests/captures/audit-caisse-secondaires-2026-07-25');
fs.mkdirSync(OUT, { recursive: true });

async function login(page) {
  await page.goto(`${BASE}/login`, { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(1200);
  await page.fill('#formEmail', 'pos@lecayenne.fr');
  await page.fill('#formPassword', '123456');
  await page.click('button:has-text("Connexion")').catch(() => {});
  await page.waitForTimeout(3500);
}

test('nav caisse cohérente sur les pages secondaires + navigation croisée', async ({ page }) => {
  const report = {};
  await login(page);

  // 1) Encaissement : nav présente + "encaissement" actif
  await page.goto(`${BASE}/admin/encaissement`, { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(3500);
  const encNav = await page.evaluate(() => ({
    nav: !!document.querySelector('[data-testid="caisse-secondary-nav"]'),
    links: [...document.querySelectorAll('[data-testid^="csn-"]')].map(a => a.getAttribute('data-testid')),
    active: document.querySelector('.csn-link.active')?.textContent?.trim() || null,
  }));
  report.encaissement = encNav;
  await page.locator('[data-testid="caisse-secondary-nav"]').first().screenshot({ path: path.join(OUT, 'NAV-encaissement.png') }).catch(() => {});
  await page.screenshot({ path: path.join(OUT, 'encaissement-avec-nav.png'), fullPage: true }).catch(() => {});
  expect(encNav.nav, 'nav présente sur Encaissement').toBeTruthy();
  expect(encNav.links, 'les 5 liens de nav').toEqual(expect.arrayContaining(['csn-encaissement', 'csn-suivi', 'csn-historique', 'csn-oss', 'csn-back-caisse']));
  expect(encNav.active, 'encaissement actif').toMatch(/encaiss/i);

  // 2) Depuis Encaissement, cliquer "Suivi" → tracker
  await page.click('[data-testid="csn-suivi"]');
  await page.waitForTimeout(3000);
  report.afterSuiviClick = await page.evaluate(() => location.pathname);
  expect(report.afterSuiviClick, 'navigation vers le tracker').toContain('tracker');

  // 3) Historique : nav présente + "historique" actif
  await page.goto(`${BASE}/admin/historique`, { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(3500);
  const histNav = await page.evaluate(() => ({
    nav: !!document.querySelector('[data-testid="caisse-secondary-nav"]'),
    active: document.querySelector('.csn-link.active')?.textContent?.trim() || null,
  }));
  report.historique = histNav;
  await page.screenshot({ path: path.join(OUT, 'historique-avec-nav.png'), fullPage: true }).catch(() => {});
  expect(histNav.nav, 'nav présente sur Historique').toBeTruthy();
  expect(histNav.active, 'historique actif').toMatch(/histor/i);

  // 4) Depuis Historique, cliquer "Encaissement" → encaissement
  await page.click('[data-testid="csn-encaissement"]');
  await page.waitForTimeout(3000);
  report.afterEncaissementClick = await page.evaluate(() => location.pathname);
  expect(report.afterEncaissementClick, 'retour vers encaissement').toContain('encaissement');

  fs.writeFileSync(path.join(OUT, 'nav-report.json'), JSON.stringify(report, null, 2));
  console.log('NAV_VALIDATION', JSON.stringify(report));
});
