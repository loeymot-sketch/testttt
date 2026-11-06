import { describe, it, expect } from 'vitest';
import { buildReceiptData } from '../../resources/js/helpers/kioskPrinter.js';

/**
 * [INCIDENT TICKET 2026-09-05] Le propriétaire : « lors de l'impression du ticket ça
 * affiche un prix, lors d'encaisser un autre prix complètement ».
 *
 * Mesuré en production : le pied du ticket est JUSTE (il vient du serveur), mais les
 * LIGNES imprimées ne totalisent pas ce pied. `buildReceiptData` recalculait le prix
 * unitaire comme `convert_price + item_variation_total + item_extra_total`, une somme
 * de composants — alors que le montant réellement facturé est porté par `total_price`.
 * Un produit pris en FORMULE perd ainsi le supplément de la formule à l'impression :
 * `pos-wizard.js:4594` écrit `item_extra_total: 0` sur la ligne d'addon.
 *
 * Reproduction réelle (commande 953) : Tacos M 7,40 € + Menu 2,50 €. La ligne imprimée
 * annonçait « 7,40 € », le pied « 9,90 € ». Le client lit deux montants sur le même
 * papier. Portée mesurée : 114 lignes borne (267,80 €) et 24 lignes web (53,40 €).
 *
 * La règle canonique existe déjà et fait autorité ailleurs dans la caisse —
 * `posCartLineMath.js:32-40 rowUnitBundled` : **`total_price` d'abord, somme des
 * composants seulement en repli**. Ce banc verrouille cette règle pour le ticket borne.
 */
describe('ticket borne — le prix de ligne imprimé est celui qui est facturé', () => {
    const base = {
        restaurantName: 'Le Cayenne',
        queueNumber: 'A12',
        subtotal: 9.9,
        discount: 0,
        total: 9.9,
        paymentMethod: 'CB',
    };

    it('une ligne prise en formule imprime le montant facturé, pas la somme des composants', () => {
        // Cas réel : le montant de la formule vit dans total_price, jamais dans
        // item_extra_total — que le wizard écrit à 0 sur la ligne d'addon.
        const recu = buildReceiptData({
            ...base,
            cartItems: [{
                name: 'Tacos M',
                quantity: 1,
                convert_price: 7.4,
                item_variation_total: 0,
                item_extra_total: 0,
                total_price: 9.9,
            }],
        });

        expect(recu.items[0].unitPrice, 'le ticket doit imprimer 9,90 € — le pied annonce 9,90 €').toBe(9.9);
    });

    it('les lignes imprimées totalisent le pied du ticket', () => {
        // C'est l'invariant que le client vérifie des yeux : la somme de ce qu'il lit
        // doit faire le total qu'on lui demande.
        const recu = buildReceiptData({
            ...base,
            subtotal: 17.3,
            total: 17.3,
            cartItems: [
                { name: 'Tacos M', quantity: 1, convert_price: 7.4, item_variation_total: 0, item_extra_total: 0, total_price: 9.9 },
                { name: 'Cheese Burger', quantity: 1, convert_price: 6.5, item_variation_total: 0, item_extra_total: 0.9, total_price: 7.4 },
            ],
        });

        const sommeDesLignes = recu.items.reduce((t, l) => t + l.unitPrice * l.quantity, 0);
        expect(Number(sommeDesLignes.toFixed(2))).toBe(17.3);
    });

    it('sans total_price, on retombe sur la somme des composants (robustesse)', () => {
        // Les addons hérités et les instantanés hors-ligne n'ont pas total_price :
        // le repli doit rester exactement l'ancien calcul.
        const recu = buildReceiptData({
            ...base,
            cartItems: [{
                name: 'Bol Frites',
                quantity: 1,
                convert_price: 8,
                item_variation_total: 0.5,
                item_extra_total: 0.9,
            }],
        });

        expect(recu.items[0].unitPrice).toBeCloseTo(9.4, 2);
    });

    it('un total_price illisible ne fait pas tomber le ticket à zéro', () => {
        // Une chaîne vide ou non numérique ne doit jamais produire 0,00 € sur le papier :
        // on repasse par la somme des composants.
        for (const valeurCassee of ['', null, undefined, 'abc']) {
            const recu = buildReceiptData({
                ...base,
                cartItems: [{
                    name: 'Cayenne',
                    quantity: 1,
                    convert_price: 7.4,
                    item_variation_total: 0,
                    item_extra_total: 0.9,
                    total_price: valeurCassee,
                }],
            });
            expect(recu.items[0].unitPrice, `total_price = ${JSON.stringify(valeurCassee)}`).toBeCloseTo(8.3, 2);
        }
    });

    it('une quantité multiple garde le prix UNITAIRE facturé', () => {
        const recu = buildReceiptData({
            ...base,
            cartItems: [{
                name: 'Tacos M',
                quantity: 3,
                convert_price: 7.4,
                item_variation_total: 0,
                item_extra_total: 0,
                total_price: 9.9,
            }],
        });

        expect(recu.items[0].unitPrice).toBe(9.9);
        expect(recu.items[0].quantity).toBe(3);
    });
});
