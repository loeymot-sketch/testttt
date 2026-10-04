/**
 * [OWNER 2026-08-25] TESTS ABUSIFS de la modification d'un produit du panier.
 *
 * Le propriétaire : « tu ne déploieras jamais qu'avec les tests d'abus, comme ça tu
 * confirmes que c'est validé à 100 %, sans problème direct ou indirect ».
 *
 * On ne teste donc pas le chemin heureux — il est couvert par kioskModifierDepuisRecap.spec.js.
 * On cherche ici ce qui COÛTE : une ligne de panier perdue, une ligne dupliquée (le client
 * paie deux fois), la MAUVAISE ligne remplacée, ou un état d'édition resté orphelin qui
 * détournera la prochaine validation. Chaque scénario est un abus plausible en service :
 * un client qui tape deux fois, qui abandonne, qui revient en arrière, qui laisse la borne
 * se remettre en veille au milieu.
 */
import { describe, it, expect, beforeEach } from 'vitest';
import { kioskCart } from '../../resources/js/store/modules/kioskCart';

/** Mini-magasin : on exerce les VRAIES mutations/actions du module, sans Vuex. */
function magasin(items = []) {
    const state = JSON.parse(JSON.stringify(kioskCart.state
        ? (typeof kioskCart.state === 'function' ? kioskCart.state() : kioskCart.state)
        : {}));
    state.items = JSON.parse(JSON.stringify(items));
    state.editingCartIndex = null;
    state.editingCartSnapshot = null;

    const commit = (nom, charge) => {
        const m = kioskCart.mutations[nom];
        if (!m) throw new Error('mutation inconnue : ' + nom);
        m(state, charge);
    };
    const dispatch = (nom, charge) => {
        const a = kioskCart.actions[nom];
        if (!a) throw new Error('action inconnue : ' + nom);
        return a({ commit, state, dispatch, getters: lecteurs() }, charge);
    };
    const lecteurs = () => ({
        isEditingCart: state.editingCartIndex !== null,
        editingCartSnapshot: state.editingCartSnapshot,
    });
    return { state, commit, dispatch, lecteurs };
}

const ligne = (id, nom, prix = 10.9) => ({
    item_id: id, name: nom, quantity: 1,
    convert_price: prix, price: prix,
    _wizardSelections: { viandes: { v_1: 1 }, sauceOrder: [51] },
});

describe('abus — modifier un produit ne doit JAMAIS perdre ni dupliquer une ligne', () => {
    let s;
    beforeEach(() => { s = magasin([ligne(26, 'Tacos M'), ligne(234, 'Tacos XL'), ligne(97, 'Tacos L')]); });

    it('la ligne reste dans le panier PENDANT toute la modification', () => {
        // Le bug historique : l'ancien `popItem` retirait la ligne à l'ouverture. Un
        // client qui abandonnait perdait son article sans jamais l'avoir demandé.
        s.dispatch('startEditingCartItem', 1);
        expect(s.state.items).toHaveLength(3);
        expect(s.state.items[1].name).toBe('Tacos XL');
    });

    it('abandonner la modification laisse le panier EXACTEMENT comme avant', () => {
        const avant = JSON.stringify(s.state.items);
        s.dispatch('startEditingCartItem', 1);
        s.dispatch('cancelEditingCartItem');
        expect(JSON.stringify(s.state.items)).toBe(avant);
        expect(s.state.editingCartIndex).toBeNull();
    });

    it('valider REMPLACE la ligne éditée — le panier ne grossit pas', () => {
        s.dispatch('startEditingCartItem', 1);
        s.dispatch('replaceEditingCartItem', ligne(234, 'Tacos XL modifié'));
        expect(s.state.items).toHaveLength(3);
        expect(s.state.items[1].name).toBe('Tacos XL modifié');
    });

    it('c\'est bien la ligne CIBLÉE qui change, pas ses voisines', () => {
        s.dispatch('startEditingCartItem', 1);
        s.dispatch('replaceEditingCartItem', ligne(234, 'Tacos XL modifié'));
        expect(s.state.items[0].name).toBe('Tacos M');
        expect(s.state.items[2].name).toBe('Tacos L');
    });

    it('valider APRÈS avoir annulé n\'écrase personne — l\'article est ajouté', () => {
        // Course réelle : la borne se remet en veille (annule l'édition) pendant que le
        // client appuie sur « Ajouter ». Sans repli, l'article composé serait perdu ;
        // avec un mauvais repli, il écraserait une ligne au hasard.
        s.dispatch('startEditingCartItem', 1);
        s.dispatch('cancelEditingCartItem');
        s.dispatch('replaceEditingCartItem', ligne(999, 'Composé après annulation'));
        expect(s.state.items).toHaveLength(4);
        expect(s.state.items[3].name).toBe('Composé après annulation');
        expect(s.state.items[1].name).toBe('Tacos XL');
    });

    it('deux « Modifier » d\'affilée ne gardent que la DERNIÈRE cible', () => {
        // Un client pressé tape sur deux lignes coup sur coup. Si l'état d'édition
        // gardait la première, sa validation écraserait le mauvais article.
        s.dispatch('startEditingCartItem', 0);
        s.dispatch('startEditingCartItem', 2);
        s.dispatch('replaceEditingCartItem', ligne(97, 'Tacos L modifié'));
        expect(s.state.items[2].name).toBe('Tacos L modifié');
        expect(s.state.items[0].name).toBe('Tacos M');
    });

    it('modifier une ligne qui n\'existe pas est refusé sans rien casser', () => {
        expect(s.dispatch('startEditingCartItem', 42)).toBe(false);
        expect(s.state.editingCartIndex).toBeNull();
        expect(s.state.items).toHaveLength(3);
    });

    it('un index négatif ne remplace rien', () => {
        s.commit('SET_EDITING', { index: -1, snapshot: s.state.items[0] });
        s.dispatch('replaceEditingCartItem', ligne(1, 'Intrus'));
        expect(s.state.items.map((i) => i.name)).toEqual(['Tacos M', 'Tacos XL', 'Tacos L']);
    });

    it('le snapshot est une COPIE : modifier le panier ensuite ne le déforme pas', () => {
        // Sans copie profonde, éditer la ligne muterait le snapshot censé permettre
        // de revenir en arrière — l'annulation ne restaurerait alors plus rien.
        s.dispatch('startEditingCartItem', 1);
        s.state.items[1].name = 'Altéré en direct';
        expect(s.state.editingCartSnapshot.name).toBe('Tacos XL');
    });

    it('la quantité reste dans ses bornes même si la ligne modifiée ment', () => {
        // Une quantité absurde arrivant du wizard (bug, rejeu, injection) ne doit pas
        // se retrouver telle quelle dans un panier qui part en caisse.
        s.dispatch('startEditingCartItem', 1);
        s.dispatch('replaceEditingCartItem', { ...ligne(234, 'Tacos XL'), quantity: 9999 });
        expect(s.state.items[1].quantity).toBeGreaterThan(0);
        expect(s.state.items[1].quantity).toBeLessThanOrEqual(20);
    });

    it('une quantité négative ou absurde retombe à 1, jamais à 0 ni en dessous', () => {
        s.dispatch('startEditingCartItem', 1);
        s.dispatch('replaceEditingCartItem', { ...ligne(234, 'Tacos XL'), quantity: -5 });
        expect(s.state.items[1].quantity).toBe(1);
    });

    it('le devis en cache est invalidé à chaque remplacement', () => {
        // Sinon la borne afficherait le total de l'ANCIENNE composition, et le client
        // paierait un montant qui ne correspond plus à ce qu'il a choisi.
        s.dispatch('startEditingCartItem', 1);
        s.state.orderQuote = { total: 10.9 };
        s.dispatch('replaceEditingCartItem', ligne(234, 'Tacos XL 4 viandes', 13.4));
        expect(s.state.orderQuote).toBeNull();
    });
});
