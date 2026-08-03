/**
 * posSplitPaymentValidation.spec.js
 *
 * Mission : CV1-POS-SPLIT-PAYMENT-001
 * Sentinel JS for the pure helpers in resources/js/helpers/posSplitPayment.js
 *
 * Why these tests
 * ───────────────
 * The split-payment surface is fully client-side until PLAN_P12 ships
 * backend storage. The cashier can already produce arbitrary tender
 * combinations (cash + card, N-way split, partial cash with change).
 * If these helpers drift, the cashier will see "Confirmer" enabled with
 * the wrong reste-dû — a P0 NF525-adjacent bug.
 *
 * Coverage
 * ────────
 *  - cents arithmetic (no float drift on .01 + .02 etc.)
 *  - validateTranche: missing mode / amount / tendered / underfunded cash
 *  - computeChangeCents: only for CASH, only when tendered ≥ amount
 *  - splitEqually: 30 € / 3 → 10/10/10  ;  30.01 € / 3 → 10/10/10.01
 *  - canConfirm: only when sum ≈ total AND every tranche valid
 *  - serializeTranches: API-shaped payload with cash change
 */

import { describe, it, expect } from 'vitest';
import posPaymentMethodEnum from '../../resources/js/enums/modules/posPaymentMethodEnum';
import {
    toCents,
    fromCents,
    validateTranche,
    computeChangeCents,
    sumCoveredCents,
    remainingCents,
    canConfirm,
    splitEqually,
    splitEquallyCents,
    serializeTranches,
    totalChangeCents,
} from '../../resources/js/helpers/posSplitPayment';

const CASH = posPaymentMethodEnum.CASH;
const CARD = posPaymentMethodEnum.CARD;

const cash = (amount, tendered = null) => ({
    id: `cash_${amount}`,
    mode: CASH,
    amount,
    tendered,
    note: null,
});

const card = (amount) => ({
    id: `card_${amount}`,
    mode: CARD,
    amount,
    tendered: null,
    note: null,
});

describe('posSplitPayment — cents arithmetic', () => {
    it('toCents handles float drift (0.1 + 0.2 == 0.30000000004)', () => {
        expect(toCents(0.1 + 0.2)).toBe(30);
        expect(toCents(10.005)).toBeGreaterThanOrEqual(1000); // half-up or banker, not 1000.5
    });

    it('toCents accepts strings and bad input', () => {
        expect(toCents('12.50')).toBe(1250);
        expect(toCents(null)).toBe(0);
        expect(toCents(undefined)).toBe(0);
        expect(toCents('abc')).toBe(0);
    });

    it('fromCents reconstructs euro number with 2 decimals', () => {
        expect(fromCents(1001)).toBeCloseTo(10.01, 2);
        expect(fromCents(0)).toBe(0);
        expect(fromCents(2599)).toBeCloseTo(25.99, 2);
    });
});

describe('posSplitPayment — validateTranche', () => {
    it('rejects null / non-object', () => {
        expect(validateTranche(null).valid).toBe(false);
        expect(validateTranche('foo').valid).toBe(false);
    });

    it('rejects unknown payment mode', () => {
        const r = validateTranche({ mode: 999, amount: 10 });
        expect(r.valid).toBe(false);
        expect(r.errors.mode).toBe('invalid_mode');
    });

    it('rejects amount <= 0', () => {
        expect(validateTranche({ mode: CASH, amount: 0, tendered: 10 }).valid).toBe(false);
        expect(validateTranche({ mode: CASH, amount: -5, tendered: 10 }).valid).toBe(false);
    });

    it('CARD tranche needs no tendered', () => {
        const r = validateTranche(card(15));
        expect(r.valid).toBe(true);
    });

    it('CASH tranche WITHOUT tendered is invalid', () => {
        const r = validateTranche(cash(10, null));
        expect(r.valid).toBe(false);
        expect(r.errors.tendered).toBe('tendered_required');
    });

    it('CASH tranche with tendered < amount is invalid', () => {
        const r = validateTranche(cash(10, 5));
        expect(r.valid).toBe(false);
        expect(r.errors.tendered).toBe('tendered_below_amount');
    });

    it('CASH tranche with tendered == amount is valid (no change)', () => {
        expect(validateTranche(cash(10, 10)).valid).toBe(true);
    });

    it('CASH tranche with tendered > amount is valid', () => {
        expect(validateTranche(cash(10, 12)).valid).toBe(true);
    });
});

describe('posSplitPayment — computeChangeCents', () => {
    it('returns 0 for non-cash', () => {
        expect(computeChangeCents(card(15))).toBe(0);
    });

    it('returns 0 when tendered missing or below amount', () => {
        expect(computeChangeCents(cash(10, null))).toBe(0);
        expect(computeChangeCents(cash(10, 5))).toBe(0);
    });

    it('returns exact cents diff when tendered >= amount', () => {
        expect(computeChangeCents(cash(10, 12))).toBe(200);
        expect(computeChangeCents(cash(10.05, 20))).toBe(995);
    });

    it('avoids float drift (10.10 tendered, amount 10.05 → 5 cts)', () => {
        expect(computeChangeCents(cash(10.05, 10.10))).toBe(5);
    });
});

describe('posSplitPayment — sumCoveredCents / remainingCents', () => {
    it('sums only valid tranches', () => {
        const arr = [cash(10, 10), card(15), cash(5, null /* invalid */)];
        expect(sumCoveredCents(arr)).toBe(2500); // invalid cash 5 not counted
    });

    it('remainingCents = totalCents − coveredCents', () => {
        const arr = [cash(10, 10), card(15)];
        expect(remainingCents(2500, arr)).toBe(0);
        expect(remainingCents(3000, arr)).toBe(500);
    });
});

describe('posSplitPayment — canConfirm', () => {
    it('false on empty tranches', () => {
        expect(canConfirm(2500, [])).toBe(false);
    });

    it('false if any tranche invalid', () => {
        const arr = [cash(10, null), card(15)];
        expect(canConfirm(2500, arr)).toBe(false);
    });

    it('true when sum exactly == total', () => {
        const arr = [cash(10, 10), card(15)];
        expect(canConfirm(2500, arr)).toBe(true);
    });

    it('true within 1 cent slack (rounding noise)', () => {
        // total = 25.01; tranches sum 25.00 → remaining = 1 cent
        const arr = [cash(10, 10), card(15)];
        expect(canConfirm(2501, arr)).toBe(true);
    });

    it('false if reste due > 1 cent', () => {
        const arr = [cash(10, 10), card(15)];
        expect(canConfirm(2502, arr)).toBe(false); // 2 cts unpaid
    });

    it('tolerates up to 1 € overpay (cashier-side rounding)', () => {
        // total = 25.00 ; sum tranches = 25.50 → overpay 50 cts (within 100c slack)
        const arr = [cash(10, 10), card(15.50)];
        expect(canConfirm(2500, arr)).toBe(true);
    });

    it('rejects overpay > 1 € (likely user error)', () => {
        const arr = [cash(10, 10), card(20)];
        expect(canConfirm(2500, arr)).toBe(false); // overpay 5 €
    });
});

describe('posSplitPayment — splitEquallyCents', () => {
    it('30 € / 3 → 10/10/10', () => {
        expect(splitEquallyCents(3000, 3)).toEqual([1000, 1000, 1000]);
    });

    it('30.01 € / 3 → 10.00/10.00/10.01 (remainder on last)', () => {
        expect(splitEquallyCents(3001, 3)).toEqual([1000, 1000, 1001]);
    });

    it('100 € / 7 → 6×14.28 + 1×14.32 (sum exact)', () => {
        const parts = splitEquallyCents(10000, 7);
        const sum = parts.reduce((a, b) => a + b, 0);
        expect(sum).toBe(10000);
        expect(parts.length).toBe(7);
    });

    it('n=1 returns single full part', () => {
        expect(splitEquallyCents(2599, 1)).toEqual([2599]);
    });

    it('n<1 clamped to 1', () => {
        expect(splitEquallyCents(2599, 0)).toEqual([2599]);
        expect(splitEquallyCents(2599, -3)).toEqual([2599]);
    });
});

describe('posSplitPayment — splitEqually (Eur API)', () => {
    it('30 € / 3 returns 3 cash stubs of 10.00 with tendered pre-filled (exact change)', () => {
        // [iter15-BUG-multi-person 2026-05-10] cash tranches pre-fill tendered=amount
        // so they're immediately VALID (sumCoveredCents counts them) — otherwise
        // the cashier sees "Couvert: 0€" right after split-equally and the
        // multi-person UX breaks.
        const parts = splitEqually(30, 3);
        expect(parts.length).toBe(3);
        parts.forEach((p) => {
            expect(p.mode).toBe(CASH);
            expect(p.amount).toBeCloseTo(10, 2);
            expect(p.tendered).toBeCloseTo(10, 2);
            expect(typeof p.id).toBe('string');
        });
    });

    it('non-cash default keeps tendered null', () => {
        // CARD/MOBILE/etc never need tendered; pre-fill is cash-only.
        const parts = splitEqually(30, 3, /* CARD */ 2);
        parts.forEach((p) => {
            expect(p.mode).toBe(2);
            expect(p.tendered).toBeNull();
        });
    });

    it('30.01 € / 3 → 10.00, 10.00, 10.01', () => {
        const parts = splitEqually(30.01, 3);
        expect(parts[0].amount).toBeCloseTo(10.00, 2);
        expect(parts[1].amount).toBeCloseTo(10.00, 2);
        expect(parts[2].amount).toBeCloseTo(10.01, 2);
    });

    it('IDs are unique (v-for key safe)', () => {
        const parts = splitEqually(50, 5);
        const ids = parts.map((p) => p.id);
        expect(new Set(ids).size).toBe(5);
    });
});

describe('posSplitPayment — serializeTranches', () => {
    it('emits API-shaped objects with change cents', () => {
        const arr = [cash(10, 12), card(15)];
        const out = serializeTranches(arr);
        expect(out).toEqual([
            { mode: CASH, amount: 10, tendered: 12, change: 2, note: null },
            { mode: CARD, amount: 15, tendered: null, change: 0, note: null },
        ]);
    });

    it('skips tendered for non-cash modes', () => {
        const out = serializeTranches([card(15)]);
        expect(out[0].tendered).toBeNull();
        expect(out[0].change).toBe(0);
    });
});

describe('posSplitPayment — totalChangeCents', () => {
    it('sums change across CASH tranches only', () => {
        const arr = [cash(10, 12), cash(5, 10), card(15)];
        // change = 2 + 5 = 7 €
        expect(totalChangeCents(arr)).toBe(700);
    });
});

describe('posSplitPayment — workflow scenarios', () => {
    it('removeTranche scenario: array decrements coverage', () => {
        const arr = [cash(10, 10), card(15)];
        expect(sumCoveredCents(arr)).toBe(2500);
        arr.splice(0, 1);
        expect(sumCoveredCents(arr)).toBe(1500);
    });

    it('cashier types 25 € total : 10€ cash (12 tendered) + 15€ card → confirmable', () => {
        const total = toCents(25);
        const arr = [cash(10, 12), card(15)];
        expect(canConfirm(total, arr)).toBe(true);
        expect(remainingCents(total, arr)).toBe(0);
        expect(totalChangeCents(arr)).toBe(200);
    });

    it('cashier under-funds: 10€ cash + 10€ card on 25€ → not confirmable', () => {
        const total = toCents(25);
        const arr = [cash(10, 10), card(10)];
        expect(canConfirm(total, arr)).toBe(false);
        expect(remainingCents(total, arr)).toBe(500);
    });
});
