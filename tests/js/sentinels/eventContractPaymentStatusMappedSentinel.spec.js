import { describe, it, expect } from 'vitest';
import { BROADCAST_MAP, EVENT_TYPES } from '../../../resources/js/services/eventContract';

// [SYNC 2026-08-05 · GOAL T-1.1.1/T-1.1.2] `OrderPaymentStatusChanged` (refund gateway / flip
// paiement) DOIT figurer dans BROADCAST_MAP : sinon les surfaces le reçoivent sans validation de
// type et, surtout, aucun handler ne s'y abonnait → un refund n'était jamais poussé (latence poll
// ≤60s), et une commande remboursée orphelinait la caisse. Sentinelle de complétude (anti-régression).
describe('eventContract — OrderPaymentStatusChanged mappé (complétude push)', () => {
    it('EVENT_TYPES expose le type PHP order.payment_status_changed', () => {
        expect(EVENT_TYPES.ORDER_PAYMENT_STATUS_CHANGED).toBe('order.payment_status_changed');
    });

    it('BROADCAST_MAP mappe OrderPaymentStatusChanged → ORDER_PAYMENT_STATUS_CHANGED', () => {
        expect(BROADCAST_MAP.OrderPaymentStatusChanged).toBe(EVENT_TYPES.ORDER_PAYMENT_STATUS_CHANGED);
    });
});
