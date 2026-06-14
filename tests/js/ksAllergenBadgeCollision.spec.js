import { describe, it, expect } from 'vitest';
import KsAllergenBadge from '../../resources/js/components/frontend/kiosk/ds/KsAllergenBadge.vue';

/**
 * FOOD-SAFETY (massive-2dot0 #1) — the allergen collision warning (red role=alert)
 * must fire regardless of the CASE of the customer's declared allergen codes.
 * Item codes are lowercased upstream; pre-heal collisionSet kept customer codes
 * verbatim, so an uppercase/capitalized declared allergen ('GLUTEN' / 'Gluten')
 * silently failed to match the lowercase item code 'gluten' → NO red alert.
 */
function call(name, ctx) {
    return KsAllergenBadge.computed[name].call(ctx);
}

describe('KsAllergenBadge collision is case-insensitive (food-safety #1)', () => {
    it('raises a collision when the customer allergen casing differs from the item code', () => {
        const ctx = {
            item: null,
            selections: null,
            allergens: ['gluten', 'lait'], // lowercased item/catalog codes
            customerAllergens: ['GLUTEN'], // declared allergen, uppercase
            compact: false,
        };
        ctx.effectiveAllergens = call('effectiveAllergens', ctx);
        ctx.collisionSet = call('collisionSet', ctx);

        expect(call('hasCollision', ctx)).toBe(true);
    });

    it('still raises a collision for exact lowercase matches (regression)', () => {
        const ctx = {
            item: null,
            selections: null,
            allergens: ['arachides'],
            customerAllergens: ['arachides'],
            compact: false,
        };
        ctx.effectiveAllergens = call('effectiveAllergens', ctx);
        ctx.collisionSet = call('collisionSet', ctx);

        expect(call('hasCollision', ctx)).toBe(true);
    });

    it('does NOT raise a collision when there is genuinely no overlap', () => {
        const ctx = {
            item: null,
            selections: null,
            allergens: ['gluten'],
            customerAllergens: ['Poisson'],
            compact: false,
        };
        ctx.effectiveAllergens = call('effectiveAllergens', ctx);
        ctx.collisionSet = call('collisionSet', ctx);

        expect(call('hasCollision', ctx)).toBe(false);
    });
});
