import { describe, it, expect } from 'vitest';

/**
 * [GOAL AUDIT 2026-09-03 · défaut signalé par le propriétaire]
 * Owner : « quand on modifie un produit dans le panier, le prix ne change pas ».
 *
 * Diagnostic réel (audit A1 + mesure en base `item_extras`) : les deux surfaces
 * RECALCULENT bien. Le défaut est ailleurs et il porte sur le MONTANT.
 *
 * `buildWizardRestorePayload` classait tout extra dont le nom contient « cheddar »
 * dans `restore.fritesCheddar`, c'est-à-dire l'option de MENU « Cheddar Fondu »
 * (`public/js/pos-wizard.js:195` FRITES_CHEDDAR_PRICE, libellé « Avec Cheddar
 * Fondu » à `:2120`). Or la carte contient AUSSI un supplément payant nommé
 * simplement « Cheddar » :
 *
 *   SELECT name, group_label, COUNT(*), price FROM item_extras
 *   WHERE deleted_at IS NULL AND status = 5 AND LOWER(name) LIKE '%cheddar%'
 *   → « Cheddar »       grp=supplement      n=22  prix 0.90
 *   → « Cheddar »       grp=supplement_bol  n=8   prix 0.90
 *   → « Cheddar Fondu » grp=(aucun)         n=2   prix 1.00
 *
 * Conséquence pour le client, à chaque réouverture d'une ligne du panier
 * contenant le supplément Cheddar :
 *   1. la tuile du supplément se rouvre NON SÉLECTIONNÉE (il n'atterrit jamais
 *      dans `restore.supplements`) ;
 *   2. le wizard ajoute 1,00 € (FRITES_CHEDDAR_PRICE) au lieu des 0,90 €
 *      réellement dus — le prix affiché change, mais pas pour le bon montant.
 *
 * La branche voisine « Grande Portion » exige DEUX mots (`grande` ET `portion`)
 * et n'a jamais eu ce défaut. Celle du cheddar n'en exigeait qu'un : c'est la
 * seule asymétrie de la chaîne de classification.
 */
import ItemComponent from '../../resources/js/components/admin/pos/ItemComponent.vue';

const buildWizardRestorePayload = ItemComponent.methods.buildWizardRestorePayload;

/** Carte fidèle à la base réelle : le supplément ET l'option de menu coexistent. */
function makeItem() {
    return {
        itemAttributes: [],
        variations: {},
        extras: [
            { id: 301, name: 'Cheddar', convert_price: 0.9, group_label: 'supplement' },
            { id: 302, name: 'Cheddar Fondu', convert_price: 1.0, group_label: null },
            { id: 303, name: 'Grande Portion', convert_price: 1.0, group_label: null },
            { id: 304, name: 'Tomate', convert_price: 0, group_label: 'crudite' },
        ],
    };
}

function makeCartLine(extraNames) {
    return {
        instruction: '',
        quantity: 1,
        item_variations: [],
        item_extras: extraNames.map((name, i) => ({ id: 9000 + i, name })),
    };
}

describe('buildWizardRestorePayload — le supplément « Cheddar » (0,90 €) n\'est pas l\'option « Cheddar Fondu » (1,00 €)', () => {
    it('le supplément payant « Cheddar » revient dans restore.supplements, jamais dans fritesCheddar', () => {
        const restore = buildWizardRestorePayload(makeCartLine(['Cheddar']), makeItem());

        expect(
            restore.supplements['p_301'],
            'le supplément Cheddar (0,90 €) doit se rouvrir SÉLECTIONNÉ dans les suppléments payants'
        ).toBe(true);
        expect(
            restore.fritesCheddar,
            'il ne doit PAS activer l\'option frites « Cheddar Fondu » — cela facture 1,00 € au lieu de 0,90 €'
        ).toBe(false);
    });

    it('l\'option de menu « Cheddar Fondu » active bien fritesCheddar (non-régression)', () => {
        const restore = buildWizardRestorePayload(makeCartLine(['Cheddar Fondu']), makeItem());

        expect(restore.fritesCheddar, '« Cheddar Fondu » EST l\'option frites du menu').toBe(true);
        expect(
            restore.supplements['p_302'],
            'elle ne doit pas être traitée en plus comme un supplément payant (double facturation)'
        ).toBeUndefined();
    });

    it('« Grande Portion » reste correctement classée (branche témoin, jamais défectueuse)', () => {
        const restore = buildWizardRestorePayload(makeCartLine(['Grande Portion']), makeItem());

        expect(restore.fritesGrande).toBe(true);
    });

    it('le supplément Cheddar payant n\'atterrit JAMAIS dans les garnitures', () => {
        // Verrouille le MIROIR d'exclusion (ItemComponent.vue ~:1565), qui doit refléter
        // exactement la chaîne de classification. Une divergence entre les deux listes est
        // précisément ce qui a produit le défaut de facturation d'origine.
        const restore = buildWizardRestorePayload(makeCartLine(['Cheddar']), makeItem());

        expect(restore.garnitures['c_301'], 'un supplément payant n\'est pas une garniture').toBeUndefined();
        expect(restore.supplements['p_301']).toBe(true);
    });

    it('les deux peuvent coexister sur la même ligne sans se contaminer', () => {
        const restore = buildWizardRestorePayload(makeCartLine(['Cheddar', 'Cheddar Fondu']), makeItem());

        expect(restore.supplements['p_301'], 'le supplément reste un supplément').toBe(true);
        expect(restore.fritesCheddar, 'l\'option de menu reste l\'option de menu').toBe(true);
    });
});
