/**
 * kioskAnalytics.js — Helper analytics client-side pour le kiosk.
 * -----------------------------------------------------------------------------
 * FoodKing Kiosk — Phase 5.5.
 *
 * Source d'autorité : docs/design/KIOSK_ANALYTICS_EVENTS.md.
 *
 * Responsabilités :
 *  - Exposer `track(event_name, payload)` — l'unique point d'émission analytics.
 *  - Opt-in gate : n'émet jamais sans consentement explicite (RGPD).
 *  - UUID session_id en mémoire, renouvelé à chaque `resetSession()`.
 *  - sendBeacon prioritaire (survit à un unload), fallback fetch keepalive.
 *  - Queue locale FIFO plafonnée à 200 events pour les pertes réseau
 *    — drain auto dès qu'un track() réussi est envoyé.
 *  - Whitelist d'event_name alignée sur le spec §3.
 *  - Validation payload : refuse les clés PII (backend-side aussi protégé).
 *
 * Invariants :
 *  - Pas de statistique dynamique dérivée ici (invariant §1.5) — ce module
 *    n'expose PAS de getters agrégés.
 *  - Jamais d'appel réseau synchrone.
 *  - Jamais de throw — fallback silencieux.
 *  - `branch_id` vient du store Vuex (kioskCart.branchId) ou d'un call site
 *    explicite. Jamais lu depuis une URL.
 */

const ENDPOINT = '/api/frontend/kiosk-event';
const ANALYTICS_TYPE = 'analytics';
const MAX_QUEUE = 200;

const FORBIDDEN_KEYS = new Set([
    'email', 'phone', 'telephone', 'first_name', 'last_name', 'name',
    'iban', 'card_number', 'pan', 'cvv', 'cvc', 'full_address',
    'customer_email', 'customer_phone', 'customer_name',
]);

const ALLOWED_EVENTS = new Set([
    'menu_viewed',
    'category_selected',
    'search_performed',
    'item_opened',
    'wizard_step_entered',
    'wizard_step_completed',
    'wizard_step_abandoned',
    'wizard_abandoned',
    'filter_toggled',
    'filter_reset',
    'add_to_cart',
    'remove_from_cart',
    'quantity_changed',
    'upsell_shown',
    'upsell_accepted',
    'upsell_rejected',
    'checkout_started',
    'payment_method_selected',
    'payment_completed',
    'payment_failed',
    'order_completed',
    'order_cancelled',
    'a11y_mode_activated',
    'a11y_mode_deactivated',
    'locale_changed',
    'loyalty_scanned',
    'turbo_mode_activated',
    'idle_warning_shown',
    'idle_reset',
    'idle_dismissed',
    'consent_given',
    'hardware_error',
    // [T14b] Offline queue lifecycle events — observability for K-3 hardening.
    // Backend whitelist mirrored in KioskEventController::ALLOWED_ANALYTICS_EVENTS.
    'offline.queued',
    'offline.replayed',
    'offline.abandoned',
    'offline.recovered',
]);

/* ------------------------------------------------------------------ */
/*  State module (singleton en mémoire)                                */
/* ------------------------------------------------------------------ */

const state = {
    consent: false,
    sessionId: null,
    branchId: null,
    queue: [],
    drainTimer: null,
};

/* ------------------------------------------------------------------ */
/*  Session                                                            */
/* ------------------------------------------------------------------ */

function uuidV4() {
    // RFC4122 v4-ish — suffisant pour une corrélation côté analytics.
    // On ne stocke PAS cet UUID en localStorage (éphémère, renouvelé par idle).
    const hex = (n) => Math.floor(Math.random() * 16).toString(16);
    let s = '';
    for (let i = 0; i < 36; i++) {
        if (i === 8 || i === 13 || i === 18 || i === 23) { s += '-'; continue; }
        if (i === 14) { s += '4'; continue; }
        if (i === 19) { s += (8 + Math.floor(Math.random() * 4)).toString(16); continue; }
        s += hex();
    }
    return s;
}

export function ensureSession() {
    if (!state.sessionId) state.sessionId = uuidV4();
    return state.sessionId;
}

export function resetSession() {
    state.sessionId = null;
    state.queue.length = 0;
    if (state.drainTimer) { clearTimeout(state.drainTimer); state.drainTimer = null; }
}

export function setBranchId(branchId) {
    const n = Number(branchId);
    state.branchId = Number.isFinite(n) && n > 0 ? n : null;
}

export function setConsent(granted) {
    const next = !!granted;
    const prev = state.consent;
    state.consent = next;
    if (!next) {
        // Consent révoqué : vider la queue (aucun event ne doit partir).
        state.queue.length = 0;
    } else if (!prev && next) {
        // Consent accordé : drain immédiat de la queue accumulée depuis la
        // consent grant (si des events ont été mis en attente avant accord,
        // ils sont JETÉS — on ne rétroactive pas le consent).
        state.queue.length = 0;
    }
}

export function hasConsent() { return state.consent; }

/* ------------------------------------------------------------------ */
/*  Validation payload                                                 */
/* ------------------------------------------------------------------ */

function containsForbidden(obj) {
    if (!obj || typeof obj !== 'object') return false;
    for (const k of Object.keys(obj)) {
        if (FORBIDDEN_KEYS.has(k.toLowerCase())) return true;
        const v = obj[k];
        if (v && typeof v === 'object' && containsForbidden(v)) return true;
    }
    return false;
}

function safePayload(payload) {
    if (!payload || typeof payload !== 'object') return {};
    if (containsForbidden(payload)) return null;
    // Copie shallow pour éviter les mutations externes.
    return { ...payload };
}

/* ------------------------------------------------------------------ */
/*  Transport                                                          */
/* ------------------------------------------------------------------ */

function sendNow(envelope) {
    const bodyStr = JSON.stringify(envelope);
    // sendBeacon = tolère l'unload, mais pas d'auth header custom.
    // On l'utilise seulement si la route accepte cookies + CSRF.
    if (typeof navigator !== 'undefined' && typeof navigator.sendBeacon === 'function') {
        try {
            const blob = new Blob([bodyStr], { type: 'application/json' });
            const ok = navigator.sendBeacon(ENDPOINT, blob);
            if (ok) return true;
        } catch (_) {}
    }
    if (typeof window !== 'undefined' && window.axios?.post) {
        window.axios.post('frontend/kiosk-event', envelope).catch(() => enqueue(envelope));
        return true;
    }
    if (typeof fetch === 'function') {
        fetch(ENDPOINT, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: bodyStr,
            keepalive: true,
        }).catch(() => enqueue(envelope));
        return true;
    }
    enqueue(envelope);
    return false;
}

function enqueue(envelope) {
    if (state.queue.length >= MAX_QUEUE) state.queue.shift(); // FIFO eviction
    state.queue.push(envelope);
    scheduleDrain();
}

function scheduleDrain() {
    if (state.drainTimer) return;
    state.drainTimer = setTimeout(() => {
        state.drainTimer = null;
        drainQueue();
    }, 2000);
}

function drainQueue() {
    if (!state.consent) { state.queue.length = 0; return; }
    if (state.queue.length === 0) return;
    const batch = state.queue.splice(0, state.queue.length);
    for (const evt of batch) {
        sendNow(evt);
    }
}

/* ------------------------------------------------------------------ */
/*  API publique                                                       */
/* ------------------------------------------------------------------ */

/**
 * Track un event analytics. No-op si pas de consent (§2 RGPD).
 *
 * @param {string} eventName   Doit appartenir à `ALLOWED_EVENTS`.
 * @param {object} [payload]   Attributes whitelistés (aucune PII).
 * @returns {boolean}           true si l'event a été émis / mis en file.
 */
export function track(eventName, payload = {}) {
    if (!state.consent) return false;
    if (!ALLOWED_EVENTS.has(eventName)) {
        // Silent-drop — préserve la stabilité runtime.
        return false;
    }
    const cleaned = safePayload(payload);
    if (cleaned === null) return false; // PII rejetée
    const envelope = {
        type: ANALYTICS_TYPE,
        event_name: eventName,
        session_id: ensureSession(),
        branch_id: state.branchId || undefined,
        details: 'analytics',
        payload: cleaned,
    };
    return sendNow(envelope);
}

/**
 * Flush forcé (ex: avant window.beforeunload). Respecte le consent.
 */
export function flush() {
    if (!state.consent) { state.queue.length = 0; return; }
    drainQueue();
}

/**
 * Snapshot readonly pour debug/tests — JAMAIS consommé par du code produit.
 */
export function _getInternalStateForTest() {
    return {
        consent: state.consent,
        sessionId: state.sessionId,
        branchId: state.branchId,
        queueSize: state.queue.length,
        allowedEventsCount: ALLOWED_EVENTS.size,
    };
}

export const KIOSK_ANALYTICS_ALLOWED_EVENTS = Array.from(ALLOWED_EVENTS);
export const KIOSK_ANALYTICS_MAX_QUEUE = MAX_QUEUE;

export default {
    track,
    ensureSession,
    resetSession,
    setConsent,
    setBranchId,
    hasConsent,
    flush,
};
