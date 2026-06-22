import { describe, expect, it } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

/**
 * @FK-ID FK-081 | @source reports/audit/TRACEABILITY_MATRIX_CAISSE_V1_2026-04-25.md | @fix-mission CV1-M21B-PAYMENT-REFACTOR | @reason PaymentComponent currently mutates parent props directly instead of emitting state changes.
 *
 * This sentinel turns green only when the mutation count is 0 after M-21b.
 */
describe('PaymentComponentPropMutationSentinel', () => {
    it('contains no direct props/form mutations', () => {
        const source = readFileSync(
            resolve(process.cwd(), 'resources/js/components/admin/pos/PaymentComponent.vue'),
            'utf8',
        );

        const directPropMutationPattern = /this\.\$props\.props\.|this\.props\.form\.\w+\s*=/g;
        const matches = source.match(directPropMutationPattern) || [];

        expect(matches.length, `direct prop mutation count: ${matches.length}`).toBe(0);
    });
});
