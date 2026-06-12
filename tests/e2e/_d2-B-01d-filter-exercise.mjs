// VAGUE B ROUND 2 — B-R1-19 (suite): exercice réel du filtre Mode de paiement + filtre txn_no + export XLS
import fs from 'fs';
import { BASE, OUT, boot, snap, mkLogger, login } from './_d2-B-lib.mjs';

const L = mkLogger('b1d-filter');
const { browser, page, state } = await boot();
const listCalls = [];
page.on('response', async (r) => {
  if (/api\/admin\/transaction/.test(r.url()) && r.request().method() === 'GET') {
    let n = null; try { const j = await r.json(); n = j?.data?.length; } catch {}
    listCalls.push({ status: r.status(), url: r.url().replace(BASE, ''), rows: n });
    L(`LIST ${r.status()} rows=${n} ${decodeURIComponent(r.url().replace(BASE, '')).slice(0, 170)}`);
  }
});

await login(page, L);
await page.goto(BASE + '/admin/transactions', { waitUntil: 'domcontentloaded' });
await page.waitForTimeout(3500);
await page.locator('.table-filter-btn').first().click();
await page.waitForTimeout(900);

const sel = page.locator('#transaction-filter .vue-select');
L(`vue-select présent: ${await sel.count()}`);
await sel.click();
await page.waitForTimeout(800);
const opts = await page.locator('.vue-dropdown li, .vue-dropdown-item, [class*="dropdown"] li').allTextContents().catch(() => []);
L(`options dropdown: ${JSON.stringify(opts.map((o) => o.trim()).filter(Boolean).slice(0, 10))}`);
await snap(page, state, 'b1-06-filter-options');
const opt = page.locator('.vue-dropdown li, .vue-dropdown-item').first();
if (await opt.count()) {
  const label = (await opt.innerText().catch(() => '?')).trim();
  await opt.click();
  await page.waitForTimeout(500);
  await page.locator('#transaction-filter button.bg-primary').first().click();
  await page.waitForTimeout(2500);
  const rows = await page.locator('table tbody tr').allTextContents();
  L(`filtre "${label}" appliqué → ${rows.length} rows visibles`);
  rows.slice(0, 5).forEach((r, i) => L(`  row${i + 1}: ${r.replace(/\s+/g, ' ').trim().slice(0, 150)}`));
  await snap(page, state, 'b1-07-filtered-credit');
  await page.locator('#transaction-filter button.bg-gray-600').first().click().catch(() => {});
  await page.waitForTimeout(2000);
}

// filtre par n° de transaction
await page.locator('.table-filter-btn').first().click().catch(() => {});
await page.waitForTimeout(700);
await page.fill('#transaction_id', 'COUNTER-4327');
await page.locator('#transaction-filter button.bg-primary').first().click();
await page.waitForTimeout(2200);
const rows2 = await page.locator('table tbody tr').allTextContents();
L(`filtre transaction_no=COUNTER-4327 → ${rows2.length} rows`);
rows2.slice(0, 3).forEach((r, i) => L(`  row${i + 1}: ${r.replace(/\s+/g, ' ').trim().slice(0, 170)}`));
await snap(page, state, 'b1-08-filtered-by-txn-no');

// export XLS (download)
const dlP = page.waitForEvent('download', { timeout: 12000 }).catch(() => null);
await page.locator('.db-card-filter .dropdown-btn:has-text("Exporter")').first().click().catch(() => {});
await page.waitForTimeout(500);
await page.locator('button:has-text("XLS"), .db-card-filter-dropdown-menu:has-text("XLS")').first().click().catch(() => L('WARN clic XLS KO'));
const dl = await dlP;
L(`export XLS: ${dl ? 'download=' + dl.suggestedFilename() : 'AUCUN download capturé'}`);
if (dl) await dl.saveAs(OUT + '_b1d-transactions-export.xlsx').catch(() => {});
await snap(page, state, 'b1-09-after-export');

fs.writeFileSync(OUT + '_b1d-list-calls.json', JSON.stringify(listCalls, null, 2));
L(`console cumulés: ${state.consoleBuf.length}`); state.consoleBuf.forEach((c) => L('  ' + c));
L(`net>=400: ${state.netBuf.length}`); state.netBuf.forEach((n) => L('  ' + n));
L.flush();
await browser.close();
console.log('DONE');
