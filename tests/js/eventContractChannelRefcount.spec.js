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
            listeners: new Map(), // event -> handler
            listen: vi.fn(function (event, handler) {
                this.listeners.set(event, handler);
                return this;
            }),
            stopListening: vi.fn(function (event) {
                this.listeners.delete(event);
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
        expect(channel.stopListening).toHaveBeenCalledWith('.OrderStatusChanged');
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
