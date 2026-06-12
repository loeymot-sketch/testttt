// VAGUE B ROUND 2 — suite B-R1-19: exercice réel du filtre "Mode de paiement" (slide via bouton Filtrer)
import fs from 'fs';
import { BASE, OUT, boot, snap, mkLogger, login } from './_d2-B-lib.mjs';

const L = mkLogger('b1b-filter');
const { browser, page, state } = await boot();
const listCalls = [];
page.on('response', async (r) => {
  if (/api\/admin\/transaction/.test(r.url()) && r.request().method() === 'GET') {
    let n = null; try { const j = await r.json(); n = j?.data?.length; } catch {}
    listCalls.push({ status: r.status(), url: r.url().replace(BASE, ''), rows: n });
    L(`LIST ${r.status()} rows=${n} ${r.url().replace(BASE, '').slice(0, 160)}`);
  }
});

await login(page, L);
await page.goto(BASE + '/admin/transactions', { waitUntil: 'domcontentloaded' });
await page.waitForTimeout(3500);

// ouvrir le panneau filtre via le bouton officiel (table-filter-btn)
const filterBtn = page.locator('.table-filter-btn, button:has(.lab-filter)').first();
L(`bouton filtre count=${await filterBtn.count()}`);
await filterBtn.click().catch(async () => {
  await page.evaluate(() => {
    const f = document.querySelector('#transaction-filter');
    f.style.visibility = 'visible'; f.style.height = 'auto'; f.style.opacity = '1'; f.style.overflow = 'visible';
  });
});
await page.waitForTimeout(900);
const filterVisible = await page.locator('#payment_method').isVisible().catch(() => false);
L(`filtre "Mode de paiement" visible après slide: ${filterVisible}`);
await snap(page, state, 'b1-05-filter-panel-open');

if (filterVisible) {
  await page.locator('#payment_method').click();
  await page.waitForTimeout(800);
  const opts = await page.locator('.vue-dropdown li, .vue-dropdown-item').allTextContents().catch(() => []);
  L(`options: ${JSON.stringify(opts.map((o) => o.trim()).filter(Boolean).slice(0, 10))}`);
  await snap(page, state, 'b1-06-filter-options');
  const opt = page.locator('.vue-dropdown li, .vue-dropdown-item').first();
  if (await opt.count()) {
    const label = (await opt.innerText()).trim();
    await opt.click();
    await page.waitForTimeout(500);
    await page.locator('#transaction-filter button.bg-primary').first().click();
    await page.waitForTimeout(2500);
    const methods = await page.locator('table tbody tr td:nth-child(4)').allTextContents().catch(() => []);
    const ids = await page.locator('table tbody tr td:nth-child(1)').allTextContents().catch(() => []);
    L(`filtre "${label}" → ${ids.length} rows ; modes: ${JSON.stringify([...new Set(methods.map((m) => m.trim()))])}`);
    await snap(page, state, 'b1-07-filtered-credit');
    // clear
    await page.locator('#transaction-filter button.bg-gray-600').first().click().catch(() => {});
    await page.waitForTimeout(2000);
    const rowsClear = await page.locator('table tbody tr').count();
    L(`après clear: ${rowsClear} rows`);
  }
  // filtre par n° de transaction (COUNTER-4327…)
  await filterBtn.click().catch(() => {});
  await page.waitForTimeout(700);
  await page.fill('#transaction_id', 'COUNTER-4327');
  await page.locator('#transaction-filter button.bg-primary').first().click();
  await page.waitForTimeout(2200);
  const rows2 = await page.locator('table tbody tr').allTextContents();
  L(`filtre transaction_no=COUNTER-4327 → ${rows2.length} rows :: ${JSON.stringify(rows2.map((r) => r.replace(/\s+/g, ' ').trim().slice(0, 140)))}`);
  await snap(page, state, 'b1-08-filtered-by-txn-no');
}

fs.writeFileSync(OUT + '_b1b-list-calls.json', JSON.stringify(listCalls, null, 2));
L(`console cumulés: ${state.consoleBuf.length}`); state.consoleBuf.forEach((c) => L('  ' + c));
L(`net>=400 cumulés: ${state.netBuf.length}`); state.netBuf.forEach((n) => L('  ' + n));
L.flush();
await browser.close();
console.log('DONE');
