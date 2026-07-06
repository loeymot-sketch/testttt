/**
 * posLocalPrinter.js — [CAISSE-BRIDGE 2026-06-28] Impression SILENCIEUSE de la caisse
 * via un pont local node sur le PC caisse (miroir du pont borne).
 *
 * Topologie : Laravel = cloud Linux → ne peut PAS sortir sur l'USB du SAGA caisse.
 * Le serveur RIND les octets ESC/POS (SSOT NF525, endpoint GET orders/{id}/escpos →
 * escpos_b64) ; ce helper les décode et les POSTe TELS QUELS au pont local
 * `http://127.0.0.1:9100/raw` → le SAGA imprime sans aucune fenêtre Chrome.
 *
 * Différence avec la borne (kioskPrinter.js) : la borne envoie un JSON que le pont
 * reconstruit en ESC/POS (ASCII-fold) ; ici on envoie les OCTETS FISCAUX EXACTS
 * (passthrough RAW), donc le ticket papier == le rendu serveur (NF525-fidèle).
 *
 * `http://127.0.0.1` est un secure-context → fetch autorisé depuis une page HTTPS
 * (exempté du blocage mixed-content). Sur la VRAIE caisse, le flag Chrome
 * `--disable-features=BlockInsecurePrivateNetworkRequests,LocalNetworkAccessChecks`
 * est requis (cf docs/runbooks/BORNE_LOCAL_BRIDGE_SETUP.md).
 */

function caisseBridgeUrl() {
  try {
    const u = window.foodkingConfig && window.foodkingConfig.caisseBridgeUrl;
    if (typeof u === 'string' && u) return u.replace(/\/+$/, '');
  } catch (_) { /* défaut ci-dessous */ }
  return 'http://127.0.0.1:9100';
}
export const CAISSE_BRIDGE_URL = caisseBridgeUrl();

function fetchWithTimeout(url, opts, timeoutMs) {
  if (typeof fetch !== 'function') return Promise.reject(new Error('no fetch'));
  const ctrl = typeof AbortController === 'function' ? new AbortController() : null;
  const t = ctrl ? setTimeout(() => ctrl.abort(), timeoutMs) : null;
  const o = Object.assign({}, opts);
  if (ctrl) o.signal = ctrl.signal;
  return fetch(url, o).finally(() => { if (t) clearTimeout(t); });
}

/** Décode une chaîne base64 → Uint8Array (octets fiscaux bruts). */
export function b64ToBytes(b64) {
  const bin = (typeof atob === 'function')
    ? atob(String(b64 || ''))
    : Buffer.from(String(b64 || ''), 'base64').toString('binary');
  const out = new Uint8Array(bin.length);
  for (let i = 0; i < bin.length; i++) out[i] = bin.charCodeAt(i) & 0xff;
  return out;
}

// [PRINT-INSTANT 2026-07-06] Health check MÉMOÏSÉ : le health frappait le pont à
// CHAQUE impression (jusqu'à 800 ms de latence ajoutée par ticket). On met le
// résultat en cache TTL court : positif 20 s (pont stable), négatif 5 s (un pont
// qui démarre redevient visible vite). `force:true` bypass le cache (re-print manuel).
const HEALTH_TTL_OK_MS = 20000;
const HEALTH_TTL_KO_MS = 5000;
let _healthCache = { ok: false, at: 0 };
/** Test-only : purge le cache health. */
export function _resetCaisseBridgeHealthCache() { _healthCache = { ok: false, at: 0 }; }

/** True si le pont caisse répond /health → "UP". Timeout court, jamais throw. */
export async function isCaisseBridgeAvailable(timeoutMs = 800, opts = {}) {
  const now = Date.now();
  const ttl = _healthCache.ok ? HEALTH_TTL_OK_MS : HEALTH_TTL_KO_MS;
  if (!opts.force && _healthCache.at && (now - _healthCache.at) < ttl) {
    return _healthCache.ok;
  }
  let ok = false;
  try {
    const res = await fetchWithTimeout(caisseBridgeUrl() + '/health', {}, timeoutMs);
    if (res && res.ok) {
      const txt = await res.text();
      ok = /UP/i.test(txt);
    }
  } catch (_) { ok = false; }
  _healthCache = { ok, at: Date.now() };
  return ok;
}

/**
 * POSTe les octets ESC/POS (depuis le base64 serveur) au pont local en passthrough RAW.
 * Renvoie {ok:true} si imprimé, sinon null (le caller retombe sur window.print). Jamais throw.
 */
// [PRINT-INSTANT 2026-07-06] Timeout /raw configurable (window.foodkingConfig
// .caisseBridgeRawTimeoutMs), défaut 3000 ms : le pont répond désormais 202
// {queued:true} DÈS réception (impression async côté pont) → 3 s suffisent
// largement, et un abort tardif ne fabrique plus de faux « échec » alors que
// le papier sort (l'ancien timeout 5 s couvrait la compile winspool à chaque job).
function rawTimeoutMs(opts = {}) {
  if (Number.isFinite(opts.timeoutMs) && opts.timeoutMs > 0) return opts.timeoutMs;
  try {
    const t = window.foodkingConfig && window.foodkingConfig.caisseBridgeRawTimeoutMs;
    if (Number.isFinite(t) && t > 0) return t;
  } catch (_) { /* défaut ci-dessous */ }
  return 3000;
}

export async function printEscPosViaCaisseBridge(escposB64, opts = {}) {
  try {
    if (!escposB64) return null;
    const bytes = b64ToBytes(escposB64);
    const res = await fetchWithTimeout(caisseBridgeUrl() + '/raw', {
      method: 'POST',
      headers: { 'Content-Type': 'application/octet-stream' },
      body: bytes,
    }, rawTimeoutMs(opts));
    return res && res.ok ? { ok: true, method: 'caisse-bridge' } : null;
  } catch (_) { return null; }
}

// [ANTI-DOUBLE 2026-06-28] Garde 1-ticket-par-(commande,type) persistée localStorage
// (survit au F5 / re-montage). Clé = orderRef|ticket|jour.
const PRINTED_LS_KEY = 'pos_printed_tickets_v1';
let _printed = null;
function _load() {
  if (_printed) return _printed;
  _printed = new Set();
  try {
    const raw = window.localStorage.getItem(PRINTED_LS_KEY);
    if (raw) JSON.parse(raw).forEach(k => _printed.add(k));
  } catch (_) { /* mémoire seule */ }
  return _printed;
}
export function markPrintedOnceCaisse(orderRef, ticket = 'client') {
  const day = (() => { try { return new Date().toISOString().slice(0, 10); } catch (_) { return ''; } })();
  const k = `${orderRef == null ? '' : orderRef}|${ticket}|${day}`.trim();
  if (k.startsWith('|')) return false; // pas de ref
  const set = _load();
  if (set.has(k)) return false;
  set.add(k);
  try { window.localStorage.setItem(PRINTED_LS_KEY, JSON.stringify(Array.from(set).slice(-300))); } catch (_) {}
  return true;
}
/** Test-only : réinitialise la garde. */
export function _resetPrintedCaisse() { _printed = null; }
