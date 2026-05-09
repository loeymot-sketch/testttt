/**
 * PosSyncService.js — Mission #1 Vague 1 action 1.7.
 *
 * Symmetric counterpart to KdsSyncService for the POS surface.
 *
 * Today the POS UI relies entirely on Echo broadcasts (`CatalogChanged`,
 * `ItemAvailabilityChanged`) to refresh its catalog. If Pusher is down or
 * the worker outbox is stuck, the cashier sees a frozen catalog with no
 * fallback. The KDS already has KdsSyncService.js for that exact case —
 * this service brings POS to feature parity.
 *
 * Behavior:
 *
 *   - Subscribes to the WebSocketService state changes.
 *   - When state === DISCONNECTED, starts a polling loop with cadence
 *     config('catalog_v15.pos_fallback_polling.interval_ms_when_disconnected'),
 *     calling /api/admin/item?surface=pos&branch_id={id} and dispatching
 *     a Vuex action `item/lists` if the response contains drift.
 *   - When state === CONNECTED, stops polling immediately (Infinity).
 *   - Adds 0–500ms client-side jitter on each poll to spread fleet bursts.
 *   - 5xx backoff doubling capped at 30s, mirroring KdsSyncService.
 *
 * Lifecycle:
 *   - start({ branchId, store, axios, webSocketService }) is called from PosComponent.vue::mounted.
 *   - stop() is called from PosComponent.vue::beforeUnmount.
 *
 * Audit: reports/audit/CLAUDE_ULTRA_REVIEW_MISSION_1_CATALOG_SYNC_2026-05-02.md §A.2 #10
 * Plan : plans/PLAN_CV1-CATALOG-CONVERGENCE-001_2026-05-02.md task 1.7
 *
 * Implemented under plan task 1.7.
 */

const STATE = Object.freeze({
    IDLE: 'idle',
    POLLING: 'polling',
    BACKOFF: 'backoff',
    STOPPED: 'stopped',
});

const DEFAULTS = Object.freeze({
    intervalMsWhenDisconnected: 30_000,
    backoffStartMs: 5_000,
    backoffCapMs: 30_000,
    jitterMaxMs: 500,
});

class PosSyncService {
    constructor() {
        this._state = STATE.IDLE;
        this._timer = null;
        this._abortController = null;
        this._lastAbortSignal = null;
        this._currentBackoffMs = DEFAULTS.backoffStartMs;
        this._opts = { ...DEFAULTS };
        this._wsUnsubscribe = null;
        this._wsState = 'unknown';
        this._started = false;
        this._branchId = null;
        this._store = null;
        this._axios = null;
        this._webSocketService = null;
        this._lastScheduledDelayMs = null;
    }

    /**
     * Start the fallback polling lifecycle.
     *
     * @param {Object}  ctx
     * @param {number}  ctx.branchId        Active POS branch id.
     * @param {Object}  ctx.store           Vuex store (dispatches `item/lists`).
     * @param {Object}  ctx.axios           Configured axios instance.
     * @param {Object}  ctx.webSocketService Same instance used by Echo.
     * @param {Object}  [ctx.options]       Override DEFAULTS.
     */
    start(ctx = {}) {
        this._started = false;
        this._cleanup({ unsubscribe: true });

        const runtimeConfig = this._runtimeConfig();
        if (!runtimeConfig.enabled) {
            this._state = STATE.IDLE;
            console.info('[PosSyncService] fallback polling disabled.');
            return;
        }

        const branchId = this._normalizeBranchId(ctx.branchId);
        if (!branchId) {
            this._state = STATE.IDLE;
            console.warn('[PosSyncService] missing branchId; fallback polling not started.');
            return;
        }

        this._opts = {
            ...DEFAULTS,
            intervalMsWhenDisconnected: runtimeConfig.intervalMsWhenDisconnected || DEFAULTS.intervalMsWhenDisconnected,
            ...(ctx.options || {}),
        };
        this._currentBackoffMs = this._opts.backoffStartMs;
        this._branchId = branchId;
        this._store = ctx.store || null;
        this._axios = ctx.axios || null;
        this._webSocketService = ctx.webSocketService || null;
        this._started = true;
        this._state = STATE.IDLE;
        this._wsState = 'unknown';

        this._bindWebSocketState();

        if (this._shouldPollForState(this._readWsState())) {
            this._resume();
        }
    }

    /**
     * Stop polling cleanly. Idempotent.
     */
    stop() {
        this._started = false;
        this._cleanup({ unsubscribe: true });
        this._state = STATE.STOPPED;
    }

    /** Read-only state inspector — used by tests and by the dashboard. */
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
            ? (window.foodkingConfig?.posFallbackPolling || {})
            : {};

        return {
            enabled: cfg.enabled === true || cfg.enabled === 1 || cfg.enabled === '1',
            intervalMsWhenDisconnected: this._positiveInt(
                cfg.intervalMsWhenDisconnected,
                DEFAULTS.intervalMsWhenDisconnected,
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

        listen('connected', () => {
            this._wsState = 'connected';
            this._suspend();
        });

        listen('disconnected', (payload = {}) => {
            this._wsState = payload.state || 'disconnected';
            this._resume();
        });

        listen('reconnect_storm', () => {
            this._wsState = 'disconnected';
            this._resume();
        });

        listen('state_change', (payload = {}) => {
            const next = payload.current || payload.to || payload.state || payload.next || null;
            if (!next) {
                return;
            }
            this._wsState = next;
            if (this._shouldPollForState(next)) {
                this._resume();
            } else if (this._isConnectedState(next)) {
                this._suspend();
            }
        });

        this._wsUnsubscribe = () => {
            unsubscribers.splice(0).forEach((unsubscribe) => {
                try { unsubscribe(); } catch (_) { /* defensive cleanup */ }
            });
        };
    }

    _resume() {
        if (!this._started) {
            return;
        }

        if (this._state === STATE.POLLING || this._state === STATE.BACKOFF) {
            if (!this._timer && !this._abortController) {
                const cadence = this._state === STATE.BACKOFF
                    ? this._currentBackoffMs
                    : this._opts.intervalMsWhenDisconnected + this._jitter();
                this._scheduleNext(cadence);
            }
            return;
        }

        this._state = STATE.POLLING;
        this._scheduleNext(this._jitter());
    }

    _suspend() {
        this._clearTimer();
        this._abortInFlight();
        if (this._started) {
            this._state = STATE.IDLE;
        }
    }

    async _poll() {
        if (!this._started || !this._shouldPollForState(this._readWsState())) {
            return;
        }

        this._abortInFlight();
        const controller = new AbortController();
        this._abortController = controller;
        this._lastAbortSignal = controller.signal;

        try {
            const result = await this._dispatchItemList(controller.signal);
            if (controller.signal.aborted || !this._started) {
                return;
            }

            const status = this._statusFromResult(result);
            if (status >= 500 && status <= 599) {
                this._handle5xx();
                return;
            }

            this._commitItemListResult(result);
            this._currentBackoffMs = this._opts.backoffStartMs;
            this._state = STATE.POLLING;
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
            this._scheduleNormalCadence();
        } finally {
            if (this._abortController === controller) {
                this._abortController = null;
            }
        }
    }

    _dispatchItemList(signal) {
        if (!this._store || typeof this._store.dispatch !== 'function') {
            return Promise.reject(new Error('Vuex store dispatch unavailable.'));
        }

        const payload = {
            surface: 'pos',
            branch_id: this._branchId,
            force: true,
            overlay: false,
            vuex: false,
        };

        const promise = this._store.dispatch('item/lists', payload);
        if (signal.aborted) {
            return Promise.resolve(null);
        }
        return promise;
    }

    _commitItemListResult(result) {
        if (!this._store || typeof this._store.commit !== 'function') {
            return;
        }

        const data = result?.data || null;
        if (!data) {
            return;
        }

        if (Object.prototype.hasOwnProperty.call(data, 'data')) {
            this._store.commit('item/lists', data.data);
        }
        if (Object.prototype.hasOwnProperty.call(data, 'meta')) {
            this._store.commit('item/page', data.meta);
        }
        this._store.commit('item/pagination', data);
    }

    _handle5xx() {
        if (!this._started || !this._shouldPollForState(this._readWsState())) {
            return;
        }

        this._state = STATE.BACKOFF;
        const delay = this._currentBackoffMs;
        this._currentBackoffMs = Math.min(this._currentBackoffMs * 2, this._opts.backoffCapMs);
        this._scheduleNext(delay);
    }

    _scheduleNormalCadence() {
        if (!this._started || !this._shouldPollForState(this._readWsState())) {
            return;
        }

        this._scheduleNext(this._opts.intervalMsWhenDisconnected + this._jitter());
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
        this._branchId = null;
        this._store = null;
        this._axios = null;
        this._webSocketService = null;
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

    _shouldPollForState(state) {
        return !this._isConnectedState(state);
    }

    _isConnectedState(state) {
        return String(state || '').toLowerCase() === 'connected';
    }

    _statusFromResult(result) {
        return Number(result?.status || result?.response?.status || 200);
    }

    _statusFromError(error) {
        return Number(error?.response?.status || error?.status || 0);
    }

    _normalizeBranchId(branchId) {
        const value = parseInt(branchId, 10);
        return Number.isFinite(value) && value > 0 ? value : null;
    }

    _positiveInt(value, fallback) {
        const parsed = parseInt(value, 10);
        return Number.isFinite(parsed) && parsed >= 0 ? parsed : fallback;
    }

    _jitter() {
        return Math.floor(Math.random() * this._opts.jitterMaxMs);
    }
}

export default new PosSyncService();
export { PosSyncService, STATE, DEFAULTS };
