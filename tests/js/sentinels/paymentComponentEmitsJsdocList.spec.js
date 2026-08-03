import { describe, expect, it } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

/**
 * @FK-ID FK-RED-R1-Q-X-1 | @source RED-R1 Q-X-1 — emits review humaine obligatoire
 * @reason
 *   Le sentinel `paymentComponentPropMutation.spec.js` est tolérant : il vérifie la
 *   non-mutation des props mais pas la liste des events emis. Sans garde explicite,
 *   un dev peut ajouter `payment-form:nuke` ou un autre event surprise sans review.
 *
 *   Ce sentinel ferme le contrat documentaire :
 *     - une JSDoc « MODIFIER UNIQUEMENT VIA REVIEW HUMAINE » précède immédiatement
 *       le bloc `emits:`
 *     - les 3 events autorisés (patch/reset/confirmed) y sont listés explicitement
 *     - le bloc `emits:` lui-même contient exactement ces 3 events (rien de plus)
 */
describe('PaymentComponent — emits JSDoc exhaustive list (RED-R1 Q-X-1)', () => {
    const source = readFileSync(
        resolve(process.cwd(), 'resources/js/components/admin/pos/PaymentComponent.vue'),
        'utf8',
    );

    it('contains a JSDoc block warning that emits require human review', () => {
        expect(source).toMatch(/MODIFIER UNIQUEMENT VIA REVIEW HUMAINE/);
    });

    it('JSDoc lists the three authorised events explicitly', () => {
        expect(source).toMatch(/payment-form:patch/);
        expect(source).toMatch(/payment-form:reset/);
        expect(source).toMatch(/order:confirmed/);
    });

    it('JSDoc immediately precedes the emits declaration', () => {
        // Match `*/` followed by optional whitespace/newlines and the `emits:` line.
        expect(source).toMatch(/MODIFIER UNIQUEMENT VIA REVIEW HUMAINE[\s\S]{0,1500}\*\/\s*emits\s*:\s*\[/);
    });

    it('emits array contains exactly the 3 authorised events (no surprise additions)', () => {
        const emitsMatch = source.match(/emits\s*:\s*\[([^\]]+)\]/);
        expect(emitsMatch, 'emits: [...] block must exist').not.toBeNull();
        const items = emitsMatch[1]
            .split(',')
            .map((s) => s.trim().replace(/^["']|["']$/g, ''))
            .filter((s) => s.length > 0);
        expect(items.sort()).toEqual(
            ['order:confirmed', 'payment-form:patch', 'payment-form:reset'].sort(),
        );
    });
});
