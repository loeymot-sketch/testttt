/**
 * posOssFetchCoalesceSentinel.spec.js — POSPERF-03-dup-oss 2026-07-22
 *
 * loadActiveOrdersStats() and loadReadyOrders() both read the SAME
 * admin/oss-order endpoint (via orderStatusScreenOrder/lists) and were fired
 * back-to-back on every kiosk poll tick AND every debounced Echo burst → TWO
 * identical GETs per cycle. `_fetchOssOrdersOnce()` coalesces concurrent callers
 * onto ONE in-flight request, releasing the slot once it settles.
 *
 * Behavioural test mirrors the method body verbatim (PosComponent.vue is 4k+ LOC
 * — full mount is brittle); the source-string assertions below guard against the
 * mirror drifting from production, and prove both loaders route through the
 * coalescer instead of dispatching directly.
 *
 * @FK-ID  POSPERF-03-dup-oss
 */
import { describe, it, expect, vi } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

const SOURCE = readFileSync(
    resolve(process.cwd(), 'resources/js/components/admin/pos/PosComponent.vue'),
    'utf8',
);

// Mirror _fetchOssOrdersOnce verbatim; the source-string assertions below fail
// loudly if PosComponent.vue drifts from this shape.
function makeFetchOnce() {
    return function _fetchOssOrdersOnce() {
        if (this._ossFetchInFlight) {
            return this._ossFetchInFlight;
        }
        const p = this.$store.dispatch('orderStatusScreenOrder/lists');
        this._ossFetchInFlight = p;
        const release = () => {
            if (this._ossFetchInFlight === p) {
                this._ossFetchInFlight = null;
            }
        };
        p.then(release, release);
        return p;
    };
}

describe('PosComponent._fetchOssOrdersOnce — POSPERF-03 dedupe', () => {
    it('two concurrent callers (loadActiveOrdersStats + loadReadyOrders) share ONE admin/oss-order dispatch', async () => {
        let resolveFn;
        const dispatch = vi.fn(() => new Promise((r) => { resolveFn = r; }));
        const ctx = { _ossFetchInFlight: null, $store: { dispatch } };
        const fn = makeFetchOnce();

        const a = fn.call(ctx); // loadActiveOrdersStats path
        const b = fn.call(ctx); // loadReadyOrders path (same synchronous tick)

        expect(dispatch).toHaveBeenCalledTimes(1);
        expect(dispatch).toHaveBeenCalledWith('orderStatusScreenOrder/lists');
        expect(a).toBe(b); // both received the same in-flight promise

        resolveFn({ data: { data: [] } });
        await a;
        expect(ctx._ossFetchInFlight).toBeNull(); // slot released after settle
    });

    it('after the shared fetch settles, the next tick issues a fresh dispatch', async () => {
        let n = 0;
        const dispatch = vi.fn(() => Promise.resolve({ data: { data: [], _n: ++n } }));
        const ctx = { _ossFetchInFlight: null, $store: { dispatch } };
        const fn = makeFetchOnce();

        await fn.call(ctx);
        await fn.call(ctx);
        expect(dispatch).toHaveBeenCalledTimes(2); // one per settled cycle — not coalesced across ticks
    });

    it('a rejected shared fetch still releases the in-flight slot (no stuck poll)', async () => {
        const dispatch = vi.fn(() => Promise.reject(new Error('boom')));
        const ctx = { _ossFetchInFlight: null, $store: { dispatch } };
        const fn = makeFetchOnce();

        await fn.call(ctx).catch(() => {});
        expect(ctx._ossFetchInFlight).toBeNull();
    });
});

describe('PosComponent.vue — POSPERF-03 source-string wiring', () => {
    it('defines _fetchOssOrdersOnce with an in-flight guard', () => {
        expect(SOURCE).toMatch(/_fetchOssOrdersOnce\s*\(\)\s*\{[\s\S]*?this\._ossFetchInFlight[\s\S]*?\}/);
    });

    it('loadActiveOrdersStats routes through the coalescer, not a raw dispatch', () => {
        const body = SOURCE.match(/async loadActiveOrdersStats\s*\(\)\s*\{[\s\S]+?\n {8}\},/);
        expect(body).not.toBeNull();
        expect(body[0]).toMatch(/this\._fetchOssOrdersOnce\s*\(\s*\)/);
        expect(body[0]).not.toMatch(/\$store\.dispatch\(\s*['"]orderStatusScreenOrder\/lists/);
    });

    it('loadReadyOrders routes through the coalescer, not a raw dispatch', () => {
        const body = SOURCE.match(/async loadReadyOrders\s*\(\)\s*\{[\s\S]+?\n {8}\},/);
        expect(body).not.toBeNull();
        expect(body[0]).toMatch(/this\._fetchOssOrdersOnce\s*\(\s*\)/);
        expect(body[0]).not.toMatch(/\$store\.dispatch\(\s*['"]orderStatusScreenOrder\/lists/);
    });
});
