import { describe, expect, it } from 'vitest';
import { isLikelyExclusionOrHoldInstruction, kdsInstructionVisualClass } from '../../resources/js/helpers/kdsLineSemantics';

describe('kdsLineSemantics', () => {
    it('flags French sans oignon / sans tomate', () => {
        expect(isLikelyExclusionOrHoldInstruction('Sans oignon, merci')).toBe(true);
        expect(isLikelyExclusionOrHoldInstruction('Bien cuit, sans fromage')).toBe(true);
    });

    it('flags English no onion', () => {
        expect(isLikelyExclusionOrHoldInstruction('no sauce please')).toBe(true);
    });

    it('flags allergen keywords', () => {
        expect(isLikelyExclusionOrHoldInstruction('Client allergique arachide')).toBe(true);
    });

    it('does not flag false positives: order No.5', () => {
        expect(isLikelyExclusionOrHoldInstruction('Menu No. 5 menu enfant')).toBe(false);
    });

    it('kdsInstructionVisualClass maps allergen / exclusion / note', () => {
        expect(kdsInstructionVisualClass('Client allergique arachide')).toBe('kds-instruction--allergen');
        expect(kdsInstructionVisualClass('Sans oignons')).toBe('kds-instruction--exclusion');
        expect(kdsInstructionVisualClass('Bien cuit seulement')).toBe('kds-instruction--note');
    });
});
