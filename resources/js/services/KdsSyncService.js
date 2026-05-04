/**
 * F-03 / NEW-02 / NEW-04
 * Authority: plans/MEGA_PLAN_SYNC_HARDENING_v3_2026-04-23.md
 */

import ENV from '../config/env';

export const KDS_SYNC_STATE = {
    IDLE: 'IDLE',
    ACTIVE: 'ACTIVE',
    BACKOFF: 'BACKOFF',
    STOPPED: 'STOPPED',
};

const WS_CONNECTED = 'CONNECTED';
const WS_RECONNECTING = 'RECONNECTING';
const WS_DEGRADED = 'DEGRADED';
const WS_DISCONNECTED = 'DISCONNECTED';
const WS_SESSION_INVALID = 'SESSION_INVALID';
const DEFAULT_CADENCE_OPTIONS = Object.freeze({
    highActivityBaseMs: 3000,
    highActivityJitterMs: 1000,
    degradedBaseMs: 5000,
    degradedJitterMs: 2000,
    disconnectedBaseMs: 10000,
    disconnectedJitterMs: 3000,
});

export class KdsSyncService {
    constructor({ wsService = (typeof window !== 'undefined' ? window._wsService : null), fetchFn, now } = {}) {
        this.wsService = wsService || null;
        this.fetchFn = fetchFn || (typeof window !== 'undefined' && window.fetch ? window.fetch.bind(window) : null);
        this.now = now || (() => Date.now());

        this._listeners = new Map();
        this._state = KDS_SYNC_STATE.IDLE;
        this._started = false;
        this._branchId = null;
        this._lastSyncAt = null;
        this._currentIntervalMs = Infinity;
        this._timer = null;
        this._driftTimer = null;
        this._abortController = null;
        this._wsUnsubscribers = [];
        this._lastSince = null;
        this._versionMap = new Map();
        this._versionOrder = [];
        this._maxVersionEntries = 256;
        this._highActivityUntil = 0;
        this._consecutive5xx = 0;
        this._cadenceOptions = this._runtimeCadenceOptions();
    }

    get state() {
        return this._state;
    }

    get lastSyncAt() {
        return this._lastSyncAt;
    }

    get currentIntervalMs() {
        return this._currentIntervalMs;
    }

    on(eventName, callback) {
        if (!this._listeners.has(eventName)) {
            this._listeners.set(eventName, new Set());
        }

        const set = this._listeners.get(eventName);
        set.add(callback);

        return () => {
            set.delete(callback);
        };
    }

    start(branchId) {
        if (this._started) {
            return;
        }

        this._started = true;
        this._branchId = branchId;
        this._lastSince = this._lastSince || new Date(this.now()).toISOString();
        this._setState(KDS_SYNC_STATE.IDLE);
        this._bindWsState();
        this._recomputeCadence('initial');
    }

    stop() {
        if (!this._started && this._state === KDS_SYNC_STATE.STOPPED) {
            return;
        }

        this._started = false;
        this._branchId = null;
        this._clearTimers();

        if (this._abortController) {
            this._abortController.abort();
            this._abortController = null;
        }

        this._wsUnsubscribers.forEach((unsubscribe) => unsubscribe && unsubscribe());
        this._wsUnsubscribers = [];
        this._setState(KDS_SYNC_STATE.STOPPED);
    }

    async forceSync() {
        if (!this.fetchFn) {
            throw new Error('No fetch implementation available.');
        }

        if (!this._lastSince) {
            this._lastSince = new Date(this.now()).toISOString();
        }

        if (this._abortController) {
            this._abortController.abort();
        }

        this._abortController = new AbortController();
        const signal = this._abortController.signal;

        try {
            const branchQuery = this._branchId !== null && this._branchId !== undefined ? `&branch_id=${encodeURIComponent(this._branchId)}` : '';
            const response = await this.fetchFn(`/api/admin/kds-order/sync?since=${encodeURIComponent(this._lastSince)}${branchQuery}&include_deleted=true`, {
                method: 'GET',
                credentials: 'same-origin',
                headers: this._authHeaders(),
                signal,
            });

            if (signal.aborted || !this._started) {
                return null;
            }

            if (!response.ok) {
                await this._handleHttpError(response);
                return null;
            }

            const payload = await response.json();
            if (signal.aborted || !this._started) {
                return null;
            }

            this._consecutive5xx = 0;
            const previousState = this._state;
            this._setState(KDS_SYNC_STATE.ACTIVE);

            if (previousState === KDS_SYNC_STATE.BACKOFF) {
                this._recomputeCadence('reset_after_200');
            }

            const gatedIds = [];
            const orders = (payload.orders || []).map((order) => {
                const version = Number(order.version || 0);
                const previousVersion = this._versionMap.get(order.id);
                const gated = previousVersion !== undefined && version <= previousVersion;

                if (gated) {
                    gatedIds.push(order.id);
                    return { ...order, versionGated: true };
                }

                this._rememberVersion(order.id, version);
                return { ...order, versionGated: false };
            });

            if (orders.filter((order) => !order.versionGated).length > 5) {
                this._highActivityUntil = this.now() + 60000;
            }

            this._lastSyncAt = payload.server_now || new Date(this.now()).toISOString();
            this._lastSince = this._lastSyncAt;

            const syncPayload = {
                orders,
                deleted_ids: payload.deleted_ids || [],
                server_now: payload.server_now,
                lastSyncAt: this._lastSyncAt,
                gatedIds,
            };

            this._emit('sync', syncPayload);
            this._recomputeCadence('post_sync');

            return payload;
        } catch (error) {
            if (error?.name === 'AbortError') {
                return null;
            }

            this._emit('error', {
                status: null,
                message: error?.message || 'Sync failed',
                willRetryInMs: Number.isFinite(this._currentIntervalMs) ? this._currentIntervalMs : 0,
            });

            // [F-03 / Lot 1.C / Audit G1 fix] Network-level errors (DNS, TLS,
            // ERR_NETWORK_CHANGED) MUST not silently halt the poll loop.
            // Re-schedule with the current cadence so the KDS self-heals once
            // connectivity returns; without this, a concurrent WS+HTTP outage
            // would leave the kitchen permanently blind.
            try {
                this._schedule();
            } catch (e) { /* defensive: never throw from catch path */ }

            throw error;
        }
    }

    _bindWsState() {
        if (!this.wsService || typeof this.wsService.on !== 'function') {
            return;
        }

        const unsubscribe = this.wsService.on('state_change', ({ from, to } = {}) => {
            this._emit('state_change', { from, to });
            this._recomputeCadence(this._reasonFromWsState(to || this.wsService.state));
        });

        this._wsUnsubscribers.push(unsubscribe);

        // [NEW-02] React to a wsService-detected reconnect storm: bypass the
        // normal state_change cycle and run a single forceSync() so the
        // kitchen does not go blind during the cool-down window. We
        // intentionally do NOT alter the cadence formula — the next scheduled
        // poll keeps the WS_DEGRADED/WS_DISCONNECTED interval, which is
        // already short enough to bridge the breaker timeout.
        //
        // [NEW-02 audit G10] When the broadcasting server restarts, every
        // KDS station receives `reconnect_storm` simultaneously. Without
        // client-side jitter, all 50+ kitchens would hit
        // /api/admin/kds-order/sync at the same instant — server-side
        // Cache::remember softens the cost but the burst still amplifies
        // during an incident. We add a 0–500ms uniform jitter to spread
        // the herd; the breaker delay is 5–30s so 500ms is negligible from
        // the user's perspective.
        const unsubscribeStorm = this.wsService.on('reconnect_storm', (payload = {}) => {
            this._emit('reconnect_storm', payload);
            const jitterMs = Math.floor(Math.random() * 500);
            const runForceSync = () => {
                try {
                    const result = this.forceSync();
                    if (result && typeof result.catch === 'function') {
                        result.catch(() => { /* swallowed: 'error' listener handles it */ });
                    }
                } catch (_) { /* defensive: never propagate from event handler */ }
            };
            if (jitterMs <= 0) {
                runForceSync();
            } else {
                setTimeout(runForceSync, jitterMs);
            }
        });

        this._wsUnsubscribers.push(unsubscribeStorm);
    }

    _reasonFromWsState(state) {
        if (state === WS_CONNECTED) {
            return 'ws_connected';
        }
        if (state === WS_RECONNECTING || state === WS_DEGRADED) {
            return 'ws_degraded';
        }
        if (state === WS_DISCONNECTED || state === WS_SESSION_INVALID) {
            return 'ws_disconnected';
        }
        return 'ws_disconnected';
    }

    _baseCadence() {
        const wsState = this.wsService?.state;
        const cfg = this._cadenceOptions;

        if (wsState === WS_CONNECTED) {
            return { interval: Infinity, reason: 'ws_connected' };
        }

        if (wsState === WS_RECONNECTING || wsState === WS_DEGRADED) {
            if (this.now() < this._highActivityUntil) {
                return { interval: cfg.highActivityBaseMs + this._jitter(cfg.highActivityJitterMs), reason: 'high_activity' };
            }
            return { interval: cfg.degradedBaseMs + this._jitter(cfg.degradedJitterMs), reason: 'ws_degraded' };
        }

        if (wsState === WS_DISCONNECTED || wsState === WS_SESSION_INVALID) {
            if (this.now() < this._highActivityUntil) {
                return { interval: cfg.highActivityBaseMs + this._jitter(cfg.highActivityJitterMs), reason: 'high_activity' };
            }
            return { interval: cfg.disconnectedBaseMs + this._jitter(cfg.disconnectedJitterMs), reason: 'ws_disconnected' };
        }

        return { interval: cfg.disconnectedBaseMs + this._jitter(cfg.disconnectedJitterMs), reason: 'ws_disconnected' };
    }

    _recomputeCadence(reasonOverride = null) {
        if (!this._started) {
            return;
        }

        const previous = this._currentIntervalMs;
        const { interval, reason } = this._baseCadence();
        const next = this._state === KDS_SYNC_STATE.BACKOFF
            ? Math.min(Number.isFinite(previous) ? previous * 2 : interval * 2, 30000)
            : interval;
        const emittedReason = this._state === KDS_SYNC_STATE.BACKOFF ? 'backoff_5xx' : (reasonOverride && reasonOverride !== 'post_sync' && reasonOverride !== 'initial' ? reasonOverride : reason);

        this._currentIntervalMs = next;
        this._schedule();

        if (previous !== next) {
            this._emit('cadence_change', {
                from: previous,
                to: next,
                reason: emittedReason,
            });
        }
    }

    _schedule() {
        this._clearTimers();

        if (!this._started) {
            return;
        }

        if (this._currentIntervalMs === Infinity) {
            this._driftTimer = setTimeout(() => {
                if (!this._started) {
                    return;
                }
                this.forceSync().catch(() => {});
                this._schedule();
            }, 60000);
            return;
        }

        this._timer = setTimeout(() => {
            if (!this._started) {
                return;
            }
            this.forceSync().catch(() => {});
        }, this._currentIntervalMs);
    }

    async _handleHttpError(response) {
        const is5xx = response.status >= 500 && response.status <= 599;

        if (is5xx) {
            this._consecutive5xx += 1;
            this._setState(KDS_SYNC_STATE.BACKOFF);
            const previous = this._currentIntervalMs;
            const base = Number.isFinite(previous) ? previous : this._baseCadence().interval;
            this._currentIntervalMs = Math.min(base * 2, 30000);
            this._schedule();
            this._emit('cadence_change', {
                from: previous,
                to: this._currentIntervalMs,
                reason: 'backoff_5xx',
            });
        }

        this._emit('error', {
            status: response.status,
            message: `KDS sync failed with status ${response.status}`,
            willRetryInMs: Number.isFinite(this._currentIntervalMs) ? this._currentIntervalMs : 0,
        });
    }

    _rememberVersion(id, version) {
        if (!this._versionMap.has(id)) {
            this._versionOrder.push(id);
        }

        this._versionMap.set(id, version);

        while (this._versionOrder.length > this._maxVersionEntries) {
            const oldestId = this._versionOrder.shift();
            if (oldestId !== undefined) {
                this._versionMap.delete(oldestId);
            }
        }
    }

    _setState(next) {
        this._state = next;
    }

    _authHeaders() {
        const headers = {
            Accept: 'application/json',
            'x-api-key': ENV.API_KEY,
        };

        try {
            const vuex = JSON.parse(localStorage.getItem('vuex') || '{}');
            const token = vuex.kioskCart?.kioskToken || vuex.auth?.authToken || '';
            const language = vuex.globalState?.lists?.language_code || null;

            if (token) {
                headers.Authorization = `Bearer ${token}`;
            }
            if (language) {
                headers['x-localization'] = language;
                headers['Accept-Language'] = language;
            }
        } catch (_) { /* corrupted localStorage falls back to unauthenticated headers */ }

        return headers;
    }

    _clearTimers() {
        if (this._timer) {
            clearTimeout(this._timer);
            this._timer = null;
        }

        if (this._driftTimer) {
            clearTimeout(this._driftTimer);
            this._driftTimer = null;
        }
    }

    _emit(eventName, payload) {
        const listeners = this._listeners.get(eventName);
        if (!listeners) {
            return;
        }

        listeners.forEach((callback) => callback(payload));
    }

    _jitter(max) {
        return Math.floor(Math.random() * (max + 1));
    }

    _runtimeCadenceOptions() {
        const cfg = (typeof window !== 'undefined' && window.foodkingConfig?.kdsFallbackPolling)
            ? window.foodkingConfig.kdsFallbackPolling
            : {};

        const toInt = (value, fallback) => {
            const parsed = parseInt(value, 10);
            return Number.isFinite(parsed) && parsed >= 0 ? parsed : fallback;
        };

        return {
            highActivityBaseMs: toInt(cfg.highActivityBaseMs, DEFAULT_CADENCE_OPTIONS.highActivityBaseMs),
            highActivityJitterMs: toInt(cfg.highActivityJitterMs, DEFAULT_CADENCE_OPTIONS.highActivityJitterMs),
            degradedBaseMs: toInt(cfg.degradedBaseMs, DEFAULT_CADENCE_OPTIONS.degradedBaseMs),
            degradedJitterMs: toInt(cfg.degradedJitterMs, DEFAULT_CADENCE_OPTIONS.degradedJitterMs),
            disconnectedBaseMs: toInt(cfg.disconnectedBaseMs, DEFAULT_CADENCE_OPTIONS.disconnectedBaseMs),
            disconnectedJitterMs: toInt(cfg.disconnectedJitterMs, DEFAULT_CADENCE_OPTIONS.disconnectedJitterMs),
        };
    }
}

const kdsSyncService = new KdsSyncService();

export default kdsSyncService;
