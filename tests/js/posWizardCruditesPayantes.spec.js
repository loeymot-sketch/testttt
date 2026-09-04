/**
 * posWizardCruditesPayantes.spec.js
 * -----------------------------------------------------------------------------
 * [LOCK_CAISSE_CRUDITES_PAYANTES_2026-09-05] Le propriétaire, en service :
 * « j'ai ajouté des suppléments, ça affiche un chiffre 90 alors que ça n'a rien
 * à voir ; ça s'affiche comme un supplément et pas comme une chose ».
 *
 * Mesuré : `Maïs`, `Olives` et `Poivrons cuits` portent `group_label='crudite'`
 * et coûtent 0,90 €. La caisse ne reconnaît une crudité que si son prix vaut 0
 * ET que son nom figure dans une liste blanche (`pos-wizard.js:3126`, liste en
 * `:3506-3513` — qui ne contient ni « cornichon » ni « olive »). Les trois
 * tombaient donc dans le bac « ➕ Suppléments +0,90 € ».
 * **57 lignes sur 132 crudités actives (43 %), sur 19 produits.**
 *
 * La borne, elle, se fie au GROUPE depuis un mandat écrit du propriétaire du
 * 2026-08-05 (`kioskExtrasPartition.js:113-121`) : les crudités payantes
 * s'affichent parmi les crudités, avec leur badge de prix. Ce banc verrouille
 * la même règle à la caisse — le groupe fait autorité, le nom et le prix ne
 * restent qu'un repli pour les extras SANS groupe.
 *
 * Ce qui ne doit PAS changer : un vrai supplément payant reste un supplément,
 * et un extra sans groupe garde exactement l'ancien comportement.
 */
import { describe, it, expect } from 'vitest';
import { mountPosWizard, cayenneLikeItem, tick } from './posWizardHarness.js';

/** Bouton de garniture (bac crudités) pour un extra donné. */
function boutonGarniture(wizard, extraId) {
    return wizard.querySelector('.garniture-toggle-btn[data-garniture="c_' + extraId + '"]');
}

/** Vrai si l'extra apparaît quelque part dans le bac « Suppléments ». */
function estDansSupplements(wizard, extraId) {
    return Boolean(wizard.querySelector('[data-supplement="p_' + extraId + '"], [data-supplement="' + extraId + '"]'));
}

function itemAvecCrudites() {
    return cayenneLikeItem({
        extras: [
            // Crudités GRATUITES, nom reconnu — déjà correctes aujourd'hui (témoins).
            { id: 51, name: 'Oignon', convert_price: 0, currency_price: '€0.00', group_label: 'crudite', thumb: null },
            { id: 52, name: 'Salade', convert_price: 0, currency_price: '€0.00', group_label: 'crudite', thumb: null },
            // Crudités PAYANTES portant le groupe : le cœur du défaut.
            { id: 70, name: 'Maïs', convert_price: 0.9, currency_price: '€0.90', group_label: 'crudite', thumb: null },
            { id: 71, name: 'Olives', convert_price: 0.9, currency_price: '€0.90', group_label: 'crudite', thumb: null },
            { id: 72, name: 'Poivrons cuits', convert_price: 0.9, currency_price: '€0.90', group_label: 'crudite', thumb: null },
            // Vrai supplément payant : doit RESTER un supplément.
            { id: 80, name: 'Cheddar', convert_price: 0.9, currency_price: '€0.90', group_label: 'supplement', thumb: null },
            // Extra SANS groupe : le repli nom+prix doit rester inchangé.
            { id: 90, name: 'Cheddar Fondu', convert_price: 1, currency_price: '€1.00', thumb: null },
        ],
    });
}

// ⏸️ EN ATTENTE DE LA CONTRESIGNATURE PROPRIÉTAIRE.
// Ce banc est ARMÉ et PROUVÉ ROUGE contre le code actuel : 3 échecs (Maïs, Olives,
// Poivrons cuits) et 3 témoins verts (vrai supplément, crudités gratuites, extra sans
// groupe). Il est mis en attente pour ne pas laisser un rouge dans le dépôt partagé —
// le correctif vit dans `public/js/pos-wizard.js`, classé STRICT no-touch.
// ➡️ Le jour où le §8 de LOCK_CAISSE_CRUDITES_PAYANTES_2026-09-05.md est contresigné :
//    retirer le `.skip` ci-dessous, appliquer le correctif décrit au §4 du LOCK,
//    réaligner l'empreinte SHA-256 dans frozen-zone-sha256-baseline.json (même commit).
describe.skip('caisse — les crudités payantes restent des crudités (le groupe fait autorité)', () => {
    it('« Maïs » à 0,90 € s\'affiche parmi les crudités, pas dans les suppléments', async () => {
        const { wizard } = await mountPosWizard({ itemData: itemAvecCrudites() });
        await tick(10);

        expect(boutonGarniture(wizard, 70), 'Maïs doit avoir un bouton de crudité').toBeTruthy();
        expect(estDansSupplements(wizard, 70), 'Maïs ne doit pas être proposé en supplément').toBe(false);
    });

    it('« Olives » — dont le nom n\'est dans aucune liste blanche — suit son groupe', async () => {
        const { wizard } = await mountPosWizard({ itemData: itemAvecCrudites() });
        await tick(10);

        expect(boutonGarniture(wizard, 71), 'Olives doit avoir un bouton de crudité').toBeTruthy();
        expect(estDansSupplements(wizard, 71)).toBe(false);
    });

    it('« Poivrons cuits » à 0,90 € suit son groupe', async () => {
        const { wizard } = await mountPosWizard({ itemData: itemAvecCrudites() });
        await tick(10);

        expect(boutonGarniture(wizard, 72), 'Poivrons cuits doit avoir un bouton de crudité').toBeTruthy();
        expect(estDansSupplements(wizard, 72)).toBe(false);
    });

    it('un VRAI supplément payant reste un supplément (le garde ne doit rien déplacer d\'utile)', async () => {
        const { wizard } = await mountPosWizard({ itemData: itemAvecCrudites() });
        await tick(10);

        expect(boutonGarniture(wizard, 80), 'Cheddar (groupe supplement) n\'est pas une crudité').toBeFalsy();
    });

    it('les crudités GRATUITES restent affichées comme avant (non-régression)', async () => {
        const { wizard } = await mountPosWizard({ itemData: itemAvecCrudites() });
        await tick(10);

        expect(boutonGarniture(wizard, 51), 'Oignon').toBeTruthy();
        expect(boutonGarniture(wizard, 52), 'Salade').toBeTruthy();
    });

    it('un extra SANS group_label garde exactement l\'ancien comportement', async () => {
        // « Cheddar Fondu » à 1,00 € sans groupe : le repli nom+prix s'applique,
        // il n'est donc pas une crudité. Sans groupe, il n'y a pas de vérité à préférer.
        const { wizard } = await mountPosWizard({ itemData: itemAvecCrudites() });
        await tick(10);

        expect(boutonGarniture(wizard, 90)).toBeFalsy();
    });
});
