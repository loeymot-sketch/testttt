/* Phase 1 — read-only sweep of petits systèmes. Own chromium headless 1280x900. */
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const BASE = 'http://127.0.0.1:8767';
const OUT = __dirname;
const ROUTES = [
  ['coupons', '/admin/coupons'],
  ['offers', '/admin/offers'],
  ['messages', '/admin/messages'],
  ['subscribers', '/admin/subscribers'],
  ['push-notifications', '/admin/push-notifications'],
  ['transactions', '/admin/transactions'],
  ['sales-report', '/admin/sales-report'],
  ['items-report', '/admin/items-report'],
  ['ingredients', '/admin/ingredients'],
  ['delivery-boys', '/admin/delivery-boys'],
  ['delivery-boy-cash-sessions', '/admin/delivery-boy-cash-sessions'],
  ['dining-tables', '/admin/dining-tables'],
  ['employees', '/admin/employees'],
  ['administrators', '/admin/administrators'],
  ['chefs', '/admin/chefs'],
  ['customers', '/admin/customers'],
  ['loyalty-setup', '/admin/settings/loyalty-setup'],
  ['historique', '/admin/historique'],
];

(async () => {
  const browser = await chromium.launch({ headless: true });
  const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 } });
  const page = await ctx.newPage();

  const report = {};
  let consoleErrs = [];
  let failedReqs = [];
  page.on('console', m => { if (m.type() === 'error' || m.type() === 'warning') consoleErrs.push(`[${m.type()}] ${m.text().slice(0, 300)}`); });
  page.on('response', r => { if (r.status() >= 400) failedReqs.push(`${r.status()} ${r.request().method()} ${r.url().replace(BASE, '')}`); });
  page.on('pageerror', e => consoleErrs.push(`[pageerror] ${String(e).slice(0, 300)}`));

  // LOGIN
  await page.goto(BASE + '/login', { waitUntil: 'networkidle' });
  await page.waitForTimeout(1500);
  const tb = page.getByRole('textbox');
  await tb.nth(0).fill('admin@lecayenne.fr');
  await tb.nth(1).fill('123456');
  await page.getByRole('button', { name: /Connexion/i }).click();
  await page.waitForURL(/admin/, { timeout: 20000 });
  await page.waitForLoadState('networkidle');
  await page.waitForTimeout(1500);
  console.log('LOGIN OK ->', page.url());
  consoleErrs = []; failedReqs = [];

  for (const [name, route] of ROUTES) {
    consoleErrs = []; failedReqs = [];
    try {
      await page.goto(BASE + route, { waitUntil: 'networkidle', timeout: 30000 });
    } catch (e) { consoleErrs.push('[nav] ' + String(e).slice(0, 200)); }
    await page.waitForTimeout(1800);
    await page.screenshot({ path: path.join(OUT, `01-${name}.png`), fullPage: false });
    const bodyText = await page.evaluate(() => document.body.innerText.slice(0, 4000));
    // raw i18n label heuristic
    const rawLabels = (bodyText.match(/\b[a-z_]+\.[a-z_]{3,}\.[a-z_]{3,}\b|Label\.[A-Za-z]+/g) || []).slice(0, 10);
    report[name] = {
      url: page.url(),
      console: consoleErrs.slice(0, 15),
      http4xx5xx: [...new Set(failedReqs)].slice(0, 15),
      rawLabels,
      textHead: bodyText.slice(0, 700),
    };
    console.log(`-- ${name}: console=${consoleErrs.length} httpErr=${failedReqs.length}`);
  }

  fs.writeFileSync(path.join(OUT, 'sweep-report.json'), JSON.stringify(report, null, 2));
  await browser.close();
  console.log('SWEEP DONE');
})().catch(e => { console.error('FATAL', e); process.exit(1); });
