import { describe, expect, it } from 'vitest';

import {
    buildNf525Footer,
    formatPaymentsBreakdown,
    receiptWidthClass,
    normalizeReceiptVariations,
    normalizeReceiptExtras,
} from '../../resources/js/helpers/posReceiptBuilder';

describe('posReceiptBuilder', () => {
    it('formatPaymentsBreakdown maps payments_breakdown when present', () => {
        const order = {
            payments_breakdown: [
                { method: 'card', amount: 10.5, currency_amount: '10,50 €', change_amount: 0, reference: 'R1' },
            ],
        };
        const lines = formatPaymentsBreakdown(order);
        expect(lines).toHaveLength(1);
        expect(lines[0].method).toBe('CARD');
        expect(lines[0].amount).toBe(10.5);
        expect(lines[0].currency_amount).toBe('10,50 €');
        expect(lines[0].reference).toBe('R1');
    });

    it('formatPaymentsBreakdown falls back to pos_payment_method with one synthetic line', () => {
        const order = {
            payments_breakdown: [],
            pos_payment_method: 1,
            pos_received_amount: 50,
            total: 42,
            pos_received_currency_amount: '50,00 €',
            cash_back_amount: 8,
        };
        const lines = formatPaymentsBreakdown(order);
        expect(lines).toHaveLength(1);
        expect(lines[0].method).toBe(1);
        expect(lines[0].amount).toBe(50);
        expect(lines[0].change_amount).toBe(8);
    });

    it('formatPaymentsBreakdown returns empty array when no payment info', () => {
        expect(formatPaymentsBreakdown({})).toEqual([]);
    });

    it('buildNf525Footer includes fiscal, fingerprint, and legal lines when set', () => {
        const order = {
            fiscal_sequence_no: 42,
            audit_chain_fingerprint: 'abcdabcdabcd',
            pos_legal_footer: 'Lorem legal.',
        };
        const lines = buildNf525Footer(order);
        expect(lines.map((l) => l.key)).toEqual(['fiscal_ticket_no', 'audit_fingerprint', 'legal_mentions']);
        expect(lines[0].value).toBe(42);
        expect(lines[1].value).toBe('abcdabcdabcd');
        expect(lines[2].value).toBe('Lorem legal.');
    });

    it('receiptWidthClass maps paper width to CSS class', () => {
        expect(receiptWidthClass(58)).toBe('receipt-58mm');
        expect(receiptWidthClass(80)).toBe('receipt-80mm');
        expect(receiptWidthClass(undefined)).toBe('receipt-58mm');
    });

    /**
     * [V14 GLOBAL FINDING G-1 P0]
     * Sentinel : Receipt must read `composition_snapshot` (T07) lines —
     * shape `[{variation_id, attribute_name, variation_name, quantity,
     * unit_price}]`. Without the helper, the template that historically
     * read `variation.name` would print "undefined" for snapshot rows.
     */
    it('normalizeReceiptVariations reads snapshot lines (T07 NF525 immutability)', () => {
        const snapshot = [
            { variation_id: 5, attribute_name: 'Viande', variation_name: 'Steak', quantity: 3, unit_price: 0 },
            { variation_id: 7, attribute_name: 'Viande', variation_name: 'Poulet', quantity: 1, unit_price: 0 },
        ];
        const lines = normalizeReceiptVariations(snapshot);
        expect(lines).toEqual([
            { label: 'Viande', name: 'Steak', quantity: 3 },
            { label: 'Viande', name: 'Poulet', quantity: 1 },
        ]);
    });

    /**
     * [V14 GLOBAL FINDING G-1 P0]
     * Sentinel : Backward-compat — legacy item_variations JSON shape
     * `[{id, variation_name, name}]` (pre-T07) must keep working.
     */
    it('normalizeReceiptVariations reads legacy item_variations shape', () => {
        const legacy = [
            { id: 5, variation_name: 'Viande', name: 'Steak' },
            { id: 7, variation_name: 'Sauce', name: 'Algerienne' },
        ];
        const lines = normalizeReceiptVariations(legacy);
        expect(lines).toEqual([
            { label: 'Viande', name: 'Steak', quantity: 1 },
            { label: 'Sauce', name: 'Algerienne', quantity: 1 },
        ]);
    });

    /**
     * [V14 GLOBAL FINDING G-1 P0]
     * Sentinel : Very legacy `{attrId: {variation_name, name}}` keyed-object
     * shape (some older orders) must also normalize.
     */
    it('normalizeReceiptVariations reads keyed-object legacy shape', () => {
        const ancient = {
            12: { variation_name: 'Viande', name: 'Steak' },
            14: { variation_name: 'Sauce', name: 'Algerienne' },
        };
        const lines = normalizeReceiptVariations(ancient);
        expect(lines).toHaveLength(2);
        expect(lines[0]).toMatchObject({ label: 'Viande', name: 'Steak', quantity: 1 });
        expect(lines[1]).toMatchObject({ label: 'Sauce', name: 'Algerienne', quantity: 1 });
    });

    /**
     * [V14 GLOBAL FINDING G-1 P0]
     * Sentinel : Defensive — null / undefined / unrelated input never
     * crashes the receipt template.
     */
    it('normalizeReceiptVariations returns [] on null/undefined/garbage', () => {
        expect(normalizeReceiptVariations(null)).toEqual([]);
        expect(normalizeReceiptVariations(undefined)).toEqual([]);
        expect(normalizeReceiptVariations([])).toEqual([]);
        expect(normalizeReceiptVariations([null, 0, 'x'])).toEqual([]);
    });

    /**
     * [V14 GLOBAL FINDING G-2 P1]
     * Sentinel : Receipt must show `quantity` for variations when > 1
     * (multi-qty parity with the cart UI). Returned quantity is a Number.
     */
    it('normalizeReceiptVariations preserves quantity > 1 (multi-qty)', () => {
        const lines = normalizeReceiptVariations([
            { variation_id: 5, attribute_name: 'Viande', variation_name: 'Steak', quantity: 4 },
        ]);
        expect(lines[0].quantity).toBe(4);
        expect(typeof lines[0].quantity).toBe('number');
    });

    /**
     * [V14 GLOBAL FINDING G-1 P0]
     * Sentinel : Extras snapshot shape `[{extra_id, name, quantity}]`
     * is normalized identically. Quantity defaults to 1 when missing.
     */
    it('normalizeReceiptExtras reads snapshot + legacy + null', () => {
        expect(normalizeReceiptExtras([
            { extra_id: 12, name: 'Cheddar', quantity: 2 },
            { extra_id: 13, name: 'Bacon' },
        ])).toEqual([
            { name: 'Cheddar', quantity: 2 },
            { name: 'Bacon', quantity: 1 },
        ]);
        expect(normalizeReceiptExtras(null)).toEqual([]);
    });
});
