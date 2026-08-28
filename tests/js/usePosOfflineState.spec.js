import 'fake-indexeddb/auto';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import {
    __unsafeResetMemoryForTests,
    clearQueue,
    enqueueOrder,
    listPending,
    listQuarantined,
    MAX_REPLAY_ATTEMPTS,
} from '../../resources/js/helpers/posOfflineQueue';
import { clearQueueEntries } from '../../resources/js/helpers/posOfflineQueueDb';
import { usePosOfflineState } from '../../resources/js/composables/usePosOfflineState';

function signedPayload(itemId = 1) {
    return {
        items: JSON.stringify([{ item_id: itemId, quantity: 1 }]),
        quote_token: '123e4567-e89b-42d3-a456-426614174000',
        quote_signature: 'b'.repeat(64),
        pos_payment_method: 1,
        pos_received_amount: 20,
        total: 10,
    };
}

function setOnline(value) {
    Object.defineProperty(navigator, 'onLine', { value, configurable: true, writable: true });
}

describe('usePosOfflineState — safe quarantine contract', () => {
    beforeEach(async () => {
        await clearQueue();
        await clearQueueEntries();
        try { localStorage.clear(); } catch (_) {}
        setOnline(true);
        vi.restoreAllMocks();
    });

    it('never POSTs a legacy unsigned entry, manually or on the online event', async () => {
        await enqueueOrder({ items: [{ item_id: 9, quantity: 1 }], total_cents: 990 });
        const state = usePosOfflineState();
        const postFn = vi.fn(async () => ({ status: 201 }));
        await state.refresh();

        expect(state.queueDepth.value).toBe(0);
        expect(state.quarantineDepth.value).toBe(1);
        expect((await state.tryFlush(postFn)).synced).toBe(0);
        expect(postFn).not.toHaveBeenCalled();

        state.bindAutoFlush(postFn);
        setOnline(false);
        window.dispatchEvent(new Event('offline'));
        setOnline(true);
        window.dispatchEvent(new Event('online'));
        await new Promise((resolve) => setTimeout(resolve, 20));
        expect(postFn).not.toHaveBeenCalled();
        state.unbindAutoFlush();
    });

    it('retains the quarantined proof after an in-memory reload', async () => {
        const entry = await enqueueOrder({ items: [{ item_id: 10, quantity: 1 }] });
        __unsafeResetMemoryForTests();

        const quarantined = await listQuarantined();
        expect(quarantined).toHaveLength(1);
        expect(quarantined[0].idempotencyKey).toBe(entry.idempotencyKey);
        expect(quarantined[0].quarantineReason).toBe('legacy_unsigned');
    });

    it('bounds network retries for signed entries and never retries after quarantine', async () => {
        await enqueueOrder(signedPayload(11));
        const state = usePosOfflineState();
        const postFn = vi.fn(async () => { throw new Error('network down'); });

        for (let i = 0; i < MAX_REPLAY_ATTEMPTS; i += 1) await state.tryFlush(postFn);
        expect(postFn).toHaveBeenCalledTimes(MAX_REPLAY_ATTEMPTS);
        expect(await listPending()).toHaveLength(0);
        expect((await listQuarantined())[0].quarantineReason).toBe('attempt_limit');

        await state.tryFlush(postFn);
        expect(postFn).toHaveBeenCalledTimes(MAX_REPLAY_ATTEMPTS);
        state.unbindAutoFlush();
    });

    it('quarantines a terminal 422 after one call but keeps 429 retryable', async () => {
        await enqueueOrder(signedPayload(12));
        const terminalState = usePosOfflineState();
        const terminalPost = vi.fn(async () => { throw { response: { status: 422 } }; });
        await terminalState.tryFlush(terminalPost);
        expect(terminalPost).toHaveBeenCalledTimes(1);
        expect((await listQuarantined())[0].quarantineReason).toBe('terminal_http_422');
        terminalState.unbindAutoFlush();

        await clearQueue();
        await enqueueOrder(signedPayload(13));
        const retryState = usePosOfflineState();
        const retryPost = vi.fn(async () => { throw { response: { status: 429 } }; });
        await retryState.tryFlush(retryPost);
        expect(retryPost).toHaveBeenCalledTimes(1);
        expect(await listPending()).toHaveLength(1);
        expect(await listQuarantined()).toHaveLength(0);
        retryState.unbindAutoFlush();
    });
});
