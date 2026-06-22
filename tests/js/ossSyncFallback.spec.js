import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { OssSyncService, STATE, DEFAULTS } from '../../resources/js/services/OssSyncService.js';

function makeWsService(initialState = 'connected') {
    const listeners = new Map();
    return {
        state: initialState,
        getState() {
            return this.state;
        },
        on(event, handler) {
            if (!listeners.has(event)) {
                listeners.set(event, new Set());
            }
            listeners.get(event).add(handler);
            return () => listeners.get(event)?.delete(handler);
        },
        emit(event, payload = {}) {
            if (event === 'connected') this.state = 'connected';
            if (event === 'disconnected' || event === 'reconnect_storm') this.state = 'disconnected';
            if (event === 'state_change') this.state = payload.current || payload.to || this.state;
            (listeners.get(event) || new Set()).forEach((handler) => handler(payload));
        },
    };
}

function setOssFallbackFlag(overrides = {}) {
    window.foodkingConfig = {
        ossFallbackPolling: {
            enabled: true,
            intervalMsWhenConnected: DEFAULTS.intervalMsWhenConnected,
            intervalMsWhenDisconnected: DEFAULTS.intervalMsWhenDisconnected,
            ...overrides,
        },
    };
}

function makeStore(dispatchImpl = () => Promise.resolve({ status: 200, data: { data: [] } })) {
    return {
        dispatch: vi.fn(dispatchImpl),
    };
}

describe('OssSyncService fallback polling', () => {
    let service;
    let randomSpy;

    beforeEach(() => {
        vi.useFakeTimers();
        randomSpy = vi.spyOn(Math, 'random').mockReturnValue(0);
        service = new OssSyncService();
    });

    afterEach(() => {
        service.stop();
        randomSpy.mockRestore();
        vi.clearAllTimers();
        vi.useRealTimers();
        delete window.foodkingConfig;
    });

    it('polls with connected cadence when websocket is healthy', async () => {
        setOssFallbackFlag();
        const store = makeStore();
        const ws = makeWsService('connected');

        service.start({
            store,
            webSocketService: ws,
            options: { jitterMaxMs: 0 },
        });

        await vi.advanceTimersByTimeAsync(0);
        expect(service.state()).toBe(STATE.POLLING);
        expect(store.dispatch).toHaveBeenCalledTimes(1);
        expect(store.dispatch).toHaveBeenCalledWith('orderStatusScreenOrder/lists');
        expect(service._lastScheduledDelayMs).toBe(DEFAULTS.intervalMsWhenConnected);
    });

    it('[TRAP-4] public/unauth wall override drives effective connected cadence to <=5s', async () => {
        // Production injects intervalMsWhenConnected=60_000 via window.foodkingConfig
        // (master.blade.php → config/catalog_v15.php). The public wall (branchId<=0)
        // is poll-only — it never joins the private branch channel — yet the WS
        // transport reports 'connected', so without the override it would schedule
        // the 60s cadence and lag PRÉPARATION→PRÊT by ~1 min. The component passes
        // options.intervalMsWhenConnected=5_000 for this surface; assert the
        // EFFECTIVE scheduled delay (not a bare constant) is <=5s.
        setOssFallbackFlag({ intervalMsWhenConnected: 60_000 });
        const store = makeStore();
        const ws = makeWsService('connected');

        service.start({
            store,
            webSocketService: ws,
            options: { jitterMaxMs: 0, intervalMsWhenConnected: 5_000 },
        });

        await vi.advanceTimersByTimeAsync(0);
        expect(service.state()).toBe(STATE.POLLING);
        expect(service._lastScheduledDelayMs).toBeLessThanOrEqual(5_000);
        expect(service._lastScheduledDelayMs).toBe(5_000);
    });

    it('switches to disconnected cadence when websocket drops', async () => {
        setOssFallbackFlag();
        const store = makeStore();
        const ws = makeWsService('connected');

        service.start({
            store,
            webSocketService: ws,
            options: { jitterMaxMs: 0 },
        });

        await vi.advanceTimersByTimeAsync(0);
        ws.emit('disconnected');

        expect(service.state()).toBe(STATE.POLLING);
        expect(service._lastScheduledDelayMs).toBe(DEFAULTS.intervalMsWhenDisconnected);
    });

    it('uses 5xx backoff doubling capped at 30s', async () => {
        setOssFallbackFlag();
        const store = makeStore(() => Promise.reject({ response: { status: 503 } }));
        const ws = makeWsService('disconnected');

        service.start({
            store,
            webSocketService: ws,
            options: { jitterMaxMs: 0 },
        });

        await vi.advanceTimersByTimeAsync(0);
        expect(service.state()).toBe(STATE.BACKOFF);
        expect(service._lastScheduledDelayMs).toBe(DEFAULTS.backoffStartMs);

        await vi.advanceTimersByTimeAsync(DEFAULTS.backoffStartMs);
        expect(store.dispatch).toHaveBeenCalledTimes(2);
        expect(service._lastScheduledDelayMs).toBe(10_000);

        await vi.advanceTimersByTimeAsync(10_000);
        expect(store.dispatch).toHaveBeenCalledTimes(3);
        expect(service._lastScheduledDelayMs).toBe(20_000);

        await vi.advanceTimersByTimeAsync(20_000);
        expect(store.dispatch).toHaveBeenCalledTimes(4);
        expect(service._lastScheduledDelayMs).toBe(30_000);
    });
});
