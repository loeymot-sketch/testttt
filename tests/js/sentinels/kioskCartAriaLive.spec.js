import { describe, expect, it } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

/**
 * @FK-ID FK-RED-R2-CART-A11Y | @source RED-R2 plan P2 (kiosk a11y cart)
 * @reason
 *   La région `kiosk-cart-summary` (totaux subtotal / loyalty / promo / total)
 *   change après ajout/retrait/promo. Sans aria-live, screen-reader n'annonce
 *   pas le nouveau total. Avec aria-live="polite" sur la région, les changements
 *   sont annoncés sans interrompre l'utilisateur.
 *
 * Sentinel ferme le contrat structurel sur l'attribut aria-live="polite".
 */
describe('Kiosk cart summary — aria-live polite (RED-R2 P2)', () => {
    const source = readFileSync(
        resolve(process.cwd(), 'resources/js/components/frontend/kiosk/KioskCartComponent.vue'),
        'utf8',
    );

    it('the kiosk-cart-summary block declares aria-live="polite"', () => {
        // Locate the kiosk-cart-summary opening tag and its attributes.
        // The block opens with class="kiosk-cart-summary" and is self-described by
        // data-testid="kiosk-cart-summary" — we match the full opening up to >.
        const blockMatch = source.match(/<div\b[^>]*class="kiosk-cart-summary"[^>]*>/);
        expect(blockMatch).not.toBeNull();
        expect(blockMatch[0]).toMatch(/aria-live="polite"/);
    });

    it('the kiosk-cart-summary block keeps role="region" and aria-label', () => {
        const blockMatch = source.match(/<div\b[^>]*class="kiosk-cart-summary"[^>]*>/);
        expect(blockMatch).not.toBeNull();
        expect(blockMatch[0]).toMatch(/role="region"/);
        expect(blockMatch[0]).toMatch(/aria-label="\$t\('kiosk\.subtotal'\)"|:aria-label="\$t\('kiosk\.subtotal'\)"/);
    });

    it('exposes data-testid="kiosk-cart-summary" probe', () => {
        const blockMatch = source.match(/<div\b[^>]*class="kiosk-cart-summary"[^>]*>/);
        expect(blockMatch).not.toBeNull();
        expect(blockMatch[0]).toMatch(/data-testid="kiosk-cart-summary"/);
    });
});
