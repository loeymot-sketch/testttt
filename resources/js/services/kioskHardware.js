/**
 * kioskHardware.js — Service wrapper autour du bridge Electron `window.borne.*`.
 * -----------------------------------------------------------------------------
 * FoodKing Kiosk — Phase 5.1.
 *
 * Source d'autorité : docs/design/KIOSK_HARDWARE_CALLS.md + KIOSK_HARDWARE_SPEC.md.
 *
 * Pourquoi ce wrapper ?
 *  - Type safety côté consommateurs Vue (pas de optional-chaining répété).
 *  - Contrat uniforme `{ ok: boolean, error?: string, ...data }`.
 *  - Stub dev no-op quand `window.borne` est `undefined` (browser classique,
 *    tests Vitest, staging navigateur).
 *  - Point d'observabilité unique : chaque échec hardware logue automatiquement
 *    un event `hardware_event` dans le backend (via `reportHardwareEvent`).
 *  - Toutes les méthodes sont `async` pour uniformité — même les sync du bridge.
 *
 * Invariants :
 *  - Aucune méthode ne `throw` — toujours résolu en `{ ok: false, error }`.
 *  - `stop*` et `dispose` ne signalent jamais d'échec (best-effort).
 *  - Jamais d'import direct de `window.borne.*` dans les composants Vue —
 *    tout doit passer par ce module pour traçabilité.
 *  - Safe côté tests : pas d'appel au DOM hors bridge lookups.
 */

/* ------------------------------------------------------------------ */
/*  Bridge detection + stub                                           */
/* ------------------------------------------------------------------ */

const STUB_MARKER = '__foodkingKioskStub';

function isBridgeAvailable() {
    return typeof window !== 'undefined' && !!window.borne;
}

/**
 * Retourne `window.borne` ou un stub no-op pour dev/tests.
 * Le stub émet dans console.debug pour aider le dev, mais reste silencieux
 * en prod (pas de throw).
 */
function getBridge() {
    if (isBridgeAvailable()) return window.borne;
    return buildStub();
}

let _cachedStub = null;
function buildStub() {
    if (_cachedStub) return _cachedStub;
    const noop = async () => {};
    const okAsync = async () => ({ ok: true });
    const stub = {
        [STUB_MARKER]: true,
        isElectron: false,
        // Audio
        play: noop,
        stopAllSounds: () => {},
        speak: noop,
        stopSpeak: () => {},
        // Haptique
        haptic: () => {},
        // Scan
        scanQR: async () => null,
        readNFC: async () => null,
        // Paiement
        tpeCharge: async () => ({ ok: true, tx_ref: 'stub-' + Date.now() }),
        tpeRefund: okAsync,
        chargeCard: async () => ({ status: 'approved', tx_ref: 'stub-' + Date.now() }),
        cancelPayment: okAsync,
        getPaymentStatus: async () => ({ ready: true }),
        // Tiroir
        openDrawer: okAsync,
        // Impression
        print: okAsync,
        printReceipt: okAsync,
        printEscPos: okAsync,
        // Diagnostics
        info: async () => ({
            firmware_version: 'stub',
            printer_status: 'ready',
            tpe_status: 'ready',
            camera_status: 'not_connected',
            nfc_status: 'ready',
        }),
        healthcheck: async () => ({ ok: true, components: {} }),
        // Événements
        onHardwareEvent: () => () => {},
        // App lifecycle
        reload: okAsync,
        quit: okAsync,
    };
    _cachedStub = stub;
    return stub;
}

/* ------------------------------------------------------------------ */
/*  [P0-5 / KIO-1] Boot healthcheck gate                              */
/* ------------------------------------------------------------------ */
/*
 * Module-level latch tracking whether the boot healthcheck has passed.
 * Closed (`false`) by default — `tpeCharge()` short-circuits unless this
 * is explicitly opened by `markBootHealthcheckPassed()` (called from
 * `kioskBootHealthcheck.js` after critical_failures.length === 0).
 *
 * Critical invariant : when `isKioskBridge() === false` (browser/dev/tests),
 * the gate behaves PERMISSIVELY so vitest, staging-without-bridge, and
 * any non-Electron run path are unaffected. Only real Electron bridge
 * sessions are gated.
 *
 * The gate resets to `false` on `resetBootHealthcheckGate()` (used by
 * tests and by the retry button on the boot overlay).
 */
let _bootHealthcheckPassed = false;

export function markBootHealthcheckPassed() {
    _bootHealthcheckPassed = true;
}

export function resetBootHealthcheckGate() {
    _bootHealthcheckPassed = false;
}

export function isBootHealthcheckPassed() {
    return _bootHealthcheckPassed;
}

/**
 * Returns `{ ok: true }` if it is safe to proceed with a payment-bearing
 * operation, otherwise `{ ok: false, error: 'healthcheck_required' }`.
 *
 * Caller responsibility : if `ok === false`, the UI must surface the
 * boot overlay and refuse to start a payment session.
 *
 * Permissive in non-bridge environments (browser/dev/tests) — see the
 * STUB_MARKER + isKioskBridge() rationale above.
 */
export function requireHealthCheck() {
    if (!isKioskBridge()) return { ok: true, stub: true };
    if (_bootHealthcheckPassed) return { ok: true };
    return { ok: false, error: 'healthcheck_required' };
}

/* ------------------------------------------------------------------ */
/*  Result wrapper                                                     */
/* ------------------------------------------------------------------ */

function ok(extra = {}) { return { ok: true, ...extra }; }
function fail(error, extra = {}) { return { ok: false, error: String(error || 'unknown'), ...extra }; }

/**
 * Enrobe une Promise bridge pour garantir le contrat `{ok, error?}`.
 * Les bridges retournent déjà `{ok}` dans la plupart des cas, mais certaines
 * méthodes (haptic, play, stopSpeak) sont purement void — d'où cet helper.
 */
async function runSafe(method, fn, args = []) {
    try {
        const result = await fn(...args);
        // Si le bridge a déjà renvoyé {ok}, respecter ce shape.
        if (result && typeof result === 'object' && 'ok' in result) return result;
        return ok({ data: result });
    } catch (e) {
        reportHardwareFailureSilent(method, e?.message || String(e));
        return fail(e?.message || String(e));
    }
}

/* ------------------------------------------------------------------ */
/*  Observabilité (non-bloquant)                                       */
/* ------------------------------------------------------------------ */

/**
 * Envoie un event `hardware_event` vers `/api/frontend/kiosk-event`.
 * Fire-and-forget — les erreurs réseau sont silencieuses pour ne jamais
 * bloquer le flux kiosk. Utilise `window.axios` si disponible, sinon
 * `fetch` avec keepalive (utile si un beforeunload est imminent).
 */
function postKioskEvent(body) {
    try {
        const payload = JSON.stringify(body);
        if (typeof window !== 'undefined' && window.axios?.post) {
            window.axios.post('frontend/kiosk-event', body).catch(() => {});
            return;
        }
        if (typeof fetch === 'function') {
            fetch('/api/frontend/kiosk-event', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: payload,
                keepalive: true,
            }).catch(() => {});
        }
    } catch (_) {
        // no-op
    }
}

function reportHardwareFailureSilent(method, message) {
    postKioskEvent({
        type: 'hardware_event',
        event_name: 'hardware_error',
        details: 'method=' + method + ' | err=' + (message || 'unknown').slice(0, 200),
        payload: { component: method, severity: 'error', code: 'bridge_throw' },
    });
}

/* ------------------------------------------------------------------ */
/*  API publique                                                       */
/* ------------------------------------------------------------------ */

/**
 * Détermine si l'environnement est Electron (vraie borne) ou stub.
 * Ne fait JAMAIS confiance à `window.borne.isElectron` seul : on vérifie
 * aussi que le bridge n'est pas notre stub.
 */
export function isKioskBridge() {
    if (!isBridgeAvailable()) return false;
    return !window.borne[STUB_MARKER] && !!window.borne.isElectron;
}

/**
 * Raccourci utilisé par les tests / debug.
 */
export function _getRawBridgeForTest() {
    return getBridge();
}

// --- Audio ---------------------------------------------------------------

export async function play(soundId) {
    const b = getBridge();
    if (typeof b.play !== 'function') return ok({ stub: true });
    return runSafe('play', b.play, [soundId]);
}

export function stopAllSounds() {
    const b = getBridge();
    try { b.stopAllSounds?.(); } catch (_) {}
}

export async function speak(text, locale) {
    const b = getBridge();
    if (typeof b.speak !== 'function') return ok({ stub: true });
    return runSafe('speak', b.speak, [text, locale]);
}

export function stopSpeak() {
    const b = getBridge();
    try { b.stopSpeak?.(); } catch (_) {}
}

// --- Haptique ------------------------------------------------------------

export function haptic(pattern = 'tap') {
    const b = getBridge();
    try { b.haptic?.(pattern); } catch (_) {}
}

// --- Scan ----------------------------------------------------------------

export async function scanQR(timeoutMs = 15000) {
    const b = getBridge();
    if (typeof b.scanQR !== 'function') return fail('scanQR_unavailable');
    return runSafe('scanQR', b.scanQR, [timeoutMs]);
}

export async function readNFC(timeoutMs = 15000) {
    const b = getBridge();
    if (typeof b.readNFC !== 'function') return fail('readNFC_unavailable');
    return runSafe('readNFC', b.readNFC, [timeoutMs]);
}

// --- Paiement ------------------------------------------------------------

/**
 * Charge une carte via TPE. Conforme au spec (§1 hardware calls) :
 *  - Renvoie `{ok, tx_ref?, error?}`
 *  - Timeout natif TPE 90 s — caller doit prévoir un timeout UI distinct.
 *
 * [P0-5 / KIO-1] Gate healthcheck obligatoire :
 *  - Hard-fail en mode bridge (Electron) si le boot healthcheck n'a pas
 *    été marqué passé via `markBootHealthcheckPassed()`. Empêche les
 *    encaissements fantômes quand le bridge est présent mais les drivers
 *    TPE ont fail init silently.
 *  - Permissif en mode stub (browser/dev/tests) — voir requireHealthCheck.
 */
export async function tpeCharge(amountCents, method = 'CB') {
    const gate = requireHealthCheck();
    if (!gate.ok) {
        reportHardwareFailureSilent('tpeCharge', 'healthcheck_required');
        return fail('healthcheck_required');
    }
    const b = getBridge();
    if (typeof b.tpeCharge !== 'function') {
        // Fallback legacy : chargeCard est le nom utilisé par KioskPayment actuel.
        if (typeof b.chargeCard === 'function') {
            const r = await runSafe('chargeCard', b.chargeCard, [amountCents / 100, method]);
            if (!r.ok) return r;
            const legacy = r.data || r;
            return ok({ tx_ref: legacy?.tx_ref ?? legacy?.txRef ?? null, legacy: true });
        }
        return fail('tpe_unavailable');
    }
    return runSafe('tpeCharge', b.tpeCharge, [amountCents, method]);
}

export async function tpeRefund(txRef, amountCents) {
    const b = getBridge();
    if (typeof b.tpeRefund !== 'function') return fail('refund_unavailable');
    return runSafe('tpeRefund', b.tpeRefund, [txRef, amountCents]);
}

export async function cancelPayment() {
    const b = getBridge();
    if (typeof b.cancelPayment !== 'function') return ok({ stub: true });
    return runSafe('cancelPayment', b.cancelPayment, []);
}

// --- Tiroir caisse -------------------------------------------------------

export async function openDrawer() {
    const b = getBridge();
    if (typeof b.openDrawer !== 'function') return fail('drawer_unavailable');
    return runSafe('openDrawer', b.openDrawer, []);
}

// --- Impression ----------------------------------------------------------

export async function printReceipt(orderData) {
    const b = getBridge();
    if (typeof b.printReceipt !== 'function') return fail('printer_unavailable');
    return runSafe('printReceipt', b.printReceipt, [orderData]);
}

export async function printEscPos(lines) {
    const b = getBridge();
    if (typeof b.printEscPos !== 'function') return fail('printer_unavailable');
    return runSafe('printEscPos', b.printEscPos, [lines]);
}

// --- Diagnostics ---------------------------------------------------------

/**
 * Healthcheck avec interprétation : retourne ok/degraded/critical.
 * - `critical` si printer ou TPE KO (composants bloquants §4)
 * - `degraded` si NFC ou camera KO (acceptable)
 * - `ok` sinon
 */
export async function healthcheck() {
    const b = getBridge();
    if (typeof b.healthcheck !== 'function') {
        return ok({ state: 'stub', components: {}, degradation: 'none' });
    }
    const r = await runSafe('healthcheck', b.healthcheck, []);
    if (!r.ok) return { ...r, state: 'critical', degradation: 'critical' };
    const hc = r.data || r;
    const components = hc?.components || {};
    const criticalKeys = ['tpe', 'tpe_status', 'printer', 'printer_status'];
    const degradedKeys = ['nfc', 'nfc_status', 'camera', 'camera_status'];
    const isKo = (v) => v === false || v === 'error' || v === 'paper_out';
    const critical = criticalKeys.some((k) => isKo(components[k]));
    const degraded = degradedKeys.some((k) => isKo(components[k]));
    const state = !hc.ok || critical ? 'critical' : degraded ? 'degraded' : 'ok';
    const degradation = critical ? 'critical' : degraded ? 'degraded' : 'none';
    return ok({ state, components, degradation, raw: hc });
}

export async function info() {
    const b = getBridge();
    if (typeof b.info !== 'function') return fail('info_unavailable');
    return runSafe('info', b.info, []);
}

// --- App lifecycle -------------------------------------------------------

/**
 * [PHASE-6.5] Recharge l'application Electron (équivalent Ctrl+R). No-op
 * en navigateur — le caller devrait déclencher window.location.reload() manuellement.
 */
export async function reload() {
    const b = getBridge();
    if (typeof b.reload !== 'function') return fail('reload_unavailable');
    return runSafe('reload', b.reload, []);
}

/**
 * [PHASE-6.5] Quitte l'application Electron. No-op en navigateur.
 */
export async function quit() {
    const b = getBridge();
    if (typeof b.quit !== 'function') return fail('quit_unavailable');
    return runSafe('quit', b.quit, []);
}

// --- Événements hardware -------------------------------------------------

/**
 * Souscrit aux événements hardware du bridge. Retourne une fonction
 * de désabonnement. Les events sont relayés dans le store Vuex si
 * un callback consumer les transforme.
 */
export function onHardwareEvent(cb) {
    const b = getBridge();
    if (typeof b.onHardwareEvent !== 'function') return () => {};
    try {
        const unsub = b.onHardwareEvent((evt) => {
            try { cb?.(evt); } catch (_) {}
        });
        return typeof unsub === 'function' ? unsub : () => {};
    } catch (_) {
        return () => {};
    }
}

// --- Log dédié observabilité ---------------------------------------------

/**
 * Log utilitaire pour signaler un event hardware au backend.
 * Exporté car certains composants Vue souhaitent signaler manuellement.
 */
export function reportHardwareEvent({ component, severity = 'warning', code, extra = {} }) {
    postKioskEvent({
        type: 'hardware_event',
        event_name: 'hardware_error',
        details: `component=${component} | severity=${severity} | code=${code || 'unknown'}`,
        payload: { component, severity, code, ...extra },
    });
}

export default {
    isKioskBridge,
    play,
    stopAllSounds,
    speak,
    stopSpeak,
    haptic,
    scanQR,
    readNFC,
    tpeCharge,
    tpeRefund,
    cancelPayment,
    openDrawer,
    printReceipt,
    printEscPos,
    healthcheck,
    info,
    reload,
    quit,
    onHardwareEvent,
    reportHardwareEvent,
    // [P0-5 / KIO-1] Boot healthcheck gate — see kioskBootHealthcheck.js.
    requireHealthCheck,
    markBootHealthcheckPassed,
    resetBootHealthcheckGate,
    isBootHealthcheckPassed,
};
