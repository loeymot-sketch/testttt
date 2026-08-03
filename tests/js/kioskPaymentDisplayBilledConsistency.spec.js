import { describe, expect, it } from 'vitest';
import fs from 'fs';
import path from 'path';

const source = fs.readFileSync(
    path.join(process.cwd(), 'resources/js/components/frontend/kiosk/KioskPaymentComponent.vue'),
    'utf8'
);

// [C39 heal 2026-07-06] L'écran paiement borne ne doit jamais afficher un total
// qui inclut une remise fidélité/promo NON transmise au serveur (flag OFF). Avant
// la quote serveur, il retombe sur un total gaté par kioskPromoEnabled (miroir du
// displayTotal du panier), pas sur le getter store `total` qui soustrait la remise.
describe('KioskPayment — affiché == facturé (fallback avant quote)', () => {
    it('gate le total de repli derrière kioskPromoEnabled', () => {
        expect(source).toContain('kioskPromoEnabled');
        expect(source).toContain('displayFallbackTotal');
        expect(source).toMatch(/this\.kioskPromoEnabled \? \(parseFloat\(this\.loyaltyDiscount\)/);
    });

    it('cartTotal préfère la quote serveur puis le repli gaté (jamais le store `total` brut)', () => {
        expect(source).toContain('cartTotal() { return this._lastQuote?.total_ttc ?? this.displayFallbackTotal; }');
        expect(source).not.toContain('cartTotal() { return this._lastQuote?.total_ttc ?? this.total; }');
    });
});
