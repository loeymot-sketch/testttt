const STATE = Object.freeze({
    IDLE: 'idle',
    POLLING: 'polling',
    BACKOFF: 'backoff',
    STOPPED: 'stopped',
});

const DEFAULTS = Object.freeze({
    intervalMsWhenConnected: 60_000,
    // [test-e2e round-2 cluster-6 D-002 2026-05-10] Tightened from 5000 → 2000
    // so that the SYNC-2 8s budget (POS pay → OSS visible) is met by the
    // polling fallback alone when the broadcast queue is idle in dev
    // (BROADCAST_DRIVER=pusher + WS port 6001 down + no queue worker).
    // Production still uses Echo/Pusher live so this fallback is essentially
    // unused there; tightening it costs nothing in prod and saves ~3s in dev.
    intervalMsWhenDisconnected: 2_000,
    backoffStartMs: 5_000,
    backoffCapMs: 30_000,
    jitterMaxMs: 500,
    // [test-e2e round-2 cluster-6 D-002 2026-05-10] Visibility burst-poll
    // throttle. When the tab regains visibility, OssSyncService fires an
    // immediate poll unless one fired within this window — protects against
    // a stream of focus/blur events spamming the backend.
    visibilityBurstMinIntervalMs: 1_000,
    // Sustained-disconnect dev warning threshold (silent in prod).
    devWarnAfterDisconnectMs: 10_000,
});

// [Wave 3c KDS-ADV3C-08 P1 2026-05-18] Cadence upper cap symmetric with
// Wave 2c KDS heal (9ff26e12b) and sibling PosSyncService heal. Without
// it, owner-misconfig like FK_CATALOG_OSS_FALLBACK_CONNECTED_INTERVAL_MS=
// 999999999 would freeze the customer wall, blowing the SYNC-2 8s budget
// (POS pay → OSS visible). 60s = 1 poll/min minimum.
const CADENCE_CEILING_MS = 60_000;
const CADENCE_FLOOR_MS = 250;

class OssSyncService {
    constructor() {
        this._state = STATE.IDLE;
        this._timer = null;
        this._abortController = null;
        this._wsUnsubscribe = null;
        this._wsState = 'unknown';
        this._listeners = new Map();
        this._started = false;
        this._store = null;
        this._opts = { ...DEFAULTS };
        this._currentBackoffMs = DEFAULTS.backoffStartMs;
        this._lastScheduledDelayMs = null;
        // [test-e2e round-2 cluster-6 D-002 2026-05-10] Burst-poll plumbing.
        this._visibilityHandler = null;
        this._lastBurstPollAt = 0;
        this._disconnectedSinceMs = null;
        this._devWarnedDisconnected = false;
    }

    start(ctx = {}) {
        this._started = false;
        this._cleanup({ unsubscribe: true });

        const runtimeConfig = this._runtimeConfig();
        if (!runtimeConfig.enabled) {
            this._state = STATE.IDLE;
            return;
        }

        if (!ctx.store || typeof ctx.store.dispatch !== 'function') {
            this._state = STATE.IDLE;
            return;
        }

        this._opts = {
            ...DEFAULTS,
            intervalMsWhenConnected: runtimeConfig.intervalMsWhenConnected,
            intervalMsWhenDisconnected: runtimeConfig.intervalMsWhenDisconnected,
            ...(ctx.options || {}),
        };
        this._currentBackoffMs = this._opts.backoffStartMs;
        this._store = ctx.store;
        this._webSocketService = ctx.webSocketService || null;
        this._wsState = 'unknown';
        this._started = true;
        this._state = STATE.POLLING;

        this._bindWebSocketState();
        this._bindVisibility();
        this._scheduleNext(this._jitter());
    }

    stop() {
        this._started = false;
        this._cleanup({ unsubscribe: true });
        this._state = STATE.STOPPED;
    }

    on(eventName, handler) {
        if (!this._listeners.has(eventName)) {
            this._listeners.set(eventName, new Set());
        }
        const set = this._listeners.get(eventName);
        set.add(handler);
        return () => set.delete(handler);
    }

    state() {
        return this._state;
    }

    static get STATES() {
        return STATE;
    }

    static get DEFAULTS() {
        return DEFAULTS;
    }

    _runtimeConfig() {
        const cfg = typeof window !== 'undefined'
            ? (window.foodkingConfig?.ossFallbackPolling || {})
            : {};

        return {
            enabled: cfg.enabled !== false && cfg.enabled !== 0 && cfg.enabled !== '0',
            // [Wave 3c KDS-ADV3C-08 P1 2026-05-18] Was `_positiveInt` —
            // accepted any positive int incl. 999999999 (freeze 11.5 days).
            // Now clamped to [250ms, 60_000ms] symmetric with KDS+POS.
            intervalMsWhenConnected: this._clampCadence(
                cfg.intervalMsWhenConnected,
                DEFAULTS.intervalMsWhenConnected
            ),
            intervalMsWhenDisconnected: this._clampCadence(
                cfg.intervalMsWhenDisconnected,
                DEFAULTS.intervalMsWhenDisconnected
            ),
        };
    }

    _bindWebSocketState() {
        const ws = this._webSocketService;
        if (!ws || typeof ws.on !== 'function') {
            this._wsState = 'disconnected';
            // [test-e2e round-2 cluster-6 D-002 2026-05-10] Seed disconnect
            // timestamp so the dev-only warn triggers when WS is never wired
            // (most common case in local dev: BROADCAST_DRIVER=pusher, port
            // 6001 down, _wsService never reaches 'connected').
            this._disconnectedSinceMs = Date.now();
            return;
        }

        const unsubscribers = [];
        const listen = (eventName, callback) => {
            const unsubscribe = ws.on(eventName, callback);
            if (typeof unsubscribe === 'function') {
                unsubscribers.push(unsubscribe);
                return;
            }
            if (typeof ws.off === 'function') {
                unsubscribers.push(() => ws.off(eventName, callback));
            }
        };

        const handleState = (next) => {
            const previousWsState = this._wsState;
            this._wsState = next || 'unknown';
            this._emit('ws_state', { state: this._wsState });
            this._state = STATE.POLLING;
            this._currentBackoffMs = this._opts.backoffStartMs;
            // [test-e2e round-2 cluster-6 D-002 2026-05-10] Track sustained
            // disconnect for the dev-only console warn (see _maybeWarnDisconnect).
            const isConnectedNow = String(this._wsState).toLowerCase() === 'connected';
            if (isConnectedNow) {
                this._disconnectedSinceMs = null;
                this._devWarnedDisconnected = false;
            } else if (!this._disconnectedSinceMs) {
                this._disconnectedSinceMs = Date.now();
            }
            // If we just transitioned from disconnected → connected, fire an
            // immediate poll so the OSS catches up with whatever piled up
            // during the WS outage instead of waiting for the next tick.
            if (
                isConnectedNow
                && previousWsState
                && String(previousWsState).toLowerCase() !== 'connected'
            ) {
                this._burstPoll('ws_reconnected');
                return;
            }
            this._scheduleNormalCadence();
        };

        listen('connected', () => handleState('connected'));
        listen('disconnected', () => handleState('disconnected'));
        listen('reconnect_storm', () => handleState('disconnected'));
        listen('state_change', (payload = {}) => {
            const next = payload.current || payload.to || payload.state || payload.next || null;
            if (next) {
                handleState(next);
            }
        });

        this._wsUnsubscribe = () => {
            unsubscribers.splice(0).forEach((unsubscribe) => {
                try { unsubscribe(); } catch (_) { /* ignore cleanup errors */ }
            });
        };
    }

    // [test-e2e round-2 cluster-6 D-002 2026-05-10] Burst-poll on tab visibility.
    // Spec captures showed POS pay → OSS lag of 14.4s when the OSS tab was
    // backgrounded between actions: setTimeout intervals throttle to ~1s when a
    // tab is hidden, but visibilitychange fires immediately on focus regain.
    // Triggering an instant fetch on `visible` collapses worst-case lag to one
    // round-trip + render. Throttled by visibilityBurstMinIntervalMs.
    _bindVisibility() {
        if (typeof document === 'undefined' || typeof document.addEventListener !== 'function') {
            return;
        }
        this._visibilityHandler = () => {
            if (!this._started) return;
            if (document.visibilityState !== 'visible') return;
            this._burstPoll('visibility');
        };
        try {
            document.addEventListener('visibilitychange', this._visibilityHandler);
        } catch (_) { /* never block start on listener wiring */ }
    }

    _unbindVisibility() {
        if (this._visibilityHandler && typeof document !== 'undefined') {
            try {
                document.removeEventListener('visibilitychange', this._visibilityHandler);
            } catch (_) { /* noop */ }
        }
        this._visibilityHandler = null;
    }

    _burstPoll(reason = 'manual') {
        if (!this._started) return;
        const now = Date.now();
        const minGap = this._opts.visibilityBurstMinIntervalMs || 0;
        if (this._lastBurstPollAt && now - this._lastBurstPollAt < minGap) {
            return;
        }
        this._lastBurstPollAt = now;
        // Maybe-warn here too: the user just brought the tab forward and the
        // WS has been down for a while → surface a dev-only diagnostic.
        this._maybeWarnDisconnect();
        // Cancel the scheduled timer and trigger an immediate fetch. _poll()
        // re-schedules normal cadence on completion.
        this._clearTimer();
        this._poll().catch(() => {});
    }

    _maybeWarnDisconnect() {
        if (this._devWarnedDisconnected) return;
        if (!this._disconnectedSinceMs) return;
        const threshold = this._opts.devWarnAfterDisconnectMs || 0;
        if (threshold <= 0) return;
        const elapsed = Date.now() - this._disconnectedSinceMs;
        if (elapsed < threshold) return;
        const isDev = typeof window !== 'undefined'
            && window.foodkingConfig?.appEnv
            && window.foodkingConfig.appEnv !== 'production';
        if (!isDev) return;
        this._devWarnedDisconnected = true;
        try {
            // Single warn per disconnect window so the console isn't spammed.
            // eslint-disable-next-line no-console
            console.warn(
                '[OSS] Realtime broadcast unavailable for ' + Math.round(elapsed / 1000)
                + 's — polling fallback active. SYNC latency may exceed live cadence.'
            );
        } catch (_) { /* noop */ }
    }

    async _poll() {
        if (!this._started || !this._store) {
            return;
        }

        this._abortInFlight();
        const controller = new AbortController();
        this._abortController = controller;

        try {
            const result = await this._store.dispatch('orderStatusScreenOrder/lists');
            if (controller.signal.aborted || !this._started) {
                return;
            }

            const status = this._statusFromResult(result);
            if (status >= 500 && status <= 599) {
                this._handle5xx();
                return;
            }

            const rows = result?.data?.data || [];
            this._state = STATE.POLLING;
            this._currentBackoffMs = this._opts.backoffStartMs;
            this._emit('sync', { rows, status });
            this._scheduleNormalCadence();
        } catch (error) {
            if (controller.signal.aborted || error?.name === 'AbortError' || error?.code === 'ERR_CANCELED') {
                return;
            }

            const status = this._statusFromError(error);
            if (status >= 500 && status <= 599) {
                this._handle5xx();
                return;
            }

            this._state = STATE.POLLING;
            this._emit('error', { status, error });
            this._scheduleNormalCadence();
        } finally {
            if (this._abortController === controller) {
                this._abortController = null;
            }
        }
    }

    _handle5xx() {
        if (!this._started) {
            return;
        }
        this._state = STATE.BACKOFF;
        const delay = this._currentBackoffMs;
        this._currentBackoffMs = Math.min(this._currentBackoffMs * 2, this._opts.backoffCapMs);
        this._emit('error', { status: 500, backoffMs: delay });
        this._scheduleNext(delay);
    }

    _scheduleNormalCadence() {
        const state = this._readWsState();
        const isConnected = String(state || '').toLowerCase() === 'connected';
        const base = isConnected
            ? this._opts.intervalMsWhenConnected
            : this._opts.intervalMsWhenDisconnected;

        // [test-e2e round-2 cluster-6 D-002 2026-05-10] Surface sustained
        // disconnect once we've been polling in fallback long enough — this
        // hooks into the normal cadence path so even backgrounded tabs warn.
        this._maybeWarnDisconnect();

        this._scheduleNext(base + this._jitter());
    }

    _scheduleNext(delayMs) {
        this._clearTimer();
        if (!this._started) {
            return;
        }

        const delay = Math.max(0, this._positiveInt(delayMs, 0));
        this._lastScheduledDelayMs = delay;
        this._timer = setTimeout(() => {
            this._timer = null;
            this._poll().catch(() => {});
        }, delay);
    }

    _cleanup({ unsubscribe = false } = {}) {
        this._clearTimer();
        this._abortInFlight();
        this._lastScheduledDelayMs = null;
        this._store = null;
        if (unsubscribe && this._wsUnsubscribe) {
            this._wsUnsubscribe();
            this._wsUnsubscribe = null;
        }
        // [test-e2e round-2 cluster-6 D-002 2026-05-10] Always tear down the
        // visibility listener — leaking it would burst-poll a stopped service.
        this._unbindVisibility();
        this._disconnectedSinceMs = null;
        this._devWarnedDisconnected = false;
        this._lastBurstPollAt = 0;
    }

    _clearTimer() {
        if (this._timer) {
            clearTimeout(this._timer);
            this._timer = null;
        }
    }

    _abortInFlight() {
        if (this._abortController) {
            this._abortController.abort();
            this._abortController = null;
        }
    }

    _readWsState() {
        if (this._wsState && this._wsState !== 'unknown') {
            return this._wsState;
        }
        const ws = this._webSocketService;
        if (ws && typeof ws.getState === 'function') {
            return ws.getState();
        }
        if (ws && typeof ws.state !== 'undefined') {
            return ws.state;
        }
        if (ws && typeof ws.isConnected === 'function') {
            return ws.isConnected() ? 'connected' : 'disconnected';
        }
        return 'disconnected';
    }

    _statusFromResult(result) {
        return Number(result?.status || result?.response?.status || 200);
    }

    _statusFromError(error) {
        return Number(error?.response?.status || error?.status || 0);
    }

    _positiveInt(value, fallback) {
        const parsed = parseInt(value, 10);
        return Number.isFinite(parsed) && parsed >= 0 ? parsed : fallback;
    }

    /**
     * [Wave 3c KDS-ADV3C-08 P1 2026-05-18] Clamp a cadence value to
     * [CADENCE_FLOOR_MS, CADENCE_CEILING_MS]. Non-numeric → fallback.
     * Protects against silent-blind misconfig (e.g. CDN-pushed config
     * with intervalMsWhenConnected=999999999 freezing the customer wall).
     */
    _clampCadence(value, fallback) {
        const parsed = parseInt(value, 10);
        const candidate = Number.isFinite(parsed) ? parsed : fallback;
        const floored = candidate >= CADENCE_FLOOR_MS ? candidate : CADENCE_FLOOR_MS;
        return floored <= CADENCE_CEILING_MS ? floored : CADENCE_CEILING_MS;
    }

    _jitter() {
        return Math.floor(Math.random() * this._opts.jitterMaxMs);
    }

    _emit(eventName, payload) {
        const listeners = this._listeners.get(eventName);
        if (!listeners) {
            return;
        }
        listeners.forEach((handler) => handler(payload));
    }
}

export default new OssSyncService();
export { OssSyncService, STATE, DEFAULTS, CADENCE_CEILING_MS, CADENCE_FLOOR_MS };
