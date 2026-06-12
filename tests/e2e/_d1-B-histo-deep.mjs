// VAGUE B dispute — historique PROFOND (filtre plage datepicker, recherche n°,
// pagination, export XLS) + widget Z dashboard (liens lecture seule + eod-pdf)
import fs from 'fs';
import { BASE, OUT, boot, snap, mkLogger, login, bodyText } from './_d1-B-lib.mjs';

const L = mkLogger('b4-histo');
const { browser, page, state } = await boot();
const net = [];
page.on('response', (r) => {
  if (/export|xls|eod-pdf|z-report/i.test(r.url())) net.push(`${r.request().method()} ${r.status()} ${r.url().replace(BASE, '').slice(0, 140)} ct=${r.headers()['content-type'] || '?'}`);
});
const downloads = [];
page.on('download', (d) => downloads.push(d));

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
await goAdmin('/admin/historique');

// ── pagination page 2 ──
const before = await page.locator('tbody tr td:first-child').first().innerText().catch(() => '?');
const clicked = await page.evaluate(() => {
  const cands = Array.from(document.querySelectorAll('button, a'))
    .filter((e) => e.offsetParent !== null && (e.textContent.trim() === '2' || e.textContent.trim() === 'Suivant'));
  const two = cands.find((e) => e.textContent.trim() === '2') || cands[0];
  if (two) { two.click(); return two.textContent.trim(); }
  return null;
});
L(`pagination clic="${clicked}"`);
await page.waitForTimeout(2500);
const after = await page.locator('tbody tr td:first-child').first().innerText().catch(() => '?');
L(`pagination: 1re ligne page1="${before.trim()}" → page2="${after.trim()}" (changé=${before !== after})`);
await snap(page, state, 'b4-01-historique-page2');

// ── filtre: panneau + recherche n° commande remboursée ──
await page.locator('button:has-text("Filtrer")').first().click().catch(() => L('WARN Filtrer introuvable'));
await page.waitForTimeout(900);
await page.locator('#order_id').fill('1006264329');
await page.locator('button:has-text("Rechercher")').first().click();
await page.waitForTimeout(2800);
const rows = await page.locator('tbody tr').count();
const t1 = await bodyText(page);
L(`recherche n° 1006264329: lignes=${rows} remboursé=${/Remboursé/i.test(t1)} montants=${JSON.stringify((t1.match(/\d+[.,]\d{2}\s?€/g) || []).slice(0, 6))}`);
await snap(page, state, 'b4-02-historique-recherche');
// chips filtre actif ?
const chips = await page.locator('.hist-filter-chip').allTextContents().catch(() => []);
L(`chips filtre actif: ${JSON.stringify(chips)}`);

// reset
await page.locator('button:has-text("Effacer")').first().click().catch(() => {});
await page.waitForTimeout(2000);

// ── filtre plage datepicker 10/06 → 12/06 ──
for (let i = 0; i < 3; i++) {
  const open = await page.locator('#order_id').isVisible().catch(() => false);
  if (open) break;
  await page.locator('button:has-text("Filtrer")').first().click().catch(() => {});
  await page.waitForTimeout(1100);
}
L(`panneau filtre ouvert=${await page.locator('#order_id').isVisible().catch(() => false)}`);
const dpInput = page.locator('#historique-filter .dp__input, .table-filter-div .dp__input').first();
L(`datepicker présent=${await dpInput.count()}`);
if (await dpInput.count()) {
  await dpInput.click();
  await page.waitForTimeout(900);
  await snap(page, state, 'b4-03-datepicker-ouvert');
  // presets ?
  const presets = await page.locator('.dp__preset_ranges .dp__preset_range, [class*="preset"]').allTextContents().catch(() => []);
  L(`presets datepicker: ${JSON.stringify(presets.slice(0, 8))}`);
  // cliquer 10 puis 12 dans le calendrier courant (juin 2026)
  const c10 = page.locator('.dp__calendar_item:not(.dp__cell_offset) .dp__cell_inner:text-is("10")').first();
  const c12 = page.locator('.dp__calendar_item:not(.dp__cell_offset) .dp__cell_inner:text-is("12")').first();
  if (await c10.isVisible().catch(() => false)) {
    await c10.click(); await page.waitForTimeout(400);
    await c12.click().catch(() => {}); await page.waitForTimeout(600);
  } else {
    L('WARN cellules 10/12 non visibles — dump classes calendrier');
  }
  const dpVal = await dpInput.inputValue().catch(() => '?');
  L(`datepicker valeur="${dpVal}"`);
  await snap(page, state, 'b4-04-datepicker-plage');
  await page.locator('button:has-text("Rechercher")').first().click().catch(() => {});
  await page.waitForTimeout(2800);
  const t2 = await bodyText(page);
  const total = (t2.match(/Affichage de .*entrées/) || ['?'])[0];
  L(`après plage 10→12/06: ${total}`);
  await snap(page, state, 'b4-05-historique-plage-appliquee');
}

// ── export XLS ──
const exportBtn = page.locator('button:has-text("Exporter")').first();
L(`bouton Exporter présent=${await exportBtn.count()}`);
if (await exportBtn.count()) {
  await exportBtn.click();
  await page.waitForTimeout(800);
  await snap(page, state, 'b4-06-export-dropdown');
  const items = await page.locator('.dropdown-list a, .dropdown-list button, .db-card-filter-dropdown-list a, .db-card-filter-dropdown-list button').allTextContents().catch(() => []);
  L(`items dropdown export: ${JSON.stringify(items.map(s => s.trim()).filter(Boolean).slice(0, 6))}`);
  const xls = page.locator('.dropdown-list >> text=/xls|excel/i').first();
  if (await xls.count()) {
    await xls.click().catch(() => {});
    await page.waitForTimeout(5000);
  }
  if (downloads.length) {
    const d = downloads[downloads.length - 1];
    const p = OUT + 'b4-export-' + d.suggestedFilename();
    await d.saveAs(p).catch((e) => L('saveAs fail ' + e.message));
    try { const st = fs.statSync(p); L(`EXPORT OK: ${d.suggestedFilename()} ${st.size} octets`); } catch {}
  } else L('EXPORT: aucun event download — voir réponses réseau');
  await snap(page, state, 'b4-07-apres-export');
}

// ── dashboard widget Z + eod-pdf ──
await goAdmin('/admin/dashboard', 3500);
await page.evaluate(() => { const el = document.querySelector('[data-testid="last-z-report-link"]'); if (el) el.scrollIntoView({ block: 'center' }); });
await page.waitForTimeout(600);
await snap(page, state, 'b4-08-dashboard-widget-z');
const widgetTxt = await page.locator('[data-testid="last-z-report-link"]').evaluate((el) => el.closest('.db-card')?.innerText).catch(() => '?');
L(`widget Z: ${JSON.stringify(widgetTxt)}`);
// clic lien "Voir les clôtures Z" — où atterrit-on ?
await page.locator('[data-testid="last-z-report-link"]').click().catch(() => L('WARN lien Z introuvable'));
await page.waitForTimeout(2500);
L(`"Voir les clôtures Z" → ${page.url().replace(BASE, '')}`);
const zDest = await bodyText(page);
L(`destination contient "Z"? rapport-z listé=${/Rapport Z|Z #|sequence/i.test(zDest)} — page=${(zDest.match(/Transactions|Historique|Clôtures?/gi) || []).slice(0, 4)}`);
await snap(page, state, 'b4-09-voir-clotures-z-destination');

// eod-pdf "PDF Clôture du jour" (génération rapport, PAS une clôture fiscale)
await goAdmin('/admin/dashboard', 3000);
const eodBtn = page.locator('button:has-text("PDF Clôture du jour")').first();
L(`bouton eod-pdf présent=${await eodBtn.count()}`);
if (await eodBtn.count()) {
  await eodBtn.click();
  await page.waitForTimeout(5000);
  if (downloads.length) {
    const d = downloads[downloads.length - 1];
    const p = OUT + 'b4-' + d.suggestedFilename();
    await d.saveAs(p).catch(() => {});
    try { const st = fs.statSync(p); L(`EOD-PDF téléchargé: ${d.suggestedFilename()} ${st.size} octets`); } catch {}
  }
  await snap(page, state, 'b4-10-eod-pdf');
}

fs.writeFileSync(OUT + '_b4-net-export.txt', net.join('\n') || '(rien)');
L(`réseau export/z: ${JSON.stringify(net)}`);
L.flush();
await browser.close();
console.log('DONE');
