/**
 * posOfflineAutoFlushNotify — OFF-01 + OFF-03 RED → GREEN spec
 * ---------------------------------------------------------------------------
 * Two "caisse-never-stops" offline defects (reports/test-e2e/all-systems-
 * 2026-06-06/WAVE1_POS_AUDIT_FINDINGS.md):
 *
 *  - OFF-03 (usePosOfflineState.js:71) — the auto-flush (online event + 30s
 *    timer) swallows sync results silently; the cashier is NEVER told a replay
 *    FAILED. FIX: bindAutoFlush(postFn, onResult) — the composable's own
 *    `online`-event auto-flush invokes onResult({synced,failed}) so the
 *    component can surface the SAME alertService messages as the manual flush.
 *
 *  - OFF-01 (PosComponent.vue:3910) — offline enqueue only fires on
 *    navigator.onLine===false; a server-unreachable-while-online live submit
 *    LOSES the sale. The pure classifier shouldEnqueueOnSubmitFailure() decides
 *    "transport failure / 5xx (server did NOT process) → enqueue" vs "any 4xx
 *    business rejection (422/409) / 2xx → do NOT enqueue". This is the exact
 *    safety property the heal must hold; it is unit-proven here.
 *
 * @FK-ID FK-POS-OFFLINE-Q3 | @source Wave-1 POS audit OFF-01 + OFF-03
 */
import 'fake-indexeddb/auto';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

const POS_SRC = readFileSync(
    resolve(__dirname, '../../resources/js/components/admin/pos/PosComponent.vue'),
    'utf8',
);

import {
    clearQueue,
    enqueueOrder,
} from '../../resources/js/helpers/posOfflineQueue';
import { clearQueueEntries } from '../../resources/js/helpers/posOfflineQueueDb';
import {
    usePosOfflineState,
    shouldEnqueueOnSubmitFailure,
} from '../../resources/js/composables/usePosOfflineState';

function setOnline(value) {
    Object.defineProperty(navigator, 'onLine', {
        value,
        configurable: true,
        writable: true,
    });
}

// ---------------------------------------------------------------------------
// OFF-03 — auto-flush must surface its result to the bound notifier
// ---------------------------------------------------------------------------
describe('OFF-03 — auto-flush surfaces sync result to cashier', () => {
    beforeEach(async () => {
        await clearQueue();
        await clearQueueEntries();
        setOnline(true);
        vi.restoreAllMocks();
    });

    it('online-event auto-flush invokes onResult with failed>0 when a replay FAILS', async () => {
        setOnline(false);
        await enqueueOrder({ items: [{ item_id: 1, quantity: 1 }] });
        const state = usePosOfflineState();
        await state.refresh();

        // Replay transport fails for every queued entry → result.failed > 0.
        const postFn = vi.fn(async () => { throw new Error('Network Error'); });
        const onResult = vi.fn();
        state.bindAutoFlush(postFn, onResult);

        setOnline(true);
        window.dispatchEvent(new Event('online'));
        // Allow the .then(tryFlush) microtask chain to settle.
        await new Promise((resolve) => setTimeout(resolve, 30));

        expect(postFn).toHaveBeenCalled();
        // RED pre-heal: the online listener swallowed the result, onResult never
        // ran. GREEN post-heal: onResult fired with the failed count.
        expect(onResult).toHaveBeenCalled();
        const arg = onResult.mock.calls[onResult.mock.calls.length - 1][0];
        expect(arg).toBeTruthy();
        expect(arg.failed).toBeGreaterThan(0);
        state.unbindAutoFlush();
    });

    it('online-event auto-flush invokes onResult with synced>0 on a successful replay', async () => {
        setOnline(false);
        await enqueueOrder({ items: [{ item_id: 2, quantity: 1 }] });
        const state = usePosOfflineState();
        await state.refresh();

        const postFn = vi.fn(async () => ({ status: 201 }));
        const onResult = vi.fn();
        state.bindAutoFlush(postFn, onResult);

        setOnline(true);
        window.dispatchEvent(new Event('online'));
        await new Promise((resolve) => setTimeout(resolve, 30));

        expect(postFn).toHaveBeenCalled();
        expect(onResult).toHaveBeenCalled();
        const arg = onResult.mock.calls[onResult.mock.calls.length - 1][0];
        expect(arg.synced).toBeGreaterThan(0);
        expect(arg.failed).toBe(0);
        state.unbindAutoFlush();
    });

    it('one online event → onResult fires exactly ONCE (no per-cycle nag loop)', async () => {
        // A persistently-failing entry must not cause the online-edge listener to
        // re-notify in a loop: one `online` event → one notification.
        setOnline(false);
        await enqueueOrder({ items: [{ item_id: 9, quantity: 1 }] });
        const state = usePosOfflineState();
        await state.refresh();

        const postFn = vi.fn(async () => { throw new Error('Network Error'); });
        const onResult = vi.fn();
        state.bindAutoFlush(postFn, onResult);

        setOnline(true);
        window.dispatchEvent(new Event('online'));
        await new Promise((resolve) => setTimeout(resolve, 40));

        expect(onResult).toHaveBeenCalledTimes(1);
        const arg = onResult.mock.calls[0][0];
        expect(arg.failed).toBeGreaterThan(0);
        state.unbindAutoFlush();
    });

    it('bindAutoFlush still works (no onResult) — backward compatible', async () => {
        setOnline(false);
        await enqueueOrder({ items: [{ item_id: 3, quantity: 1 }] });
        const state = usePosOfflineState();
        const postFn = vi.fn(async () => ({ status: 201 }));
        // Legacy single-arg call must not throw when the listener fires.
        state.bindAutoFlush(postFn);
        setOnline(true);
        window.dispatchEvent(new Event('online'));
        await new Promise((resolve) => setTimeout(resolve, 30));
        expect(postFn).toHaveBeenCalled();
        state.unbindAutoFlush();
    });
});

// ---------------------------------------------------------------------------
// OFF-01 — classifier: transport failure / 5xx → enqueue; 4xx / 2xx → no
// ---------------------------------------------------------------------------
describe('OFF-01 — shouldEnqueueOnSubmitFailure classifier', () => {
    it('returns TRUE on a transport/network error (no response object)', () => {
        expect(shouldEnqueueOnSubmitFailure(new Error('Network Error'))).toBe(true);
        expect(shouldEnqueueOnSubmitFailure({ message: 'timeout of 0ms exceeded' })).toBe(true);
        expect(shouldEnqueueOnSubmitFailure({ code: 'ECONNABORTED' })).toBe(true);
        expect(shouldEnqueueOnSubmitFailure(undefined)).toBe(true);
        expect(shouldEnqueueOnSubmitFailure(null)).toBe(true);
    });

    it('returns TRUE on 5xx (server did NOT process the order)', () => {
        expect(shouldEnqueueOnSubmitFailure({ response: { status: 500 } })).toBe(true);
        expect(shouldEnqueueOnSubmitFailure({ response: { status: 502 } })).toBe(true);
        expect(shouldEnqueueOnSubmitFailure({ response: { status: 503 } })).toBe(true);
    });

    it('returns FALSE on any 4xx business rejection (422 / 409 / 401 / 403 / 429)', () => {
        // 4xx means the server received + decided → enqueuing would duplicate /
        // resubmit a rejected order. NEVER enqueue.
        expect(shouldEnqueueOnSubmitFailure({ response: { status: 422 } })).toBe(false);
        expect(shouldEnqueueOnSubmitFailure({ response: { status: 409 } })).toBe(false);
        expect(shouldEnqueueOnSubmitFailure({ response: { status: 401 } })).toBe(false);
        expect(shouldEnqueueOnSubmitFailure({ response: { status: 403 } })).toBe(false);
        expect(shouldEnqueueOnSubmitFailure({ response: { status: 429 } })).toBe(false);
    });

    it('returns FALSE on a 2xx (server processed it — not a failure)', () => {
        expect(shouldEnqueueOnSubmitFailure({ response: { status: 200 } })).toBe(false);
        expect(shouldEnqueueOnSubmitFailure({ response: { status: 201 } })).toBe(false);
    });

    it('accepts a raw response (no wrapping error) too', () => {
        // Defensive: a caller may pass error.response directly.
        expect(shouldEnqueueOnSubmitFailure({ status: 500 })).toBe(true);
        expect(shouldEnqueueOnSubmitFailure({ status: 422 })).toBe(false);
    });
});

// ---------------------------------------------------------------------------
// OFF-01 — source wiring lock: the pre-modal gate must consult a preflight and
// route an unreachable server into the SAME enqueue path (not the payment modal)
// ---------------------------------------------------------------------------
describe('OFF-01 — PosComponent pre-modal gate wires the unreachable→enqueue path', () => {
    it('defines a side-effect-free reachability preflight (read-only GET)', () => {
        expect(POS_SRC).toMatch(/preflightServerReachable/);
        // Probe must hit the read-only counter-collect/pending endpoint, NOT a
        // mutating endpoint, and never the order-create POST.
        const block = POS_SRC.slice(POS_SRC.indexOf('preflightServerReachable: async'));
        const head = block.slice(0, 600);
        expect(head).toMatch(/axios\.get\('admin\/pos\/counter-collect\/pending'/);
        expect(head).toMatch(/shouldEnqueueOnSubmitFailure/);
    });

    it('the online branch enqueues when the preflight says unreachable (does NOT open the modal)', () => {
        // The gate: const reachable = await this.preflightServerReachable();
        //           if (!reachable) { await this.enqueueCurrentCheckout(); return; }
        const idx = POS_SRC.indexOf('const reachable = await this.preflightServerReachable()');
        expect(idx).toBeGreaterThan(-1);
        const gate = POS_SRC.slice(idx, idx + 220);
        expect(gate).toMatch(/if\s*\(!reachable\)/);
        expect(gate).toMatch(/enqueueCurrentCheckout/);
        // The unreachable branch returns BEFORE modalShow('#orderpayment').
        const returnIdx = POS_SRC.indexOf('return;', idx);
        const modalIdx = POS_SRC.indexOf("modalShow('#orderpayment')", idx);
        expect(returnIdx).toBeGreaterThan(-1);
        expect(modalIdx).toBeGreaterThan(returnIdx);
    });

    it('both gates (navigator-offline AND unreachable) share one enqueue path', () => {
        // enqueueCurrentCheckout must be the single source of the M1-02 CASH
        // default + OFF-02 resetCart so both entry points behave identically.
        expect(POS_SRC).toMatch(/enqueueCurrentCheckout: async function/);
        const body = POS_SRC.slice(POS_SRC.indexOf('enqueueCurrentCheckout: async function'));
        const head = body.slice(0, 2200);
        expect(head).toMatch(/pos_received_amount/);          // M1-02 preserved
        expect(head).toMatch(/this\.resetCart\(\)/);          // OFF-02 preserved
        expect(head).toMatch(/this\.enqueueOrder\(/);
    });
});

// ---------------------------------------------------------------------------
// OFF-03 — source wiring lock: auto-flush paths route through the notifier
// ---------------------------------------------------------------------------
describe('OFF-03 — PosComponent auto-flush paths surface their result', () => {
    it('bindAutoFlush passes a result notifier (2nd arg)', () => {
        expect(POS_SRC).toMatch(/bindAutoFlush\(axios\.post,\s*\(res\)\s*=>\s*this\.notifyAutoFlushResult\(res\)\)/);
    });

    it('the 30s interval routes its result through the notifier in HEARTBEAT mode', () => {
        const idx = POS_SRC.indexOf('_posOfflineFlushTimer = setInterval');
        expect(idx).toBeGreaterThan(-1);
        const block = POS_SRC.slice(idx, idx + 1400);
        expect(block).toMatch(/notifyAutoFlushResult/);
        // The interval MUST pass heartbeat:true so a persistently-failing entry
        // does not nag the cashier every 30s.
        expect(block).toMatch(/heartbeat:\s*true/);
    });

    it('notifyAutoFlushResult reuses manual-flush messages AND suppresses failure under heartbeat', () => {
        const idx = POS_SRC.indexOf('notifyAutoFlushResult: function');
        expect(idx).toBeGreaterThan(-1);
        const block = POS_SRC.slice(idx, idx + 1100);
        expect(block).toMatch(/en échec/);                    // warning on failed>0
        expect(block).toMatch(/synchronisée/);                // success on synced>0
        expect(block).toMatch(/alertService\.warning/);
        // The failure warning is gated by !heartbeat so the 30s interval stays
        // silent on repeated failures (online-edge + manual button still warn).
        expect(block).toMatch(/res\.failed\s*>\s*0\s*&&\s*!heartbeat/);
    });
});
