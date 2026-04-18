/**
 * WebSocketService — Heartbeat, reconnection, and fallback coordination for Laravel Echo / Pusher.
 *
 * Responsibilities:
 * - Monitor Pusher connection state via state_change events
 * - Expose reactive connection status for UI banners (KDS, OSS, POS)
 * - Emit events so components can adapt polling interval
 * - Log state transitions for debugging
 *
 * Usage:
 *   import { wsService } from '@/services/WebSocketService';
 *   wsService.on('connected', () => { ... });
 *   wsService.on('disconnected', () => { ... });
 *   wsService.isConnected();
 */

const HEARTBEAT_INTERVAL_MS = 30000;
const MAX_RECONNECT_DELAY_MS = 30000;

const STATE = Object.freeze({
    INITIALIZED: 'initialized',
    CONNECTING: 'connecting',
    CONNECTED: 'connected',
    DISCONNECTED: 'disconnected',
    UNAVAILABLE: 'unavailable',
    FAILED: 'failed',
});

class WebSocketService {
    constructor() {
        this._state = STATE.INITIALIZED;
        this._listeners = {};
        this._heartbeatTimer = null;
        this._lastPongAt = 0;
        this._reconnectAttempts = 0;
        this._bound = false;
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
            console.log(`[WS] ${previous} → ${current}`);

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

    on(event, fn) {
        if (!this._listeners[event]) this._listeners[event] = [];
        this._listeners[event].push(fn);
    }

    off(event, fn) {
        if (!this._listeners[event]) return;
        this._listeners[event] = this._listeners[event].filter(f => f !== fn);
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
        this._emit('state_change', { previous: prev, current: newState });
        if (newState === STATE.CONNECTED) this._emit('connected');
        if (newState === STATE.DISCONNECTED || newState === STATE.UNAVAILABLE || newState === STATE.FAILED) {
            this._emit('disconnected', { state: newState });
        }
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
    }
}

export const wsService = new WebSocketService();
export { STATE as WS_STATE };
export default wsService;
