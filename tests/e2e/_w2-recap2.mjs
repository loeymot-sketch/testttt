import { chromium } from 'playwright';
import fs from 'fs';
const BASE = 'http://127.0.0.1:8768';
const OUT = 'reports/test-e2e/uiux-caisse-borne-2026-06-11/round1/recapture-w2';
const results = [];
async function login(page) {
  await page.goto(BASE + '/login', { waitUntil: 'domcontentloaded' });
  await page.fill('input[autocomplete="email"]', 'admin@lecayenne.fr');
  await page.fill('input[type="password"]', '123456');
  await page.click('button[type="submit"]');
  await page.waitForTimeout(3500);
}
const browser = await chromium.launch({ channel: 'chrome' });
const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 }, locale: 'fr-FR' });
const p = await ctx.newPage();
await login(p);
for (const [url, name] of [['/admin/cash-overview','cash-overview'],['/admin/cash-sessions-report','cash-sessions-report'],['/admin/pos-orders/show/4511','pos-orders-show-pending']]) {
  await p.goto(BASE + url, { waitUntil: 'domcontentloaded' });
  await p.waitForTimeout(2800);
  if (p.url().includes('/login')) { await login(p); await p.goto(BASE + url); await p.waitForTimeout(2800); }
  await p.screenshot({ path: `${OUT}/${name}.png` });
  results.push(`OK ${name} → ${p.url().replace(BASE,'')}`);
}
// datepicker historique
await p.goto(BASE + '/admin/historique', { waitUntil: 'domcontentloaded' });
await p.waitForTimeout(2500);
const inputs = await p.evaluate(() => Array.from(document.querySelectorAll('input')).map(i => ({cls: i.className.slice(0,60), ph: i.placeholder})).slice(0,10));
results.push('INPUTS: ' + JSON.stringify(inputs));
const dp = p.locator('input[class*="dp"], .dp__input, input[placeholder*="date" i], input[placeholder*="période" i]').first();
if (await dp.count()) { await dp.click({timeout:8000}).catch(e=>results.push('dp click fail')); await p.waitForTimeout(900); await p.screenshot({ path: `${OUT}/historique-datepicker-open.png` }); results.push('OK historique-datepicker-open'); }
await browser.close();
fs.writeFileSync(`${OUT}/_manifest2.txt`, results.join('\n'));
console.log(results.join('\n'));
