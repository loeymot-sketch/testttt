import { describe, expect, it } from 'vitest';

import { getKdsAgeBucket, getKdsEscalationClass } from '../../resources/js/helpers/kdsDisplay';

// [kds/sprint-2 F-5] Thresholds tightened from 5/10 min to 3/6 min for V1
// takeaway-only single-chef workflow. See DESIGN_SPEC_KDS_V2_2026-05-11.md §3.
describe('KDS wait escalation classes', () => {
    const base = 1_700_000_000_000;

    it('<3min maps to green / fresh bucket', () => {
        expect(getKdsEscalationClass(base, base + 2 * 60 * 1000)).toBe('kds-wait-green');
        expect(getKdsAgeBucket(base, base + 2 * 60 * 1000)).toBe('fresh');
    });

    it('3-6min maps to orange / warning bucket', () => {
        expect(getKdsEscalationClass(base, base + 4 * 60 * 1000)).toBe('kds-wait-orange');
        expect(getKdsAgeBucket(base, base + 4 * 60 * 1000)).toBe('warning');
    });

    it('>6min maps to red with pulse / critical bucket', () => {
        expect(getKdsEscalationClass(base, base + 7 * 60 * 1000)).toBe('kds-wait-red animate-pulse');
        expect(getKdsAgeBucket(base, base + 7 * 60 * 1000)).toBe('critical');
    });
});
