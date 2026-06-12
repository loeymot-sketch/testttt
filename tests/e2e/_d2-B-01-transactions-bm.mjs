// VAGUE B ROUND 2 — heal B-R1-19: /admin/transactions rôle BM
// (a) payment-gateway → 200 (plus de 403/AxiosError), (b) filtre Mode de paiement opérationnel,
// (c) AUCUN secret gateway dans les réponses réseau (scan body).
import fs from 'fs';
import { BASE, OUT, boot, snap, mkLogger, login, bodyText } from './_d2-B-lib.mjs';

const L = mkLogger('b1-transactions');
const { browser, page, state } = await boot();

const gwResponses = [];
const SECRET_PATTERNS = /sk_live|sk_test|stripe_secret|secret_key|client_secret|private_key|api_secret|sk-_|whsec_/i;
page.on('response', async (r) => {
  const u = r.url();
  if (/payment-gateway|setting\/payment/i.test(u)) {
    let text = null;
    try { text = await r.text(); } catch {}
    gwResponses.push({ method: r.request().method(), status: r.status(), url: u.replace(BASE, ''), bytes: text?.length ?? null, body: text });
    L(`GW ${r.request().method()} ${r.status()} ${u.replace(BASE, '').slice(0, 120)} bytes=${text?.length}`);
  }
});

await login(page, L); // bm.t2admin@lecayenne.fr (BM)

// ── état 1: page transactions initiale ──
await page.goto(BASE + '/admin/transactions', { waitUntil: 'domcontentloaded' });
await page.waitForTimeout(4000);
L(`url=${page.url().replace(BASE, '')}`);
const t0 = await bodyText(page);
const rowCount0 = await page.locator('table tbody tr').count();
L(`rows visibles: ${rowCount0}`);
L(`extrait page: ${t0.replace(/\n+/g, ' | ').slice(0, 600)}`);
await snap(page, state, 'b1-01-transactions-initial');

// ── filtre visible ? (heal H3: masqué seulement si gateways indisponibles) ──
await page.locator('button:has-text("Filtrer"), .db-card-filter button').nth(1).click().catch(() => {});
await page.evaluate(() => { const f = document.querySelector('#transaction-filter'); if (f) f.style.display = 'block'; });
await page.waitForTimeout(800);
const filterVisible = await page.locator('#payment_method').isVisible().catch(() => false);
L(`filtre "Mode de paiement" visible: ${filterVisible}`);
await snap(page, state, 'b1-02-transactions-filter-open');

// ── état 2: exercer le filtre Mode de paiement ──
if (filterVisible) {
  await page.locator('#payment_method').click();
  await page.waitForTimeout(700);
  const opts = await page.locator('#payment_method .vue-dropdown-item, .vue-dropdown-item').allTextContents().catch(() => []);
  L(`options du filtre: ${JSON.stringify(opts.map((o) => o.trim()).slice(0, 12))}`);
  await snap(page, state, 'b1-03-filter-options');
  // choisir "Cash"/"Espèces" si présent, sinon la 1re option
  const cashOpt = page.locator('.vue-dropdown-item', { hasText: /cash|esp/i }).first();
  const anyOpt = page.locator('.vue-dropdown-item').first();
  const target = (await cashOpt.count()) ? cashOpt : anyOpt;
  const chosen = await target.innerText().catch(() => '?');
  await target.click().catch(() => L('WARN clic option filtre KO'));
  await page.waitForTimeout(600);
  const listResp = page.waitForResponse((r) => /api\/admin\/transaction/.test(r.url()) && r.request().method() === 'GET', { timeout: 15000 }).catch(() => null);
  await page.locator('#transaction-filter button:has-text("Recherche"), #transaction-filter button.bg-primary').first().click();
  const lr = await listResp;
  L(`filtre "${chosen.trim()}" appliqué → liste ${lr ? lr.status() : 'TIMEOUT'} ${lr ? lr.url().replace(BASE, '').slice(0, 140) : ''}`);
  await page.waitForTimeout(2500);
  const rowsAfter = await page.locator('table tbody tr').count();
  const methods = await page.locator('table tbody tr td:nth-child(4)').allTextContents().catch(() => []);
  L(`rows après filtre: ${rowsAfter} ; modes colonne: ${JSON.stringify([...new Set(methods.map((m) => m.trim()))])}`);
  await snap(page, state, 'b1-04-transactions-filtered');
}

// ── scan secrets sur TOUTES les réponses gateway capturées ──
let leak = false;
for (const g of gwResponses) {
  if (g.body && SECRET_PATTERNS.test(g.body)) { leak = true; L(`!!! SECRET PATTERN dans ${g.url} (status ${g.status})`); }
  // valeurs d'options non vides ?
  try {
    const j = JSON.parse(g.body);
    const rows = j?.data || [];
    const withOpts = rows.filter((r) => Array.isArray(r.options) ? r.options.length : r.options && Object.keys(r.options).length);
    L(`gw ${g.url.slice(0, 80)}: ${rows.length} gateways, ${withOpts.length} avec options non vides ${withOpts.length ? JSON.stringify(withOpts.map((w) => w.slug || w.name)) : ''}`);
  } catch {}
}
L(`SECRET LEAK: ${leak ? 'OUI — FUITE' : 'NON (aucun pattern sk_live/secret dans les bodies gateway)'}`);
fs.writeFileSync(OUT + '_b1-gateway-responses.json', JSON.stringify(gwResponses.map((g) => ({ ...g, body: g.body?.slice(0, 4000) })), null, 2));

// ── verdict console/réseau ──
L(`console err/warn cumulés: ${state.consoleBuf.length}`);
state.consoleBuf.forEach((c) => L('  CONSOLE: ' + c));
L(`réseau >=400 cumulés: ${state.netBuf.length}`);
state.netBuf.forEach((n) => L('  NET: ' + n));

L.flush();
await browser.close();
console.log('DONE');
