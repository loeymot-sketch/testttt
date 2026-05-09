/**
 * WebSocketService — Heartbeat, reconnection, and fallback coordination for Laravel Echo / Pusher.
 *
 * Responsibilities:
 * - Monitor Pusher connection state via state_change events
 * - Expose reactive connection status for UI banners (KDS, OSS, POS)
 * - Emit events so components can adapt polling interval
 * - [F-12] Surface auth/subscription errors and detect token expiration via
 *   a sliding-window failure counter; promote to SESSION_INVALID after threshold.
 * - Log state transitions for debugging
 *
 * Usage:
 *   import { wsService } from '@/services/WebSocketService';
 *   wsService.on('connected', () => { ... });
 *   wsService.on('disconnected', () => { ... });
 *   wsService.on('auth_error', (payload) => { ... });
 *   wsService.on('session_invalid', () => { ... });
 *   wsService.isConnected();
 */

const HEARTBEAT_INTERVAL_MS = 30000;
const MAX_RECONNECT_DELAY_MS = 30000;

// [F-12] Sliding-window auth failure detection.
const AUTH_FAILURE_WINDOW_MS = 60000;
const AUTH_FAILURE_THRESHOLD = 3;

// [NEW-02] Reconnect-storm circuit breaker.
// 4 disconnects within 30s → open the breaker (decorrelated jitter delay
// 5–30s), forcibly close the Pusher connection during the cool-down, then
// reconnect once. Mitigates the "thundering herd" when the broadcasting
// server restarts and a fleet of clients re-converges in lockstep.
export const STORM_DETECTION_WINDOW_MS = 30_000;
export const STORM_DETECTION_THRESHOLD = 4;
export const STORM_MIN_DELAY_MS = 5_000;
export const STORM_MAX_DELAY_MS = 30_000;

const STATE = Object.freeze({
    INITIALIZED: 'initialized',
    CONNECTING: 'connecting',
    CONNECTED: 'connected',
    DISCONNECTED: 'disconnected',
    UNAVAILABLE: 'unavailable',
    FAILED: 'failed',
    SESSION_INVALID: 'session_invalid',
});

class WebSocketService {
    constructor() {
        this._state = STATE.INITIALIZED;
        this._listeners = {};
        this._heartbeatTimer = null;
        this._lastPongAt = 0;
        this._reconnectAttempts = 0;
        this._bound = false;
        this._authFailureTimestamps = [];
        this._sessionInvalidEmitted = false;
        // [NEW-02] Reconnect-storm fields. Independent from F-12 auth failures.
        this._disconnectTimestamps = [];
        this._circuitBreakerOpen = false;
        this._stormReconnectTimer = null;
        this._lastStormDelayMs = STORM_MIN_DELAY_MS;
    }

    /**
     * Bind to the Pusher connection after Echo is initialized.
     * Safe to call multiple times — only binds once.
     */
    start() {
        if (this._bound) return;
        const pusher = window.Echo?.connector?.pusher;
        if (!pusher) {
            this._setState(STATE.UNAVAILABLE);
            console.warn('[WS] Echo/Pusher not available — fallback polling will be used.');
            return;
        }

        this._bound = true;
        const conn = pusher.connection;

        conn.bind('state_change', ({ previous, current }) => {
            switch (current) {
                case 'connected':
                    this._reconnectAttempts = 0;
                    this._lastPongAt = Date.now();
                    this._setState(STATE.CONNECTED);
                    this._startHeartbeat(conn);
                    break;
                case 'connecting':
                    this._setState(STATE.CONNECTING);
                    break;
                case 'disconnected':
                    this._setState(STATE.DISCONNECTED);
                    this._stopHeartbeat();
                    break;
                case 'unavailable':
                case 'failed':
                    this._setState(STATE.UNAVAILABLE);
                    this._stopHeartbeat();
                    break;
            }
        });

        conn.bind('connected', () => {
            this._lastPongAt = Date.now();
        });

        if (conn.state === 'connected') {
            this._reconnectAttempts = 0;
            this._lastPongAt = Date.now();
            this._setState(STATE.CONNECTED);
            this._startHeartbeat(conn);
        } else if (conn.state === 'connecting') {
            this._setState(STATE.CONNECTING);
        }
    }

    isConnected() {
        return this._state === STATE.CONNECTED;
    }

    getState() {
        return this._state;
    }

    /**
     * Subscribe to a wsService event.
     *
     * [NEW-02 audit G7] Returns an unsubscribe function. KdsSyncService and
     * other consumers MUST capture the return value and call it on stop()/
     * unmount; otherwise the singleton wsService accumulates listeners across
     * start/stop cycles and forceSync()/state_change handlers fire N times.
     */
    on(event, fn) {
        if (!this._listeners[event]) this._listeners[event] = [];
        this._listeners[event].push(fn);
        return () => this.off(event, fn);
    }

    off(event, fn) {
        if (!this._listeners[event]) return;
        this._listeners[event] = this._listeners[event].filter(f => f !== fn);
    }

    /**
     * [F-12] Convenience subscriber for auth-error stream.
     *
     * [NEW-02 audit-2 A2] Returns the unsubscribe handle from on() so callers
     * can clean up (otherwise the singleton wsService accumulates listeners).
     */
    onAuthError(fn) {
        return this.on('auth_error', fn);
    }

    /**
     * [F-12] Sliding-window count of recent auth failures (last 60s).
     */
    get authFailureCount() {
        this._pruneAuthFailures();
        return this._authFailureTimestamps.length;
    }

    /**
     * [F-12] Public hook called by bootstrap.js when Echo/Pusher emits
     * a `pusher:subscription_error` (or any subscription-level auth failure).
     *
     * Behavior:
     *   - Always emits 'auth_error' with the original payload.
     *   - After AUTH_FAILURE_THRESHOLD failures within AUTH_FAILURE_WINDOW_MS,
     *     transitions to SESSION_INVALID exactly once and emits 'session_invalid'.
     *   - A successful 'connected' state transition resets the counter
     *     (see _setState).
     */
    handleSubscriptionError(payload) {
        const now = Date.now();
        this._authFailureTimestamps.push(now);
        this._pruneAuthFailures(now);
        this._emit('auth_error', payload);

        // [NEW-04] Non-blocking observability emit. MetricsBatcher subscribes
        // to 'observability_metric' and silently drops non-whitelisted types,
        // so this does NOT pollute the client client-metrics endpoint payload.
        // Emitted BEFORE the SESSION_INVALID promotion so a downstream
        // listener still sees the failure even when the threshold trips.
        this._emit('observability_metric', { type: 'ws.auth_failure', value: 1 });

        if (
            this._authFailureTimestamps.length >= AUTH_FAILURE_THRESHOLD &&
            this._state !== STATE.SESSION_INVALID
        ) {
            this._setState(STATE.SESSION_INVALID);
        }
    }

    _emit(event, data) {
        (this._listeners[event] || []).forEach(fn => {
            try { fn(data); } catch (e) { console.error('[WS] listener error:', e); }
        });
    }

    _setState(newState) {
        if (this._state === newState) return;
        const prev = this._state;
        this._state = newState;

        // [NEW-02 audit G2] Bookkeeping FIRST, side-effecting emissions LAST.
        // Rationale: a synchronous listener on 'state_change' could re-enter
        // _setState(CONNECTED) before our original DISCONNECTED branch runs,
        // leaving a stale storm timestamp recorded after a reset. By updating
        // internal counters before notifying outside listeners, the wsService
        // stays in a consistent state regardless of what listeners do.
        if (newState === STATE.CONNECTED) {
            this._resetAuthFailures();
            this._resetReconnectStormState();
        }
        if (newState === STATE.DISCONNECTED || newState === STATE.UNAVAILABLE || newState === STATE.FAILED) {
            // FAILED is included (vs the original spec which only mentioned
            // DISCONNECTED/UNAVAILABLE) because Pusher escalates to FAILED after
            // its own internal retries — that signal is part of the herd pattern.
            this._recordDisconnectForStormDetection(Date.now());
        }
        let shouldEmitSessionInvalid = false;
        if (newState === STATE.SESSION_INVALID && !this._sessionInvalidEmitted) {
            this._sessionInvalidEmitted = true;
            shouldEmitSessionInvalid = true;
            // [NEW-02 audit-2 A1] Defense-in-depth: cancel any pending storm
            // reconnect timer when the session is invalidated so we don't even
            // queue a doomed pusher.connect(). The timer-fire callback also
            // checks SESSION_INVALID, so this is belt-and-suspenders.
            this._resetReconnectStormState();
        }

        this._emit('state_change', { previous: prev, current: newState });
        if (newState === STATE.CONNECTED) {
            this._emit('connected');
        }
        if (newState === STATE.DISCONNECTED || newState === STATE.UNAVAILABLE || newState === STATE.FAILED) {
            this._emit('disconnected', { state: newState });
        }
        if (shouldEmitSessionInvalid) {
            this._emit('session_invalid');
        }
    }

    // ---------------------------------------------------------------------
    // [NEW-02] Reconnect-storm detection + decorrelated-jitter circuit breaker.
    // Independent from F-12 auth-failure logic above. Do not merge counters.
    // ---------------------------------------------------------------------

    isCircuitBreakerOpen() {
        return this._circuitBreakerOpen === true;
    }

    getDisconnectAttemptsInWindow() {
        this._pruneDisconnectStormWindow(Date.now());
        return this._disconnectTimestamps.length;
    }

    _pruneDisconnectStormWindow(now = Date.now()) {
        const cutoff = now - STORM_DETECTION_WINDOW_MS;
        this._disconnectTimestamps = this._disconnectTimestamps.filter((ts) => ts >= cutoff);
    }

    _recordDisconnectForStormDetection(now = Date.now()) {
        this._disconnectTimestamps.push(now);
        this._pruneDisconnectStormWindow(now);

        if (this._circuitBreakerOpen) {
            return;
        }

        if (this._disconnectTimestamps.length >= STORM_DETECTION_THRESHOLD) {
            this._openReconnectStormCircuitBreaker(now);
        }
    }

    _computeReconnectStormDelay() {
        // AWS-style decorrelated jitter: delay = min(MAX, rand(MIN, max(MIN, last*3))).
        // Bounded so a long-lasting outage cannot stretch the cool-down indefinitely.
        const upperBound = Math.min(STORM_MAX_DELAY_MS, Math.max(STORM_MIN_DELAY_MS, this._lastStormDelayMs * 3));
        const range = upperBound - STORM_MIN_DELAY_MS;
        const random = STORM_MIN_DELAY_MS + (Math.random() * (range > 0 ? range : 0));
        const delay = Math.min(STORM_MAX_DELAY_MS, Math.max(STORM_MIN_DELAY_MS, Math.round(random)));
        this._lastStormDelayMs = delay;
        return delay;
    }

    _getPusherConnection() {
        return (typeof window !== 'undefined' && window.Echo && window.Echo.connector && window.Echo.connector.pusher)
            ? window.Echo.connector.pusher
            : null;
    }

    _openReconnectStormCircuitBreaker(now = Date.now()) {
        if (this._circuitBreakerOpen) return;

        // [NEW-02 audit-2 A3] Snapshot the count BEFORE pusher.disconnect()
        // because Pusher may emit a synchronous 'disconnected' state_change
        // that re-enters _recordDisconnectForStormDetection() and bumps
        // _disconnectTimestamps.length to 5+. The reported attempts must match
        // the threshold semantics (exactly THRESHOLD at the moment the breaker
        // was triggered).
        const attemptsSnapshot = this._disconnectTimestamps.length;

        this._circuitBreakerOpen = true;

        const pusher = this._getPusherConnection();
        if (pusher && typeof pusher.disconnect === 'function') {
            try { pusher.disconnect(); } catch (_) { /* defensive */ }
        }

        const delay = this._computeReconnectStormDelay();
        const nextReconnectAt = now + delay;

        if (this._stormReconnectTimer) {
            clearTimeout(this._stormReconnectTimer);
        }

        this._stormReconnectTimer = setTimeout(() => {
            this._stormReconnectTimer = null;
            // [NEW-02 audit-2 A1] If F-12 promoted us to SESSION_INVALID during
            // the cool-down, do NOT call pusher.connect(): Pusher would attempt
            // an auth that is guaranteed to fail (token already revoked) and
            // we'd silently spin. The breaker still self-clears so a future
            // session-recovery flow (re-login) can re-open the connection.
            if (this._state !== STATE.SESSION_INVALID) {
                const reconnectPusher = this._getPusherConnection();
                if (reconnectPusher && typeof reconnectPusher.connect === 'function') {
                    try { reconnectPusher.connect(); } catch (_) { /* defensive */ }
                }
            }
            // [NEW-02 audit G6] Clear the storm timestamp window when the timer
            // fires so the next breaker requires 4 *fresh* disconnects, not
            // residual ones from the previous storm. Without this, an already-
            // saturated window (4+ entries within 30s) could re-open the breaker
            // after a SINGLE post-timer disconnect — which is too aggressive
            // and breaks the documented "4 disconnects per cycle" contract.
            this._disconnectTimestamps = [];
            this._circuitBreakerOpen = false;
        }, delay);

        // [NEW-02 audit-2 A5] reconnect_storm is emitted from inside
        // _setState's bookkeeping phase (before _emit('state_change')).
        // Subscribers therefore see this event BEFORE the corresponding
        // state_change for the 4th disconnect — intentional, so polling
        // fallbacks (KdsSyncService) can engage immediately.
        this._emit('reconnect_storm', {
            delay_ms: delay,
            attempts_in_window: attemptsSnapshot,
            next_reconnect_at: nextReconnectAt,
        });
    }

    _resetReconnectStormState({ clearDelay = false } = {}) {
        this._disconnectTimestamps = [];
        this._circuitBreakerOpen = false;
        if (this._stormReconnectTimer) {
            clearTimeout(this._stormReconnectTimer);
            this._stormReconnectTimer = null;
        }
        if (clearDelay) {
            this._lastStormDelayMs = STORM_MIN_DELAY_MS;
        }
    }

    _pruneAuthFailures(now = Date.now()) {
        this._authFailureTimestamps = this._authFailureTimestamps.filter(
            ts => now - ts <= AUTH_FAILURE_WINDOW_MS
        );
    }

    _resetAuthFailures() {
        this._authFailureTimestamps = [];
        this._sessionInvalidEmitted = false;
    }

    _startHeartbeat(conn) {
        this._stopHeartbeat();
        this._heartbeatTimer = setInterval(() => {
            if (conn.state !== 'connected') {
                this._stopHeartbeat();
                return;
            }
            this._lastPongAt = Date.now();
        }, HEARTBEAT_INTERVAL_MS);
    }

    _stopHeartbeat() {
        if (this._heartbeatTimer) {
            clearInterval(this._heartbeatTimer);
            this._heartbeatTimer = null;
        }
    }

    destroy() {
        this._stopHeartbeat();
        this._listeners = {};
        this._bound = false;
        this._resetAuthFailures();
        // [NEW-02] Clear storm timer + reset breaker; pass clearDelay so a
        // fresh service instance starts with the floor delay.
        this._resetReconnectStormState({ clearDelay: true });
    }
}

export const wsService = new WebSocketService();
export { STATE as WS_STATE, WebSocketService };
export default wsService;
