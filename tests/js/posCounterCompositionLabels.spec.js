import { describe, it, expect } from 'vitest';
import PosComponent from '../../resources/js/components/admin/pos/PosComponent.vue';

/**
 * [P1-4 AUDIT CAISSIER 2026-08-01] Le détail « À encaisser au comptoir » affichait
 * « Algérienne: undefined » et « Extras:, , » — sur 3375/3413 order_items. Le caissier
 * encaissait donc sans pouvoir vérifier la composition de ce qu'il vendait.
 *
 * Racine : DEUX formes de données coexistent selon la source, et le rendu n'en connaissait
 * qu'une seule. Ce test verrouille les deux formes réelles (relevées en base) + les cas
 * dégradés, pour qu'aucun « undefined » ne puisse réapparaître devant le client.
 */
const { composedVariations, composedExtras } = PosComponent.methods;

describe('Caisse — composition lisible avant encaissement (P1-4)', () => {
    it('forme « lignes DB » : variation_name = étiquette, name = valeur', () => {
        const out = composedVariations({
            item_variations: [{ variation_name: 'Sauce (1ère Gratuite)', name: 'Algérienne' }],
        });
        expect(out).toBe('Sauce (1ère Gratuite): Algérienne');
        expect(out).not.toContain('undefined');
    });

    it('forme « snapshot NF525 » : attribute_name = étiquette, variation_name = valeur', () => {
        const out = composedVariations({
            item_variations: [{ attribute_name: 'Sauce (1ère Gratuite)', variation_name: 'Algérienne' }],
        });
        expect(out).toBe('Sauce (1ère Gratuite): Algérienne');
        expect(out).not.toContain('undefined');
    });

    it('affiche au moins la valeur quand l\'étiquette manque, jamais « undefined »', () => {
        expect(composedVariations({ item_variations: [{ variation_name: 'Algérienne' }] })).toBe('Algérienne');
        expect(composedVariations({ item_variations: [{}] })).toBe('');
    });

    it('les extras ne produisent plus « , , » quand des noms manquent', () => {
        expect(composedExtras({ item_extras: [{ name: 'Cheddar' }, {}, { extra_name: 'Bacon' }] }))
            .toBe('Cheddar, Bacon');
        expect(composedExtras({ item_extras: [] })).toBe('');
        expect(composedExtras({})).toBe('');
    });

    it('supporte plusieurs variations mélangeant les deux formes', () => {
        expect(composedVariations({
            item_variations: [
                { variation_name: 'Sauce', name: 'Algérienne' },
                { attribute_name: 'Pain', variation_name: 'Brioché' },
            ],
        })).toBe('Sauce: Algérienne, Pain: Brioché');
    });
});
