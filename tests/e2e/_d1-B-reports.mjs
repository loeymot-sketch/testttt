// VAGUE B dispute — /admin/historique (filtres+recherche+pagination+export),
// Z-report (lecture seule), /admin/cash-sessions-report, /admin/transactions
import fs from 'fs';
import { BASE, OUT, boot, snap, mkLogger, login, bodyText } from './_d1-B-lib.mjs';

const L = mkLogger('b3-reports');
const { browser, page, state } = await boot();

async function goAdmin(path, settle = 3000) {
  await page.goto(BASE + path, { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(settle);
  if (page.url().includes('/login')) {
    await login(page, L);
    await page.goto(BASE + path, { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(settle);
  }
}

await login(page, L);

// ── 4. HISTORIQUE ──────────────────────────────────────────────────────────
await goAdmin('/admin/historique');
const h0 = await bodyText(page);
fs.writeFileSync(OUT + '_b3-historique-initial.txt', h0);
L(`historique initial: ${h0.replace(/\n+/g, ' | ').slice(0, 500)}`);
await snap(page, state, 'b3-01-historique-initial');

// pagination: compter lignes + footer
const rowsCount = await page.locator('tbody tr').count();
L(`historique lignes visibles: ${rowsCount}`);
const pagin = await page.locator('.pagination, [class*="paginat"]').first().innerText().catch(() => '(pas de pagination trouvée)');
L(`pagination: "${pagin.replace(/\s+/g, ' ').slice(0, 200)}"`);
// page 2 si dispo
const next = page.locator('.pagination a, .pagination button, [class*="paginat"] a, [class*="paginat"] button').filter({ hasText: /2|›|»|Suivant/i }).first();
if (await next.count()) {
  await next.click().catch(() => {});
  await page.waitForTimeout(2200);
  L(`pagination → page 2: lignes=${await page.locator('tbody tr').count()}`);
  await snap(page, state, 'b3-02-historique-page2');
}

// filtre datepicker plage réelle: ouvrir panneau filtres
const filterBtn = page.locator('button:has-text("Filtrer"), button:has-text("Filtre")').first();
if (await filterBtn.count()) { await filterBtn.click(); await page.waitForTimeout(900); }
await snap(page, state, 'b3-03-historique-filtres-ouverts');
const dateInputs = page.locator('input[type="date"], .dp__input, input[placeholder*="date" i], input[class*="date"]');
L(`inputs date visibles: ${await dateInputs.count()}`);
// saisir plage 10/06 → 12/06
if (await dateInputs.count() >= 1) {
  const di = dateInputs.first();
  await di.click().catch(() => {});
  await page.waitForTimeout(800);
  await snap(page, state, 'b3-04-historique-datepicker');
  // type range si input texte
  const tag = await di.evaluate((e) => e.type || e.tagName);
  L(`datepicker premier input type=${tag}`);
  if (tag === 'date') {
    await di.fill('2026-06-10');
    const di2 = dateInputs.nth(1);
    if (await di2.count()) await di2.fill('2026-06-12');
  } else {
    await di.fill('10/06/2026').catch(() => {});
    // datepicker UI: cliquer 10 puis 12 si calendrier ouvert
    const day10 = page.locator('.dp__calendar [aria-label*="10"], .dp__cell_inner:has-text("10")').first();
    if (await day10.isVisible().catch(() => false)) {
      await day10.click().catch(() => {});
      await page.waitForTimeout(400);
      const day12 = page.locator('.dp__calendar [aria-label*="12"], .dp__cell_inner:has-text("12")').first();
      await day12.click().catch(() => {});
      await page.waitForTimeout(400);
      const sel = page.locator('.dp__action_select, button:has-text("Sélectionner")').first();
      if (await sel.isVisible().catch(() => false)) await sel.click().catch(() => {});
    }
  }
  await page.waitForTimeout(800);
  await snap(page, state, 'b3-05-historique-plage-saisie');
  const applyBtn = page.locator('button:has-text("Rechercher"), button:has-text("Appliquer"), button[type="submit"]:has-text("Filtrer")').first();
  if (await applyBtn.count()) { await applyBtn.click().catch(() => {}); await page.waitForTimeout(2500); }
  L(`après filtre plage: lignes=${await page.locator('tbody tr').count()}`);
  await snap(page, state, 'b3-06-historique-filtre-applique');
}

// recherche texte: serial de la commande remboursée
const searchInput = page.locator('input[type="search"], input[placeholder*="echerch" i], input[placeholder*="Search" i]').first();
if (await searchInput.count()) {
  await searchInput.fill('1006264329');
  await page.waitForTimeout(300);
  await searchInput.press('Enter').catch(() => {});
  await page.waitForTimeout(2500);
  const rs = await page.locator('tbody tr').count();
  const t = await bodyText(page);
  L(`recherche 1006264329: lignes=${rs} contient-remboursé=${/Remboursé/i.test(t)} négatif=${JSON.stringify((t.match(/[-−]\s?\d+[.,]\d{2}\s?€/g) || []).slice(0, 4))}`);
  await snap(page, state, 'b3-07-historique-recherche-4329');
} else L('WARN: pas de champ recherche trouvé sur historique');

// export XLSX
const dl = [];
page.on('download', (d) => dl.push(d));
const exportBtn = page.locator('button:has-text("Export"), a:has-text("Export"), [class*="export"] button').first();
L(`export bouton présent=${await exportBtn.count()}`);
if (await exportBtn.count()) {
  await exportBtn.click().catch(() => {});
  await page.waitForTimeout(1200);
  await snap(page, state, 'b3-08-historique-export-menu');
  // sous-menu XLSX/Excel ?
  const xls = page.locator('button:has-text("Excel"), a:has-text("Excel"), button:has-text("XLSX"), a:has-text("XLSX"), button:has-text("xls"), li:has-text("Excel")').first();
  if (await xls.count()) { await xls.click().catch(() => {}); }
  await page.waitForTimeout(4000);
  if (dl.length) {
    const d = dl[0];
    const p = OUT + 'b3-export-' + d.suggestedFilename();
    await d.saveAs(p).catch((e) => L('export saveAs fail: ' + e.message));
    L(`EXPORT téléchargé: ${d.suggestedFilename()} → ${p}`);
    try { const st = fs.statSync(p); L(`export taille=${st.size} octets`); } catch {}
  } else L('EXPORT: aucun download intercepté (vérifier réponse réseau)');
  await snap(page, state, 'b3-09-historique-apres-export');
}

// ── 5. Z-REPORT (lecture seule) ────────────────────────────────────────────
// Pas de page admin dédiée (grep router = 0). Widget dashboard + GET API index.
await goAdmin('/admin/dashboard', 3500);
const dash = await bodyText(page);
const zIdx = dash.indexOf('Z');
L(`dashboard contient widget Z? extrait=${JSON.stringify((dash.match(/[^\n]*Z[- ]?report[^\n]*|[^\n]*Rapport Z[^\n]*|[^\n]*Clôture[^\n]*/gi) || []).slice(0, 5))}`);
await snap(page, state, 'b3-10-dashboard-widget-z');
// GET index z-report (lecture seule, AUCUN POST open/close)
const zJson = await page.evaluate(async () => {
  try {
    const r = await window.axios.get('admin/fiscal/z-report');
    return { status: r.status, data: r.data };
  } catch (e) { return { error: String(e), status: e?.response?.status, data: e?.response?.data }; }
});
fs.writeFileSync(OUT + '_b3-zreport-index.json', JSON.stringify(zJson, null, 2));
L(`GET z-report index: status=${zJson.status} → ${JSON.stringify(zJson.data)?.slice(0, 400)}`);
const xJson = await page.evaluate(async () => {
  try {
    const r = await window.axios.get('admin/fiscal/x-report');
    return { status: r.status, data: r.data };
  } catch (e) { return { error: String(e), status: e?.response?.status, data: e?.response?.data }; }
});
fs.writeFileSync(OUT + '_b3-xreport.json', JSON.stringify(xJson, null, 2));
L(`GET x-report: status=${xJson.status} → ${JSON.stringify(xJson.data)?.slice(0, 400)}`);

// ── 6. CASH-SESSIONS-REPORT + TRANSACTIONS ─────────────────────────────────
await goAdmin('/admin/cash-sessions-report');
const csr = await bodyText(page);
fs.writeFileSync(OUT + '_b3-cash-sessions-report.txt', csr);
L(`cash-sessions-report: ${csr.replace(/\n+/g, ' | ').slice(0, 900)}`);
await snap(page, state, 'b3-11-cash-sessions-report');
// chercher la session 21 (100 → 105,30, écart +0,50)
L(`session21 visible: 100→105,30? ${/105,30|105\.30/.test(csr)} écart 0,50? ${/0,50|\+0,50/.test(csr)} raison? ${/dispute round-1|Écart test/.test(csr)}`);

await goAdmin('/admin/transactions');
const tx = await bodyText(page);
fs.writeFileSync(OUT + '_b3-transactions.txt', tx);
L(`transactions: ${tx.replace(/\n+/g, ' | ').slice(0, 900)}`);
await snap(page, state, 'b3-12-transactions');
const txRows = await page.locator('tbody tr').count();
L(`transactions lignes=${txRows} contient 3,80? ${/3,80/.test(tx)} contient 25,00? ${/25,00/.test(tx)} négatif? ${JSON.stringify((tx.match(/[-−]\s?\d+[.,]\d{2}/g) || []).slice(0, 4))}`);

L.flush();
await browser.close();
console.log('DONE');
