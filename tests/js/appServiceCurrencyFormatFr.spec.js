import { describe, it, expect } from 'vitest';
import appService from '../../resources/js/services/appService.js';
import currencyPositionEnum from '../../resources/js/enums/modules/currencyPositionEnum.js';

/**
 * [UIUX-W2 F1 2026-06-11] appService.currencyFormat rendait un format EN-US
 * (`toFixed` → point décimal + symbole collé : "€2.50" / "2.50€") alors que
 * tout le reste de la stack (backend AppLibrary fr_FR NumberFormatter,
 * helpers/formatPrice.js, PosCounterCollectModal.formatPrice) rend
 * "2,50 €". Le modal de paiement POS (frozen — délègue à appService) et les
 * surfaces web/table affichaient donc des montants EN-US.
 *
 * Fix : Intl.NumberFormat('fr-FR', { style:'currency', currency:'EUR' })
 * en honorant le paramètre `decimal` (site_digit_after_decimal_point).
 * V1 LOCAL Le Cayenne = FR/EUR only (ADR-007) — les paramètres
 * currency/position sont conservés dans la signature (compat call-sites)
 * mais le rendu suit la typographie FR canonique, identique à
 * helpers/formatPrice.js. Vérifié : aucun call-site ne re-parse la sortie
 * (toutes les utilisations sont des interpolations template).
 */

const intlRef = (n, digits = 2) => new Intl.NumberFormat('fr-FR', {
    style: 'currency',
    currency: 'EUR',
    minimumFractionDigits: digits,
    maximumFractionDigits: digits,
}).format(n);

describe('appService.currencyFormat FR (F1 UIUX-W2)', () => {
    it('renders FR EUR with comma decimal and spaced symbol (position LEFT input)', () => {
        const out = appService.currencyFormat(2.5, 2, '€', currencyPositionEnum.LEFT);
        expect(out).toBe(intlRef(2.5));
        expect(out).toContain('2,50');
        expect(out).not.toContain('2.50');
    });

    it('renders FR EUR regardless of RIGHT position input', () => {
        const out = appService.currencyFormat(8.9, 2, '€', currencyPositionEnum.RIGHT);
        expect(out).toBe(intlRef(8.9));
        expect(out).toContain('8,90');
    });

    it('honors the decimal digits parameter', () => {
        expect(appService.currencyFormat(3, 0, '€', currencyPositionEnum.RIGHT)).toBe(intlRef(3, 0));
    });

    it('groups thousands the FR way', () => {
        const out = appService.currencyFormat(1234.5, 2, '€', currencyPositionEnum.RIGHT);
        expect(out).toBe(intlRef(1234.5));
        expect(out).toContain('234,50');
    });

    it('falls back to 0 for non-numeric input (no NaN on screen)', () => {
        const out = appService.currencyFormat(undefined, 2, '€', currencyPositionEnum.RIGHT);
        expect(out).toBe(intlRef(0));
        expect(out).not.toContain('NaN');
    });
});
