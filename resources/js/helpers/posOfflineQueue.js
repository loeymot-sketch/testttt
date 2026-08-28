/**
 * posOfflineQueue — POS Caisse offline replay queue (V1, server-authoritative).
 * ---------------------------------------------------------------------------
 * Safety contract (2026-08-23): legacy V1 entries never contained the signed
 * server quote required by the current POS endpoint. They remain stored as an
 * operator-visible audit trace, but are quarantined and can never be replayed.
 * Only an explicitly versioned payload carrying a fresh server quote may enter
 * the replay lane; retries are bounded and terminal 4xx errors quarantine it.
 *
 * NF525 (CLAUDE.md §8): NEVER allocate fiscal_sequence_no locally — server is
 * SSOT. We queue only item_id / quantity / option_ids / total_cents. Server
 * allocates fiscal_sequence_no at replay time (cash-only or pre-paid).
 *
 * PCI-DSS + PII: PAN / CVV / customer_email / customer_phone STRIPPED on enqueue.
 * Frozen-zone-safe: no touch to pos-wizard.js / admin-pos-v4.blade.php.
 *
 * [Wave 5F SHIPPED 2026-05-17, commit 55edb83ba] PosComponent.vue integration
 * is now live (see PosComponent.vue:1104 / :1148 / :1626). The V1.0.2 deferral
 * note from the original Wave H3.6 was superseded by Wave 5F; this header was
 * lying about the shipped status until the P1 V1 Cloud-Prep insights heal
 * (2026-05-18). The helper + the Vue integration both ship in V1.0.1.
 */
import { clearQueueEntries, getQueueEntry, setQueueEntry } from './posOfflineQueueDb';

const QUEUE_KEY = 'pos:offline-queue:v1';
export const TTL_MS = 30 * 60 * 1000; // 30 min — owner D1 decision
export const MAX_ENTRIES = 50;         // reject-new at cap (preserve earliest cash sales)
export const MAX_REPLAY_ATTEMPTS = 3;
export const MAX_SIGNED_QUOTE_AGE_MS = 4 * 60 * 1000; // server quote TTL is 5 min
export const SIGNED_REPLAY_VERSION = 2;

const FORBIDDEN_FIELDS = [
    'card_number', 'cvv', 'pan',
    'customer_email', 'customer_phone', 'cardholder_name',
];

let _cache = [];
let _bootPromise = null;

function uuidv4() {
    if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
        return crypto.randomUUID();
    }
    return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
        const r = (Math.random() * 16) | 0;
        const v = c === 'x' ? r : (r & 0x3) | 0x8;
        return v.toString(16);
    });
}

function sanitize(payload) {
    const clean = { ...(payload || {}) };
    FORBIDDEN_FIELDS.forEach((k) => { delete clean[k]; });
    return clean;
}

function isSignedPayload(payload) {
    return !!(
        payload
        && typeof payload.quote_token === 'string'
        && /^[0-9a-f-]{36}$/i.test(payload.quote_token)
        && typeof payload.quote_signature === 'string'
        && /^[0-9a-f]{64}$/i.test(payload.quote_signature)
        && payload.items
    );
}

function quarantineReason(entry, now = Date.now()) {
    if (!entry || entry.replayVersion !== SIGNED_REPLAY_VERSION || !isSignedPayload(entry.payload)) {
        return 'legacy_unsigned';
    }
    if (entry.terminalFailure) return entry.terminalFailure;
    if ((Number(entry.attempts) || 0) >= MAX_REPLAY_ATTEMPTS) return 'attempt_limit';
    if (now - Number(entry.savedAt || 0) > MAX_SIGNED_QUOTE_AGE_MS) return 'expired_quote';
    return null;
}

function isReplayable(entry, now = Date.now()) {
    return quarantineReason(entry, now) === null;
}

function ensureLoaded() {
    if (!_bootPromise) {
        _bootPromise = getQueueEntry(QUEUE_KEY)
            .then((stored) => { if (Array.isArray(stored)) _cache = stored; return _cache; })
            .catch(() => _cache);
    }
    return _bootPromise;
}

async function persist() {
    try { await setQueueEntry(QUEUE_KEY, _cache.map((e) => ({ ...e }))); } catch (_) { /* quota — caller polls */ }
}

/** Enqueue an order. Returns the entry, or null when capacity reached (reject-new). */
export async function enqueueOrder(rawPayload) {
    await ensureLoaded();
    if (_cache.length >= MAX_ENTRIES) return null;
    const clean = sanitize(rawPayload);
    const entry = {
        idempotencyKey: uuidv4(),
        payload: clean,
        savedAt: Date.now(),
        attempts: 0,
        lastFailedAt: null,
        terminalFailure: null,
        replayVersion: isSignedPayload(clean) ? SIGNED_REPLAY_VERSION : 1,
    };
    _cache.push(entry);
    await persist();
    return { ...entry };
}

export async function listPending() {
    await ensureLoaded();
    const now = Date.now();
    return _cache.filter((entry) => isReplayable(entry, now)).map((e) => ({ ...e }));
}

export async function listQuarantined() {
    await ensureLoaded();
    const now = Date.now();
    return _cache
        .map((entry) => ({ ...entry, quarantineReason: quarantineReason(entry, now) }))
        .filter((entry) => entry.quarantineReason !== null);
}

export function getQueueDepth() { return _cache.filter((entry) => isReplayable(entry)).length; }

export async function markSynced(idempotencyKey) {
    await ensureLoaded();
    const before = _cache.length;
    _cache = _cache.filter((e) => e.idempotencyKey !== idempotencyKey);
    if (_cache.length !== before) await persist();
    return _cache.length !== before;
}

/** Bump attempts + lastFailedAt. Terminal failures and exhausted retries stay quarantined. */
export async function markFailed(idempotencyKey, _error = null) {
    await ensureLoaded();
    let touched = false;
    _cache = _cache.map((e) => {
        if (e.idempotencyKey !== idempotencyKey) return e;
        touched = true;
        const status = Number(_error?.status || _error?.response?.status || 0);
        const terminal = status >= 400 && status < 500 && ![408, 429].includes(status)
            ? `terminal_http_${status}`
            : null;
        return {
            ...e,
            attempts: (Number(e.attempts) || 0) + 1,
            lastFailedAt: Date.now(),
            terminalFailure: terminal || e.terminalFailure || null,
        };
    });
    if (touched) await persist();
    return touched;
}

export async function purgeExpired() {
    await ensureLoaded();
    // Backwards-compatible no-op. Expired entries are quarantined and retained
    // until an explicit, gated operator retention action exists.
    return 0;
}

export async function clearQueue() {
    _cache = [];
    _bootPromise = null;
    try { await clearQueueEntries(); } catch (_) {}
}

export function __unsafeGetCacheForTests() {
    return _cache.map((e) => ({ ...e }));
}

export function __unsafeResetMemoryForTests() {
    _cache = [];
    _bootPromise = null;
}
