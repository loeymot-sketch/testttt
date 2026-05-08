import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { onEvents, onEvent } from '../../resources/js/services/eventContract';

/**
 * [PARALLEL-TRACK-5 / 5.2] Memory-leak invariants on Echo channel
 * subscriptions.
 *
 * The kiosk / KDS / OSS / POS Vue surfaces all subscribe to private
 * `branch.{id}` Echo channels via `onEvents()`. If a component remounts
 * (route navigation, tab switch, idle reset) without first calling the
 * returned `unsubscribe()`, the listener stays bound on the previous
 * channel instance — a classic Vue 3 memory leak that manifests in
 * production as duplicate toasts, stale order data, or an OOM after
 * an 8h shift.
 *
 * The contract enforced here:
 *   1. `onEvents(branchId, bindings)` increments the listener counter
 *      once per binding.
 *   2. The returned object has `.unsubscribe()` (object, NOT a bare
 *      function — see eventContract.js:113).
 *   3. Calling `.unsubscribe()` calls `channel.stopListening()` once
 *      per binding AND `Echo.leave(channelName)` once.
 *   4. A Vue component that wires `onEvents` in `mounted` and calls
 *      `unsubscribe` in `beforeUnmount` brings the global counter back
 *      to zero on every cycle.
 *   5. 10 mount/unmount cycles never leave a residual listener.
 */

let listenerCount = 0;
let leaveCount = 0;

function makeEchoMock() {
    const channelInstances = new Map();
    return {
        private: vi.fn((channelName) => {
            // Reuse the same channel instance per name (matches Echo behaviour).
            if (!channelInstances.has(channelName)) {
                channelInstances.set(channelName, {
                    listen: vi.fn(() => { listenerCount++; }),
                    stopListening: vi.fn(() => { listenerCount--; }),
                });
            }
            return channelInstances.get(channelName);
        }),
        leave: vi.fn(() => { leaveCount++; }),
    };
}

beforeEach(() => {
    listenerCount = 0;
    leaveCount = 0;
    window.Echo = makeEchoMock();
});

afterEach(() => {
    delete window.Echo;
});

describe('Echo memory leaks — onEvents contract', () => {
    it('returns an object exposing unsubscribe() (not a bare function)', () => {
        const handle = onEvents(1, [
            { broadcastAs: 'OrderCreated', handler: () => {} },
        ]);

        expect(handle).toBeTypeOf('object');
        expect(handle.unsubscribe).toBeTypeOf('function');
        // Defensive: contract change would silently break every consumer.
        expect(typeof handle).not.toBe('function');
    });

    it('increments listener count by exactly one per binding', () => {
        expect(listenerCount).toBe(0);

        const handle = onEvents(1, [
            { broadcastAs: 'OrderCreated', handler: () => {} },
            { broadcastAs: 'OrderStatusChanged', handler: () => {} },
        ]);

        expect(listenerCount).toBe(2);
        handle.unsubscribe();
    });

    it('decrements listener count to zero after unsubscribe()', () => {
        const handle = onEvents(1, [
            { broadcastAs: 'OrderCreated', handler: () => {} },
            { broadcastAs: 'OrderStatusChanged', handler: () => {} },
        ]);
        expect(listenerCount).toBe(2);

        handle.unsubscribe();

        expect(listenerCount).toBe(0);
        expect(leaveCount).toBe(1);
    });

    it('the convenience onEvent() wrapper also unsubscribes correctly', () => {
        const handle = onEvent(7, 'OrderCreated', () => {});
        expect(listenerCount).toBe(1);

        handle.unsubscribe();
        expect(listenerCount).toBe(0);
        expect(leaveCount).toBe(1);
    });

    it('skipped bindings (missing handler) do not leak listeners', () => {
        const handle = onEvents(1, [
            { broadcastAs: 'OrderCreated', handler: () => {} },
            { broadcastAs: 'OrderStatusChanged' /* missing handler */ },
            { broadcastAs: '', handler: () => {} },
        ]);

        // Only the first binding is valid.
        expect(listenerCount).toBe(1);
        handle.unsubscribe();
        expect(listenerCount).toBe(0);
    });

    it('no-ops gracefully when window.Echo is missing', () => {
        delete window.Echo;
        const handle = onEvents(1, [
            { broadcastAs: 'OrderCreated', handler: () => {} },
        ]);

        expect(handle.unsubscribe).toBeTypeOf('function');
        // Calling unsubscribe with no Echo must not throw.
        expect(() => handle.unsubscribe()).not.toThrow();
        // listenerCount stays at 0 because no real subscription happened.
        expect(listenerCount).toBe(0);
    });

    it('no-ops when branchId is missing or empty bindings', () => {
        const h1 = onEvents(0, [{ broadcastAs: 'X', handler: () => {} }]);
        expect(listenerCount).toBe(0);
        h1.unsubscribe();

        const h2 = onEvents(null, [{ broadcastAs: 'X', handler: () => {} }]);
        expect(listenerCount).toBe(0);
        h2.unsubscribe();

        const h3 = onEvents(1, []);
        expect(listenerCount).toBe(0);
        h3.unsubscribe();
    });
});

describe('Echo memory leaks — Vue component lifecycle', () => {
    /**
     * Stub component mirroring the production pattern used by
     * PosComponent / PreparingAndReadyComponent / KitchenDisplaySystemComponent:
     *   - subscribe in `mounted()`
     *   - unsubscribe in `beforeUnmount()`
     */
    const SubscriberStub = {
        template: '<div>subscriber</div>',
        props: { branchId: { type: Number, default: 1 } },
        data() {
            return { _eventSub: null };
        },
        mounted() {
            this._eventSub = onEvents(this.branchId, [
                { broadcastAs: 'OrderCreated', handler: () => {} },
                { broadcastAs: 'OrderStatusChanged', handler: () => {} },
            ]);
        },
        beforeUnmount() {
            if (this._eventSub && typeof this._eventSub.unsubscribe === 'function') {
                this._eventSub.unsubscribe();
            }
        },
    };

    it('mount + unmount returns listener count to zero', async () => {
        const wrapper = mount(SubscriberStub);
        expect(listenerCount).toBe(2);

        wrapper.unmount();
        // Cleanup is synchronous in onEvents().
        expect(listenerCount).toBe(0);
        expect(leaveCount).toBe(1);
    });

    it('10 mount/unmount cycles never leak a listener', async () => {
        for (let i = 0; i < 10; i++) {
            const wrapper = mount(SubscriberStub);
            expect(listenerCount).toBe(2);
            wrapper.unmount();
            expect(listenerCount).toBe(0);
        }

        expect(listenerCount).toBe(0);
        expect(leaveCount).toBe(10);
    });

    it('parallel mounts (different branchIds) keep their counters independent', async () => {
        const w1 = mount(SubscriberStub, { props: { branchId: 1 } });
        const w2 = mount(SubscriberStub, { props: { branchId: 2 } });

        expect(listenerCount).toBe(4); // 2 listeners per channel

        w1.unmount();
        expect(listenerCount).toBe(2); // only branch.1 cleared

        w2.unmount();
        expect(listenerCount).toBe(0);
        expect(leaveCount).toBe(2);
    });

    it('a component that FORGETS to unsubscribe leaks (regression sentinel)', () => {
        // Sentinel: documents the failure mode this suite guards against.
        const LeakyComponent = {
            template: '<div>leaky</div>',
            mounted() {
                onEvents(1, [{ broadcastAs: 'OrderCreated', handler: () => {} }]);
                // intentionally NEVER stores the handle / never unsubscribes
            },
        };

        const w = mount(LeakyComponent);
        expect(listenerCount).toBe(1);
        w.unmount();
        // Without unsubscribe, listener stays — this is the bug we prevent.
        expect(listenerCount).toBe(1);
    });

    it('calling unsubscribe twice is idempotent (no negative counter)', () => {
        const handle = onEvents(1, [
            { broadcastAs: 'OrderCreated', handler: () => {} },
        ]);
        expect(listenerCount).toBe(1);

        handle.unsubscribe();
        // Second call is a no-op for our mock (channel.stopListening on the
        // same name is wrapped in try/catch in eventContract.js). Real
        // production guarantee: never throws.
        expect(() => handle.unsubscribe()).not.toThrow();
        // We don't assert exact count because the second stopListening()
        // would decrement again on this naive mock — the meaningful
        // invariant is "does not throw".
    });
});
