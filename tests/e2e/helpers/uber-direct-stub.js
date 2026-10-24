#!/usr/bin/env node
// Faux serveur Uber Direct pour les bancs E2E — le VRAI backend (UberDirectClient) lui parle en HTTP, exactement comme à
// api.uber.com : jeton OAuth (scope `eats.deliveries` exigé, comme Uber), devis, création de course (idempotente), lecture,
// annulation ; et il POUSSE des webhooks SIGNÉS (HMAC-SHA256 du corps brut, en-tête `X-Uber-Signature`) vers notre
// `/api/webhooks/uber-direct`, comme Uber. Rien ne part vers Uber : tout reste sur 127.0.0.1.
//
// Pourquoi : jusqu'ici les bancs Uber Direct simulaient NOTRE API dans le navigateur (page.route) ou le client HTTP en PHPUnit
// (Http::fake) ; la chaîne serveur réelle (client OAuth → corps envoyés → webhook → machine à états de la commande) n'avait jamais
// été parcourue de bout en bout. Avec ce faux serveur, passer à Uber réel = changer deux URL du `.env`.
//
// Lancement :  node tests/e2e/helpers/uber-direct-stub.js            (port 8790, webhook → http://127.0.0.1:8766)
//   variables : UBER_STUB_PORT, UBER_STUB_WEBHOOK_URL, UBER_STUB_WEBHOOK_SECRET (= UBER_DIRECT_WEBHOOK_SECRET du .env),
//               UBER_STUB_FEE_CENTS (défaut 742), UBER_STUB_ETA_MIN (défaut 25)
// Côté backend (.env) :  UBER_DIRECT_ENABLED=true  UBER_DIRECT_TOKEN_URL=http://127.0.0.1:8790/oauth/v2/token
//                        UBER_DIRECT_API_BASE=http://127.0.0.1:8790  UBER_DIRECT_WEBHOOK_SECRET=<même secret>
//
// Pilotage par le test (endpoints de contrôle, jamais exposés par Uber) :
//   GET  /__control/log                      → toutes les requêtes reçues (méthode, chemin, corps) + webhooks poussés (code HTTP)
//   POST /__control/reset                    → vide journal, devis, courses, modes
//   POST /__control/mode  {quote:'ok'|'fail'|'uncovered', create:'ok'|'fail'|'timeout', cancel:'ok'|'refuse'}
//   POST /__control/status {delivery_id, status, push:true}  → change le statut de la course et pousse le webhook signé
//   POST /__control/webhook {delivery_id, status, secret?}    → pousse un webhook (secret différent = signature invalide)
//   GET  /__control/deliveries               → état des courses
const http = require('http');
const crypto = require('crypto');

const PORT = Number(process.env.UBER_STUB_PORT || 8790);
const WEBHOOK_URL = process.env.UBER_STUB_WEBHOOK_URL || 'http://127.0.0.1:8766/api/webhooks/uber-direct';
const SECRET = process.env.UBER_STUB_WEBHOOK_SECRET || 'e2e-uber-direct-secret';
const FEE = Number(process.env.UBER_STUB_FEE_CENTS || 742);
const ETA = Number(process.env.UBER_STUB_ETA_MIN || 25);

const state = { log: [], webhooks: [], quotes: {}, deliveries: {}, byIdem: {}, mode: { quote: 'ok', create: 'ok', cancel: 'ok' }, seq: 0, token: null };
const iso = (d) => new Date(d).toISOString();
const json = (res, code, body) => { const s = JSON.stringify(body); res.writeHead(code, { 'content-type': 'application/json', 'content-length': Buffer.byteLength(s) }); res.end(s); };
const readBody = (req) => new Promise((resolve) => { const c = []; req.on('data', (d) => c.push(d)); req.on('end', () => resolve(Buffer.concat(c).toString('utf8'))); });
function parse(raw, ct) {
  if (!raw) return {};
  if (/application\/x-www-form-urlencoded/.test(ct || '')) return Object.fromEntries(new URLSearchParams(raw));
  try { return JSON.parse(raw); } catch (_) { return { _raw: raw }; }
}
function pushWebhook(delivery, status, secret = SECRET) {
  const body = JSON.stringify({ kind: 'event.delivery_status', created: iso(Date.now()), status, delivery_id: delivery.id, id: 'evt_' + (++state.seq), data: { ...delivery, status } });
  const sig = crypto.createHmac('sha256', secret).update(body).digest('hex');
  const u = new URL(WEBHOOK_URL);
  return new Promise((resolve) => {
    const req = http.request({ hostname: u.hostname, port: u.port, path: u.pathname, method: 'POST', headers: { 'content-type': 'application/json', 'content-length': Buffer.byteLength(body), 'x-uber-signature': sig, accept: 'application/json' } }, (r) => {
      const c = []; r.on('data', (d) => c.push(d)); r.on('end', () => { const entry = { delivery_id: delivery.id, status, http: r.statusCode, body: Buffer.concat(c).toString('utf8').slice(0, 300), signed_with_real_secret: secret === SECRET }; state.webhooks.push(entry); resolve(entry); });
    });
    req.on('error', (e) => { const entry = { delivery_id: delivery.id, status, http: 0, error: e.message }; state.webhooks.push(entry); resolve(entry); });
    req.end(body);
  });
}
function authed(req) { const h = req.headers.authorization || ''; return state.token && h === 'Bearer ' + state.token; }

const server = http.createServer(async (req, res) => {
  const url = new URL(req.url, 'http://x');
  const raw = await readBody(req);
  const body = parse(raw, req.headers['content-type']);
  const entry = { at: iso(Date.now()), method: req.method, path: url.pathname, body, auth: !!req.headers.authorization };
  if (!url.pathname.startsWith('/__control')) state.log.push(entry);

  // ── contrôle du banc
  if (url.pathname === '/__control/log') return json(res, 200, { log: state.log, webhooks: state.webhooks, mode: state.mode });
  if (url.pathname === '/__control/deliveries') return json(res, 200, state.deliveries);
  if (url.pathname === '/__control/reset') { state.log = []; state.webhooks = []; state.quotes = {}; state.deliveries = {}; state.byIdem = {}; state.mode = { quote: 'ok', create: 'ok', cancel: 'ok' }; return json(res, 200, { ok: true }); }
  if (url.pathname === '/__control/mode') { Object.assign(state.mode, body); return json(res, 200, state.mode); }
  if (url.pathname === '/__control/status' || url.pathname === '/__control/webhook') {
    const d = state.deliveries[body.delivery_id];
    if (!d) return json(res, 404, { error: 'course inconnue' });
    if (url.pathname === '/__control/status') { d.status = body.status; d.updated = iso(Date.now()); }
    const out = (url.pathname === '/__control/webhook' || body.push) ? await pushWebhook(d, body.status, body.secret || SECRET) : null;
    return json(res, 200, { delivery: d, webhook: out });
  }

  // ── OAuth : comme Uber, seul le scope `eats.deliveries` est accordé pour l'API Direct.
  if (url.pathname === '/oauth/v2/token' && req.method === 'POST') {
    if (!body.client_id || !body.client_secret) return json(res, 401, { error: 'invalid_client' });
    if (body.grant_type !== 'client_credentials') return json(res, 400, { error: 'unsupported_grant_type' });
    if (body.scope !== 'eats.deliveries') return json(res, 400, { error: 'invalid_scope', error_description: 'scope(s) are invalid' });
    state.token = 'stub-' + crypto.randomBytes(12).toString('hex');
    return json(res, 200, { access_token: state.token, expires_in: 2592000, token_type: 'Bearer', scope: 'eats.deliveries' });
  }

  const m = url.pathname.match(/^\/v1\/customers\/([^/]+)\/(delivery_quotes|deliveries)(?:\/([^/]+))?(?:\/(cancel))?$/);
  if (!m) return json(res, 404, { code: 'not_found', message: url.pathname });
  if (!authed(req)) return json(res, 401, { code: 'unauthorized', message: 'This endpoint requires at least one of the following scopes: eats.deliveries' });
  const [, customerId, kind, id, action] = m;

  if (kind === 'delivery_quotes' && req.method === 'POST') {
    if (state.mode.quote === 'fail') return json(res, 500, { code: 'internal_server_error' });
    if (state.mode.quote === 'uncovered') return json(res, 400, { code: 'address_undeliverable', message: 'The specified location is not in a deliverable area.' });
    for (const f of ['pickup_address', 'dropoff_address']) if (!body[f]) return json(res, 400, { code: 'invalid_params', message: f + ' is required' });
    const q = { kind: 'delivery_quote', id: 'dqt_' + (++state.seq), created: iso(Date.now()), expires: iso(Date.now() + 15 * 60000), fee: FEE, currency: 'EUR', currency_type: 'EUR', dropoff_eta: iso(Date.now() + ETA * 60000), duration: ETA, pickup_duration: 10, dropoff_deadline: iso(Date.now() + 90 * 60000), external_store_id: body.external_store_id || null };
    state.quotes[q.id] = q;
    return json(res, 200, q);
  }
  if (kind === 'deliveries' && !id && req.method === 'POST') {
    if (state.mode.create === 'timeout') { await new Promise((r) => setTimeout(r, 12000)); return json(res, 504, { code: 'timeout' }); }
    if (state.mode.create === 'fail') return json(res, 500, { code: 'internal_server_error' });
    const idem = body.idempotency_key || body.external_id;
    if (idem && state.byIdem[idem]) return json(res, 200, state.byIdem[idem]);
    const q = state.quotes[body.quote_id];
    if (!q) return json(res, 400, { code: 'invalid_params', message: 'quote_id unknown or expired' });
    if (new Date(q.expires).getTime() < Date.now()) return json(res, 400, { code: 'invalid_params', message: 'quote expired' });
    for (const f of ['pickup_address', 'dropoff_address', 'pickup_phone_number', 'dropoff_phone_number', 'manifest_items']) if (!body[f]) return json(res, 400, { code: 'invalid_params', message: f + ' is required' });
    const d = { kind: 'delivery', id: 'del_' + (++state.seq), quote_id: q.id, status: 'pending', complete: false, fee: q.fee, currency: 'EUR', tracking_url: 'https://stub.uber.local/track/' + state.seq, external_id: body.external_id || null, created: iso(Date.now()), updated: iso(Date.now()), dropoff_eta: q.dropoff_eta, courier: null, manifest: { reference: body.manifest_reference || null, items: body.manifest_items }, customer_id: customerId };
    state.deliveries[d.id] = d;
    if (idem) state.byIdem[idem] = d;
    return json(res, 200, d);
  }
  if (kind === 'deliveries' && id && action === 'cancel' && req.method === 'POST') {
    const d = state.deliveries[id];
    if (!d) return json(res, 404, { code: 'not_found' });
    if (state.mode.cancel === 'refuse' || ['pickup_complete', 'dropoff', 'delivered'].includes(d.status)) return json(res, 400, { code: 'cannot_cancel', message: 'Delivery cannot be canceled at this stage.' });
    d.status = 'canceled'; d.updated = iso(Date.now());
    return json(res, 200, d);
  }
  if (kind === 'deliveries' && id && !action && req.method === 'GET') {
    const d = state.deliveries[id];
    return d ? json(res, 200, d) : json(res, 404, { code: 'not_found' });
  }
  return json(res, 405, { code: 'method_not_allowed' });
});
server.listen(PORT, '127.0.0.1', () => console.log(`[uber-stub] écoute sur http://127.0.0.1:${PORT} — webhooks → ${WEBHOOK_URL}`));
