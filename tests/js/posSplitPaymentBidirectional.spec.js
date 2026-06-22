/**
 * [iter12 2026-05-09] POS multi-paiement bidirectional split helpers.
 *
 * Owner reported on the POS Caisse payment modal :
 *  - Need "10€ card + reste cash" auto-balance (or vice versa)
 *  - Need split-by-N-people with realistic tendered suggestion
 *  - "Order quote expired" → tracked separately via OrderQuoteService TTL
 *    bump (60s → 300s) + config('quote.ttl_seconds')
 *
 * This spec covers the new pure helpers :
 *  - autoBalanceTranches(tranches, totalCents, editedIndex)
 *  - computeRemainderForNextTrancheCents(totalCents, tranches)
 *  - suggestTenderedForCashTranches(tranches, step)
 */

import { describe, it, expect } from 'vitest';

import {
    autoBalanceTranches,
    computeRemainderForNextTrancheCents,
    suggestTenderedForCashTranches,
    splitEqually,
    toCents,
    fromCents,
} from '../../resources/js/helpers/posSplitPayment';

import posPaymentMethodEnum from '../../resources/js/enums/modules/posPaymentMethodEnum';

describe('autoBalanceTranches — bidirectional split', () => {
    it('rebalances 2-tranche pair when first tranche edited', () => {
        const tranches = [
            { id: 't1', mode: posPaymentMethodEnum.CARD, amount: 10.00, tendered: null, note: null },
            { id: 't2', mode: posPaymentMethodEnum.CASH, amount: 10.50, tendered: null, note: null },
        ];

        const balanced = autoBalanceTranches(tranches, toCents(20.50), 0);

        expect(balanced[0].amount).toBe(10.00);
        expect(balanced[1].amount).toBe(10.50);
    });

    it('rebalances when card amount changes — cash absorbs remainder', () => {
        const tranches = [
            { id: 't1', mode: posPaymentMethodEnum.CARD, amount: 5.00, tendered: null, note: null },
            { id: 't2', mode: posPaymentMethodEnum.CASH, amount: 0.00, tendered: null, note: null },
        ];

        const balanced = autoBalanceTranches(tranches, toCents(20.50), 0);

        expect(balanced[0].amount).toBe(5.00);
        expect(balanced[1].amount).toBe(15.50);
    });

    it('rebalances when cash amount changes — card absorbs remainder', () => {
        const tranches = [
            { id: 't1', mode: posPaymentMethodEnum.CARD, amount: 0.00, tendered: null, note: null },
            { id: 't2', mode: posPaymentMethodEnum.CASH, amount: 12.30, tendered: 15.00, note: null },
        ];

        const balanced = autoBalanceTranches(tranches, toCents(30.00), 1);

        expect(balanced[0].amount).toBe(17.70);
        expect(balanced[1].amount).toBe(12.30);
    });

    it('returns input unchanged if total invalid', () => {
        const tranches = [
            { id: 't1', mode: posPaymentMethodEnum.CARD, amount: 10, tendered: null, note: null },
            { id: 't2', mode: posPaymentMethodEnum.CASH, amount: 10, tendered: null, note: null },
        ];
        expect(autoBalanceTranches(tranches, 0, 0)).toBe(tranches);
        expect(autoBalanceTranches(tranches, -100, 0)).toBe(tranches);
    });

    it('returns input unchanged if edited index out of range', () => {
        const tranches = [
            { id: 't1', mode: posPaymentMethodEnum.CARD, amount: 10, tendered: null, note: null },
        ];
        expect(autoBalanceTranches(tranches, toCents(20), 5)).toBe(tranches);
        expect(autoBalanceTranches(tranches, toCents(20), -1)).toBe(tranches);
    });

    it('returns input if amount edited exceeds total (overpay protection)', () => {
        const tranches = [
            { id: 't1', mode: posPaymentMethodEnum.CARD, amount: 30, tendered: null, note: null },
            { id: 't2', mode: posPaymentMethodEnum.CASH, amount: 0, tendered: null, note: null },
        ];

        const balanced = autoBalanceTranches(tranches, toCents(20), 0);

        expect(balanced).toBe(tranches);
    });

    it('handles 3-tranche policy auto-pair (next absorbs)', () => {
        const tranches = [
            { id: 't1', mode: posPaymentMethodEnum.CARD, amount: 5, tendered: null, note: null },
            { id: 't2', mode: posPaymentMethodEnum.CASH, amount: 0, tendered: null, note: null },
            { id: 't3', mode: posPaymentMethodEnum.OTHER, amount: 0, tendered: null, note: null },
        ];

        const balanced = autoBalanceTranches(tranches, toCents(30), 0, 'auto-pair');

        expect(balanced[0].amount).toBe(5);
        expect(balanced[1].amount).toBe(25);
        expect(balanced[2].amount).toBe(0);
    });

    it('handles 3-tranche policy last-tranche', () => {
        const tranches = [
            { id: 't1', mode: posPaymentMethodEnum.CARD, amount: 5, tendered: null, note: null },
            { id: 't2', mode: posPaymentMethodEnum.OTHER, amount: 10, tendered: null, note: null },
            { id: 't3', mode: posPaymentMethodEnum.CASH, amount: 0, tendered: null, note: null },
        ];

        const balanced = autoBalanceTranches(tranches, toCents(30), 0, 'last-tranche');

        expect(balanced[0].amount).toBe(5);
        expect(balanced[2].amount).toBe(25);
    });

    it('round-trips correctly with non-round totals (€20.50 split)', () => {
        const tranches = [
            { id: 't1', mode: posPaymentMethodEnum.CARD, amount: 10.00, tendered: null, note: null },
            { id: 't2', mode: posPaymentMethodEnum.CASH, amount: 0, tendered: null, note: null },
        ];

        const balanced = autoBalanceTranches(tranches, toCents(20.50), 0);

        expect(balanced[1].amount).toBe(10.50);
    });
});

describe('computeRemainderForNextTrancheCents', () => {
    it('returns total when no tranches', () => {
        expect(computeRemainderForNextTrancheCents(toCents(20.50), [])).toBe(toCents(20.50));
    });

    it('returns positive remainder', () => {
        const tranches = [
            { id: 't1', mode: posPaymentMethodEnum.CARD, amount: 8.00, tendered: null },
        ];
        expect(computeRemainderForNextTrancheCents(toCents(20.50), tranches)).toBe(toCents(12.50));
    });

    it('returns 0 when already covered', () => {
        const tranches = [
            { id: 't1', mode: posPaymentMethodEnum.CARD, amount: 20.50, tendered: null },
        ];
        expect(computeRemainderForNextTrancheCents(toCents(20.50), tranches)).toBe(0);
    });

    it('returns 0 when overpaid (clamped)', () => {
        const tranches = [
            { id: 't1', mode: posPaymentMethodEnum.CARD, amount: 25.00, tendered: null },
        ];
        expect(computeRemainderForNextTrancheCents(toCents(20.50), tranches)).toBe(0);
    });
});

describe('suggestTenderedForCashTranches', () => {
    it('rounds CASH amount UP to next €5 by default', () => {
        const tranches = [
            { id: 't1', mode: posPaymentMethodEnum.CASH, amount: 10.50, tendered: null, note: null },
            { id: 't2', mode: posPaymentMethodEnum.CASH, amount: 14.30, tendered: null, note: null },
        ];

        const suggested = suggestTenderedForCashTranches(tranches);

        expect(suggested[0].tendered).toBe(15);
        expect(suggested[1].tendered).toBe(15);
    });

    it('rounds UP to next €10 when step=10', () => {
        const tranches = [
            { id: 't1', mode: posPaymentMethodEnum.CASH, amount: 10.50, tendered: null, note: null },
            { id: 't2', mode: posPaymentMethodEnum.CASH, amount: 22.00, tendered: null, note: null },
        ];

        const suggested = suggestTenderedForCashTranches(tranches, 10);

        expect(suggested[0].tendered).toBe(20);
        expect(suggested[1].tendered).toBe(30);
    });

    it('does NOT touch CARD/OTHER tranches', () => {
        const tranches = [
            { id: 't1', mode: posPaymentMethodEnum.CARD, amount: 10, tendered: null, note: null },
            { id: 't2', mode: posPaymentMethodEnum.OTHER, amount: 5, tendered: null, note: null },
            { id: 't3', mode: posPaymentMethodEnum.CASH, amount: 5, tendered: null, note: null },
        ];

        const suggested = suggestTenderedForCashTranches(tranches);

        expect(suggested[0].tendered).toBe(null);
        expect(suggested[1].tendered).toBe(null);
        expect(suggested[2].tendered).toBe(5);
    });

    it('preserves existing tendered (never overwrites)', () => {
        const tranches = [
            { id: 't1', mode: posPaymentMethodEnum.CASH, amount: 10, tendered: 50, note: null },
        ];

        const suggested = suggestTenderedForCashTranches(tranches);

        expect(suggested[0].tendered).toBe(50);
    });

    it('handles non-array input gracefully', () => {
        expect(suggestTenderedForCashTranches(null)).toBe(null);
        expect(suggestTenderedForCashTranches(undefined)).toBe(undefined);
    });
});

describe('integration — splitEqually + autoBalance + suggestTendered', () => {
    it('typical split-3-people-cash scenario yields suggested tenders', () => {
        const tranches = splitEqually(30.00, 3, posPaymentMethodEnum.CASH);

        const suggested = suggestTenderedForCashTranches(tranches);

        expect(suggested.length).toBe(3);
        suggested.forEach((tr) => {
            expect(tr.tendered).toBe(10);
        });
    });

    it('typical 50/50 cash + card scenario rebalances on card edit', () => {
        let tranches = splitEqually(20.50, 2, posPaymentMethodEnum.CASH);
        tranches[0] = { ...tranches[0], mode: posPaymentMethodEnum.CARD, amount: 8.00 };

        const balanced = autoBalanceTranches(tranches, toCents(20.50), 0);

        expect(balanced[0].mode).toBe(posPaymentMethodEnum.CARD);
        expect(balanced[0].amount).toBe(8.00);
        expect(balanced[1].mode).toBe(posPaymentMethodEnum.CASH);
        expect(balanced[1].amount).toBe(12.50);
    });
});
