import { describe, it, expect, beforeEach } from 'vitest';
import PosComponent from '../../resources/js/components/admin/pos/PosComponent.vue';

/**
 * [AUDIT ABUSIF 2026-11-06 · tour 3 · ECR-R3-001] Réponse d'encaissement perdue (vente scellée côté serveur), puis F5 :
 * le panier revient (posCart, 2 h) et la clé d'idempotence gardée dans `pos.cle-encaissement` doit revenir AVEC lui.
 * Prouvé au navigateur : le watcher `carts` (immediate) voyait le panier encore vide AVANT sa réhydratation et effaçait la
 * clé 239 ms après le chargement — le nouvel essai partait avec une clé neuve et scellait une 2ᵉ vente fiscale.
 * Banc sur le VRAI handler du composant et la VRAIE méthode cleStablePourPanier (pas une copie).
 */
const handler = PosComponent.watch.carts.handler;
const quantite = PosComponent.watch.cartTotalQuantity;
const cleStable = PosComponent.methods.cleStablePourPanier;

function caisse(carts) {
    return {
        carts,
        checkoutProps: { form: { order_type: 5, dining_table_id: null, customer_id: 12, discount_reason: 'x' } },
        $refs: {},
        $nextTick: () => {},
        ensureWalkInCustomer: () => {},
        _cleEncaissement: null,
    };
}
const panier = [{ item_id: 7, quantity: 2, item_variations: [], item_extras: [], item_addons: [], instruction: '', total_price: 13 }];

describe('caisse — clé d\'encaissement après rechargement', () => {
    beforeEach(() => window.localStorage.clear());

    it('le premier appel (panier pas encore réhydraté) ne détruit pas la clé gardée', () => {
        const avant = caisse(panier);
        const cle = cleStable.call(avant, 'encaissement', 1);
        expect(window.localStorage.getItem('pos.cle-encaissement')).toContain(cle);

        // F5 : nouvelle instance, panier vide au démarrage puis réhydraté.
        const apres = caisse([]);
        handler.call(apres, [], undefined);
        expect(window.localStorage.getItem('pos.cle-encaissement')).toContain(cle);
        apres.carts = panier;
        expect(cleStable.call(apres, 'encaissement', 1)).toBe(cle);
    });

    it('un panier VIDÉ après une vente oublie la clé : la vente suivante est une nouvelle commande', () => {
        const c = caisse(panier);
        const cle = cleStable.call(c, 'encaissement', 1);
        handler.call(c, [], panier);
        expect(window.localStorage.getItem('pos.cle-encaissement')).toBeNull();
        expect(c._cleEncaissement).toBeNull();
        expect(cleStable.call(caisse(panier), 'encaissement', 1)).not.toBe(cle);
    });

    it('[tour 4 · R4-ADV-001] lignes retirées une à une (panier vidé EN PLACE) : la clé est quand même oubliée', () => {
        const c = { ...caisse(panier), triggerCartBump: () => {}, triggerTotalFlash: () => {} };
        cleStable.call(c, 'encaissement', 1);
        quantite.call(c, 1, 2);
        expect(window.localStorage.getItem('pos.cle-encaissement')).not.toBeNull();
        quantite.call(c, 0, 1);
        expect(window.localStorage.getItem('pos.cle-encaissement')).toBeNull();
        expect(c._cleEncaissement).toBeNull();
    });

    it('réhydratation (quantité 0 → 2) : la clé gardée reste', () => {
        const c = { ...caisse(panier), triggerCartBump: () => {}, triggerTotalFlash: () => {} };
        const cle = cleStable.call(c, 'encaissement', 1);
        quantite.call(c, 2, 0);
        expect(window.localStorage.getItem('pos.cle-encaissement')).toContain(cle);
    });
});
