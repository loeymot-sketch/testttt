import { describe, expect, it } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

/**
 * CENTRAL-C1-P3-01 — L'aperçu live du barème fidélité rendait la réduction
 * en-US glué : "10€ d'achat … → 0.10€ de réduction" (point décimal + € collé,
 * placé à droite sans espace). En FR canonique ça doit être "0,10 €"
 * (virgule décimale, espace insécable, € après) — comme partout ailleurs via
 * appService.currencyFormat (helper FR EUR, Intl fr-FR).
 *
 * Approche : contrat source statique (même précédent que itemListWizardButton.spec.js
 * et productComposerSummary.spec.js). Monter LoyaltySetupComponent tirerait
 * Vuex loyaltySetup + LoadingComponent + alertService.
 */

const componentPath = resolve(
    process.cwd(),
    'resources/js/components/admin/settings/LoyaltySetup/LoyaltySetupComponent.vue',
);

const readSource = (path) => readFileSync(path, 'utf8');

describe('LoyaltySetupComponent — live preview FR currency (CENTRAL-C1-P3-01)', () => {
    it('formats the discount preview via appService.currencyFormat (0,10 €), not toFixed+glued €', () => {
        const source = readSource(componentPath);

        // The FR helper must be imported and used for the discount amount.
        expect(source).toMatch(/import\s+appService\s+from/);
        expect(source).toContain('appService.currencyFormat(');

        // The en-US shape ".toFixed(2) }}€" must be gone from the preview.
        expect(source).not.toMatch(/\.toFixed\(2\)\s*\}\}\s*€/);
    });

    it('does not emit a number glued to € (no "}}€" and no "10€")', () => {
        const source = readSource(componentPath);

        // No interpolation immediately followed by a glued euro sign.
        expect(source).not.toMatch(/\}\}€/);
        // The literal "10€ d'achat" (en-US, no space) must be corrected.
        expect(source).not.toContain("10€");
    });
});
