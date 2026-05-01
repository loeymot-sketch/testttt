import * as kioskAnalytics from './kioskAnalytics';
import {
    clearQueueEntries,
    delQueueEntry,
    getQueueEntry,
    isIndexedDbReady,
    setQueueEntry,
} from './kioskOfflineQueueDb';

const LEGACY_QUEUE_KEY = 'kiosk_offline_queue_v1';
const QUEUE_KEY = 'kiosk:offline-queue:v2';
const LOCK_KEY = 'kiosk:offline-queue:lock';
const SYNC_INTERVAL_MS = 30_000;
const LOCK_TTL_MS = 60_000;
const LOCK_HEARTBEAT_MS = 20_000;
const MAX_ATTEMPTS = 10;
const MAX_BACKOFF_MS = 30_000;
const QUOTA_EVENT = 'kiosk-offline-queue:quota-exceeded';
const CHANNEL_NAME = 'kiosk-offline-queue-sync';
const TAB_ID = `kiosk-tab-${Math.random().toString(36).slice(2, 10)}`;

let _syncTimer = null;
let _syncInFlight = null;
let _bootPromise = null;
let _persistPromise = Promise.resolve();
let _queueCache = [];
let _channel = null;
let _channelInitialized = false;

function now() {
    return Date.now();
}

function _track(eventName, payload) {
    try {
        if (typeof kioskAnalytics.track === 'function') {
            kioskAnalytics.track(eventName, payload || {});
        }
    } catch (_) {}
}

function _emitWindowEvent(name, detail) {
    if (typeof window === 'undefined' || typeof window.dispatchEvent !== 'function') return;
    window.dispatchEvent(new CustomEvent(name, { detail }));
}

function _ensureChannel() {
    if (_channelInitialized || typeof BroadcastChannel === 'undefined') {
        _channelInitialized = true;
        return;
    }
    _channelInitialized = true;
    _channel = new BroadcastChannel(CHANNEL_NAME);
    _channel.addEventListener('message', (event) => {
        const message = event?.data || {};
        if (message.tabId === TAB_ID) return;
        if (message.type === 'queue-updated') {
            _reloadQueue().catch(() => {});
        }
    });
}

function _broadcast(message) {
    _ensureChannel();
    try {
        _channel?.postMessage({ ...message, tabId: TAB_ID });
    } catch (_) {}
}

function _isQuotaExceeded(error) {
    return error?.name === 'QuotaExceededError'
        || /quota/i.test(String(error?.name || ''))
        || /quota/i.test(String(error?.message || ''));
}

function _safeParseItems(payload) {
    const raw = payload?.items;
    if (Array.isArray(raw)) return raw;
    if (typeof raw === 'string') {
        try {
            const parsed = JSON.parse(raw);
            return Array.isArray(parsed) ? parsed : [];
        } catch (_) {
            return [];
        }
    }
    return [];
}

function _normalizeBranchId(branchId) {
    const normalized = parseInt(branchId, 10);
    return Number.isFinite(normalized) ? normalized : null;
}

function _generatedOfflineKey(savedAt) {
    return `offline_${savedAt}_${Math.random().toString(36).slice(2, 8)}`;
}

function _localKey(candidate, savedAt) {
    return typeof candidate === 'string' && candidate.trim() !== ''
        ? candidate
        : _generatedOfflineKey(savedAt);
}

function _offlineKey(candidate, localKey, savedAt) {
    if (typeof candidate === 'string' && candidate.startsWith('offline_')) {
        return candidate;
    }
    if (typeof localKey === 'string' && localKey.startsWith('offline_')) {
        return localKey;
    }

    return _generatedOfflineKey(savedAt);
}

function _entryContainsItem(entry, itemId) {
    const normalizedItemId = parseInt(itemId, 10);
    return _safeParseItems(entry?.payload).some((line) => parseInt(line?.item_id, 10) === normalizedItemId);
}

function _entryMatchesBranch(entry, branchId) {
    const normalizedBranchId = _normalizeBranchId(branchId);
    if (normalizedBranchId === null) {
        return true;
    }

    // Legacy entries created before branch scoping remain wildcard matches so the
    // queue can be migrated safely without discarding parked offline orders.
    const entryBranchId = _normalizeBranchId(entry?.branchId ?? entry?.branch_id ?? null);
    if (entryBranchId === null) {
        return true;
    }

    return entryBranchId === normalizedBranchId;
}

function _normalizeEntry(entry) {
    const savedAt = Number.isFinite(entry?.savedAt) ? entry.savedAt : now();
    let attempts = Math.max(1, parseInt(entry?.attempts, 10) || 1);
    const staleItems = Array.isArray(entry?.staleItems)
        ? [...new Set(entry.staleItems.map((id) => parseInt(id, 10)).filter(Number.isFinite))]
        : [];
    let lastFailedAt = Number.isFinite(entry?.lastFailedAt) ? entry.lastFailedAt : null;

    if (lastFailedAt === null && Number.isFinite(entry?.lastAttemptAt)) {
        const looksLikeInitialEnqueue = attempts <= 1 && entry.lastAttemptAt === savedAt;
        if (looksLikeInitialEnqueue) {
            attempts = 1;
        } else {
            attempts = Math.max(1, attempts);
            lastFailedAt = entry.lastAttemptAt;
        }
    }

    const localKey = _localKey(entry?.localKey, savedAt);

    return {
        localKey,
        offlineKey: _offlineKey(entry?.offlineKey, localKey, savedAt),
        payload: entry?.payload || {},
        savedAt,
        attempts,
        lastFailedAt,
        abandoned: entry?.abandoned === true,
        abandonedAt: Number.isFinite(entry?.abandonedAt) ? entry.abandonedAt : null,
        abandonedReason: entry?.abandonedReason || null,
        staleItems,
        branchId: _normalizeBranchId(entry?.branchId ?? entry?.branch_id ?? null),
    };
}

function _mergeQueue(existing, incoming) {
    const merged = new Map();
    [...incoming, ...existing].forEach((entry) => {
        const normalized = _normalizeEntry(entry);
        merged.set(normalized.localKey, normalized);
    });
    return [...merged.values()].sort((a, b) => a.savedAt - b.savedAt);
}

function _loadLegacyQueue() {
    try {
        const raw = localStorage.getItem(LEGACY_QUEUE_KEY);
        if (!raw) return [];
        const parsed = JSON.parse(raw);
        return Array.isArray(parsed) ? parsed : [];
    } catch (_) {
        return [];
    }
}

function _clearLegacyQueue() {
    try {
        localStorage.removeItem(LEGACY_QUEUE_KEY);
    } catch (_) {}
}

async function _migrateLegacyQueue() {
    const existingV2 = await getQueueEntry(QUEUE_KEY);
    const legacyEntries = _loadLegacyQueue();

    if (Array.isArray(existingV2)) {
        if (legacyEntries.length > 0) {
            _clearLegacyQueue();
        }
        return _mergeQueue(_queueCache, existingV2);
    }

    if (legacyEntries.length === 0) {
        return _queueCache;
    }

    const migrated = legacyEntries
        .filter((entry) => entry && entry.synced !== true)
        .map((entry) => _normalizeEntry({
            ...entry,
            attempts: Math.max(1, parseInt(entry?.attempts, 10) || 0),
        }));

    await setQueueEntry(QUEUE_KEY, migrated);
    _clearLegacyQueue();
    if (migrated.length > 0) {
        _track('offline.queue.v2.migrated', { count: migrated.length });
    }
    return _mergeQueue(_queueCache, migrated);
}

async function _bootstrapQueue() {
    const storedQueue = await getQueueEntry(QUEUE_KEY);
    if (Array.isArray(storedQueue)) {
        _queueCache = _mergeQueue(_queueCache, storedQueue);
        if (_loadLegacyQueue().length > 0) {
            _clearLegacyQueue();
        }
        return _queueCache;
    }

    _queueCache = await _migrateLegacyQueue();
    return _queueCache;
}

function _ensureLoaded() {
    if (!_bootPromise) {
        _bootPromise = _bootstrapQueue().catch((error) => {
            if (process.env.NODE_ENV !== 'production') {
                console.warn('[kioskOfflineQueue] Failed to bootstrap queue.', error);
            }
            return _queueCache;
        });
    }
    return _bootPromise;
}

async function _reloadQueue() {
    _bootPromise = null;
    return _ensureLoaded();
}

async function _persistQueue() {
    const snapshot = _queueCache.map((entry) => ({ ...entry }));
    _persistPromise = _persistPromise.then(async () => {
        try {
            await setQueueEntry(QUEUE_KEY, snapshot);
            _broadcast({ type: 'queue-updated' });
        } catch (error) {
            if (_isQuotaExceeded(error)) {
                _track('offline.queue.v2.quota_exceeded', { queue_size: snapshot.length });
                _emitWindowEvent(QUOTA_EVENT, { queueSize: snapshot.length });
            } else {
                throw error;
            }
        }
    }).catch((error) => {
        if (process.env.NODE_ENV !== 'production') {
            console.warn('[kioskOfflineQueue] Failed to persist queue.', error);
        }
    });

    return _persistPromise;
}

function _computeRetryDelay(attempts, randomValue = Math.random()) {
    const baseDelay = 1000 * Math.pow(2, Math.max(0, attempts - 1));
    const jitterMultiplier = 1 + ((Math.max(0, Math.min(1, randomValue)) * 0.4) - 0.2);
    return Math.min(MAX_BACKOFF_MS, Math.round(baseDelay * jitterMultiplier));
}

function _computeNextRetryAt(entry, randomValue = Math.random()) {
    if (!entry.lastFailedAt) return 0;
    return entry.lastFailedAt + _computeRetryDelay(Math.max(1, entry.attempts - 1), randomValue);
}

function _buildLock(owner, currentTime = now()) {
    return {
        owner,
        expiresAt: currentTime + LOCK_TTL_MS,
    };
}

async function _acquireLock() {
    const owner = `${TAB_ID}:${now()}`;
    const lock = await getQueueEntry(LOCK_KEY);
    const currentTime = now();

    if (lock?.expiresAt && lock.expiresAt > currentTime && lock.owner !== owner) {
        return null;
    }

    const nextLock = _buildLock(owner, currentTime);
    await setQueueEntry(LOCK_KEY, nextLock);
    const confirmed = await getQueueEntry(LOCK_KEY);
    if (confirmed?.owner !== owner) {
        return null;
    }
    _broadcast({ type: 'lock-acquired', owner });
    return owner;
}

async function _refreshLock(owner) {
    if (!owner) return false;
    const lock = await getQueueEntry(LOCK_KEY);
    if (lock?.owner !== owner) {
        return false;
    }

    await setQueueEntry(LOCK_KEY, _buildLock(owner));
    const confirmed = await getQueueEntry(LOCK_KEY);
    return confirmed?.owner === owner;
}

async function _releaseLock(owner) {
    if (!owner) return;
    const lock = await getQueueEntry(LOCK_KEY);
    if (lock?.owner === owner) {
        await delQueueEntry(LOCK_KEY);
        _broadcast({ type: 'lock-released', owner });
    }
}

async function _reportAbandoned(postFn, count) {
    if (!count || count <= 0) return;
    try {
        await postFn('frontend/kiosk-event', {
            type: 'order_abandoned',
            count,
            details: `${count} order(s) abandoned after max sync attempts`,
        }, {});
    } catch (_) {}
}

function _needsFreshQuote(payload = {}) {
    return payload
        && payload.order_type !== undefined
        && payload.source !== undefined
        && payload.payment_method !== undefined
        && (!payload.quote_token || !payload.quote_signature);
}

function _stripQuoteFields(payload = {}) {
    const clean = { ...(payload || {}) };
    delete clean.quote_token;
    delete clean.quote_signature;
    delete clean.subtotal;
    delete clean.discount;
    delete clean.delivery_charge;
    delete clean.total;
    return clean;
}

async function _payloadWithFreshQuote(postFn, payload, config) {
    if (!_needsFreshQuote(payload)) {
        return payload;
    }

    const quotePayload = _stripQuoteFields(payload);
    const response = await postFn('frontend/order/quote', quotePayload, config || {});
    const quote = response?.data?.data || response?.data || {};
    if (!quote.quote_token || !quote.signature || quote.total_ttc === undefined) {
        const error = new Error('KIOSK_OFFLINE_QUOTE_REFRESH_FAILED');
        error.code = 'KIOSK_OFFLINE_QUOTE_REFRESH_FAILED';
        throw error;
    }

    return {
        ...quotePayload,
        quote_token: quote.quote_token,
        quote_signature: quote.signature,
        subtotal: quote.subtotal,
        discount: quote.discount,
        delivery_charge: quote.delivery_charge,
        total: quote.total_ttc,
    };
}

export function saveOrder(payload, originalKey = null, options = {}) {
    _ensureLoaded();
    const savedAt = now();
    const localKey = _localKey(originalKey, savedAt);
    const offlineKey = _offlineKey(null, localKey, savedAt);
    const branchId = _normalizeBranchId(options?.branchId ?? options?.branch_id ?? null);
    _queueCache = _mergeQueue(_queueCache, [{
        localKey,
        offlineKey,
        payload,
        savedAt,
        attempts: 1,
        lastFailedAt: null,
        abandoned: false,
        abandonedAt: null,
        abandonedReason: null,
        staleItems: [],
        branchId,
    }]);
    _persistQueue();
    _track('offline.queued', {
        idempotency_key: localKey,
        queue_size: getPendingCount(),
        backend: isIndexedDbReady() ? 'idb' : 'localStorage-fallback',
    });
    return offlineKey;
}

export function getPendingCount() {
    return _queueCache.filter((entry) => !entry.abandoned).length;
}

export function getAbandonedCount() {
    return _queueCache.filter((entry) => entry.abandoned === true).length;
}

export async function getStaleEntries() {
    await _ensureLoaded();
    return _queueCache
        .filter((entry) => Array.isArray(entry.staleItems) && entry.staleItems.length > 0 && !entry.abandoned)
        .map((entry) => ({ ...entry, staleItems: [...entry.staleItems] }));
}

export async function markStaleItems({ itemId, branchId = null } = {}) {
    await _ensureLoaded();
    let updatedEntries = 0;
    let markedItems = 0;
    const normalizedItemId = parseInt(itemId, 10);

    _queueCache = _queueCache.map((entry) => {
        if (entry.abandoned || !_entryContainsItem(entry, normalizedItemId) || !_entryMatchesBranch(entry, branchId)) {
            return entry;
        }
        const staleItems = Array.isArray(entry.staleItems) ? [...entry.staleItems] : [];
        if (!staleItems.includes(normalizedItemId)) {
            staleItems.push(normalizedItemId);
            updatedEntries += 1;
            markedItems += 1;
        }
        return { ...entry, staleItems };
    });

    if (updatedEntries > 0) {
        await _persistQueue();
        _track('offline.queue.v2.stale_marked', {
            count: markedItems,
            entries: updatedEntries,
            branch_id: branchId,
            item_id: normalizedItemId,
        });
    }

    return {
        updatedEntries,
        markedItems,
        entries: await getStaleEntries(),
    };
}

export async function cancelStaleEntry(localKey) {
    await _ensureLoaded();
    const before = _queueCache.length;
    _queueCache = _queueCache.filter((entry) => entry.localKey !== localKey);
    if (_queueCache.length !== before) {
        await _persistQueue();
    }
    return _queueCache.length !== before;
}

export async function forceRetryEntry(localKey) {
    await _ensureLoaded();
    let changed = false;
    _queueCache = _queueCache.map((entry) => {
        if (entry.localKey !== localKey) return entry;
        changed = true;
        return {
            ...entry,
            staleItems: [],
            attempts: 1,
            lastFailedAt: null,
            abandoned: false,
            abandonedAt: null,
            abandonedReason: null,
        };
    });
    if (changed) {
        await _persistQueue();
    }
    return changed;
}

export async function syncQueue(postFn) {
    if (_syncInFlight) {
        return _syncInFlight;
    }

    _syncInFlight = (async () => {
        await _ensureLoaded();
        const owner = await _acquireLock();
        if (!owner) {
            return { synced: 0, failed: 0, abandonedNew: 0, skippedByLock: true };
        }

        let synced = 0;
        let failed = 0;
        let abandonedNew = 0;
        const remaining = [];
        let lockHeartbeat = null;

        try {
            lockHeartbeat = setInterval(() => {
                _refreshLock(owner).catch(() => {});
            }, LOCK_HEARTBEAT_MS);

            for (const entry of _queueCache) {
                if (entry.abandoned) {
                    remaining.push(entry);
                    continue;
                }
                if (Array.isArray(entry.staleItems) && entry.staleItems.length > 0) {
                    remaining.push(entry);
                    continue;
                }

                const nextRetryAt = _computeNextRetryAt(entry);
                if (entry.lastFailedAt && nextRetryAt > now()) {
                    remaining.push(entry);
                    _track('offline.queue.v2.backoff_skip', {
                        idempotency_key: entry.localKey,
                        attempts: Math.max(1, entry.attempts - 1),
                        retry_at: nextRetryAt,
                    });
                    continue;
                }

                try {
                    const config = entry.localKey
                        ? { headers: { 'X-Idempotency-Key': entry.localKey } }
                        : {};
                    const payload = await _payloadWithFreshQuote(postFn, entry.payload, config);
                    await postFn('frontend/order', payload, config);
                    synced += 1;
                    _track('offline.replayed', {
                        idempotency_key: entry.localKey,
                        retry_count: entry.attempts,
                    });
                } catch (error) {
                    failed += 1;
                    const failedAt = now();
                    const attempts = entry.attempts + 1;
                    if (attempts >= MAX_ATTEMPTS) {
                        abandonedNew += 1;
                        remaining.push({
                            ...entry,
                            attempts,
                            lastFailedAt: failedAt,
                            abandoned: true,
                            abandonedAt: failedAt,
                            abandonedReason: (error && (error.response?.status || error.code)) || 'unknown',
                        });
                        _track('offline.abandoned', {
                            idempotency_key: entry.localKey,
                            retry_count: attempts,
                            error_code: (error && (error.response?.status || error.code)) || 'unknown',
                        });
                    } else {
                        remaining.push({
                            ...entry,
                            attempts,
                            lastFailedAt: failedAt,
                        });
                    }
                }
            }

            _queueCache = remaining;
            await _persistQueue();
            return { synced, failed, abandonedNew, skippedByLock: false };
        } finally {
            if (lockHeartbeat) {
                clearInterval(lockHeartbeat);
            }
            await _releaseLock(owner);
        }
    })();

    try {
        return await _syncInFlight;
    } finally {
        _syncInFlight = null;
    }
}

export function startAutoSync(postFn, onSync) {
    stopAutoSync();

    const run = async () => {
        if (getPendingCount() === 0) return;
        const result = await syncQueue(postFn);
        if (result.abandonedNew > 0) {
            _reportAbandoned(postFn, result.abandonedNew);
        }
        if (result.synced > 0) {
            _track('offline.recovered', {
                replayed_count: result.synced,
                still_failed: result.failed,
            });
        }
        if (typeof onSync === 'function') onSync(result);
    };

    run();
    _syncTimer = setInterval(run, SYNC_INTERVAL_MS);
}

export function stopAutoSync() {
    if (_syncTimer) {
        clearInterval(_syncTimer);
        _syncTimer = null;
    }
}

export function clearQueue() {
    _queueCache = [];
    _bootPromise = null;
    _persistPromise = Promise.resolve();
    try {
        localStorage.removeItem(LEGACY_QUEUE_KEY);
    } catch (_) {}
    clearQueueEntries().catch(() => {});
}

export function __unsafeGetQueueForTests() {
    return _queueCache.map((entry) => ({ ...entry, staleItems: [...(entry.staleItems || [])] }));
}

export function __retryDelayForTests(attempts, randomValue) {
    return _computeRetryDelay(attempts, randomValue);
}
