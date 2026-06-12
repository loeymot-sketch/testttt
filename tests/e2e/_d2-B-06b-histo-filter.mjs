// VAGUE B ROUND 2 — historique: filtre par order_id 4531 + statut Remboursé + show père post-refund
import { BASE, OUT, boot, snap, mkLogger, login, bodyText } from './_d2-B-lib.mjs';
const L = mkLogger('b6b-histo');
const { browser, page, state } = await boot();
try {
  await login(page, L);
  await page.goto(BASE + '/admin/pos-orders', { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(3500);
  await page.locator('.table-filter-btn').first().click().catch(() => {});
  await page.waitForTimeout(900);
  const inp = page.locator('.table-filter-div input#order_id').first();
  L(`input order_id présent: ${await inp.count()}`);
  await inp.fill('1206264531');
  await page.locator('.table-filter-div button.bg-primary').first().click();
  await page.waitForTimeout(2500);
  const rows = await page.locator('table tbody tr').allTextContents();
  L(`filtre order_id=1206264531 → ${rows.length} rows`);
  rows.slice(0, 3).forEach((r, i) => L(`  row${i + 1}: ${r.replace(/\s+/g, ' ').trim().slice(0, 200)}`));
  await snap(page, state, 'b6-10-historique-filtre-4531');
  // show du père remboursé
  await page.goto(BASE + '/admin/pos-orders/show/4531', { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(2800);
  const t = await bodyText(page);
  L(`show 4531 post-refund: marqueurs=${JSON.stringify((t.match(/Remboursé|REMBOURS\S*|Retournée|miroir|avoir|Espèces/gi) || []).slice(0, 10))}`);
  const negs = (t.match(/[-−]\s?\d+[.,]\d{2}\s?€/g) || []).slice(0, 6);
  L(`montants négatifs: ${JSON.stringify(negs)}`);
  await snap(page, state, 'b6-11-show-4531-post-refund');
} finally {
  L(`console: ${state.consoleBuf.length}`); state.consoleBuf.forEach((c) => L('  ' + c));
  L(`net>=400: ${state.netBuf.length}`); state.netBuf.forEach((n) => L('  ' + n));
  L.flush();
  await browser.close();
}
console.log('DONE');
