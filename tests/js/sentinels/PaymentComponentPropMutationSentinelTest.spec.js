import { describe, expect, it } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

/**
 * @FK-ID FK-081 | @fix-mission CV1-LOT-P04-PAYMENT-REFACTOR-PROPS
 *
 * Canonical sentinel name from TRACEABILITY_MATRIX_CAISSE_V1_2026-04-25.md.
 */
describe('PaymentComponentPropMutationSentinelTest', () => {
    it('contains no direct props/form mutations and emits parent-state patches', () => {
        const source = readFileSync(
            resolve(process.cwd(), 'resources/js/components/admin/pos/PaymentComponent.vue'),
            'utf8',
        );

        const directPropMutationPattern = /this\.\$props\.props\.|this\.props\.form\.\w+\s*=/g;
        const matches = source.match(directPropMutationPattern) || [];

        expect(matches, `direct prop mutations: ${matches.join(', ')}`).toEqual([]);

        // Tolerant pattern: emits array MUST include payment-form:patch + payment-form:reset
        // (additional events allowed — sentinel verrouille intent, pas signature stricte).
        const emitsBlockMatch = source.match(/emits:\s*\[[^\]]*\]/);
        expect(emitsBlockMatch).toBeTruthy();
        const emitsBlock = emitsBlockMatch[0];
        expect(emitsBlock).toContain('"payment-form:patch"');
        expect(emitsBlock).toContain('"payment-form:reset"');

        expect(source).toContain('this.$emit("payment-form:patch", patch)');
        expect(source).toContain('this.$emit("payment-form:reset")');
    });
});
