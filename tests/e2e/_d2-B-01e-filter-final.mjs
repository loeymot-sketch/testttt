// VAGUE B ROUND 2 — B-R1-19 (final): filtre Mode de paiement + txn_no + export XLS — robuste
import fs from 'fs';
import { BASE, OUT, boot, snap, mkLogger, login } from './_d2-B-lib.mjs';

const L = mkLogger('b1e-filter');
const { browser, page, state } = await boot();
const listCalls = [];
page.on('response', async (r) => {
  if (/api\/admin\/transaction\?/.test(r.url()) && r.request().method() === 'GET') {
    let n = null; try { const j = await r.json(); n = j?.data?.length; } catch {}
    listCalls.push({ status: r.status(), url: decodeURIComponent(r.url().replace(BASE, '')), rows: n });
    L(`LIST ${r.status()} rows=${n} ${decodeURIComponent(r.url().replace(BASE, '')).slice(0, 170)}`);
  }
});

async function ensurePanelOpen() {
  const vis = await page.evaluate(() => {
    const f = document.querySelector('#transaction-filter');
    return f && getComputedStyle(f).visibility === 'visible' && parseInt(getComputedStyle(f).height) > 50;
  });
  if (!vis) { await page.locator('.table-filter-btn').first().click(); await page.waitForTimeout(900); }
}

try {
  await login(page, L);
  await page.goto(BASE + '/admin/transactions', { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(3500);

  // ── 1. filtre Mode de paiement ──
  await ensurePanelOpen();
  const sel = page.locator('#transaction-filter .vue-select');
  L(`vue-select présent: ${await sel.count()}`);
  await sel.click();
  await page.waitForTimeout(900);
  const opts = await page.evaluate(() => Array.from(document.querySelectorAll('.vue-dropdown li, .vue-dropdown-item, ul[class*=dropdown] li')).map((x) => x.innerText.trim()));
  L(`options dropdown: ${JSON.stringify(opts.slice(0, 10))}`);
  await snap(page, state, 'b1-06-filter-options');
  const clicked = await page.evaluate(() => {
    const li = document.querySelector('.vue-dropdown li, .vue-dropdown-item');
    if (li) { li.click(); return li.innerText.trim(); }
    return null;
  });
  L(`option cliquée: ${clicked}`);
  await page.waitForTimeout(500);
  await ensurePanelOpen();
  await page.locator('#transaction-filter button.bg-primary').first().click();
  await page.waitForTimeout(2500);
  let rows = await page.locator('table tbody tr').allTextContents();
  L(`filtre "${clicked}" → ${rows.length} rows`);
  rows.slice(0, 4).forEach((r, i) => L(`  row${i + 1}: ${r.replace(/\s+/g, ' ').trim().slice(0, 150)}`));
  await snap(page, state, 'b1-07-filtered-credit');
  await ensurePanelOpen();
  await page.locator('#transaction-filter button.bg-gray-600').first().click().catch(() => {});
  await page.waitForTimeout(2200);

  // ── 2. filtre par n° de transaction ──
  await ensurePanelOpen();
  await page.fill('#transaction_id', 'COUNTER-4327');
  await page.locator('#transaction-filter button.bg-primary').first().click();
  await page.waitForTimeout(2200);
  rows = await page.locator('table tbody tr').allTextContents();
  L(`filtre transaction_no=COUNTER-4327 → ${rows.length} rows`);
  rows.slice(0, 3).forEach((r, i) => L(`  row${i + 1}: ${r.replace(/\s+/g, ' ').trim().slice(0, 170)}`));
  await snap(page, state, 'b1-08-filtered-by-txn-no');
  await ensurePanelOpen();
  await page.locator('#transaction-filter button.bg-gray-600').first().click().catch(() => {});
  await page.waitForTimeout(1800);

  // ── 3. export XLS ──
  const dlP = page.waitForEvent('download', { timeout: 15000 }).catch(() => null);
  await page.locator('.db-card-filter .dropdown-btn:has-text("Exporter")').first().click().catch(() => {});
  await page.waitForTimeout(600);
  await page.evaluate(() => {
    const xls = Array.from(document.querySelectorAll('button, a')).find((b) => /XLS/i.test(b.innerText) && b.closest('.dropdown-list, .db-card-filter'));
    if (xls) xls.click();
  });
  const dl = await dlP;
  L(`export XLS: ${dl ? 'download=' + dl.suggestedFilename() : 'AUCUN download capturé'}`);
  if (dl) { await dl.saveAs(OUT + '_b1e-transactions-export.xlsx').catch(() => {}); }
  await snap(page, state, 'b1-09-after-export');
} finally {
  fs.writeFileSync(OUT + '_b1e-list-calls.json', JSON.stringify(listCalls, null, 2));
  L(`console cumulés: ${state.consoleBuf.length}`); state.consoleBuf.forEach((c) => L('  ' + c));
  L(`net>=400: ${state.netBuf.length}`); state.netBuf.forEach((n) => L('  ' + n));
  L.flush();
  await browser.close();
}
console.log('DONE');
