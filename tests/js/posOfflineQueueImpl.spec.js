/**
 * posOfflineQueue — RED → GREEN spec
 * ---------------------------------------------------------------------------
 * FoodKing V1 — POS Caisse offline helper (mirrors kiosk pattern, scope-minimal).
 *
 * Strict contract (CLAUDE.md §8 NF525) :
 *  - Path A : queue client-side, server-authoritative replay with idempotency-key
 *  - NEVER allocate fiscal_sequence_no locally
 *  - NEVER persist PAN/CVV/customer_email/customer_phone (PCI-DSS + PII)
 *  - Reject-new at MAX_ENTRIES (preserve earliest cash sales which are
 *    most critical to replay first — drop-oldest would risk losing a
 *    fiscal-critical sale)
 *  - TTL 30 min, idempotency UUIDv4 stable across replay
 *
 * @FK-ID FK-POS-OFFLINE-Q1 | @source V1 hardening, owner gate carte-blanche
 */
import 'fake-indexeddb/auto';
import { beforeEach, describe, expect, it, vi } from 'vitest';

// idb-keyval mock with a togglable `set()` rejection — only flipped on for the
// localStorage-fallback test below; default behavior is the real implementation.
const idbKeyvalThrowFlag = { set: false };
vi.mock('idb-keyval', async () => {
    const actual = await vi.importActual('idb-keyval');
    return { ...actual, set: (...a) => (idbKeyvalThrowFlag.set
        ? Promise.reject(new Error('indexeddb unavailable')) : actual.set(...a)) };
});

import {
    clearQueue,
    enqueueOrder,
    listPending,
    markFailed,
    markSynced,
    MAX_ENTRIES,
    purgeExpired,
    TTL_MS,
    __unsafeGetCacheForTests,
} from '../../resources/js/helpers/posOfflineQueue';
import { clearQueueEntries } from '../../resources/js/helpers/posOfflineQueueDb';
import { usePosOfflineState } from '../../resources/js/composables/usePosOfflineState';

function advanceClock(ms) {
    const next = Date.now() + ms;
    vi.spyOn(Date, 'now').mockImplementation(() => next);
}

function setOnline(value) {
    Object.defineProperty(navigator, 'onLine', {
        value,
        configurable: true,
        writable: true,
    });
}

describe('posOfflineQueue — enqueue + persist', () => {
    beforeEach(async () => {
        await clearQueue();
        await clearQueueEntries();
        try { localStorage.clear(); } catch (_) {}
        vi.restoreAllMocks();
    });

    it('enqueues an order and exposes it via listPending()', async () => {
        const entry = await enqueueOrder({ items: [{ item_id: 7, quantity: 1 }], total_cents: 590 });
        expect(entry).toBeTruthy();
        expect(entry.idempotencyKey).toMatch(/^[0-9a-f-]{36}$/);
        const pending = await listPending();
        expect(pending.length).toBe(1);
        expect(pending[0].payload.total_cents).toBe(590);
    });

    it('persists entries with a stable idempotency-key (UUIDv4 shape)', async () => {
        const entry = await enqueueOrder({ items: [{ item_id: 1, quantity: 2 }] });
        // UUIDv4 shape: 8-4-4-4-12 hex
        expect(entry.idempotencyKey).toMatch(
            /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/,
        );
        const cache = __unsafeGetCacheForTests();
        expect(cache[0].idempotencyKey).toBe(entry.idempotencyKey);
    });

    it('refuses to persist PAN / CVV / customer_email / customer_phone (PCI/PII)', async () => {
        const entry = await enqueueOrder({
            items: [{ item_id: 1, quantity: 1 }],
            card_number: '4111111111111111',
            cvv: '123',
            pan: '4111111111111111',
            customer_email: 'a@b.com',
            customer_phone: '+33600000000',
            total_cents: 590,
        });
        expect(entry.payload.card_number).toBeUndefined();
        expect(entry.payload.cvv).toBeUndefined();
        expect(entry.payload.pan).toBeUndefined();
        expect(entry.payload.customer_email).toBeUndefined();
        expect(entry.payload.customer_phone).toBeUndefined();
        expect(entry.payload.items).toBeDefined();
        expect(entry.payload.total_cents).toBe(590);
    });
});

describe('posOfflineQueue — TTL + capacity', () => {
    beforeEach(async () => {
        await clearQueue();
        await clearQueueEntries();
        vi.restoreAllMocks();
    });

    it('purgeExpired() drops entries older than TTL_MS (30 min)', async () => {
        await enqueueOrder({ items: [{ item_id: 1, quantity: 1 }] });
        expect((await listPending()).length).toBe(1);
        advanceClock(TTL_MS + 1000);
        const dropped = await purgeExpired();
        expect(dropped).toBe(1);
        expect((await listPending()).length).toBe(0);
    });

    it('caps the queue at MAX_ENTRIES (reject-new policy, oldest preserved)', async () => {
        for (let i = 0; i < MAX_ENTRIES; i += 1) {
            await enqueueOrder({ items: [{ item_id: i, quantity: 1 }] });
        }
        expect((await listPending()).length).toBe(MAX_ENTRIES);
        const rejected = await enqueueOrder({ items: [{ item_id: 999, quantity: 1 }] });
        expect(rejected).toBeNull();
        // Oldest entry (item_id=0) MUST still be present.
        const pending = await listPending();
        expect(pending.length).toBe(MAX_ENTRIES);
        expect(pending.some((e) => e.payload.items[0].item_id === 0)).toBe(true);
        expect(pending.some((e) => e.payload.items[0].item_id === 999)).toBe(false);
    });
});

describe('posOfflineQueue — sync semantics (idempotency-key + conflict)', () => {
    beforeEach(async () => {
        await clearQueue();
        await clearQueueEntries();
        vi.restoreAllMocks();
    });

    it('markSynced() removes the entry from the queue', async () => {
        const entry = await enqueueOrder({ items: [{ item_id: 1, quantity: 1 }] });
        expect((await listPending()).length).toBe(1);
        await markSynced(entry.idempotencyKey);
        expect((await listPending()).length).toBe(0);
    });

    it('markFailed() retains the entry on HTTP 409 conflict and bumps attempts', async () => {
        const entry = await enqueueOrder({ items: [{ item_id: 1, quantity: 1 }] });
        await markFailed(entry.idempotencyKey, { status: 409 });
        const pending = await listPending();
        expect(pending.length).toBe(1);
        expect(pending[0].attempts).toBeGreaterThanOrEqual(2);
        expect(pending[0].lastFailedAt).toBeTruthy();
    });

    it('markFailed() retains the entry on network error (no status)', async () => {
        const entry = await enqueueOrder({ items: [{ item_id: 1, quantity: 1 }] });
        await markFailed(entry.idempotencyKey, null);
        expect((await listPending()).length).toBe(1);
    });
});

describe('usePosOfflineState — composable reactive state', () => {
    beforeEach(async () => {
        await clearQueue();
        await clearQueueEntries();
        setOnline(true);
        vi.restoreAllMocks();
    });

    it('exposes isOnline reactive flag synced with navigator.onLine', () => {
        const state = usePosOfflineState();
        expect(state.isOnline.value).toBe(true);
        setOnline(false);
        window.dispatchEvent(new Event('offline'));
        expect(state.isOnline.value).toBe(false);
        setOnline(true);
        window.dispatchEvent(new Event('online'));
        expect(state.isOnline.value).toBe(true);
    });

    it('exposes queueDepth that increments on enqueueOrder', async () => {
        const state = usePosOfflineState();
        expect(state.queueDepth.value).toBe(0);
        await enqueueOrder({ items: [{ item_id: 1, quantity: 1 }] });
        await state.refresh();
        expect(state.queueDepth.value).toBe(1);
    });

    it('tryFlush() calls the postFn with X-Idempotency-Key header per entry', async () => {
        await enqueueOrder({ items: [{ item_id: 1, quantity: 1 }] });
        await enqueueOrder({ items: [{ item_id: 2, quantity: 1 }] });
        const state = usePosOfflineState();
        await state.refresh();
        const postFn = vi.fn(async () => ({ status: 201 }));
        const result = await state.tryFlush(postFn);
        expect(postFn).toHaveBeenCalledTimes(2);
        // Inspect headers of first call
        const [, , config] = postFn.mock.calls[0];
        expect(config?.headers?.['X-Idempotency-Key']).toMatch(/^[0-9a-f-]{36}$/);
        expect(result.synced).toBe(2);
        expect(result.failed).toBe(0);
        expect((await listPending()).length).toBe(0);
    });

    it('online event triggers an auto-flush of the queue', async () => {
        setOnline(false);
        await enqueueOrder({ items: [{ item_id: 1, quantity: 1 }] });
        const state = usePosOfflineState();
        const postFn = vi.fn(async () => ({ status: 201 }));
        state.bindAutoFlush(postFn);
        setOnline(true);
        window.dispatchEvent(new Event('online'));
        // Allow microtasks to settle.
        await new Promise((resolve) => setTimeout(resolve, 20));
        expect(postFn).toHaveBeenCalled();
        state.unbindAutoFlush();
    });
});

describe('posOfflineQueue — RED-team gap coverage', () => {
    beforeEach(async () => {
        await clearQueue();
        await clearQueueEntries();
        try { localStorage.clear(); } catch (_) {}
        vi.restoreAllMocks();
    });

    it('markSynced() on unknown key returns false and leaves the queue untouched', async () => {
        await enqueueOrder({ items: [{ item_id: 1, quantity: 1 }] });
        const result = await markSynced('00000000-0000-4000-8000-000000000000');
        expect(result).toBe(false);
        expect((await listPending()).length).toBe(1);
    });

    it('markFailed() on unknown key returns false and leaves the queue untouched', async () => {
        const entry = await enqueueOrder({ items: [{ item_id: 1, quantity: 1 }] });
        const result = await markFailed('00000000-0000-4000-8000-000000000000', { status: 409 });
        expect(result).toBe(false);
        const pending = await listPending();
        expect(pending.length).toBe(1);
        expect(pending[0].attempts).toBe(entry.attempts);
        expect(pending[0].lastFailedAt).toBeNull();
    });

    it('falls back to localStorage when IndexedDB set() throws (Safari ITP / private mode)', async () => {
        idbKeyvalThrowFlag.set = true;
        try {
            const entry = await enqueueOrder({ items: [{ item_id: 42, quantity: 1 }], total_cents: 100 });
            expect(entry).toBeTruthy();
            const rawLs = localStorage.getItem('__pos_idb_fallback__:pos:offline-queue:v1');
            expect(rawLs).toBeTruthy();
            const parsed = JSON.parse(rawLs);
            expect(Array.isArray(parsed)).toBe(true);
            expect(parsed[0].idempotencyKey).toBe(entry.idempotencyKey);
        } finally {
            idbKeyvalThrowFlag.set = false;
        }
    });

    it('clearQueue() empties the in-memory cache and the persisted store', async () => {
        await enqueueOrder({ items: [{ item_id: 1, quantity: 1 }] });
        await enqueueOrder({ items: [{ item_id: 2, quantity: 1 }] });
        expect(__unsafeGetCacheForTests().length).toBe(2);
        await clearQueue();
        expect(__unsafeGetCacheForTests().length).toBe(0);
        expect((await listPending()).length).toBe(0);
    });
});
