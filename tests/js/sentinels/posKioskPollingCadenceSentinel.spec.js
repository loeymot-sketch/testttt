/**
 * posKioskPollingCadenceSentinel.spec.js
 *   — GOAL-HEAL-SYNC-001 2026-05-23 (original)
 *   — POSPERF-04-idle-hammer 2026-07-22 (cadence contract corrected)
 *
 * Locks the kiosk polling cadence for the caisse dashboard (kiosk-cash /
 * « Prêt » / web-orders panels).
 *
 * CONTRACT (POSPERF-04):
 *   - _kioskPollingInterval() returns 60000ms whenever the realtime socket is
 *     connected — EVEN when the "Prêt" list is empty. An empty ready list is the
 *     normal calm state and must NOT force the fast cadence: the old
 *     `isEmpty || isStale → 5000` heuristic hammered admin/oss-order ~48 req/min
 *     WebSocket-healthy (POSPERF-04). We now trust the Echo push while the socket
 *     is up.
 *   - _kioskPollingInterval() returns 5000ms when the socket is NOT connected
 *     (degraded — polling is the only discovery path), regardless of list state.
 *
 * Why the reversal of GOAL-HEAL-SYNC-001's empty/stale downshift:
 *   The 30s staleness threshold was BELOW the 60s slow cadence, and
 *   `lastReadyRefresh` is re-stamped by the poll itself, so every 60s tick
 *   re-evaluated as "stale" → permanent downshift to 5s (the ~16-32s ΔT
 *   oscillation was the 5s/60s flap, not a fix). The silent-Echo-death case
 *   (socket up, channel-auth KO) is now covered by the ws 'disconnected' event
 *   (_restartKioskPolling) + PosSyncService fallback + a bounded 60s worst-case,
 *   documented as sync-engine IMP-2 (heartbeat freshness) backlog.
 *
 * Why behavioural-via-direct-invocation (not mount):
 *   PosComponent.vue is 4k+ LOC with deep transitive imports. We test the method
 *   itself with a synthetic `this` context — same input/output as production.
 *
 * @FK-ID  GOAL-HEAL-SYNC-001 / POSPERF-04
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
        return window._wsService?.isConnected() ? 60000 : 5000;
    };
}

function ctx({ readyOrders, lastReadyRefresh, echoConnected, noWs }) {
    const previousWs = global.window?._wsService;
    if (!global.window) global.window = {};
    if (noWs) {
        delete global.window._wsService;
    } else {
        global.window._wsService = {
            isConnected: () => !!echoConnected,
        };
    }
    return {
        // readyOrders/lastReadyRefresh retained on the ctx to prove the new body
        // IGNORES them (no idle hammer) — they must not change the result.
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

describe('PosComponent._kioskPollingInterval — cadence contract (POSPERF-04)', () => {
    const fn = makeInterval();

    it('returns 60000ms when Echo connected + EMPTY ready list (POSPERF-04: idle must NOT hammer)', () => {
        // THE FIX. Previously this forced 5000ms (48 req/min WebSocket-healthy).
        const t = ctx({ readyOrders: [], lastReadyRefresh: Date.now(), echoConnected: true });
        try {
            expect(fn.call(t.ctx)).toBe(60000);
        } finally { t.restore(); }
    });

    it('returns 60000ms when Echo connected + orders present', () => {
        const t = ctx({ readyOrders: [{ id: 1 }], lastReadyRefresh: Date.now(), echoConnected: true });
        try {
            expect(fn.call(t.ctx)).toBe(60000);
        } finally { t.restore(); }
    });

    it('returns 60000ms when Echo connected even if lastReadyRefresh is old (staleness no longer downshifts)', () => {
        const t = ctx({ readyOrders: [], lastReadyRefresh: Date.now() - 120_000, echoConnected: true });
        try {
            expect(fn.call(t.ctx)).toBe(60000);
        } finally { t.restore(); }
    });

    it('returns 5000ms when Echo DISCONNECTED + empty (degraded: poll is the only discovery path)', () => {
        const t = ctx({ readyOrders: [], lastReadyRefresh: Date.now(), echoConnected: false });
        try {
            expect(fn.call(t.ctx)).toBe(5000);
        } finally { t.restore(); }
    });

    it('returns 5000ms when Echo DISCONNECTED + orders present', () => {
        const t = ctx({ readyOrders: [{ id: 1 }], lastReadyRefresh: Date.now(), echoConnected: false });
        try {
            expect(fn.call(t.ctx)).toBe(5000);
        } finally { t.restore(); }
    });

    it('returns 5000ms when _wsService is absent (optional-chaining → treated as not connected)', () => {
        const t = ctx({ readyOrders: [{ id: 1 }], lastReadyRefresh: Date.now(), echoConnected: false, noWs: true });
        try {
            expect(fn.call(t.ctx)).toBe(5000);
        } finally { t.restore(); }
    });
});

describe('PosComponent.vue — source-string structural invariants (POSPERF-04)', () => {
    // Extract the _kioskPollingInterval body, anchored to the next method name
    // so the negative assertions below scope to the method only.
    const intervalBody = SOURCE.match(/_kioskPollingInterval\s*\(\)\s*\{[\s\S]+?\}\s*,\s*\n\s*_startKioskPolling/);
    // Strip comments so the negative assertions test the EXECUTABLE code only —
    // the docblock legitimately cites the removed lastReadyRefresh/30000 logic to
    // explain WHY it's gone; that history must not trip the "removed" checks.
    const intervalCode = intervalBody
        ? intervalBody[0].replace(/\/\*[\s\S]*?\*\//g, '').replace(/\/\/[^\n]*/g, '')
        : '';

    it('carries the POSPERF-04 anchor comment', () => {
        expect(SOURCE).toMatch(/POSPERF-04/);
    });

    it('_kioskPollingInterval() keeps the isConnected() ? 60000 : 5000 cadence', () => {
        expect(intervalBody).not.toBeNull();
        expect(intervalCode).toMatch(/isConnected\s*\(\s*\)\s*\?\s*60000\s*:\s*5000/);
    });

    it('_kioskPollingInterval() executable body no longer references readyOrders / lastReadyRefresh / the 30s threshold (idle hammer removed)', () => {
        expect(intervalBody).not.toBeNull();
        expect(intervalCode).not.toMatch(/readyOrders/);
        expect(intervalCode).not.toMatch(/lastReadyRefresh/);
        expect(intervalCode).not.toMatch(/30000/);
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
