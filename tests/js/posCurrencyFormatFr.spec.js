/**
 * [abuse-e2e CAISSE-FMT-01 2026-06-16] The POS caisse ticket (Sous-total / Total / cart lines /
 * cart bar) rendered money via appService.currencyFormat → en-US "0.00€" (period decimal, glued,
 * no NBSP) while the rest of the FR-locale admin (BORNE list "5,30 €", dashboard "2,00 €") uses
 * the canonical formatPrice() (Intl fr-FR EUR, comma + NBSP). Live-confirmed inconsistency on
 * :8766 (ticket showed "0.00€", same page's order list showed "5,30 €").
 *
 * Heal: PosComponent's local currencyFormat() now routes through formatPrice(). This spec invokes
 * the REAL component method (not source text) and proves the FR contract — mutation-resistant: a
 * revert to the appService en-US path would turn it RED.
 */
import { describe, it, expect } from 'vitest';
import PosComponent from '../../resources/js/components/admin/pos/PosComponent.vue';
import { formatPrice } from '../../resources/js/helpers/formatPrice';

const fr = PosComponent.methods.currencyFormat;

describe('POS caisse currency rendering — FR EUR (CAISSE-FMT-01)', () => {
    it('renders a comma decimal separator, never the en-US period-glued "X.XX€"', () => {
        const out = fr.call(PosComponent.methods, 5.3);
        expect(out).toMatch(/^5,30\s?€$/u);   // FR: comma + (narrow) space + €
        expect(out).not.toMatch(/5\.30€/);     // NOT en-US glued
        expect(out).not.toContain('.');        // no period decimal
    });

    it('renders zero as "0,00 €" (was "0.00€" before the heal)', () => {
        const out = fr.call(PosComponent.methods, 0);
        expect(out).toMatch(/^0,00\s?€$/u);
        expect(out).not.toMatch(/0\.00€/);
    });

    it('is identical to the canonical formatPrice() SSOT for the same value', () => {
        for (const v of [0, 2, 5.3, 10, 8.5, 37063.87]) {
            expect(fr.call(PosComponent.methods, v)).toBe(formatPrice(v));
        }
    });
});
