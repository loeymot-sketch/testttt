/**
 * kioskWizardResumeSnapshot — Phase 9.3.13 (extracted helper)
 *
 * Persists kiosk wizard state across reloads / navigations / crashes so the
 * customer does not lose their selections if they accidentally reload, if
 * the browser tab crashes, or if the kiosk is briefly power-cycled.
 *
 * Storage contract:
 *   - Backed by window.localStorage (survives tab close, power cycle).
 *   - Keyed per item id: `kiosk:wizard-snapshot:<itemId>`.
 *   - Envelope versioned; mismatched versions are discarded.
 *   - TTL of 10 minutes by default: stale snapshots are auto-purged on read
 *     so customers never rehydrate a snapshot bound to an item whose menu
 *     has likely changed in the meantime.
 *
 * The helper is pure and framework-agnostic. All persistence errors are
 * swallowed silently: a broken localStorage (Safari private mode, quota
 * exceeded, etc.) must NEVER break the wizard.
 */

export const VERSION = 1;
export const DEFAULT_TTL_MS = 10 * 60 * 1000;

function getStorage() {
    if (typeof window === 'undefined') return null;
    try {
        return window.localStorage || null;
    } catch (_) {
        return null;
    }
}

export function buildSnapshotKey(itemId) {
    const numericId = parseInt(itemId || 0, 10);
    if (!numericId || numericId <= 0) return null;
    return `kiosk:wizard-snapshot:${numericId}`;
}

export function saveSnapshot(itemId, payload, options = {}) {
    const storage = getStorage();
    const key = buildSnapshotKey(itemId);
    if (!storage || !key) return false;

    const ttlMs = Number.isFinite(options.ttlMs) ? options.ttlMs : DEFAULT_TTL_MS;
    const now = typeof options.now === 'function' ? options.now : () => Date.now();

    const record = {
        version: VERSION,
        item_id: parseInt(itemId, 10),
        saved_at: now(),
        ttl_ms: ttlMs,
        payload: payload || {},
    };

    try {
        storage.setItem(key, JSON.stringify(record));
        return true;
    } catch (_) {
        return false;
    }
}

export function readSnapshot(itemId, options = {}) {
    const storage = getStorage();
    const key = buildSnapshotKey(itemId);
    if (!storage || !key) return null;

    const now = typeof options.now === 'function' ? options.now : () => Date.now();

    let raw = null;
    try {
        raw = storage.getItem(key);
    } catch (_) {
        return null;
    }
    if (!raw) return null;

    let record = null;
    try {
        record = JSON.parse(raw);
    } catch (_) {
        try { storage.removeItem(key); } catch (_e) { /* silent */ }
        return null;
    }

    if (!record || record.version !== VERSION) {
        try { storage.removeItem(key); } catch (_e) { /* silent */ }
        return null;
    }

    if (parseInt(record.item_id || 0, 10) !== parseInt(itemId, 10)) {
        return null;
    }

    const savedAt = parseInt(record.saved_at || 0, 10);
    const ttlMs = parseInt(record.ttl_ms || 0, 10);
    if (savedAt > 0 && ttlMs > 0 && now() - savedAt > ttlMs) {
        try { storage.removeItem(key); } catch (_e) { /* silent */ }
        return null;
    }

    return record.payload || null;
}

export function clearSnapshot(itemId) {
    const storage = getStorage();
    const key = buildSnapshotKey(itemId);
    if (!storage || !key) return;
    try { storage.removeItem(key); } catch (_) { /* silent */ }
}

export default {
    buildSnapshotKey,
    saveSnapshot,
    readSnapshot,
    clearSnapshot,
};
