import { describe, expect, it } from 'vitest';

import { getKdsEscalationClass } from '../../resources/js/helpers/kdsDisplay';

describe('KDS wait escalation classes', () => {
    const base = 1_700_000_000_000;

    it('<5min maps to green', () => {
        expect(getKdsEscalationClass(base, base + 2 * 60 * 1000)).toBe('kds-wait-green');
    });

    it('7min maps to orange', () => {
        expect(getKdsEscalationClass(base, base + 7 * 60 * 1000)).toBe('kds-wait-orange');
    });

    it('>10min maps to red with pulse', () => {
        expect(getKdsEscalationClass(base, base + 11 * 60 * 1000)).toBe('kds-wait-red animate-pulse');
    });
});
