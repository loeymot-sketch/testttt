/**
 * [abuse-e2e A2 2026-06-16] appService.currencyFormat was en-US: `parseFloat().toFixed()+symbol`
 * → "0.00€" (period decimal, glued, no thousands grouping). It is the formatter behind ~11 admin +
 * customer-storefront + table .vue surfaces (Checkout, Coupon, Item, Frontend nav/cart, Table),
 * while the kiosk + (now) POS use the FR `formatPrice()` SSOT → the app rendered money two ways.
 *
 * Heal: route currencyFormat through Intl fr-FR (comma decimal + NBSP grouping) while still honoring
 * the configured symbol + position + decimal. This spec invokes the REAL service method and is
 * mutation-resistant — a revert to the en-US `toFixed()+symbol` path turns it RED. Uses \s (matches
 * U+00A0 and the narrow U+202F some ICU builds emit) so it is robust across Node ICU versions.
 */
import { describe, it, expect } from 'vitest';
import appService from '../../resources/js/services/appService';
import currencyPositionEnum from '../../resources/js/enums/modules/currencyPositionEnum';

const R = currencyPositionEnum.RIGHT; // 10 — Le Cayenne EUR (symbol after)
const L = currencyPositionEnum.LEFT;  // 5

describe('appService.currencyFormat — FR locale (A2)', () => {
    it('renders comma decimal + space, never the en-US glued "0.00€"', () => {
        const out = appService.currencyFormat(0, 2, '€', R);
        expect(out).toMatch(/^0,00\s€$/u);
        expect(out).not.toContain('.');     // no period decimal
        expect(out).not.toBe('0.00€');       // not the old en-US form
    });

    it('5.30 → "5,30 €" (comma)', () => {
        expect(appService.currencyFormat(5.3, 2, '€', R)).toMatch(/^5,30\s€$/u);
    });

    it('groups thousands (37063.87 → "37 063,87 €")', () => {
        expect(appService.currencyFormat(37063.87, 2, '€', R)).toMatch(/^37\s063,87\s€$/u);
    });

    it('honors LEFT position (symbol before)', () => {
        expect(appService.currencyFormat(7, 2, '€', L)).toMatch(/^€\s7,00$/u);
    });

    it('accepts string decimal + guards NaN/undefined to "0,00 €"', () => {
        expect(appService.currencyFormat('5.3', '2', '€', R)).toMatch(/^5,30\s€$/u);
        expect(appService.currencyFormat(undefined, 2, '€', R)).toMatch(/^0,00\s€$/u);
        expect(appService.currencyFormat(null, 2, '€', R)).toMatch(/^0,00\s€$/u);
    });
});
