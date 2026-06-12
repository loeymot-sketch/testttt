// ADVERSARIAL — combler le trou A-RED-5 : show + historique des 2 commandes abouties de la vague
import { boot, login } from './_d1-A-lib.mjs';
import path from 'path';
const OUT = new URL('../../reports/test-e2e/dispute-2026-06-12/round-1/A-caisse-vente/', import.meta.url).pathname;
const BASE = 'http://127.0.0.1:8768';
const { browser, page, netLog } = await boot();

async function inspect(serial, orderId, shotName) {
  // historique POS (suivi commandes / pos-orders)
  await page.goto(BASE + '/admin/pos-orders', { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(2500);
  // chercher le serial dans la liste (recherche si dispo)
  const search = page.locator('input[type="search"], input[placeholder*="echerch"]').first();
  if (await search.count()) { await search.fill(serial); await page.waitForTimeout(1800); }
  const rowText = await page.evaluate((s) => {
    const el = Array.from(document.querySelectorAll('tr, [class*="row"], a')).find((e) => e.innerText && e.innerText.includes(s));
    return el ? el.innerText.replace(/\s+/g, ' ').slice(0, 300) : 'ROW-ABSENTE';
  }, serial);
  console.log(`HIST[${serial}]:`, JSON.stringify(rowText));
  // show direct
  await page.goto(`${BASE}/admin/pos-orders/show/${orderId}`, { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(2800);
  const showText = await page.evaluate(() => document.body.innerText.replace(/\s+/g, ' ').slice(0, 1800));
  console.log(`SHOW[${serial}]:`, JSON.stringify(showText.slice(0, 1100)));
  await page.screenshot({ path: path.join(OUT, shotName) });
}

try {
  await login(page);
  await inspect('1206264522', 4522, '_red02-show-4522.png');
  await inspect('1206264536', 4536, '_red02-show-4536.png');
  console.log('NET>=400:', JSON.stringify(netLog.slice(0, 10)));
} finally { await browser.close(); }
