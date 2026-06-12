// VAGUE B ROUND 2 — rapports après mutations:
// /admin/cash-overview (réconciliation + brut/net B-R1-07) · /admin/cash-sessions-report (écart signé ? session 22) ·
// historique /admin/pos-orders (filtres + export) · Z-report LECTURE SEULE (widget dashboard + GET index).
import fs from 'fs';
import { BASE, OUT, boot, snap, mkLogger, login, bodyText } from './_d2-B-lib.mjs';

const L = mkLogger('b6-reports');
const { browser, page, state } = await boot();
const apiGets = [];
page.on('response', async (r) => {
  if (/cash-overview|cash-drawer\/sessions|z-report/i.test(r.url()) && r.request().method() === 'GET') {
    let body = null; try { body = await r.json(); } catch {}
    apiGets.push({ status: r.status(), url: r.url().replace(BASE, ''), body });
    L(`GET ${r.status()} ${r.url().replace(BASE, '').slice(0, 120)}`);
  }
});

try {
  await login(page, L);

  // ── 1. CASH-OVERVIEW ──
  await page.goto(BASE + '/admin/cash-overview', { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(3500);
  const ov = await bodyText(page);
  fs.writeFileSync(OUT + '_b6-cash-overview-text.txt', ov);
  L(`cash-overview (extrait): ${ov.replace(/\n+/g, ' | ').slice(0, 1500)}`);
  await snap(page, state, 'b6-01-cash-overview');
  await page.evaluate(() => window.scrollTo(0, document.body.scrollHeight));
  await page.waitForTimeout(900);
  await snap(page, state, 'b6-02-cash-overview-bas');

  // ── 2. CASH-SESSIONS-REPORT ──
  await page.goto(BASE + '/admin/cash-sessions-report', { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(3500);
  const sr = await bodyText(page);
  fs.writeFileSync(OUT + '_b6-cash-sessions-report-text.txt', sr);
  L(`sessions-report (extrait): ${sr.replace(/\n+/g, ' | ').slice(0, 1200)}`);
  // row session 22 ?
  const s22 = (sr.match(/[^\n]*22[^\n]*/g) || []).filter((x) => /58,90|59,40|0,50/.test(x));
  L(`rows matching session 22: ${JSON.stringify(s22.slice(0, 4))}`);
  await snap(page, state, 'b6-03-cash-sessions-report');
  await page.evaluate(() => window.scrollTo(0, document.body.scrollHeight));
  await page.waitForTimeout(800);
  await snap(page, state, 'b6-04-cash-sessions-report-bas');

  // ── 3. HISTORIQUE /admin/pos-orders : filtres + export ──
  await page.goto(BASE + '/admin/pos-orders', { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(3500);
  const rows0 = await page.locator('table tbody tr').count();
  L(`historique rows initiales: ${rows0}`);
  await snap(page, state, 'b6-05-historique-initial');
  // ouvrir filtre
  await page.locator('.table-filter-btn').first().click().catch(() => {});
  await page.waitForTimeout(900);
  const filterIds = await page.evaluate(() => Array.from(document.querySelectorAll('.table-filter-div input, .table-filter-div select, .table-filter-div .vue-select')).map((e) => e.id || e.className.toString().slice(0, 30)));
  L(`champs filtre historique: ${JSON.stringify(filterIds.slice(0, 10))}`);
  // recherche par n° de commande de la vente directe 4531
  const serialInput = page.locator('.table-filter-div input#order_serial_no, .table-filter-div input[id*=serial]').first();
  if (await serialInput.count()) {
    await serialInput.fill('1206264531');
    await page.locator('.table-filter-div button.bg-primary').first().click();
    await page.waitForTimeout(2500);
    const rowsF = await page.locator('table tbody tr').allTextContents();
    L(`filtre serial 1206264531 → ${rowsF.length} rows: ${JSON.stringify(rowsF.map((r) => r.replace(/\s+/g, ' ').trim().slice(0, 160)))}`);
    await snap(page, state, 'b6-06-historique-filtre-serial');
  } else L('WARN input serial introuvable dans le filtre historique');
  // statut Remboursé visible ?
  const t = await bodyText(page);
  L(`statuts visibles: ${JSON.stringify((t.match(/Remboursé|Payé|À Encaisser|Annulé/g) || []).slice(0, 8))}`);
  // export XLS
  const dlP = page.waitForEvent('download', { timeout: 12000 }).catch(() => null);
  await page.locator('.db-card-filter .dropdown-btn:has-text("Exporter")').first().click().catch(() => {});
  await page.waitForTimeout(500);
  await page.evaluate(() => {
    const xls = Array.from(document.querySelectorAll('button, a')).find((b) => /XLS/i.test(b.innerText));
    if (xls) xls.click();
  });
  const dl = await dlP;
  L(`export historique XLS: ${dl ? dl.suggestedFilename() : 'AUCUN download'}`);
  if (dl) await dl.saveAs(OUT + '_b6-historique-export.xlsx').catch(() => {});
  await snap(page, state, 'b6-07-historique-export');

  // ── 4. Z-REPORT lecture seule ──
  await page.goto(BASE + '/admin/dashboard', { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(3500);
  const widget = await page.evaluate(() => {
    const link = document.querySelector('[data-testid="last-z-report-link"]');
    const card = link?.closest('.db-card');
    return card ? card.innerText.replace(/\s+/g, ' ').trim() : '(widget Dernier rapport Z ABSENT du dashboard)';
  });
  L(`widget Z dashboard: ${widget}`);
  await snap(page, state, 'b6-08-dashboard-z-widget');
  // GET index z-report (READ ONLY) depuis le contexte de la page
  const zIndex = await page.evaluate(async () => {
    try { const r = await window.axios.get('admin/z-report'); return { status: r.status, data: r.data }; }
    catch (e) { return { status: e?.response?.status, data: e?.response?.data }; }
  });
  fs.writeFileSync(OUT + '_b6-zreport-index.json', JSON.stringify(zIndex, null, 2));
  const zRows = zIndex?.data?.data || [];
  L(`GET admin/z-report → ${zIndex.status} ; ${Array.isArray(zRows) ? zRows.length : '?'} rows ; dernier: ${JSON.stringify(Array.isArray(zRows) ? (zRows[0] || null) : zRows)?.slice(0, 260)}`);
  // cliquer le lien « Voir les clôtures Z » → où atterrit-on ? (B-R1-16)
  await page.locator('[data-testid="last-z-report-link"]').click().catch(() => L('WARN lien Z introuvable'));
  await page.waitForTimeout(2500);
  L(`après clic « Voir les clôtures Z »: url=${page.url().replace(BASE, '')}`);
  await snap(page, state, 'b6-09-apres-lien-z');
} finally {
  fs.writeFileSync(OUT + '_b6-api-gets.json', JSON.stringify(apiGets.map((g) => ({ status: g.status, url: g.url, body: JSON.stringify(g.body)?.slice(0, 3000) })), null, 2));
  L(`console cumulés: ${state.consoleBuf.length}`); state.consoleBuf.forEach((c) => L('  ' + c));
  L(`net>=400: ${state.netBuf.length}`); state.netBuf.forEach((n) => L('  ' + n));
  L.flush();
  await browser.close();
}
console.log('DONE');
