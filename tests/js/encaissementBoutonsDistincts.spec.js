import { describe, it, expect } from 'vitest';
import fs from 'node:fs';
import path from 'node:path';

/**
 * LE GESTE QUI PREND L'ARGENT DOIT DIRE DE QUELLE COMMANDE IL PARLE.
 *
 * D-007, relevé par le superviseur adverse. La file d'encaissement affichait trois tickets
 * — 11,10 €, 8,30 € et 14,60 € — et trois boutons RIGOUREUSEMENT identiques :
 *
 *     <button class="enc-collect-btn">Encaisser</button>
 *
 * Ni aria-label, ni data-testid, aucune référence à la commande. Au lecteur d'écran comme au
 * clavier, on entendait « Encaisser, bouton » trois fois de suite, pour trois montants
 * différents. C'est le geste qui PREND l'argent du client.
 *
 * Le même dépôt fait pourtant l'inverse ailleurs : le bouton de réimpression de l'historique
 * porte `title` ET `aria-label`. On s'aligne.
 *
 * Le `data-testid` n'est pas décoratif non plus : sans lui, aucun test ne peut viser UNE
 * commande précise dans cette file — ce qui est exactement pourquoi ce défaut a survécu
 * jusqu'ici.
 */

const VUE = path.resolve(
    __dirname,
    '../../resources/js/components/admin/encaissement/EncaissementComponent.vue'
);
const source = () => fs.readFileSync(VUE, 'utf8');

/** Le bloc du bouton d'encaissement. */
function boutonEncaisser(src) {
    const i = src.indexOf('enc-collect-btn"');
    if (i === -1) return null;
    const debut = src.lastIndexOf('<button', i);
    const fin = src.indexOf('</button>', i);
    return debut === -1 || fin === -1 ? null : src.slice(debut, fin + 9);
}

describe('file d\'encaissement — chaque bouton désigne sa commande', () => {
    it('le bouton porte un nom accessible', () => {
        const b = boutonEncaisser(source());
        expect(b, 'bouton d\'encaissement introuvable').not.toBeNull();

        expect(
            /:aria-label=/.test(b),
            'RÉGRESSION D-007 : le bouton qui prend l\'argent n\'a plus de nom accessible. '
            + 'Trois tickets, trois montants, trois fois « Encaisser, bouton ».'
        ).toBe(true);
    });

    it('le nom accessible porte LA COMMANDE et LE MONTANT', () => {
        const b = boutonEncaisser(source());

        expect(
            /order\.(order_serial_no|id)/.test(b),
            'le nom doit identifier la commande : sinon les trois boutons restent '
            + 'indiscernables, aria-label ou pas.'
        ).toBe(true);

        expect(
            /orderAmount\(order\)/.test(b),
            'le nom doit porter le MONTANT. C\'est le chiffre que le caissier confirme au '
            + 'client : se tromper de ticket, c\'est encaisser la mauvaise somme.'
        ).toBe(true);
    });

    it('le bouton est ciblable par un test, commande par commande', () => {
        const b = boutonEncaisser(source());

        expect(
            /:data-testid=.*order\.id/.test(b),
            'sans identifiant par commande, aucun test ne peut viser UN ticket dans la file — '
            + 'ce qui est précisément pourquoi ce défaut a survécu jusqu\'ici.'
        ).toBe(true);
    });

    it('le libellé VISIBLE reste court — on ne surcharge pas le bouton', () => {
        const b = boutonEncaisser(source());

        // Le nom accessible est riche ; le texte à l'écran doit rester un verbe.
        const visible = b.match(/>\s*\{\{([^}]*)\}\}\s*</);
        expect(visible, 'le libellé visible du bouton a disparu').not.toBeNull();
        expect(
            /order\./.test(visible[1]),
            'le montant et le numéro appartiennent au NOM ACCESSIBLE, pas au bouton lui-même : '
            + 'ils sont déjà affichés juste à côté, et un bouton qui répète son voisin encombre '
            + 'sans informer.'
        ).toBe(false);
    });
});
