import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

const ROOT = resolve(__dirname, '../../..');

/**
 * M1-02 — an offline CASH order must be enqueued with a numeric
 * pos_received_amount. Offline the payment modal cannot open, so the form's
 * pos_received_amount stays null; on replay PosOrderRequest requires it for CASH
 * → 422 → the queued cash sale is silently lost. The offline-enqueue branch in
 * PosComponent must set pos_received_amount (= order total) BEFORE enqueueOrder.
 *
 * Source sentinel (the offline path is a component method, heavy to mount): locks
 * that the offline branch sets pos_received_amount from the total before enqueue.
 */
describe('M1-02 offline CASH pos_received_amount', () => {
    const src = readFileSync(resolve(ROOT, 'resources/js/components/admin/pos/PosComponent.vue'), 'utf8');

    it('offline enqueue sets pos_received_amount from the total before enqueueOrder', () => {
        const offlineIdx = src.indexOf('navigator.onLine === false');
        expect(offlineIdx).toBeGreaterThan(-1);

        const enqueueIdx = src.indexOf('enqueueOrder', offlineIdx);
        expect(enqueueIdx).toBeGreaterThan(offlineIdx);

        const block = src.slice(offlineIdx, enqueueIdx);
        // Between the offline gate and enqueueOrder, pos_received_amount must be set
        // from the order total (grandTotal / form.total).
        expect(block).toMatch(/pos_received_amount/);
        expect(block).toMatch(/grandTotal|form\.total/);
    });
});
