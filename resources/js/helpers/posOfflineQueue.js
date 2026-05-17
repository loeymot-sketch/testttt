/**
 * posOfflineQueue — POS Caisse offline replay queue (V1, server-authoritative).
 * ---------------------------------------------------------------------------
 * Path A: queue pending orders in IndexedDB while network is down, replay on
 * reconnect via tryFlush() with stable X-Idempotency-Key per entry. Server
 * decides accept (2xx → markSynced) or conflict (409 → markFailed, retained).
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
    const entry = {
        idempotencyKey: uuidv4(),
        payload: sanitize(rawPayload),
        savedAt: Date.now(),
        attempts: 1,
        lastFailedAt: null,
    };
    _cache.push(entry);
    await persist();
    return { ...entry };
}

export async function listPending() {
    await ensureLoaded();
    return _cache.map((e) => ({ ...e }));
}

export function getQueueDepth() { return _cache.length; }

export async function markSynced(idempotencyKey) {
    await ensureLoaded();
    const before = _cache.length;
    _cache = _cache.filter((e) => e.idempotencyKey !== idempotencyKey);
    if (_cache.length !== before) await persist();
    return _cache.length !== before;
}

/** Bump attempts + lastFailedAt. Entry retained until markSynced() or purgeExpired(). */
export async function markFailed(idempotencyKey, _error = null) {
    await ensureLoaded();
    let touched = false;
    _cache = _cache.map((e) => {
        if (e.idempotencyKey !== idempotencyKey) return e;
        touched = true;
        return { ...e, attempts: e.attempts + 1, lastFailedAt: Date.now() };
    });
    if (touched) await persist();
    return touched;
}

export async function purgeExpired() {
    await ensureLoaded();
    const cutoff = Date.now() - TTL_MS;
    const before = _cache.length;
    _cache = _cache.filter((e) => e.savedAt >= cutoff);
    const dropped = before - _cache.length;
    if (dropped > 0) await persist();
    return dropped;
}

export async function clearQueue() {
    _cache = [];
    _bootPromise = null;
    try { await clearQueueEntries(); } catch (_) {}
}

export function __unsafeGetCacheForTests() {
    return _cache.map((e) => ({ ...e }));
}
