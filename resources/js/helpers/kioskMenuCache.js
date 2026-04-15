/**
 * kioskMenuCache.js — Offline snapshot for kiosk menu (C2)
 *
 * Persists the full menu (categories + items) in IndexedDB so the kiosk
 * can display a catalogue even when the backend is unreachable.
 *
 * IMPORTANT: The snapshot is display-only. Prices are always recalculated
 * server-side at order creation — a stale snapshot never affects billing.
 *
 * API:
 *   saveSnapshot(categories, items)  → persist to IndexedDB
 *   loadSnapshot()                   → { categories, items, savedAt } | null
 *   isSnapshotFresh(savedAt, maxAgeMs) → boolean
 *   clearSnapshot()                  → remove from IndexedDB (testing/reset)
 */

import { get, set, del } from 'idb-keyval';

const SNAPSHOT_KEY   = 'kiosk_menu_snapshot_v1';
const DEFAULT_MAX_AGE = 24 * 60 * 60 * 1000; // 24 hours

/**
 * Persist categories and items to IndexedDB.
 * Falls back to localStorage if IndexedDB is unavailable (e.g. private mode iOS).
 */
export async function saveSnapshot(categories, items) {
    const snapshot = {
        categories,
        items,
        savedAt: Date.now(),
    };
    try {
        await set(SNAPSHOT_KEY, snapshot);
    } catch (_idbErr) {
        // Fallback: localStorage (chunked if needed, but menu is usually < 1 MB)
        try {
            localStorage.setItem(SNAPSHOT_KEY, JSON.stringify(snapshot));
        } catch (_lsErr) {
            // Quota exceeded — silently ignore; TTL cache remains the fallback
        }
    }
}

/**
 * Load the snapshot from IndexedDB (or localStorage fallback).
 * @returns {{ categories: Array, items: Array, savedAt: number } | null}
 */
export async function loadSnapshot() {
    try {
        const snapshot = await get(SNAPSHOT_KEY);
        if (snapshot && Array.isArray(snapshot.categories) && Array.isArray(snapshot.items)) {
            return snapshot;
        }
    } catch (_idbErr) {
        // Fall through to localStorage
    }
    // localStorage fallback
    try {
        const raw = localStorage.getItem(SNAPSHOT_KEY);
        if (raw) {
            const parsed = JSON.parse(raw);
            if (parsed && Array.isArray(parsed.categories) && Array.isArray(parsed.items)) {
                return parsed;
            }
        }
    } catch (_) { /* ignore */ }
    return null;
}

/**
 * Check whether a snapshot is still fresh enough to display.
 * @param {number} savedAt     — timestamp from snapshot.savedAt
 * @param {number} [maxAgeMs]  — max acceptable age in ms (default 24h)
 */
export function isSnapshotFresh(savedAt, maxAgeMs = DEFAULT_MAX_AGE) {
    if (!savedAt || typeof savedAt !== 'number') return false;
    return (Date.now() - savedAt) < maxAgeMs;
}

/**
 * Check whether a snapshot is too old to trust for display.
 * @param {number} savedAt       — timestamp from snapshot.savedAt
 * @param {number} [thresholdMs] — staleness threshold in ms (default 4h)
 * @returns {boolean}
 */
export function isSnapshotStale(savedAt, thresholdMs = 4 * 60 * 60 * 1000) {
    if (!savedAt || typeof savedAt !== 'number') return true;
    return (Date.now() - savedAt) > thresholdMs;
}

/**
 * Remove the snapshot (for testing or manual reset).
 */
export async function clearSnapshot() {
    try {
        await del(SNAPSHOT_KEY);
    } catch (_) { /* ignore */ }
    try {
        localStorage.removeItem(SNAPSHOT_KEY);
    } catch (_) { /* ignore */ }
}
