import { describe, it, expect } from 'vitest';
import { mergeAllergens } from '../../resources/js/helpers/kioskFilters';

describe('mergeAllergens (P-MEGA-08)', () => {
    it('1 — item sans allergènes + 0 extras → []', () => {
        expect(mergeAllergens({ allergens: [] }, [], [])).toEqual([]);
    });

    it('2 — item [gluten] + 0 extras → [gluten]', () => {
        expect(mergeAllergens({ allergens: ['gluten'] }, [], [])).toEqual(['gluten']);
    });

    it('3 — item [] + extra fromage [lait] → [lait]', () => {
        const extra = { id: 1, name: 'Fromage', allergens: [{ code: 'lait' }] };
        expect(mergeAllergens({ allergens: [] }, [], [extra])).toEqual(['lait']);
    });

    it('4 — item [gluten] + extra fromage [lait] → [gluten, lait] ordre canonique', () => {
        const extra = { allergens: ['lait'] };
        expect(mergeAllergens({ allergens: ['gluten'] }, [], [extra])).toEqual(['gluten', 'lait']);
    });

    it('5 — qty extra duplicate ne duplique pas les codes', () => {
        const extra = { allergens: ['lait'] };
        expect(
            mergeAllergens({ allergens: ['gluten'] }, [], [extra, extra]),
        ).toEqual(['gluten', 'lait']);
    });

    it('6 — item [gluten] + variation pain [gluten] → [gluten]', () => {
        const pain = { id: 10, allergens: ['gluten'] };
        expect(mergeAllergens({ allergens: ['gluten'] }, [pain], [])).toEqual(['gluten']);
    });

    it('7 — variations/extras sans allergens → pas de crash', () => {
        expect(mergeAllergens({ allergens: [] }, [{}], [{}])).toEqual([]);
    });

    it('8 — selections.variations undefined → seulement allergènes item (rétrocompat)', () => {
        const item = { allergens: ['gluten'] };
        const extra = { allergens: ['lait'] };
        expect(mergeAllergens(item, undefined, [extra])).toEqual(['gluten']);
    });

    it('9 — casse mixte item + extra → un seul code lowercase (gluten)', () => {
        const item = { allergens: ['Gluten'] };
        const extra = { allergens: [{ code: 'GLUTEN' }] };
        expect(mergeAllergens(item, [], [extra])).toEqual(['gluten']);
    });

    it('10 — selectedVariations null → rétrocompat, extras ignorés (pas de crash)', () => {
        const item = { allergens: ['gluten'] };
        const extra = { allergens: ['lait'] };
        expect(mergeAllergens(item, null, [extra])).toEqual(['gluten']);
    });

    it('11 — item.allergens string CSV → codes lowercase séparés', () => {
        const item = { allergens: 'gluten,lait' };
        expect(mergeAllergens(item, [], [])).toEqual(['gluten', 'lait']);
    });
});
