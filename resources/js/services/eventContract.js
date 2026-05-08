export const EVENT_TYPES = {
    ORDER_CREATED: 'order.created',
    ORDER_STATUS_CHANGED: 'order.status_changed',
    ORDER_ITEM_ADDED: 'order.item_added',
    ORDER_CANCELLED: 'order.cancelled',
    MENU_ITEM_AVAILABILITY_CHANGED: 'menu.item_availability_changed',
    STOCK_LOW: 'stock.low',
};

export const BROADCAST_MAP = {
    OrderCreated: EVENT_TYPES.ORDER_CREATED,
    OrderStatusChanged: EVENT_TYPES.ORDER_STATUS_CHANGED,
    ItemAvailabilityChanged: EVENT_TYPES.MENU_ITEM_AVAILABILITY_CHANGED,
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

// [PRE-PROD HARDENING / SYN-2 / P0-7] Pusher heartbeat client→server ack.
// After every successfully parsed envelope we POST a single line to
// internal/pusher-ack so the server can compare dispatched-vs-ack'd
// counts on a 5-minute sliding window. The call is fire-and-forget:
//
//  - no correlation_id → no ack possible (server cannot dedupe);
//  - no axios → silently skipped (test envs without bootstrap);
//  - any HTTP failure is swallowed (the handler must already have run).
//
// URL : RELATIVE (`internal/pusher-ack`), pas absolue (`/api/...`).
// `axios.defaults.baseURL` est déjà `<API_URL>/api` (cf. resources/js/app.js:56),
// donc préfixer par `/api/` produit `<API_URL>/api/api/...` → 404.
// Bug pré-existant fixé par plan ULTRA_PLAN_CORRECTION §A2 (2026-05-08).
//
// Reads the snake_case fields straight off the raw envelope, NOT the
// camelCase parsed object — keeps the wire format identical to the
// server-side validation rules.
export function sendAck(raw, channelName) {
    if (!raw || !raw.correlation_id) {
        return;
    }
    if (typeof window === 'undefined' || !window.axios) {
        return;
    }
    try {
        window.axios.post('internal/pusher-ack', {
            correlation_id:  raw.correlation_id,
            branch_id:       raw.branch_id ?? null,
            channel:         channelName || 'unknown',
            event_name:      raw.type || 'unknown',
            received_at:     new Date().toISOString(),
            domain_event_id: raw.domain_event_id ?? null,
        }, { timeout: 3000 }).catch(() => { /* silent */ });
    } catch (_) {
        /* silent */
    }
}

export function onEvent(branchId, broadcastAs, handler) {
    return onEvents(branchId, [{ broadcastAs, handler }]);
}

export function onEvents(branchId, bindings) {
    if (!window.Echo) {
        // [V1 SYNC_ROBUSTNESS 3.2] Surface a warning so a missing/late-loaded Echo
        // doesn't silently swallow listener bindings — components hold the
        // returned handle and never re-subscribe, which manifests as a memory
        // leak / dead realtime channel in production (kiosk, KDS, OSS).
        console.warn('[eventContract] window.Echo not ready, listener binding skipped — possible memory leak');
        return {
            unsubscribe() {},
        };
    }
    if (!branchId || !Array.isArray(bindings) || bindings.length === 0) {
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

                handler(parsed);

                // [PRE-PROD HARDENING / SYN-2 / P0-7] Heartbeat ack —
                // fire-and-forget. Always after the handler so a slow
                // server doesn't block UI updates, always inside the
                // try block so a malformed envelope (which already
                // emitted a console.warn above) does not produce an
                // ack for un-handled data.
                sendAck(raw, channelName);
            } catch (error) {
                console.warn(`[eventContract] Failed to parse ${broadcastAs}.`, error, raw);
            }
        };

        channel.listen(`.${broadcastAs}`, rawHandler);
        listeners.push({ broadcastAs, rawHandler });
    });

    return {
        unsubscribe() {
            listeners.forEach(({ broadcastAs }) => {
                try {
                    channel.stopListening(`.${broadcastAs}`);
                } catch (_) {}
            });

            try {
                window.Echo.leave(channelName);
            } catch (_) {}
        },
    };
}
