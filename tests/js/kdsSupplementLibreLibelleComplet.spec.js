import { describe, it, expect } from 'vitest';
import { renderItemSymbolic } from '../../resources/js/helpers/kdsSymbolic';

/**
 * [AUDIT AVAL 2026-09-29 · P0] Un supplément libre doit garder son libellé COMPLET
 * sur la carte cuisine.
 *
 * Le moteur symbolique réduit un nom à ses 3 premières lettres significatives.
 * Tout supplément libre commence par « Supplément — … » (PricingService) : la carte
 * affichait donc « SUP » pour « Supplément — Sauce blanche maison » comme pour
 * « Supplément — Viande hachée en plus » — deux lignes indiscernables — et
 * « Supplément — Tacos en plus » devenait « Tacos », un taco entier à préparer.
 *
 * Ce banc part de la CARTE RENDUE, pas de la ligne en base : c'est précisément le
 * périmètre que les 7 tests existants sur `manual_supplement` ne couvraient pas.
 */
const supplement = (label, extra = {}) => ({
    line_type: 'manual_supplement',
    item_id: null,
    manual_label: label,
    item_name: label,
    quantity: 1,
    item_variations: '[]',
    item_extras: '[]',
    composition_snapshot: { lines: [], extras: [], addons: [] },
    ...extra,
});

const labelPrincipal = (item) => renderItemSymbolic(item).lines.find((l) => l.type === 'symbolic-main')?.label;

describe('carte cuisine — supplément libre', () => {
    it('garde le libellé complet au lieu du code à 3 lettres', () => {
        expect(labelPrincipal(supplement('Supplément — Sauce blanche maison')))
            .toBe('Supplément — Sauce blanche maison');
    });

    it('deux suppléments différents restent discernables', () => {
        const a = labelPrincipal(supplement('Supplément — Sauce blanche maison'));
        const b = labelPrincipal(supplement('Supplément — Viande hachée en plus'));
        expect(a).not.toBe(b);
        expect(a).not.toMatch(/^SUP$/);
        expect(b).not.toMatch(/^SUP$/);
    });

    it("« Supplément — Tacos en plus » ne devient pas un taco entier", () => {
        expect(labelPrincipal(supplement('Supplément — Tacos en plus')))
            .toBe('Supplément — Tacos en plus');
    });

    it("« Supplément — Formule du midi » ne devient pas « MENU »", () => {
        expect(labelPrincipal(supplement('Supplément — Formule du midi')))
            .toBe('Supplément — Formule du midi');
    });

    it('la quantité et la note client sont conservées', () => {
        const lignes = renderItemSymbolic(supplement('Supplément — Emballage', { quantity: 2, instruction: 'Bien chaud' })).lines;
        const principale = lignes.find((l) => l.type === 'symbolic-main');
        expect(principale.qty).toBe(2);
        expect(lignes.some((l) => /Bien chaud/.test(String(l.label ?? l.text ?? '')))).toBe(true);
    });

    it("le contrôle mord : une ligne CATALOGUE nommée pareil passe, elle, par le moteur symbolique", () => {
        // Si ce test échoue un jour parce que le catalogue rend aussi le nom complet,
        // c'est le moteur symbolique qui a changé — pas ce correctif.
        const catalogue = { ...supplement('Supplément — Sauce blanche maison'), line_type: 'catalog', item_id: 42 };
        expect(labelPrincipal(catalogue)).not.toBe('Supplément — Sauce blanche maison');
    });
});
