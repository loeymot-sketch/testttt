/**
 * F-03 / NEW-02 / NEW-04
 * Authority: plans/MEGA_PLAN_SYNC_HARDENING_v3_2026-04-23.md
 */

import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { KdsSyncService } from '../../resources/js/services/KdsSyncService';

function makeWsService(initialState = 'CONNECTED') {
    const listeners = new Map();
    return {
        state: initialState,
        on(event, cb) {
            if (!listeners.has(event)) listeners.set(event, new Set());
            listeners.get(event).add(cb);
            return () => listeners.get(event).delete(cb);
        },
        emit(event, payload) {
            (listeners.get(event) || new Set()).forEach((cb) => cb(payload));
        },
    };
}

describe('KdsSyncService cadence', () => {
    beforeEach(() => {
        vi.useFakeTimers();
        vi.spyOn(Math, 'random').mockReturnValue(0);
    });

    afterEach(() => {
        vi.restoreAllMocks();
        vi.useRealTimers();
        delete window.foodkingConfig;
    });

    it('pauses polling when ws is CONNECTED and resumes near 10s when DISCONNECTED', () => {
        const ws = makeWsService('CONNECTED');
        const service = new KdsSyncService({ wsService: ws, fetchFn: vi.fn() });

        service.start(1);
        expect(service.currentIntervalMs).toBe(Infinity);

        ws.state = 'DISCONNECTED';
        ws.emit('state_change', { from: 'CONNECTED', to: 'DISCONNECTED' });

        expect(service.currentIntervalMs).toBeGreaterThanOrEqual(10000);
        expect(service.currentIntervalMs).toBeLessThanOrEqual(13000);
    });

    it('emits cadence_change with ws_degraded on CONNECTED to RECONNECTING', () => {
        const ws = makeWsService('CONNECTED');
        const service = new KdsSyncService({ wsService: ws, fetchFn: vi.fn() });
        const spy = vi.fn();

        service.on('cadence_change', spy);
        service.start(1);

        ws.state = 'RECONNECTING';
        ws.emit('state_change', { from: 'CONNECTED', to: 'RECONNECTING' });

        expect(spy).toHaveBeenCalled();
        expect(spy.mock.calls.at(-1)[0].reason).toBe('ws_degraded');
    });

    it('uses runtime kdsFallbackPolling overrides when provided', () => {
        window.foodkingConfig = {
            kdsFallbackPolling: {
                degradedBaseMs: 7000,
                degradedJitterMs: 0,
                disconnectedBaseMs: 12000,
                disconnectedJitterMs: 0,
                highActivityBaseMs: 4000,
                highActivityJitterMs: 0,
            },
        };

        const ws = makeWsService('DISCONNECTED');
        const service = new KdsSyncService({ wsService: ws, fetchFn: vi.fn() });
        service.start(1);

        expect(service.currentIntervalMs).toBe(12000);

        ws.state = 'RECONNECTING';
        ws.emit('state_change', { from: 'DISCONNECTED', to: 'RECONNECTING' });
        expect(service.currentIntervalMs).toBe(7000);
    });
});
