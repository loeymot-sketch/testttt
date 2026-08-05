// [GOAL 2026-07-25] Audit e2e UI/UX des pages secondaires de la caisse (avant amélioration).
const { test } = require('@playwright/test');
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

const PAGES = [
  { name: 'pos-v4', url: '/admin/pos-v4' },
  { name: 'encaissement', url: '/admin/encaissement' },
  { name: 'suivi-tracker', url: '/admin/pos-orders-tracker' },
  { name: 'historique', url: '/admin/historique' },
  { name: 'oss-ecran-client', url: '/admin/order-status-screen' },
];

test('audit pages secondaires caisse', async ({ page }) => {
  const report = {};
  await login(page);
  for (const p of PAGES) {
    await page.goto(`${BASE}${p.url}`, { waitUntil: 'domcontentloaded' }).catch(() => {});
    await page.waitForTimeout(4000);
    await page.screenshot({ path: path.join(OUT, `${p.name}.png`), fullPage: true }).catch(() => {});
    const info = await page.evaluate(() => {
      const body = document.body.innerText || '';
      return {
        url: location.pathname,
        onLogin: location.pathname.includes('login'),
        title: (document.querySelector('h1, h2, .page-title, [class*="title"]')?.innerText || '').slice(0, 60),
        bodyLen: body.length,
        empty: /aucune|vide|no data|empty/i.test(body) && body.length < 400,
        rawLabel: /\b(label\.|menu\.|pos\.|admin\.)[a-z_]+\b/.test(body),
        hasError: /error|erreur|404|not found|undefined/i.test(body.slice(0, 300)),
        firstText: body.replace(/\s+/g, ' ').slice(0, 180),
      };
    });
    report[p.name] = info;
  }
  fs.writeFileSync(path.join(OUT, 'audit-report.json'), JSON.stringify(report, null, 2));
  console.log('AUDIT', JSON.stringify(report));
});
