export const EVENT_TYPES = {
    ORDER_CREATED: 'order.created',
    ORDER_STATUS_CHANGED: 'order.status_changed',
    ORDER_PAYMENT_CONFIRMED: 'order.payment_confirmed',
    ORDER_ITEM_ADDED: 'order.item_added',
    ORDER_CANCELLED: 'order.cancelled',
    // [F-02] KDS table reassignment fan-out — keep in sync with PHP EventType.
    ORDER_TABLE_CHANGED: 'order.table_changed',
    MENU_ITEM_AVAILABILITY_CHANGED: 'menu.item_availability_changed',
    CATALOG_CHANGED: 'catalog.changed',
    STOCK_LOW: 'stock.low',
    // [PROMO-DASH-2026-05-06] Coupon mutations broadcast (Dashboard cycle 6).
    COUPON_CHANGED: 'promo.coupon_changed',
    // [Heal-5 / PROPOSAL KDS Archive Undo 2026-05-25 — Path B compensating action]
    KDS_ORDER_RECALLED: 'kds.order_recalled',
};

export const BROADCAST_MAP = {
    OrderCreated: EVENT_TYPES.ORDER_CREATED,
    OrderStatusChanged: EVENT_TYPES.ORDER_STATUS_CHANGED,
    OrderPaidAtCounter: EVENT_TYPES.ORDER_PAYMENT_CONFIRMED,
    OrderTableChanged: EVENT_TYPES.ORDER_TABLE_CHANGED,
    ItemAvailabilityChanged: EVENT_TYPES.MENU_ITEM_AVAILABILITY_CHANGED,
    CatalogChanged: EVENT_TYPES.CATALOG_CHANGED,
    // [PROMO-DASH-2026-05-06] Coupon mutations broadcast.
    CouponChanged: EVENT_TYPES.COUPON_CHANGED,
    // [Heal-5] Kitchen recall — emitted by the chef "↶ Annuler bump" path.
    KdsOrderRecalled: EVENT_TYPES.KDS_ORDER_RECALLED,
};

function warnValidation(reason, data) {
    console.warn('[eventContract] Invalid envelope:', reason, data);
}

export function validateEnvelope(data) {
    if (!data || typeof data !== 'object' || Array.isArray(data)) {
        warnValidation('Envelope must be an object.', data);
        return false;
    }

    if (data.version !== 1) {
        warnValidation('Envelope version must be 1.', data);
        return false;
    }

    if (typeof data.type !== 'string' || data.type.length === 0) {
        warnValidation('Envelope type must be a non-empty string.', data);
        return false;
    }

    if (!data.payload || typeof data.payload !== 'object' || Array.isArray(data.payload)) {
        warnValidation('Envelope payload must be an object.', data);
        return false;
    }

    return true;
}

export function parseEvent(raw) {
    if (!validateEnvelope(raw)) {
        throw new Error('Invalid event envelope.');
    }

    return {
        version: raw.version,
        type: raw.type,
        aggregateId: raw.aggregate_id,
        branchId: raw.branch_id ?? null,
        occurredAt: raw.occurred_at ?? null,
        correlationId: raw.correlation_id ?? null,
        payload: raw.payload,
    };
}

/**
 * Bounded set of correlation IDs already handled in this session.
 * Mitigates risk of double broadcast (worker race on dispatched_at) by
 * dropping duplicates before they reach UI handlers (toast spam, double
 * refresh, double cart prune).
 *
 * [NEW-01] Phase 1bis hardening:
 *   - Capacity raised from 512 → 2048 to absorb high-volume branches
 *     (>50 events/min) without false negatives.
 *   - Persisted to sessionStorage with a 10-minute TTL so a tab reload
 *     does NOT lose the dedupe window. Storage failure (quota, privacy
 *     mode) degrades gracefully to in-memory-only with one console.warn.
 *   - TTL eviction is lazy (no setInterval) — happens on each lookup.
 *   - Persistence is debounced (250ms) + flushed on `pagehide` to avoid
 *     I/O storm on bursty traffic without losing the last writes. (Audit G4.)
 *
 * Known limitations (Audit G5/G6/G7 — info-level, accepted by design):
 *   - sessionStorage is per-tab (W3C spec); dedupe is NOT shared across
 *     browser tabs. Each tab maintains its own window. Cross-tab dedup
 *     would require BroadcastChannel + localStorage and is out of scope.
 *   - The eviction policy is FIFO by insertion order, not strict LRU
 *     (lookups do NOT reposition entries). Functionally equivalent for
 *     our use case (a correlation id seen once must remain visible until
 *     TTL/capacity eviction).
 *   - Events without a `correlation_id` (null/undefined/empty) bypass
 *     dedup entirely — by design, a missing id cannot be matched.
 *     Producers MUST set correlation_id (already enforced by V1 contract).
 */
const SEEN_CORRELATION_CAP = 2048;
const CORRELATION_DEDUPE_TTL_MS = 10 * 60 * 1000;
const CORRELATION_DEDUPE_STORAGE_KEY = 'foodking_correlation_dedupe_v1';

const seenCorrelationIds = new Set();
const seenCorrelationOrder = [];
let correlationStorageWarningLogged = false;

function warnCorrelationStorageOnce(error) {
    if (correlationStorageWarningLogged || typeof console === 'undefined' || typeof console.warn !== 'function') {
        return;
    }
    correlationStorageWarningLogged = true;
    console.warn(
        '[eventContract] correlation dedupe sessionStorage unavailable; using in-memory dedupe only',
        error
    );
}

function getCorrelationSessionStorage() {
    if (typeof window === 'undefined' || !window.sessionStorage) {
        return null;
    }
    return window.sessionStorage;
}

// [NEW-01 / Audit G4] Debounced storage write. At ≥50 events/min the previous
// per-event setItem with full JSON serialisation became wasteful; coalesce
// rapid bursts into a single write 250ms after the last mutation. A pagehide
// listener flushes synchronously so the last-mutation-window is never lost
// when the user closes/reloads the tab faster than the debounce timer.
const PERSIST_DEBOUNCE_MS = 250;
let persistTimer = null;
let persistDirty = false;

function flushCorrelationDedupeNow() {
    persistDirty = false;
    if (persistTimer !== null) {
        clearTimeout(persistTimer);
        persistTimer = null;
    }
    try {
        const storage = getCorrelationSessionStorage();
        if (!storage) {
            return;
        }
        storage.setItem(
            CORRELATION_DEDUPE_STORAGE_KEY,
            JSON.stringify(seenCorrelationOrder.map((entry) => ({ id: entry.id, ts: entry.ts })))
        );
    } catch (error) {
        warnCorrelationStorageOnce(error);
    }
}

function persistCorrelationDedupe() {
    persistDirty = true;
    if (persistTimer !== null) {
        return;
    }
    if (typeof setTimeout !== 'function') {
        flushCorrelationDedupeNow();
        return;
    }
    persistTimer = setTimeout(() => {
        persistTimer = null;
        if (persistDirty) {
            flushCorrelationDedupeNow();
        }
    }, PERSIST_DEBOUNCE_MS);
}

if (typeof window !== 'undefined' && typeof window.addEventListener === 'function') {
    // Synchronous flush before the tab unloads / pagehide → guarantees no
    // lost writes when the debounce timer hasn't fired yet.
    window.addEventListener('pagehide', () => {
        if (persistDirty) {
            flushCorrelationDedupeNow();
        }
    });
}

function resetCorrelationMemory() {
    seenCorrelationIds.clear();
    seenCorrelationOrder.length = 0;
}

function loadCorrelationDedupeFromStorage() {
    resetCorrelationMemory();
    try {
        const storage = getCorrelationSessionStorage();
        if (!storage) {
            return;
        }
        const raw = storage.getItem(CORRELATION_DEDUPE_STORAGE_KEY);
        if (!raw) {
            return;
        }
        const parsed = JSON.parse(raw);
        if (!Array.isArray(parsed)) {
            return;
        }
        const now = Date.now();
        for (const entry of parsed) {
            if (!entry || typeof entry.id !== 'string' || typeof entry.ts !== 'number') {
                continue;
            }
            if (now - entry.ts > CORRELATION_DEDUPE_TTL_MS) {
                continue;
            }
            if (seenCorrelationIds.has(entry.id)) {
                continue;
            }
            seenCorrelationIds.add(entry.id);
            seenCorrelationOrder.push({ id: entry.id, ts: entry.ts });
        }
        while (seenCorrelationOrder.length > SEEN_CORRELATION_CAP) {
            const evicted = seenCorrelationOrder.shift();
            if (evicted) {
                seenCorrelationIds.delete(evicted.id);
            }
        }
        persistCorrelationDedupe();
    } catch (error) {
        resetCorrelationMemory();
        warnCorrelationStorageOnce(error);
    }
}

function evictExpiredCorrelations(now) {
    let mutated = false;
    while (seenCorrelationOrder.length > 0) {
        const oldest = seenCorrelationOrder[0];
        if (!oldest || now - oldest.ts <= CORRELATION_DEDUPE_TTL_MS) {
            break;
        }
        seenCorrelationOrder.shift();
        seenCorrelationIds.delete(oldest.id);
        mutated = true;
    }
    if (mutated) {
        persistCorrelationDedupe();
    }
}

function evictCorrelationCapacity() {
    let mutated = false;
    while (seenCorrelationOrder.length > SEEN_CORRELATION_CAP) {
        const evicted = seenCorrelationOrder.shift();
        if (evicted) {
            seenCorrelationIds.delete(evicted.id);
            mutated = true;
        }
    }
    if (mutated) {
        persistCorrelationDedupe();
    }
}

loadCorrelationDedupeFromStorage();

function correlationDedupeKey(correlationId, eventType = null, branchId = null, aggregateId = null) {
    const branchKey = branchId === null || branchId === undefined || branchId === ''
        ? null
        : `branch:${branchId}`;
    // [GOAL-2026-05-29 F4] Include the event's aggregate id when present so a SINGLE
    // request/correlation that legitimately emits one event PER ENTITY — e.g. a
    // multi-item auto-86 emitting ItemAvailabilityChanged for each item, all sharing
    // one correlationId+type+branch — is NOT collapsed to the first event (which kept
    // the 2nd..Nth depleted items sellable). A genuine re-delivery (WS+poll) carries
    // the SAME aggregate id, so it still dedups. Backward-compatible: when aggregateId
    // is null the key is byte-identical to the previous format.
    const aggKey = aggregateId === null || aggregateId === undefined || aggregateId === ''
        ? null
        : `agg:${aggregateId}`;
    const parts = [];
    if (eventType && typeof eventType === 'string') parts.push(eventType);
    if (branchKey) parts.push(branchKey);
    if (aggKey) parts.push(aggKey);
    parts.push(correlationId);
    return parts.join(':');
}

export function isDuplicateCorrelation(correlationId, eventType = null, branchId = null, aggregateId = null) {
    if (!correlationId || typeof correlationId !== 'string') {
        return false;
    }
    const dedupeKey = correlationDedupeKey(correlationId, eventType, branchId, aggregateId);
    const now = Date.now();
    evictExpiredCorrelations(now);
    if (seenCorrelationIds.has(dedupeKey)) {
        return true;
    }
    seenCorrelationIds.add(dedupeKey);
    seenCorrelationOrder.push({ id: dedupeKey, ts: now });
    evictCorrelationCapacity();
    persistCorrelationDedupe();
    return false;
}

// Test hook : reset the dedupe window between specs.
export function __resetCorrelationDedupe() {
    if (persistTimer !== null) {
        clearTimeout(persistTimer);
        persistTimer = null;
    }
    persistDirty = false;
    resetCorrelationMemory();
    correlationStorageWarningLogged = false;
    try {
        const storage = getCorrelationSessionStorage();
        if (storage) {
            storage.removeItem(CORRELATION_DEDUPE_STORAGE_KEY);
        }
    } catch (error) {
        warnCorrelationStorageOnce(error);
    }
}

// Test hook : re-read sessionStorage to simulate a tab reload. Flushes any
// pending debounced write first so the simulation observes the latest state.
export function __forceLoadCorrelationDedupe() {
    if (persistDirty) {
        flushCorrelationDedupeNow();
    }
    loadCorrelationDedupeFromStorage();
}

// Test hook : observe the in-memory dedupe size after lazy eviction.
export function __getCorrelationDedupeSize() {
    evictExpiredCorrelations(Date.now());
    return seenCorrelationOrder.length;
}

// Test hook : force-flush any pending debounced sessionStorage write.
export function __flushCorrelationDedupePersistence() {
    flushCorrelationDedupeNow();
}

export function onEvent(branchId, broadcastAs, handler) {
    return onEvents(branchId, [{ broadcastAs, handler }]);
}

export function onEvents(branchId, bindings) {
    if (!window.Echo || !branchId || !Array.isArray(bindings) || bindings.length === 0) {
        return {
            unsubscribe() {},
        };
    }

    const channelName = `branch.${branchId}`;
    const channel = window.Echo.private(channelName);
    const listeners = [];

    bindings.forEach(({ broadcastAs, handler }) => {
        if (!broadcastAs || typeof handler !== 'function') {
            return;
        }

        const rawHandler = (raw) => {
            try {
                const parsed = parseEvent(raw);
                const expectedType = BROADCAST_MAP[broadcastAs];

                if (expectedType && parsed.type !== expectedType) {
                    console.warn('[eventContract] Event type mismatch for broadcast.', {
                        broadcastAs,
                        expectedType,
                        receivedType: parsed.type,
                    });
                }

                // [GOAL-2026-05-29 F4] Pass the aggregate id so per-entity events of one
                // request/correlation (multi-item auto-86) aren't collapsed; a true
                // re-delivery (same aggregate) still dedups.
                if (isDuplicateCorrelation(parsed.correlationId, parsed.type || expectedType || broadcastAs, parsed.branchId, parsed.aggregateId)) {
                    return;
                }

                handler(parsed);
            } catch (error) {
                console.warn(`[eventContract] Failed to parse ${broadcastAs}.`, error, raw);
            }
        };

        channel.listen(`.${broadcastAs}`, rawHandler);
        listeners.push({ broadcastAs, rawHandler });
    });

    return {
        unsubscribe() {
            // [SYNC-01 2026-06-08] Remove ONLY this subscriber's own listeners on the SHARED
            // branch channel. Two prior bugs are fixed here:
            //   1. The old code called `window.Echo.leave(channelName)`, which tore down the
            //      ENTIRE `branch.{id}` channel — but it is shared by the kiosk shell
            //      (KioskAppComponent availability/86 pushes, subscribed once on boot), KDS,
            //      OSS and the POS tracker. A child component unmounting (e.g.
            //      KioskWaitingComponent after an order) thus killed the shell's live
            //      availability stream for the rest of the session (no re-subscribe). Removed.
            //   2. `stopListening('.event')` without a callback removes ALL callbacks for that
            //      event — clobbering a co-subscriber listening to the same event. Pass our own
            //      `rawHandler` so only THIS subscription is detached. The channel stays joined
            //      for the other subscribers and is cleaned up by Echo on page unload.
            listeners.forEach(({ broadcastAs, rawHandler }) => {
                try {
                    channel.stopListening(`.${broadcastAs}`, rawHandler);
                } catch (_) {}
            });
        },
    };
}
