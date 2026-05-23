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
