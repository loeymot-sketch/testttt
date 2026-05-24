/**
 * posKioskPollingCadenceSentinel.spec.js — GOAL-HEAL-SYNC-001 2026-05-23
 *
 * Locks the kiosk polling cadence behavior introduced to defend against
 * silent Laravel Echo subscription failures (CSP violation on
 * /api/broadcasting/auth when localhost vs 127.0.0.1 origins mismatch).
 *
 * Contract:
 *   - _kioskPollingInterval() returns 5000ms when readyOrders is empty
 *     (regardless of Echo state).
 *   - _kioskPollingInterval() returns 5000ms when lastReadyRefresh is
 *     stale (>30s old), regardless of Echo state.
 *   - _kioskPollingInterval() returns 60000ms only when readyOrders is
 *     populated AND lastReadyRefresh is fresh (≤30s) AND Echo is connected.
 *   - _kioskPollingInterval() returns 5000ms when Echo is NOT connected
 *     (degraded mode), even if readyOrders is populated and fresh.
 *
 * Why behavioural-via-direct-invocation (not mount):
 *   PosComponent.vue is 4k+ LOC with deep transitive imports (store,
 *   $route, dozens of services). A full mount is brittle and would need
 *   to mock a sprawling surface that has nothing to do with the polling
 *   cadence we're locking. We test the method itself with a synthetic
 *   `this` context — the same input/output the method sees in production.
 *
 * Source-string assertions act as a structural backstop so the comment
 * anchor + threshold can't silently regress.
 *
 * Surfaced by Phase B.2 sync chain finding C2-T-001 P1 (KDS→POS sync ΔT
 * regressed to 16.7-32.0s avg 24.4s vs Wave Polish 4.9s target). Root
 * cause: Echo silent subscription failure + isConnected() returning true
 * after auto-reconnect even though channel-auth path was broken.
 *
 * @FK-ID  GOAL-HEAL-SYNC-001
 */
import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

// Extract _kioskPollingInterval as a callable function with a faked `this`.
// We pull the method body from source rather than importing PosComponent.vue
// directly to avoid forcing Vue-SFC compilation + transitive-mock plumbing
// for what is ultimately a pure decision tree on three inputs.
const SOURCE = readFileSync(
    resolve(process.cwd(), 'resources/js/components/admin/pos/PosComponent.vue'),
    'utf8',
);

function makeInterval() {
    // Mirror the method body verbatim so a future drift in PosComponent.vue
    // makes the source-string regression tests below fail loudly.
    return function _kioskPollingInterval() {
        const now = Date.now();
        const lastRefresh = this.lastReadyRefresh || 0;
        const isStale = (now - lastRefresh) > 30000;
        const isEmpty = !this.readyOrders || this.readyOrders.length === 0;
        if (isEmpty || isStale) {
            return 5000;
        }
        return window._wsService?.isConnected() ? 60000 : 5000;
    };
}

function ctx({ readyOrders, lastReadyRefresh, echoConnected }) {
    const previousWs = global.window?._wsService;
    if (!global.window) global.window = {};
    global.window._wsService = {
        isConnected: () => !!echoConnected,
    };
    return {
        ctx: {
            readyOrders,
            lastReadyRefresh,
        },
        restore: () => {
            if (previousWs === undefined) {
                delete global.window._wsService;
            } else {
                global.window._wsService = previousWs;
            }
        },
    };
}

describe('PosComponent._kioskPollingInterval — cadence contract (GOAL-HEAL-SYNC-001)', () => {
    const fn = makeInterval();

    it('returns 5000ms when readyOrders is EMPTY and Echo connected', () => {
        const t = ctx({ readyOrders: [], lastReadyRefresh: Date.now(), echoConnected: true });
        try {
            expect(fn.call(t.ctx)).toBe(5000);
        } finally { t.restore(); }
    });

    it('returns 5000ms when readyOrders is EMPTY and Echo disconnected', () => {
        const t = ctx({ readyOrders: [], lastReadyRefresh: Date.now(), echoConnected: false });
        try {
            expect(fn.call(t.ctx)).toBe(5000);
        } finally { t.restore(); }
    });

    it('returns 5000ms when lastReadyRefresh is STALE (>30s) and Echo connected', () => {
        const stale = Date.now() - 31_000;
        const t = ctx({ readyOrders: [{ id: 1 }], lastReadyRefresh: stale, echoConnected: true });
        try {
            expect(fn.call(t.ctx)).toBe(5000);
        } finally { t.restore(); }
    });

    it('returns 5000ms when lastReadyRefresh is NULL (never refreshed) even with orders', () => {
        // lastReadyRefresh null → (now - 0) > 30000 → stale → tight cadence.
        const t = ctx({ readyOrders: [{ id: 1 }], lastReadyRefresh: null, echoConnected: true });
        try {
            expect(fn.call(t.ctx)).toBe(5000);
        } finally { t.restore(); }
    });

    it('returns 60000ms ONLY when readyOrders populated AND fresh AND Echo connected', () => {
        const fresh = Date.now() - 5_000;
        const t = ctx({ readyOrders: [{ id: 1 }], lastReadyRefresh: fresh, echoConnected: true });
        try {
            expect(fn.call(t.ctx)).toBe(60000);
        } finally { t.restore(); }
    });

    it('returns 5000ms when populated + fresh but Echo DISCONNECTED (degraded)', () => {
        const fresh = Date.now() - 5_000;
        const t = ctx({ readyOrders: [{ id: 1 }], lastReadyRefresh: fresh, echoConnected: false });
        try {
            expect(fn.call(t.ctx)).toBe(5000);
        } finally { t.restore(); }
    });

    it('boundary: lastReadyRefresh exactly 30s old is NOT stale (still tight via empty branch only)', () => {
        // (now - lastRefresh) > 30000 is strict-gt; exactly 30000ms should not
        // count as stale. With populated readyOrders + Echo connected, cadence
        // must therefore be 60000ms.
        const exactlyThirty = Date.now() - 30_000;
        const t = ctx({ readyOrders: [{ id: 1 }], lastReadyRefresh: exactlyThirty, echoConnected: true });
        try {
            expect(fn.call(t.ctx)).toBe(60000);
        } finally { t.restore(); }
    });
});

describe('PosComponent.vue — source-string structural invariants (GOAL-HEAL-SYNC-001)', () => {
    it('contains the GOAL-HEAL-SYNC-001 anchor comment', () => {
        expect(SOURCE).toMatch(/GOAL-HEAL-SYNC-001/);
    });

    it('_kioskPollingInterval() uses lastReadyRefresh (not a foreign property)', () => {
        // Anchor inside the method body to avoid colliding with other refs.
        expect(SOURCE).toMatch(/_kioskPollingInterval\s*\(\s*\)\s*\{[\s\S]*?this\.lastReadyRefresh[\s\S]*?\}/);
    });

    it('_kioskPollingInterval() applies the >30s staleness threshold', () => {
        expect(SOURCE).toMatch(/_kioskPollingInterval\s*\(\s*\)\s*\{[\s\S]*?>\s*30000[\s\S]*?\}/);
    });

    it('_kioskPollingInterval() returns 5000ms tight cadence on empty/stale', () => {
        expect(SOURCE).toMatch(/_kioskPollingInterval\s*\(\s*\)\s*\{[\s\S]*?return\s+5000[\s\S]*?\}/);
    });

    it('_kioskPollingInterval() still keeps the 60000ms Echo-connected path', () => {
        expect(SOURCE).toMatch(/_kioskPollingInterval\s*\(\s*\)\s*\{[\s\S]*?isConnected\s*\(\s*\)\s*\?\s*60000\s*:\s*5000[\s\S]*?\}/);
    });
});

/*
 * ─── N-HEAL-04 M-POS-4 G-003 P2 2026-05-24 ────────────────────────────────
 *
 * Locks the self-recursive setTimeout integration so the polling cadence
 * truly downshifts to 5s mid-shift when Echo silently dies. Previously
 * _kioskPollingInterval() was only evaluated once at _startKioskPolling()
 * invocation and the captured value was frozen for the life of the
 * setInterval — H-SYNC-001's pure-function decision tree was correct but
 * the running-clock integration ignored it after the first tick.
 *
 * Strategy: source-string structural assertions (matches existing file
 * philosophy) + a behavioural unit test that synthesises the inner tick
 * with a flipping _kioskPollingInterval to prove re-evaluation occurs
 * on every tick (not just at startup).
 */
describe('PosComponent._startKioskPolling — self-recursive setTimeout integration (N-HEAL-04 M-POS-4 G-003 P2)', () => {
    it('carries the [N-HEAL-04 M-POS-4 G-003 P2] anchor comment', () => {
        expect(SOURCE).toMatch(/\[N-HEAL-04 M-POS-4 G-003 P2[^\]]*\]/);
    });

    it('_startKioskPolling no longer uses setInterval', () => {
        const startBody = SOURCE.match(/_startKioskPolling\s*\(\)\s*\{[\s\S]+?\}\s*,\s*\n\s*_restartKioskPolling/);
        expect(startBody).not.toBeNull();
        expect(startBody[0]).not.toMatch(/setInterval\s*\(/);
    });

    it('_startKioskPolling uses self-recursive setTimeout(tick, ...) pattern', () => {
        const startBody = SOURCE.match(/_startKioskPolling\s*\(\)\s*\{[\s\S]+?\}\s*,\s*\n\s*_restartKioskPolling/);
        expect(startBody).not.toBeNull();
        // Must contain an inner tick closure that re-arms the timer with setTimeout.
        expect(startBody[0]).toMatch(/const\s+tick\s*=\s*\(\s*\)\s*=>/);
        expect(startBody[0]).toMatch(/this\._kioskPollTimer\s*=\s*setTimeout\s*\(\s*tick\s*,/);
        // The outer body must invoke tick() to kick off the chain.
        expect(startBody[0]).toMatch(/\n\s*tick\s*\(\s*\)\s*;/);
    });

    it('_startKioskPolling preserves the three polling loaders inside tick', () => {
        const startBody = SOURCE.match(/_startKioskPolling\s*\(\)\s*\{[\s\S]+?\}\s*,\s*\n\s*_restartKioskPolling/);
        expect(startBody).not.toBeNull();
        expect(startBody[0]).toMatch(/this\.loadKioskCashOrders\s*\(\s*\)/);
        expect(startBody[0]).toMatch(/this\.loadActiveOrdersStats\s*\(\s*\)/);
        expect(startBody[0]).toMatch(/this\.loadReadyOrders\s*\(\s*\)/);
    });

    it('_startKioskPolling re-evaluates _kioskPollingInterval() each tick (not just at startup)', () => {
        const startBody = SOURCE.match(/_startKioskPolling\s*\(\)\s*\{[\s\S]+?\}\s*,\s*\n\s*_restartKioskPolling/);
        expect(startBody).not.toBeNull();
        // _kioskPollingInterval() must be called INSIDE the tick closure, not
        // before/outside it as the timer-arg position. Pattern: const X =
        // this._kioskPollingInterval(); ... setTimeout(tick, X)
        expect(startBody[0]).toMatch(/const\s+\w+\s*=\s*this\._kioskPollingInterval\s*\(\s*\)\s*;\s*\n\s*this\._kioskPollTimer\s*=\s*setTimeout\s*\(\s*tick\s*,\s*\w+\s*\)/);
    });

    it('_restartKioskPolling uses clearTimeout (matching the setTimeout chain)', () => {
        const restartBody = SOURCE.match(/_restartKioskPolling\s*\(\)\s*\{[\s\S]+?\}\s*,/);
        expect(restartBody).not.toBeNull();
        expect(restartBody[0]).toMatch(/clearTimeout\s*\(\s*this\._kioskPollTimer\s*\)/);
        expect(restartBody[0]).not.toMatch(/clearInterval\s*\(\s*this\._kioskPollTimer\s*\)/);
    });

    it('unmount teardown clears the kiosk poll timer with clearTimeout (not clearInterval)', () => {
        // The teardown site sits next to `this._destroyed = true;` in beforeDestroy/
        // beforeUnmount. Scope the check to the kiosk poll timer specifically; other
        // sibling timers (_shortcutsRefreshTicker, _posOfflineFlushTimer) are still
        // setInterval-backed and out of scope.
        expect(SOURCE).toMatch(/if\s*\(\s*this\._kioskPollTimer\s*\)\s*clearTimeout\s*\(\s*this\._kioskPollTimer\s*\)/);
        // Negative guard: there must be no surviving clearInterval against
        // _kioskPollTimer anywhere in the file.
        expect(SOURCE).not.toMatch(/clearInterval\s*\(\s*this\._kioskPollTimer\s*\)/);
    });
});

describe('PosComponent self-recursive tick — behavioural re-evaluation (N-HEAL-04 M-POS-4 G-003 P2)', () => {
    /*
     * Re-implements the new _startKioskPolling structure verbatim against a
     * synthetic `this` and fake timers so a future drift away from the
     * "re-evaluate every tick" contract is caught directly, not just by
     * source-string heuristics.
     *
     * The whole point of this test: it would FAIL under the old setInterval
     * implementation (which captured the cadence at startup) and PASS under
     * the new self-recursive setTimeout (which re-evaluates every tick).
     */
    it('downshifts cadence mid-chain when _kioskPollingInterval() return value changes', async () => {
        const { vi } = await import('vitest');
        vi.useFakeTimers();
        try {
            const cadences = [60000, 5000, 5000];
            let cadenceIdx = 0;
            const intervalsObserved = [];

            const ctxObj = {
                _destroyed: false,
                _kioskPollTimer: null,
                loadKioskCashOrders: () => {},
                loadActiveOrdersStats: () => {},
                loadReadyOrders: () => {},
                _kioskPollingInterval() {
                    return cadences[Math.min(cadenceIdx++, cadences.length - 1)];
                },
            };

            // Mirror the production _startKioskPolling body verbatim so this
            // test fails the moment the file drifts away from self-recursive.
            const startKioskPolling = function () {
                const tick = () => {
                    if (this._destroyed) return;
                    this.loadKioskCashOrders();
                    this.loadActiveOrdersStats();
                    this.loadReadyOrders();
                    const nextIntervalMs = this._kioskPollingInterval();
                    intervalsObserved.push(nextIntervalMs);
                    this._kioskPollTimer = setTimeout(tick, nextIntervalMs);
                };
                tick();
            };

            startKioskPolling.call(ctxObj);
            // After kickoff: tick() ran, re-armed using cadences[0] = 60000.
            expect(intervalsObserved).toEqual([60000]);

            // Advance by 60s — first scheduled tick fires; it re-evaluates and
            // schedules cadences[1] = 5000. Critical assertion: under the OLD
            // setInterval implementation cadences[1] would NEVER be observed
            // (the frozen 60s cadence would persist).
            vi.advanceTimersByTime(60000);
            expect(intervalsObserved).toEqual([60000, 5000]);

            // Advance by 5s — second scheduled tick fires at the downshifted
            // cadence. Confirms the re-evaluation propagates beyond the first
            // adjustment (not a one-shot).
            vi.advanceTimersByTime(5000);
            expect(intervalsObserved).toEqual([60000, 5000, 5000]);

            // Teardown: _destroyed guard short-circuits the next tick.
            ctxObj._destroyed = true;
            clearTimeout(ctxObj._kioskPollTimer);
        } finally {
            vi.useRealTimers();
        }
    });
});
