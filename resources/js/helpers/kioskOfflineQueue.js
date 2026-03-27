/**
 * kioskOfflineQueue.js
 *
 * Inspired by Splash's payement.utils.js offline command queue.
 * When the kiosk loses connectivity, orders are saved locally in
 * localStorage and synced automatically when the network comes back.
 *
 * Architecture:
 *   - saveOrder(payload)   → persist to localStorage queue
 *   - syncQueue(axios)     → attempt to flush pending orders to server
 *   - getPendingCount()    → how many orders are waiting
 *   - startAutoSync(axios) → start a background sync loop (every 30s)
 *   - stopAutoSync()       → stop the background loop
 */

const QUEUE_KEY = 'kiosk_offline_queue_v1';
const SYNC_INTERVAL_MS = 30_000;

let _syncTimer = null;

// ─── Persistence helpers ──────────────────────────────────────────────────────

function _load() {
    try {
        const raw = localStorage.getItem(QUEUE_KEY);
        return raw ? JSON.parse(raw) : [];
    } catch {
        return [];
    }
}

function _save(queue) {
    try {
        localStorage.setItem(QUEUE_KEY, JSON.stringify(queue));
    } catch {
        // localStorage quota exceeded — silently ignore
    }
}

// ─── Public API ───────────────────────────────────────────────────────────────

/**
 * Persist an order payload to the local queue.
 * @param {Object} payload  — the same payload sent to frontend/order
 * @param {string} originalKey — the original idempotency key used for the first attempt (optional)
 * @returns {string}        — a local idempotency key for this queued order
 */
export function saveOrder(payload, originalKey = null) {
    const queue = _load();
    // [FIX-54-3] Preserve original idempotency key so replay uses the same key
    // as the initial attempt — prevents duplicate orders if server received the
    // first POST but the client lost the response.
    const localKey = originalKey || `offline_${Date.now()}_${Math.random().toString(36).slice(2, 8)}`;
    queue.push({
        localKey,
        payload,
        savedAt: Date.now(),
        attempts: 0,
        synced: false,
    });
    _save(queue);
    return localKey;
}

/**
 * Return the number of unsynced orders in the queue.
 */
export function getPendingCount() {
    return _load().filter(e => !e.synced).length;
}

/**
 * Attempt to flush all pending orders to the server.
 * @param {Function} postFn  — async function(url, data, config?) → response
 *                             The third argument carries axios config (headers, etc.)
 * @returns {Object}         — { synced: number, failed: number }
 */
export async function syncQueue(postFn) {
    const queue = _load();
    let synced = 0;
    let failed = 0;

    for (const entry of queue) {
        if (entry.synced) continue;

        try {
            // [AUDIT-P0] Always replay with the same localKey as X-Idempotency-Key.
            // This prevents duplicate orders when the original POST succeeded server-side
            // but the client timed out or saw a 5xx before receiving the 201 response.
            const config = entry.localKey
                ? { headers: { 'X-Idempotency-Key': entry.localKey } }
                : {};
            await postFn('frontend/order', entry.payload, config);
            entry.synced = true;
            entry.syncedAt = Date.now();
            synced++;
        } catch (err) {
            entry.attempts++;
            // Give up after 10 attempts (order is stale)
            if (entry.attempts >= 10) {
                entry.synced = true; // mark as done to stop retrying
                entry.abandoned = true;
            }
            failed++;
        }
    }

    // Prune synced entries older than 24h to keep storage clean
    const cutoff = Date.now() - 24 * 60 * 60 * 1000;
    const pruned = queue.filter(e => !(e.synced && e.savedAt < cutoff));
    _save(pruned);

    return { synced, failed };
}

/**
 * Start a background auto-sync loop.
 * @param {Function} postFn  — async function(url, data) → response
 * @param {Function} onSync  — optional callback({ synced, failed }) after each attempt
 */
export function startAutoSync(postFn, onSync) {
    stopAutoSync();

    const run = async () => {
        if (getPendingCount() === 0) return;
        const result = await syncQueue(postFn);
        if (onSync) onSync(result);
    };

    // Sync immediately, then on interval
    run();
    _syncTimer = setInterval(run, SYNC_INTERVAL_MS);
}

/**
 * Stop the background auto-sync loop.
 */
export function stopAutoSync() {
    if (_syncTimer) {
        clearInterval(_syncTimer);
        _syncTimer = null;
    }
}

/**
 * Clear the entire queue (use with caution — for testing/reset only).
 */
export function clearQueue() {
    localStorage.removeItem(QUEUE_KEY);
}
