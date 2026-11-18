/**
 * [ÉTIQUETTES DLC · audit E2E 2026-11-18 P2-b] « l'ail » doit trouver « Ail haché » : un article élidé (l', d', j') ou une lettre
 * isolée n'est pas un mot du produit. Une saisie faite UNIQUEMENT de lettres isolées garde son comportement (on tape encore).
 */
import { describe, it, expect } from 'vitest';
import { searchProducts, matchProduct } from '../../resources/js/helpers/dlcSearch';

const LIST = [
    { name: 'Ail haché', category: 'Légumes' }, { name: "Huile d'olive", category: 'Épicerie' },
    { name: 'Oignons émincés', category: 'Légumes' }, { name: 'Steak haché', category: 'Viandes' },
];
const names = (q) => searchProducts(LIST, q).items.map((p) => p.name);

describe('recherche DLC — articles élidés', () => {
    it("« l'ail », « l’ail » (apostrophe typographique) et « d'oignon » trouvent le produit", () => {
        expect(names("l'ail")).toEqual(['Ail haché']);
        expect(names('l’ail')).toEqual(['Ail haché']);
        expect(names("d'oignon")).toEqual(['Oignons émincés']);
        expect(names("huile d'olive")).toEqual(["Huile d'olive"]);
    });

    it('les mots de plus d’une lettre restent tous exigés', () => {
        expect(names("l'ail steak")).toEqual([]);
        expect(names('steak hache')).toEqual(['Steak haché']);
    });

    it('une saisie faite uniquement d’une lettre isolée ne devient pas « tout correspond »', () => {
        expect(matchProduct('l', { name: 'Steak haché', category: 'Viandes' })).toBeNull();
        expect(names('l')).toEqual([]);
    });
});
