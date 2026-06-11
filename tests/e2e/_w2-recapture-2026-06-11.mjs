// W2 re-capture post-heal+rebuild — surfaces caisse touchées par H1/H2
import { chromium } from 'playwright';
import fs from 'fs';

const BASE = 'http://127.0.0.1:8768';
const OUT = 'reports/test-e2e/uiux-caisse-borne-2026-06-11/round1/recapture-w2';
fs.mkdirSync(OUT, { recursive: true });
const results = [];

async function login(page) {
  await page.goto(BASE + '/login', { waitUntil: 'domcontentloaded' });
  await page.fill('input[autocomplete="email"]', 'pos@lecayenne.fr');
  await page.fill('input[type="password"]', '123456');
  await page.click('button[type="submit"]');
  await page.waitForTimeout(3500);
}
async function snap(page, url, name, wait = 2500) {
  try {
    await page.goto(BASE + url, { waitUntil: 'domcontentloaded', timeout: 30000 });
    await page.waitForTimeout(800);
    if (page.url().includes('/login')) { await login(page); await page.goto(BASE + url, { waitUntil: 'domcontentloaded' }); }
    await page.waitForTimeout(wait);
    await page.screenshot({ path: `${OUT}/${name}.png` });
    results.push(`OK ${name} → ${page.url().replace(BASE, '')}`);
  } catch (e) { results.push(`FAIL ${name} ${e.message.split('\n')[0]}`); }
}

const browser = await chromium.launch({ channel: 'chrome' });
const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 }, locale: 'fr-FR' });
const p = await ctx.newPage();
p.on('console', m => { if (m.type() === 'error') results.push(`CONSOLE-ERR ${p.url().replace(BASE, '')} :: ${m.text().slice(0, 140)}`); });
await login(p);
results.push(`LOGIN → ${p.url().replace(BASE, '')}`);

await snap(p, '/admin/pos-orders', 'pos-orders');           // F2 datepicker FR
await snap(p, '/admin/historique', 'historique');           // F2
await snap(p, '/admin/cash-overview', 'cash-overview');     // F2 natifs → datepicker
await snap(p, '/admin/cash-sessions-report', 'cash-sessions-report'); // F2
await snap(p, '/admin/pos-orders-tracker', 'tracker');      // F4 fenêtre 48h
await snap(p, '/admin/encaissement', 'encaissement');       // F6/G2 badge attente, toasts
await snap(p, '/admin/pos/floorplan', 'floorplan');         // G3 état explicatif
await snap(p, '/admin/pos', 'pos-main', 3500);              // F5 écran client, G2

// F3: show d'une commande PENDING (badge statut non vide)
await snap(p, '/admin/pos-orders/show/4511', 'pos-orders-show-pending');

// F2 interaction: ouvrir le datepicker historique
try {
  await p.goto(BASE + '/admin/historique', { waitUntil: 'domcontentloaded' });
  await p.waitForTimeout(2200);
  const dp = p.locator('.dp__input').first();
  if (await dp.count()) { await dp.click(); await p.waitForTimeout(900); await p.screenshot({ path: `${OUT}/historique-datepicker-open.png` }); results.push('OK historique-datepicker-open'); }
  else results.push('WARN datepicker input introuvable (.dp__input)');
} catch (e) { results.push(`FAIL datepicker ${e.message.split('\n')[0]}`); }

await browser.close();
fs.writeFileSync(`${OUT}/_manifest.txt`, results.join('\n'));
console.log(results.join('\n'));
