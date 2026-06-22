import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { PosSyncService, STATE, DEFAULTS } from '../../resources/js/services/PosSyncService.js';

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

function setPosFallbackFlag(enabled, overrides = {}) {
    window.foodkingConfig = {
        posFallbackPolling: {
            enabled,
            intervalMsWhenDisconnected: DEFAULTS.intervalMsWhenDisconnected,
            ...overrides,
        },
    };
}

function makeStore(dispatchImpl = () => Promise.resolve({ status: 200 })) {
    return {
        dispatch: vi.fn(dispatchImpl),
        commit: vi.fn(),
    };
}

function startService({ service, store, ws, options = {} }) {
    service.start({
        branchId: 42,
        store,
        axios: { get: vi.fn() },
        webSocketService: ws,
        options: {
            jitterMaxMs: 0,
            ...options,
        },
    });
}

describe('PosSyncService fallback polling', () => {
    let service;
    let randomSpy;

    beforeEach(() => {
        vi.useFakeTimers();
        randomSpy = vi.spyOn(Math, 'random').mockReturnValue(0);
        service = new PosSyncService();
    });

    afterEach(() => {
        service.stop();
        randomSpy.mockRestore();
        vi.clearAllTimers();
        vi.useRealTimers();
        delete window.foodkingConfig;
    });

    it('case 1 - flag off leaves the service idle and schedules no poll', () => {
        setPosFallbackFlag(false);
        const infoSpy = vi.spyOn(console, 'info').mockImplementation(() => {});
        const store = makeStore();
        const ws = makeWsService('disconnected');

        startService({ service, store, ws });

        expect(service.state()).toBe(STATE.IDLE);
        expect(store.dispatch).not.toHaveBeenCalled();
        expect(service._timer).toBeNull();
        expect(infoSpy).toHaveBeenCalledWith('[PosSyncService] fallback polling disabled.');

        infoSpy.mockRestore();
    });

    it('case 2 - disconnected Echo state starts polling after the jitter window', async () => {
        setPosFallbackFlag(true);
        const store = makeStore();
        const ws = makeWsService('connected');

        startService({ service, store, ws });
        ws.emit('disconnected');

        await vi.advanceTimersByTimeAsync(0);

        expect(service.state()).toBe(STATE.POLLING);
        expect(store.dispatch).toHaveBeenCalledTimes(1);
        expect(store.dispatch).toHaveBeenCalledWith('item/lists', expect.objectContaining({
            surface: 'pos',
            branch_id: 42,
            force: true,
            overlay: false,
        }));
    });

    it('case 3 - connected Echo state suspends polling and prevents follow-up polls', async () => {
        setPosFallbackFlag(true);
        const store = makeStore();
        const ws = makeWsService('connected');

        startService({ service, store, ws });
        ws.emit('disconnected');
        await vi.advanceTimersByTimeAsync(0);

        ws.emit('connected');
        await vi.advanceTimersByTimeAsync(DEFAULTS.intervalMsWhenDisconnected * 2);

        expect(service.state()).toBe(STATE.IDLE);
        expect(store.dispatch).toHaveBeenCalledTimes(1);
        expect(service._timer).toBeNull();
    });

    it('case 4 - 5xx responses double backoff and cap at 30s', async () => {
        setPosFallbackFlag(true);
        const store = makeStore(() => Promise.reject({ response: { status: 503 } }));
        const ws = makeWsService('connected');

        startService({ service, store, ws });
        ws.emit('disconnected');

        await vi.advanceTimersByTimeAsync(0);
        expect(store.dispatch).toHaveBeenCalledTimes(1);
        expect(service.state()).toBe(STATE.BACKOFF);
        expect(service._lastScheduledDelayMs).toBe(5000);

        await vi.advanceTimersByTimeAsync(5000);
        expect(store.dispatch).toHaveBeenCalledTimes(2);
        expect(service._lastScheduledDelayMs).toBe(10000);

        await vi.advanceTimersByTimeAsync(10000);
        expect(store.dispatch).toHaveBeenCalledTimes(3);
        expect(service._lastScheduledDelayMs).toBe(20000);

        await vi.advanceTimersByTimeAsync(20000);
        expect(store.dispatch).toHaveBeenCalledTimes(4);
        expect(service._lastScheduledDelayMs).toBe(30000);
        expect(service._currentBackoffMs).toBe(DEFAULTS.backoffCapMs);
    });

    it('case 5 - overlapping disconnected/connected/disconnected aborts the prior request', async () => {
        setPosFallbackFlag(true);
        const store = makeStore(() => new Promise(() => {}));
        const ws = makeWsService('connected');

        startService({ service, store, ws });
        ws.emit('disconnected');
        await vi.advanceTimersByTimeAsync(0);

        const firstSignal = service._lastAbortSignal;
        expect(firstSignal.aborted).toBe(false);
        expect(store.dispatch).toHaveBeenCalledTimes(1);

        ws.emit('connected');
        expect(firstSignal.aborted).toBe(true);

        ws.emit('disconnected');
        await vi.advanceTimersByTimeAsync(0);

        const secondSignal = service._lastAbortSignal;
        expect(store.dispatch).toHaveBeenCalledTimes(2);
        expect(secondSignal).not.toBe(firstSignal);
        expect(secondSignal.aborted).toBe(false);
        expect(service._abortController?.signal).toBe(secondSignal);
    });
});
