/**
 * [E2E-COMPLET 2026-10-22 · VAGUE B] Sonde temps réel : une vente de caisse (API) fait-elle arriver une trame « order.created » sur la WebSocket
 * du KDS (chef) et la carte apparaît-elle SANS recharger ? soketi :6001 + queue:work sont actifs : un échec = signal réel.
 * Vente préfixée E2E-CAISSE-. Aucune correction.
 */
const { test, expect, request } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const crypto = require('crypto');
const { loginAsChefOperator } = require('../../e2e/helpers/login');
const { attachMegaAuditRecorder } = require('../../e2e/helpers/mega-audit-snap');
const { clearFoodKingRateLimits } = require('../../e2e/helpers/rate-limit');

const ROOT = path.resolve(__dirname, '../../..');
const BASE = process.env.PLAYWRIGHT_BASE_URL || 'http://127.0.0.1:8766';
const SHOTS = path.join(ROOT, 'tests/e2e/__screenshots__/e2e-complet-B');
const env = (k) => { const m = fs.readFileSync(path.join(ROOT, '.env'), 'utf8').match(new RegExp('^' + k + '=(.*)$', 'm')); return m ? m[1].trim().replace(/^"|"$/g, '') : ''; };
const pub = { 'X-API-KEY': env('MIX_API_KEY') || env('API_KEY'), Accept: 'application/json', 'Content-Type': 'application/json' };
test.setTimeout(180_000);

test('sonde temps réel : trame WS + carte KDS pour une vente caisse', async ({ page }) => {
  const frames = [];
  page.on('websocket', (ws) => {
    ws.on('framereceived', (f) => { try { const s = String(f.payload); if (/"event"/.test(s)) frames.push({ t: Date.now(), ev: (JSON.parse(s).event || '').slice(0, 60), ch: (JSON.parse(s).channel || '').slice(0, 40), len: s.length }); } catch (_) {} });
  });
  await loginAsChefOperator(page);
  await page.waitForLoadState('networkidle').catch(() => {});
  await page.waitForTimeout(3000);
  const avant = frames.length;

  clearFoodKingRateLimits();
  const l = await request.newContext({ baseURL: BASE, extraHTTPHeaders: { ...pub, 'X-Device-Id': 'e2e-caisse-b-rt-' + Date.now() } });
  const r = await l.post('/api/auth/login', { data: { email: 'pos@lecayenne.fr', password: '123456' } });
  const tok = (await r.json()).token;
  const api = await request.newContext({ baseURL: BASE, extraHTTPHeaders: { ...pub, Authorization: `Bearer ${tok}` } });
  const items = JSON.stringify([{ item_id: 58, item_price: 1, branch_id: 1, instruction: '', quantity: 1, discount: 0, total_price: 1, item_variation_total: 0, item_extra_total: 0, item_variations: [], item_extras: [], pos_line_addons: [] }]);
  const token = 'E2E-CAISSE-RT-' + Date.now();
  const base = { branch_id: 1, token, discount: 0, order_type: 10, is_advance_order: 10, source: 15, phone_order: true, pos_customer_name: 'E2E-CAISSE-RT', pos_payment_method: 1, pos_received_amount: 5, items };
  const q = await api.post('/api/admin/pos/quote', { data: base });
  const qd = (await q.json()); const qq = qd.data || qd;
  const t0 = Date.now();
  const o = await api.post('/api/admin/pos', { data: { ...base, quote_token: qq.quote_token, quote_signature: qq.signature }, headers: { 'X-Idempotency-Key': crypto.randomUUID() } });
  const oj = await o.json();
  expect(o.status(), JSON.stringify(oj).slice(0, 300)).toBe(201);
  const id = oj.data.id;
  // on attend jusqu'à 12 s : trame WS ?
  let ws = null;
  for (let i = 0; i < 24 && !ws; i++) { await page.waitForTimeout(500); ws = frames.slice(avant).find((f) => /order/i.test(f.ev)) || null; }
  const apresWs = frames.slice(avant).map((f) => ({ ms: f.t - t0, ev: f.ev, ch: f.ch }));
  // carte KDS ? (plafond 24 cartes : on regarde aussi le magasin)
  const carte = await page.locator(`[data-order-id="${id}"]`).count();
  const cartes = await page.locator('[data-order-id]').count();
  const out = { id, http: o.status(), trames_apres_la_vente: apresWs, trame_order_recue: !!ws, carte_kds_visible: carte > 0, cartes_kds: cartes, domain_events: null };
  fs.writeFileSync(path.join(SHOTS, '_resultats-sonde-temps-reel.json'), JSON.stringify(out, null, 2));
  console.log('[SONDE-RT]', JSON.stringify(out));
  const rec = attachMegaAuditRecorder(page, SHOTS);
  await rec.snap('40-kds-apres-vente-caisse-ws');
});
