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

/** True si le pont caisse répond /health → "UP". Timeout court, jamais throw. */
export async function isCaisseBridgeAvailable(timeoutMs = 800) {
  try {
    const res = await fetchWithTimeout(caisseBridgeUrl() + '/health', {}, timeoutMs);
    if (!res || !res.ok) return false;
    const txt = await res.text();
    return /UP/i.test(txt);
  } catch (_) { return false; }
}

/**
 * POSTe les octets ESC/POS (depuis le base64 serveur) au pont local en passthrough RAW.
 * Renvoie {ok:true} si imprimé, sinon null (le caller retombe sur window.print). Jamais throw.
 */
export async function printEscPosViaCaisseBridge(escposB64, opts = {}) {
  try {
    if (!escposB64) return null;
    const bytes = b64ToBytes(escposB64);
    const res = await fetchWithTimeout(caisseBridgeUrl() + '/raw', {
      method: 'POST',
      headers: { 'Content-Type': 'application/octet-stream' },
      body: bytes,
    }, opts.timeoutMs || 5000);
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
