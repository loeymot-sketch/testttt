/**
 * kitchenLocalPrinter.js — [KITCHEN-BRIDGE 2026-07-09] Impression SILENCIEUSE du
 * ticket CUISINE via un pont local node sur le PC cuisine (miroir du pont caisse
 * posLocalPrinter.js).
 *
 * Topologie : Laravel = cloud Linux → ne peut PAS sortir sur l'USB de l'imprimante
 * cuisine. Le serveur rend les octets ESC/POS du ticket cuisine (SSOT NF525,
 * endpoint GET orders/{id}/escpos?ticket=kitchen → escpos_b64, width-safe/symbolique) ;
 * ce helper les décode et les POSTe TELS QUELS au pont local
 * `http://127.0.0.1:9101/raw` → l'imprimante cuisine imprime sans fenêtre Chrome.
 *
 * C'est l'écran KDS qui appelle ce helper AUTOMATIQUEMENT à chaque nouvelle commande
 * (toutes sources), avec une dé-dup robuste persistée en localStorage (jamais deux
 * fois le même ticket au refresh/reconnexion).
 *
 * `http://127.0.0.1` est un secure-context → fetch autorisé depuis une page HTTPS.
 * Sur le vrai PC cuisine, le flag Chrome
 * `--disable-features=BlockInsecurePrivateNetworkRequests,LocalNetworkAccessChecks`
 * est requis (même topologie que la caisse).
 */

const KITCHEN_BRIDGE_PORT = 9101;

function kitchenBridgeUrl() {
  try {
    const u = window.foodkingConfig && window.foodkingConfig.kitchenBridgeUrl;
    if (typeof u === 'string' && u) return u.replace(/\/+$/, '');
  } catch (_) { /* défaut ci-dessous */ }
  return 'http://127.0.0.1:' + KITCHEN_BRIDGE_PORT;
}
export const KITCHEN_BRIDGE_URL = kitchenBridgeUrl();

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

/**
 * POSTe les octets ESC/POS (depuis le base64 serveur) au pont cuisine local en
 * passthrough RAW. Renvoie un résultat DISCRIMINÉ, jamais throw :
 *   - {ok:true}                        → accepté (202/2xx) : imprimé
 *   - {ok:false, retriable:true, reason} → pont injoignable / timeout / réseau /
 *     302 / 401 / 429 / 5xx : PAS imprimé mais à réessayer (le RETRY KDS
 *     ressortira le ticket quand le pont/imprimante revient)
 *   - null                             → rien à imprimer (b64 vide) : non-retriable
 * Backward-compat : les appelants testent `r && r.ok`.
 */
function rawTimeoutMs(opts = {}) {
  if (Number.isFinite(opts.timeoutMs) && opts.timeoutMs > 0) return opts.timeoutMs;
  try {
    const t = window.foodkingConfig && window.foodkingConfig.kitchenBridgeRawTimeoutMs;
    if (Number.isFinite(t) && t > 0) return t;
  } catch (_) { /* défaut ci-dessous */ }
  // [KITCHEN-RESILIENCE 2026-07-13] Le pont /raw répond désormais le RÉSULTAT RÉEL de
  // l'impression (200/500), borné par SON timeout d'impression (KITCHEN_PRINT_TIMEOUT_MS,
  // défaut 15 s). Le timeout client DOIT être PLUS GRAND (20 s) : sinon on abandonnerait
  // (abort) pendant que le pont imprime encore → faux « échec » → retry → DOUBLE impression.
  // Ainsi on obtient toujours le vrai verdict avant d'abandonner.
  return 20000;
}

export async function printEscPosViaKitchenBridge(escposB64, opts = {}) {
  try {
    if (!escposB64) return null;
    const bytes = b64ToBytes(escposB64);
    const res = await fetchWithTimeout(kitchenBridgeUrl() + '/raw', {
      method: 'POST',
      headers: { 'Content-Type': 'application/octet-stream' },
      body: bytes,
    }, rawTimeoutMs(opts));
    if (res && res.ok) return { ok: true, method: 'kitchen-bridge' };
    // Réponse non-2xx (302 login, 401 session expirée, 429 throttle, 5xx pont) :
    // ticket NON imprimé → retriable pour que le RETRY le ressorte.
    return { ok: false, retriable: true, reason: 'http-' + ((res && res.status) || 0) };
  } catch (_) {
    // Pont éteint / timeout (abort) / réseau : retriable.
    return { ok: false, retriable: true, reason: 'network' };
  }
}

// ── Dé-dup persistée : un ticket cuisine PAR commande, jamais deux ────────────
// Set d'ids déjà imprimés, persisté en localStorage (survit au F5 / re-montage /
// reconnexion WS), borné aux N derniers ids pour ne pas gonfler indéfiniment.
const PRINTED_LS_KEY = 'kds.printedKitchenIds';
const PRINTED_MAX = 500;
let _printed = null;

function _load() {
  if (_printed) return _printed;
  _printed = new Set();
  try {
    const raw = window.localStorage.getItem(PRINTED_LS_KEY);
    if (raw) JSON.parse(raw).forEach((id) => _printed.add(String(id)));
  } catch (_) { /* mémoire seule */ }
  return _printed;
}

function _persist(set) {
  try {
    window.localStorage.setItem(PRINTED_LS_KEY, JSON.stringify(Array.from(set).slice(-PRINTED_MAX)));
  } catch (_) { /* private mode / quota — la garde mémoire tient dans la session */ }
}

/** True si l'id a déjà été imprimé (dé-dup). */
export function hasKitchenPrinted(orderId) {
  if (orderId == null) return true;
  return _load().has(String(orderId));
}

/**
 * Marque un id comme imprimé. Renvoie true si NOUVEAU (à imprimer), false si déjà vu.
 * À appeler juste avant/après l'impression pour garantir « exactement une fois ».
 */
export function markKitchenPrinted(orderId) {
  if (orderId == null) return false;
  const set = _load();
  const key = String(orderId);
  if (set.has(key)) return false;
  set.add(key);
  _persist(set);
  return true;
}

/**
 * Seed le set avec les ids du backlog (commandes déjà présentes au montage), SANS
 * imprimer. Empêche une ré-impression massive au reload/reconnexion.
 */
export function seedKitchenPrinted(orderIds) {
  const set = _load();
  let changed = false;
  (orderIds || []).forEach((id) => {
    if (id == null) return;
    const key = String(id);
    if (!set.has(key)) { set.add(key); changed = true; }
  });
  if (changed) _persist(set);
}

/** Test-only : réinitialise la garde (mémoire — ne touche pas localStorage). */
export function _resetPrintedKitchen() { _printed = null; }

// ── Dé-dup CROSS-ONGLET : coordination in-flight + printed set entre onglets ────
// [S3-06 2026-07-18] Le set `_printed` était mémoïsé PAR ONGLET (lu une fois puis
// caché dans le module) et la garde in-flight vivait dans le composant KDS, donc PAR
// ONGLET aussi. Deux onglets /kds ouverts sur le PC cuisine recevaient la MÊME commande
// (WS/poll) et voyaient tous deux hasKitchenPrinted=false / rien en in-flight → 2 POST
// au pont 9101 → 2 tickets physiques. (Distinct du doublon INTRA-onglet 20 s déjà healé.)
//
// Fix cross-onglet, sans dépendance externe :
//  (1) un listener `storage` resynchronise `_printed` quand un AUTRE onglet marque un
//      ticket imprimé (même pattern que la sync token app.js:248 / pos-app.js:211) ;
//  (2) une CARTE de claims in-flight PERSISTÉE en localStorage coordonne « quel onglet
//      imprime cette commande » (claim par relecture fraîche + TTL) : un seul onglet
//      obtient le claim → un seul POST ;
//  (3) le check « déjà imprimé » du claim se fait sur une RELECTURE FRAÎCHE de
//      localStorage (pas le cache module) → immunisé au cache périmé.

// Identité de cet onglet (propriétaire d'un claim). Un random suffit : le claim bloque
// sur TOUT claim vivant ; l'id ne sert qu'à ne relâcher QUE son propre claim.
const _tabId = (() => {
  try {
    if (typeof window !== 'undefined' && window.crypto && typeof window.crypto.randomUUID === 'function') {
      return window.crypto.randomUUID();
    }
  } catch (_) { /* fallback ci-dessous */ }
  return 'kds-' + Math.random().toString(36).slice(2) + '-' + Date.now().toString(36);
})();

// Un AUTRE onglet a écrit le set imprimé → fusionne ses ids dans le cache local pour que
// hasKitchenPrinted() soit à jour sans attendre un reload (corrige le cache périmé).
function _syncPrintedFromOtherTab(e) {
  if (!e || e.key !== PRINTED_LS_KEY || !e.newValue) return;
  try {
    const set = _load();
    JSON.parse(e.newValue).forEach((id) => set.add(String(id)));
  } catch (_) { /* jamais casser l'app sur une sync cross-onglet */ }
}
try {
  if (typeof window !== 'undefined' && typeof window.addEventListener === 'function') {
    window.addEventListener('storage', _syncPrintedFromOtherTab);
  }
} catch (_) { /* pas de window (node pur) — dé-dup dégradée en mémoire seule */ }

// Carte de claims in-flight partagée entre onglets : { [orderId]: { tab, ts } }.
const CLAIM_LS_KEY = 'kds.kitchenPrintClaims';
// TTL d'un claim : DOIT dépasser le timeout /raw du pont (20 s, cf. rawTimeoutMs) pour ne
// jamais relâcher une commande encore en cours d'impression dans un autre onglet ; borne
// aussi les claims orphelins (onglet fermé pendant l'impression) → repris ensuite.
const CLAIM_TTL_MS = 30000;

function _readClaims() {
  try {
    const raw = window.localStorage.getItem(CLAIM_LS_KEY);
    if (raw) { const o = JSON.parse(raw); if (o && typeof o === 'object') return o; }
  } catch (_) { /* mémoire seule */ }
  return {};
}

function _writeClaims(claims) {
  try { window.localStorage.setItem(CLAIM_LS_KEY, JSON.stringify(claims)); } catch (_) { /* private mode / quota */ }
}

// Supprime les claims expirés (TTL) — mutation en place, renvoie l'objet.
function _pruneClaims(claims, now) {
  Object.keys(claims).forEach((k) => {
    const c = claims[k];
    if (!c || typeof c.ts !== 'number' || (now - c.ts) > CLAIM_TTL_MS) delete claims[k];
  });
  return claims;
}

// Relit le set imprimé DEPUIS localStorage (frais) et le fusionne dans le cache module.
function _reloadPrintedFromStorage() {
  const set = _load();
  try {
    const raw = window.localStorage.getItem(PRINTED_LS_KEY);
    if (raw) JSON.parse(raw).forEach((id) => set.add(String(id)));
  } catch (_) { /* garde mémoire */ }
  return set;
}

/**
 * Réserve l'impression de `orderId` pour CET onglet, coordonné entre onglets via
 * localStorage. Renvoie true si CET onglet doit imprimer (claim obtenu), false si :
 *   - déjà imprimé (relecture FRAÎCHE cross-onglet) — sauf `opts.force` (réimpression
 *     volontaire) ;
 *   - un claim est déjà VIVANT (cet onglet en burst, ou un AUTRE onglet imprime).
 * À libérer par releaseKitchenPrint() dans le finally (succès OU échec) : un échec doit
 * relâcher pour que le retry (ici ou dans un autre onglet) puisse ressortir le ticket.
 */
export function claimKitchenPrint(orderId, opts = {}) {
  if (orderId == null) return false;
  const key = String(orderId);
  if (!opts.force && _reloadPrintedFromStorage().has(key)) return false;
  const now = Date.now();
  const claims = _pruneClaims(_readClaims(), now);
  if (claims[key]) return false; // claim vivant (même onglet en burst OU autre onglet)
  claims[key] = { tab: _tabId, ts: now };
  _writeClaims(claims);
  return true;
}

/** Libère le claim in-flight de `orderId` s'il est détenu par CET onglet. */
export function releaseKitchenPrint(orderId) {
  if (orderId == null) return;
  const key = String(orderId);
  const claims = _readClaims();
  const c = claims[key];
  if (c && c.tab === _tabId) { delete claims[key]; _writeClaims(claims); }
}

/** Test-only : purge la carte de claims in-flight (ne touche pas le printed set). */
export function _resetKitchenClaims() { try { window.localStorage.removeItem(CLAIM_LS_KEY); } catch (_) { /* noop */ } }

// ── Liste d'ÉCHEC persistée ───────────────────────────────────────────────────
// [SUPERVISOR-HEAL 2026-07-13] Les tickets cuisine dont l'impression a ÉCHOUÉ (pont/
// imprimante KO) doivent survivre à un reload pendant la panne : sinon le seed du
// backlog les marquerait « imprimés » et ils seraient PERDUS à vie. On persiste donc
// la liste d'échec, aussi durable que la dé-dup ; au montage, ces ids sont exclus du
// seed et restent à réessayer (retry auto) → 0 ticket perdu même après un F5.
const FAILED_LS_KEY = 'kds.failedKitchenIds';
const FAILED_MAX = 500;

/** Charge la liste d'ids cuisine en échec persistée (survit au reload). @return {string[]} */
export function getKitchenFailed() {
  try {
    const raw = window.localStorage.getItem(FAILED_LS_KEY);
    if (raw) { const a = JSON.parse(raw); if (Array.isArray(a)) return a.map((id) => String(id)); }
  } catch (_) { /* mémoire seule */ }
  return [];
}

/** Persiste la liste d'ids cuisine en échec (bornée aux N derniers). */
export function setKitchenFailed(ids) {
  try {
    window.localStorage.setItem(FAILED_LS_KEY, JSON.stringify((ids || []).map((id) => String(id)).slice(-FAILED_MAX)));
  } catch (_) { /* private mode / quota */ }
}
