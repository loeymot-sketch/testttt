import { describe, it, expect } from 'vitest';
import PosComponent from '../../resources/js/components/admin/pos/PosComponent.vue';

/**
 * [PERF-CAISSE 2026-08-19 · GOAL owner] Les animations « panier qui rebondit » et
 * « total qui flashe » ne se déclenchaient JAMAIS. C'est littéralement la plainte
 * du propriétaire — « l'interface n'est pas si dynamique » — et c'était un défaut,
 * pas une opinion.
 *
 * CAUSE : le watcher était `deep: true` sur `carts`, et le getter Vuex
 * `posCart/lists` renvoie `state.lists` LUI-MÊME. Vue passe alors la MÊME référence
 * en `newValue` et `oldValue`. Les deux `reduce` du handler comptaient donc le même
 * tableau : `newCount` était toujours STRICTEMENT ÉGAL à `oldCount`, et la condition
 * `newCount > oldCount` était structurellement impossible.
 *
 * CORRECTIF : observer une VALEUR SCALAIRE dérivée (`cartTotalQuantity`). Vue compare
 * alors deux nombres et la comparaison redevient vraie.
 *
 * BÉNÉFICE SECONDAIRE : c'était le SEUL watcher `deep` de l'arbre POS. Le retirer
 * supprime la traversée profonde du panier à chaque mutation ET neutralise un risque
 * de récursion — le getter `posCart/lists` mute ses propres dépendances (il normalise
 * chaque ligne à la lecture) ; un second observateur profond aurait suffi à faire
 * boucler Vue à l'infini, et le garde-fou « Maximum recursive updates » est compilé
 * HORS du build de production (l'onglet aurait figé sans message).
 */
describe('Panier caisse — les animations sont vivantes', () => {
    const cartTotalQuantity = PosComponent.computed.cartTotalQuantity;
    const watcher = PosComponent.watch.cartTotalQuantity;

    it('la quantité totale est bien une valeur scalaire', () => {
        expect(cartTotalQuantity.call({ carts: [{ quantity: 2 }, { quantity: 3 }] })).toBe(5);
        expect(cartTotalQuantity.call({ carts: [] })).toBe(0);
        expect(cartTotalQuantity.call({ carts: null })).toBe(0);
        // Quantité illisible ⇒ comptée 0, jamais NaN (un NaN casserait toute comparaison).
        expect(cartTotalQuantity.call({ carts: [{ quantity: 'x' }, { quantity: 2 }] })).toBe(2);
    });

    it('AJOUT d\'un article → le panier rebondit et le total flashe', () => {
        const appels = [];
        const vm = {
            triggerCartBump: () => appels.push('bump'),
            triggerTotalFlash: () => appels.push('flash'),
        };

        watcher.call(vm, 1, 0);

        expect(appels).toEqual(['bump', 'flash']);
    });

    it('RETRAIT d\'un article → aucune animation', () => {
        const appels = [];
        const vm = {
            triggerCartBump: () => appels.push('bump'),
            triggerTotalFlash: () => appels.push('flash'),
        };

        watcher.call(vm, 1, 2);

        expect(appels).toEqual([]);
    });

    it('ÉDITION sans changement de quantité → aucune animation', () => {
        const appels = [];
        const vm = {
            triggerCartBump: () => appels.push('bump'),
            triggerTotalFlash: () => appels.push('flash'),
        };

        watcher.call(vm, 3, 3);

        expect(appels).toEqual([]);
    });

    it('vider le panier ne déclenche pas d\'animation', () => {
        const appels = [];
        const vm = {
            triggerCartBump: () => appels.push('bump'),
            triggerTotalFlash: () => appels.push('flash'),
        };

        watcher.call(vm, 0, 4);

        expect(appels).toEqual([]);
    });

    it('RÉGRESSION : plus aucun watcher `deep` sur le panier', () => {
        // Le watcher `carts` ne sert plus qu'à réinitialiser remise et type de commande
        // quand le panier se vide — aucune traversée profonde n'est nécessaire pour ça.
        // Un `deep: true` réintroduit ici ferait resurgir la traversée à chaque mutation
        // ET le risque de récursion décrit en tête de fichier.
        expect(PosComponent.watch.carts.deep).toBeUndefined();
    });
});
