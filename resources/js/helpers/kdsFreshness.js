// [CLUSTER-4 P2 2026-07-11] KDS data-freshness helpers (pure, unit-tested).
//
// Why this exists:
//   The KDS board's only "live" signal used to be `wsConnected` — the soketi
//   socket state. That is a LIE about data freshness: OrderCreated events flow
//   through the QUEUE (domain_events → job → broadcast). If the queue worker
//   dies, soketi stays "connected" while NO order reaches the board, so the
//   screen keeps implying "live" for up to a full poll cycle (~60s).
//
//   The honest signal is the age of the last SUCCESSFUL data refresh from ANY
//   path: an Echo broadcast that hydrated the board, a fallback poll, or a
//   sync-service 200. These helpers compute that unified freshness age and the
//   two staleness tiers the status badge uses.
//
// All functions are pure (no `this`, no globals) so they are trivially testable.

// Soft tier: badge turns orange. Reassurance is fading; a refresh is due soon.
export const SYNC_STALE_MS = 30000;

// Hard tier: two missed 60s poll cycles with NO confirmed refresh. The board
// may be materially behind — surface an explicit "synchro incertaine" instead
// of implying the data is live.
export const SYNC_UNCERTAIN_MS = 75000;

/**
 * Normalise a timestamp candidate to epoch-ms. Accepts:
 *   - a number (already epoch-ms)
 *   - an ISO string / Date-parseable string
 *   - null / undefined / '' → 0
 * Invalid values collapse to 0 so they never win a Math.max.
 * @param {number|string|null|undefined} value
 * @returns {number}
 */
export function toEpochMs(value) {
    if (value === null || value === undefined || value === '') {
        return 0;
    }
    if (typeof value === 'number') {
        return Number.isFinite(value) ? value : 0;
    }
    const parsed = new Date(value).getTime();
    return Number.isFinite(parsed) ? parsed : 0;
}

/**
 * Freshest of the two data-refresh clocks:
 *   - lastDataFreshAt : board hydrated (Echo broadcast OR fallback poll OR list())
 *   - lastSyncAt      : sync-service poll returned 200 (direct DB read)
 * @param {{lastDataFreshAt?: number|string|null, lastSyncAt?: number|string|null}} clocks
 * @returns {number} epoch-ms, or 0 when neither path has ever succeeded
 */
export function freshnessBaseMs({ lastDataFreshAt = null, lastSyncAt = null } = {}) {
    return Math.max(toEpochMs(lastDataFreshAt), toEpochMs(lastSyncAt));
}

/**
 * Age (ms) of the last confirmed refresh, clamped to ≥ 0.
 * @returns {number|null} null when never refreshed (baseMs === 0)
 */
export function freshnessAgeMs(baseMs, nowMs) {
    if (!baseMs) {
        return null;
    }
    return Math.max(0, nowMs - baseMs);
}

/** Soft-stale: fed data older than SYNC_STALE_MS (or never refreshed). */
export function isSyncStale(baseMs, nowMs, thresholdMs = SYNC_STALE_MS) {
    if (!baseMs) {
        return true;
    }
    return (nowMs - baseMs) > thresholdMs;
}

/**
 * Hard-uncertain: no confirmed refresh for > SYNC_UNCERTAIN_MS. Never-refreshed
 * is NOT "uncertain" (the badge shows "not yet synced" instead), so this is
 * false when baseMs === 0.
 */
export function isSyncUncertain(baseMs, nowMs, thresholdMs = SYNC_UNCERTAIN_MS) {
    if (!baseMs) {
        return false;
    }
    return (nowMs - baseMs) > thresholdMs;
}

/**
 * Human "ago" suffix matching the legacy KDS badge format: "<Xs>" under a
 * minute, "<Ym>" beyond. Returns null when never refreshed.
 */
export function humanizeSyncAgo(baseMs, nowMs) {
    const ageMs = freshnessAgeMs(baseMs, nowMs);
    if (ageMs === null) {
        return null;
    }
    const seconds = Math.floor(ageMs / 1000);
    if (seconds < 60) {
        return `${seconds}s`;
    }
    const minutes = Math.floor(seconds / 60);
    return `${minutes}m`;
}
