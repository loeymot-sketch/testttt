import { describe, it, expect } from 'vitest';
import appService from '../../resources/js/services/appService';
import currencyPositionEnum from '../../resources/js/enums/modules/currencyPositionEnum';

/**
 * [HEAL-money-fr 2026-06-26] appService.currencyFormat must render French (ADR-007)
 * currency: comma decimal, NBSP (U+00A0) thousands separator, NBSP before the symbol
 * when the symbol is a suffix (position RIGHT). It used to render "0.00€" (US decimal
 * point + glued symbol, no space), visible on the POS cart Sous-total/Total.
 *
 * Aligned with the codebase canonical FR convention (helpers/posFormatCents.js +
 * its spec): "1${NBSP}234,56 €". Here we additionally use NBSP before the symbol
 * per the FR-typography mandate.
 */
describe('appService.currencyFormat — FR (ADR-007)', () => {
    const NBSP = ' ';

    it('renders 0 as "0,00 €" (NBSP before symbol, comma decimal) — suffix', () => {
        expect(appService.currencyFormat(0, 2, '€', currencyPositionEnum.RIGHT))
            .toBe(`0,00${NBSP}€`);
    });

    it('renders 1234.5 as "1 234,50 €" (NBSP thousands + NBSP before symbol) — suffix', () => {
        expect(appService.currencyFormat(1234.5, 2, '€', currencyPositionEnum.RIGHT))
            .toBe(`1${NBSP}234,50${NBSP}€`);
    });

    it('renders 7.9 as "7,90 €" — suffix', () => {
        expect(appService.currencyFormat(7.9, 2, '€', currencyPositionEnum.RIGHT))
            .toBe(`7,90${NBSP}€`);
    });

    it('renders 1234567.89 with grouped NBSP thousands — suffix', () => {
        expect(appService.currencyFormat(1234567.89, 2, '€', currencyPositionEnum.RIGHT))
            .toBe(`1${NBSP}234${NBSP}567,89${NBSP}€`);
    });

    it('places symbol on the left with NBSP after it — prefix (position LEFT)', () => {
        expect(appService.currencyFormat(1234.5, 2, '€', currencyPositionEnum.LEFT))
            .toBe(`€${NBSP}1${NBSP}234,50`);
    });

    it('honours a different decimal precision (preserves toFixed rounding)', () => {
        // 0 decimals: 2.5.toFixed(0) === "3" (banker-agnostic JS round-half-up-away).
        expect(appService.currencyFormat(2.5, 0, '€', currencyPositionEnum.RIGHT))
            .toBe(`3${NBSP}€`);
        // 3 decimals path still renders FR comma + NBSP symbol.
        expect(appService.currencyFormat(19.5, 3, '€', currencyPositionEnum.RIGHT))
            .toBe(`19,500${NBSP}€`);
    });

    it('coerces null/undefined/empty/NaN to "0,00 €" (numeric integrity)', () => {
        const expected = `0,00${NBSP}€`;
        expect(appService.currencyFormat(null, 2, '€', currencyPositionEnum.RIGHT)).toBe(expected);
        expect(appService.currencyFormat(undefined, 2, '€', currencyPositionEnum.RIGHT)).toBe(expected);
        expect(appService.currencyFormat('', 2, '€', currencyPositionEnum.RIGHT)).toBe(expected);
        expect(appService.currencyFormat('abc', 2, '€', currencyPositionEnum.RIGHT)).toBe(expected);
    });

    it('parses numeric-looking strings (backend convert_price strings)', () => {
        expect(appService.currencyFormat('19.00', 2, '€', currencyPositionEnum.RIGHT))
            .toBe(`19,00${NBSP}€`);
    });
});
