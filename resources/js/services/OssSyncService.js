const STATE = Object.freeze({
    IDLE: 'idle',
    POLLING: 'polling',
    BACKOFF: 'backoff',
    STOPPED: 'stopped',
});

const DEFAULTS = Object.freeze({
    intervalMsWhenConnected: 60_000,
    intervalMsWhenDisconnected: 5_000,
    backoffStartMs: 5_000,
    backoffCapMs: 30_000,
    jitterMaxMs: 500,
});

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
            intervalMsWhenConnected: this._positiveInt(
                cfg.intervalMsWhenConnected,
                DEFAULTS.intervalMsWhenConnected
            ),
            intervalMsWhenDisconnected: this._positiveInt(
                cfg.intervalMsWhenDisconnected,
                DEFAULTS.intervalMsWhenDisconnected
            ),
        };
    }

    _bindWebSocketState() {
        const ws = this._webSocketService;
        if (!ws || typeof ws.on !== 'function') {
            this._wsState = 'disconnected';
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
            this._wsState = next || 'unknown';
            this._emit('ws_state', { state: this._wsState });
            this._state = STATE.POLLING;
            this._currentBackoffMs = this._opts.backoffStartMs;
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
export { OssSyncService, STATE, DEFAULTS };
