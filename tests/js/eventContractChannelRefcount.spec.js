import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import {
    onEvents,
    onEvent,
    __resetChannelRefCounts,
    __getChannelRefCount,
    __resetCorrelationDedupe,
} from '../../resources/js/services/eventContract.js';

/**
 * [SYNC-BROADCAST-2026-07-07] Regression guard for the shared-channel teardown bug.
 *
 * laravel-echo shares ONE channel object per name (connector.channels registry),
 * and `Echo.leave(name)` unsubscribes + deletes it for ALL subscribers. In the
 * kiosk shell, KioskAppComponent (live availability) and KioskWaitingComponent
 * (order status) both subscribe to `branch.{id}`. Unmounting the waiting screen
 * used to call `Echo.leave('branch.{id}')`, killing the still-mounted shell's
 * real-time availability subscription. These specs prove the refcount fix:
 *  - two subscribers on the same channel share ONE Echo channel object,
 *  - the first to unsubscribe removes only ITS bindings and does NOT leave,
 *  - the second (last) to unsubscribe DOES leave (no channel leak),
 *  - a lone subscriber leaving frees the channel,
 *  - a fresh subscribe after a full leave re-subscribes cleanly (reconnect).
 */

// Mock laravel-echo's connector.channels registry: `private(name)` returns the
// SAME channel object for a given name; `leave(name)` deletes it from the
// registry (mirroring connector.leaveChannel unsubscribe + delete).
function makeEchoMock() {
    const channels = new Map();

    function makeChannel(name) {
        return {
            name,
            // [SYNC-W6 2026-07-22] event -> Set<handler>. A single event name can
            // carry MULTIPLE bindings (co-subscribers), mirroring pusher's
            // `subscription.bind(event, handler)` registry.
            listeners: new Map(),
            listen: vi.fn(function (event, handler) {
                if (!this.listeners.has(event)) {
                    this.listeners.set(event, new Set());
                }
                this.listeners.get(event).add(handler);
                return this;
            }),
            // Mirror laravel-echo Channel.stopListening(e, t): WITH a handler →
            // targeted `subscription.unbind(event, handler)` (remove only that
            // binding); WITHOUT → `unbind(event)` removes EVERY binding. The
            // event key is dropped only once its last binding is gone.
            stopListening: vi.fn(function (event, handler) {
                const set = this.listeners.get(event);
                if (!set) {
                    return this;
                }
                if (handler) {
                    set.delete(handler);
                    if (set.size === 0) {
                        this.listeners.delete(event);
                    }
                } else {
                    this.listeners.delete(event);
                }
                return this;
            }),
        };
    }

    const echo = {
        channels,
        private: vi.fn((name) => {
            if (!channels.has(name)) {
                channels.set(name, makeChannel(name));
            }
            return channels.get(name);
        }),
        leave: vi.fn((name) => {
            channels.delete(name);
        }),
    };

    return echo;
}

describe('eventContract — shared branch channel refcount (SYNC-BROADCAST)', () => {
    beforeEach(() => {
        __resetChannelRefCounts();
        __resetCorrelationDedupe();
        window.Echo = makeEchoMock();
    });

    afterEach(() => {
        delete window.Echo;
        vi.restoreAllMocks();
    });

    it('two subscribers to the same branch channel share ONE Echo channel object', () => {
        const subA = onEvents(1, [{ broadcastAs: 'ItemAvailabilityChanged', handler: () => {} }]);
        const subB = onEvents(1, [{ broadcastAs: 'OrderStatusChanged', handler: () => {} }]);

        // Same channel name → private() returned the same underlying object.
        expect(window.Echo.private).toHaveBeenCalledTimes(2);
        expect(window.Echo.channels.size).toBe(1);
        expect(window.Echo.channels.has('branch.1')).toBe(true);
        expect(__getChannelRefCount('branch.1')).toBe(2);

        // Both event bindings live on the one shared channel.
        const channel = window.Echo.channels.get('branch.1');
        expect(channel.listeners.has('.ItemAvailabilityChanged')).toBe(true);
        expect(channel.listeners.has('.OrderStatusChanged')).toBe(true);

        subA.unsubscribe();
        subB.unsubscribe();
    });

    it('first unsubscribe does NOT leave the channel and keeps the co-subscriber listeners intact', () => {
        const shellHandler = vi.fn();
        const shellSub = onEvents(1, [{ broadcastAs: 'ItemAvailabilityChanged', handler: shellHandler }]);
        const waitingSub = onEvents(1, [{ broadcastAs: 'OrderStatusChanged', handler: () => {} }]);

        const channel = window.Echo.channels.get('branch.1');

        // Waiting screen unmounts first.
        waitingSub.unsubscribe();

        // refcount drops 2 → 1, so NO leave, channel object survives.
        expect(window.Echo.leave).not.toHaveBeenCalled();
        expect(window.Echo.channels.has('branch.1')).toBe(true);
        expect(__getChannelRefCount('branch.1')).toBe(1);

        // Only the waiting screen's binding was removed; the shell keeps its own.
        // [SYNC-W6 2026-07-22] stopListening is now called WITH the subscriber's
        // exact handler (targeted unbind), so assert the 2-arg call shape.
        expect(channel.stopListening).toHaveBeenCalledWith('.OrderStatusChanged', expect.any(Function));
        expect(channel.listeners.has('.OrderStatusChanged')).toBe(false);
        expect(channel.listeners.has('.ItemAvailabilityChanged')).toBe(true);

        shellSub.unsubscribe();
    });

    it('last unsubscribe leaves the channel exactly once (no leak)', () => {
        const subA = onEvents(1, [{ broadcastAs: 'ItemAvailabilityChanged', handler: () => {} }]);
        const subB = onEvents(1, [{ broadcastAs: 'OrderStatusChanged', handler: () => {} }]);

        subA.unsubscribe();
        expect(window.Echo.leave).not.toHaveBeenCalled();

        subB.unsubscribe();
        // refcount reached 0 → leave the shared channel exactly once.
        expect(window.Echo.leave).toHaveBeenCalledTimes(1);
        expect(window.Echo.leave).toHaveBeenCalledWith('branch.1');
        expect(window.Echo.channels.has('branch.1')).toBe(false);
        expect(__getChannelRefCount('branch.1')).toBe(0);
    });

    it('a lone subscriber leaving frees the channel (no leak when refcount was 1)', () => {
        const sub = onEvent(1, 'CatalogChanged', () => {});
        expect(__getChannelRefCount('branch.1')).toBe(1);

        sub.unsubscribe();
        expect(window.Echo.leave).toHaveBeenCalledTimes(1);
        expect(window.Echo.leave).toHaveBeenCalledWith('branch.1');
        expect(__getChannelRefCount('branch.1')).toBe(0);
        expect(window.Echo.channels.has('branch.1')).toBe(false);
    });

    it('double unsubscribe is idempotent — does not decrement a co-subscriber to a premature leave', () => {
        const subA = onEvents(1, [{ broadcastAs: 'ItemAvailabilityChanged', handler: () => {} }]);
        const subB = onEvents(1, [{ broadcastAs: 'OrderStatusChanged', handler: () => {} }]);

        subA.unsubscribe();
        subA.unsubscribe(); // accidental double call must be a no-op

        // subB is still the sole remaining subscriber; channel must be alive.
        expect(window.Echo.leave).not.toHaveBeenCalled();
        expect(__getChannelRefCount('branch.1')).toBe(1);
        expect(window.Echo.channels.has('branch.1')).toBe(true);

        subB.unsubscribe();
        expect(window.Echo.leave).toHaveBeenCalledTimes(1);
    });

    it('re-subscribe after a full leave rebuilds the channel cleanly (Echo reconnect)', () => {
        const first = onEvents(1, [{ broadcastAs: 'ItemAvailabilityChanged', handler: () => {} }]);
        first.unsubscribe();
        expect(window.Echo.channels.has('branch.1')).toBe(false);
        expect(__getChannelRefCount('branch.1')).toBe(0);

        // Component re-subscribes (e.g. after reconnect / re-mount).
        const second = onEvents(1, [{ broadcastAs: 'ItemAvailabilityChanged', handler: () => {} }]);
        expect(window.Echo.channels.has('branch.1')).toBe(true);
        expect(__getChannelRefCount('branch.1')).toBe(1);

        const channel = window.Echo.channels.get('branch.1');
        expect(channel.listeners.has('.ItemAvailabilityChanged')).toBe(true);

        second.unsubscribe();
        expect(__getChannelRefCount('branch.1')).toBe(0);
    });

    it('distinct branch channels are refcounted independently', () => {
        const b1 = onEvents(1, [{ broadcastAs: 'ItemAvailabilityChanged', handler: () => {} }]);
        const b2 = onEvents(2, [{ broadcastAs: 'ItemAvailabilityChanged', handler: () => {} }]);

        expect(__getChannelRefCount('branch.1')).toBe(1);
        expect(__getChannelRefCount('branch.2')).toBe(1);

        b1.unsubscribe();
        expect(window.Echo.leave).toHaveBeenCalledWith('branch.1');
        expect(window.Echo.leave).not.toHaveBeenCalledWith('branch.2');
        expect(window.Echo.channels.has('branch.2')).toBe(true);

        b2.unsubscribe();
    });

    // [SYNC-W6 2026-07-22] The refcount fix (above) protected the shared channel
    // OBJECT, but unsubscribe() called `stopListening(event)` WITHOUT the handler
    // — which unbinds EVERY listener for that event name. Two co-subscribers on
    // the SAME event (e.g. POS tracker + POS dashboard both on OrderStatusChanged)
    // therefore lost BOTH handlers the moment one unmounted. This test fails under
    // the old single-arg teardown and passes with the targeted `stopListening(event,
    // handler)` unbind.
    it('[SYNC-W6] targeted unsubscribe on a SHARED event keeps a co-subscriber handler bound + firing', () => {
        const handlerA = vi.fn();
        const handlerB = vi.fn();
        const subA = onEvents(1, [{ broadcastAs: 'OrderStatusChanged', handler: handlerA }]);
        const subB = onEvents(1, [{ broadcastAs: 'OrderStatusChanged', handler: handlerB }]);

        const channel = window.Echo.channels.get('branch.1');
        // Both bindings coexist on the one shared event key.
        expect(channel.listeners.get('.OrderStatusChanged').size).toBe(2);

        // Surface A unmounts — its targeted unbind removes ONLY handlerA's binding.
        subA.unsubscribe();

        // The event key survives (B still listening); exactly ONE binding remains.
        expect(window.Echo.leave).not.toHaveBeenCalled();
        expect(channel.listeners.has('.OrderStatusChanged')).toBe(true);
        expect(channel.listeners.get('.OrderStatusChanged').size).toBe(1);

        // Fan a real (valid) event out to every surviving binding: only B fires.
        const envelope = {
            version: 1,
            type: 'order.status_changed',
            payload: { order_id: 7, new_status: 8 },
            correlation_id: 'sync-w6-shared',
            branch_id: 1,
        };
        channel.listeners.get('.OrderStatusChanged').forEach((raw) => raw(envelope));

        expect(handlerB).toHaveBeenCalledTimes(1);
        expect(handlerA).not.toHaveBeenCalled();

        // The last subscriber leaving still tears the shared channel down (no leak).
        subB.unsubscribe();
        expect(window.Echo.leave).toHaveBeenCalledWith('branch.1');
        expect(__getChannelRefCount('branch.1')).toBe(0);
    });

    it('no-op subscription (no Echo / no branch) returns a safe idempotent handle', () => {
        delete window.Echo;
        const sub = onEvents(1, [{ broadcastAs: 'ItemAvailabilityChanged', handler: () => {} }]);
        expect(() => sub.unsubscribe()).not.toThrow();

        window.Echo = makeEchoMock();
        const sub2 = onEvents(0, [{ broadcastAs: 'ItemAvailabilityChanged', handler: () => {} }]);
        expect(() => sub2.unsubscribe()).not.toThrow();
        expect(window.Echo.private).not.toHaveBeenCalled();
    });
});
