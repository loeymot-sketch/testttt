import { describe, expect, it } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

const paymentComponentPath = resolve(
    process.cwd(),
    'resources/js/components/admin/pos/PaymentComponent.vue',
);

describe('PaymentComponent prop mutation contract', () => {
    it('contains no direct parent prop/form mutation sites', () => {
        const source = readFileSync(paymentComponentPath, 'utf8');
        const directPropMutationPattern = /this\.\$props\.props\.|this\.props\.form\.\w+\s*=/g;
        const matches = source.match(directPropMutationPattern) || [];

        expect(matches, `direct prop mutations: ${matches.join(', ')}`).toEqual([]);
    });

    it('declares explicit payment-form events for parent-owned state changes', () => {
        const source = readFileSync(paymentComponentPath, 'utf8');

        // Tolerant pattern: emits array MUST include payment-form:patch + payment-form:reset
        // (additional events like "order:confirmed" are allowed — sentinel verrouille
        // l'intent "events explicites pour parent-owned state", pas une signature stricte).
        const emitsBlockMatch = source.match(/emits:\s*\[[^\]]*\]/);
        expect(emitsBlockMatch, 'emits array should be declared').toBeTruthy();
        const emitsBlock = emitsBlockMatch[0];
        expect(emitsBlock).toContain('"payment-form:patch"');
        expect(emitsBlock).toContain('"payment-form:reset"');

        expect(source).toContain('this.$emit("payment-form:patch", patch)');
        expect(source).toContain('this.$emit("payment-form:reset")');
    });
});
