/**
 * [KDS-01 FIX] The WS-aware cadence ladder must read the REAL WebSocketService
 * surface: getState() returning LOWERCASE vocabulary ('connected',
 * 'disconnected', 'connecting', 'unavailable', 'failed', 'session_invalid'),
 * and state_change events carrying { previous, current } — NOT a public
 * `.state` property and NOT { from, to }.
 *
 * Before the fix, _baseCadence() read `this.wsService?.state` (undefined on the
 * real service) and the handler destructured `{ from, to }` (both undefined on
 * the real payload) → the connected/degraded/disconnected ladder was DEAD code:
 * the poller always ran the disconnected cadence and never paused when WS was up.
 *
 * These mocks intentionally expose ONLY the real interface (no `.state`,
 * lowercase getState, { previous, current } events) so they fail against the
 * old code and pass only when the accessor + payload field are fixed. The
 * legacy uppercase-`.state` mocks in kdsSyncCadence.spec.js are kept for
 * back-compat but prove nothing about this bug.
 *
 * Source defect: reports/test-e2e/all-systems-2026-06-06/WAVE1_POS_AUDIT_FINDINGS.md
 * (KDS-01 P3). WebSocketService surface: getState() ~:122, isConnected() ~:118,
 * STATE values lowercase ~:38-46, state_change emits { previous, current } ~:233.
 */

import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { KdsSyncService } from '../../resources/js/services/KdsSyncService';

/**
 * Faithful stand-in for the production WebSocketService:
 *   - getState() returns the LOWERCASE STATE value (no `.state` property).
 *   - on('state_change', cb) delivers { previous, current } (NOT { from, to }).
 */
function makeRealWsService(initialState = 'connected') {
    const listeners = new Map();
    return {
        _state: initialState,
        getState() { return this._state; },
        isConnected() { return this._state === 'connected'; },
        on(event, cb) {
            if (!listeners.has(event)) listeners.set(event, new Set());
            listeners.get(event).add(cb);
            return () => listeners.get(event).delete(cb);
        },
        // Helper to drive a real-shaped transition.
        transitionTo(next) {
            const previous = this._state;
            this._state = next;
            (listeners.get('state_change') || new Set()).forEach((cb) => cb({ previous, current: next }));
        },
    };
}

describe('KdsSyncService — KDS-01 reads the REAL transport surface', () => {
    beforeEach(() => {
        vi.useFakeTimers();
        vi.spyOn(Math, 'random').mockReturnValue(0);
    });

    afterEach(() => {
        vi.restoreAllMocks();
        vi.useRealTimers();
        delete window.foodkingConfig;
    });

    it('pauses polling (Infinity) when the real transport reports connected', () => {
        const ws = makeRealWsService('connected');
        const service = new KdsSyncService({ wsService: ws, fetchFn: vi.fn() });

        service.start(1);

        // Connected → live push handles freshness → poller idles.
        expect(service.currentIntervalMs).toBe(Infinity);
    });

    it('polls ~10s when the real transport reports disconnected', () => {
        const ws = makeRealWsService('disconnected');
        const service = new KdsSyncService({ wsService: ws, fetchFn: vi.fn() });

        service.start(1);

        expect(service.currentIntervalMs).toBeGreaterThanOrEqual(10000);
        expect(service.currentIntervalMs).toBeLessThanOrEqual(13000);
    });

    it('uses the degraded (~5s) cadence when the real transport is connecting', () => {
        const ws = makeRealWsService('connecting');
        const service = new KdsSyncService({ wsService: ws, fetchFn: vi.fn() });

        service.start(1);

        // 'connecting' maps to the degraded tier (base 5000, jitter 0 here).
        expect(service.currentIntervalMs).toBe(5000);
    });

    it('reacts to a real { previous, current } state_change: connected pauses, then disconnected resumes', () => {
        const ws = makeRealWsService('connected');
        const service = new KdsSyncService({ wsService: ws, fetchFn: vi.fn() });

        service.start(1);
        expect(service.currentIntervalMs).toBe(Infinity);

        ws.transitionTo('disconnected');

        expect(service.currentIntervalMs).toBeGreaterThanOrEqual(10000);
        expect(service.currentIntervalMs).toBeLessThanOrEqual(13000);
    });

    it('emits cadence_change with a ws_* reason driven by the real `current` field', () => {
        const ws = makeRealWsService('connected');
        const service = new KdsSyncService({ wsService: ws, fetchFn: vi.fn() });
        const spy = vi.fn();

        service.on('cadence_change', spy);
        service.start(1);

        ws.transitionTo('unavailable'); // real Pusher vocabulary → disconnected tier

        expect(spy).toHaveBeenCalled();
        const last = spy.mock.calls.at(-1)[0];
        expect(last.reason).toBe('ws_disconnected');
        expect(last.to).toBeGreaterThanOrEqual(10000);
    });

    it('resumes pausing when the real transport reconnects (connected again)', () => {
        const ws = makeRealWsService('disconnected');
        const service = new KdsSyncService({ wsService: ws, fetchFn: vi.fn() });

        service.start(1);
        expect(service.currentIntervalMs).toBeGreaterThanOrEqual(10000);

        ws.transitionTo('connected');

        expect(service.currentIntervalMs).toBe(Infinity);
    });
});
