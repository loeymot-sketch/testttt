import { describe, it, expect } from 'vitest';
import fs from 'node:fs';
import path from 'node:path';

/**
 * SUR UNE CAISSE TACTILE, DEUX BOUTONS NE DOIVENT PAS SE RESSEMBLER QUAND L'UN ANNULE.
 *
 * AB-009 et AB-015, relevés par le superviseur adverse.
 *
 * AB-009 — les cartes du suivi portent deux carrés d'environ 30 px, accolés : « imprimer »
 * et « annuler la commande ». Ils n'avaient qu'un attribut `title` pour se distinguer.
 *
 * C'est le mécanisme le plus faible — et il est INATTEIGNABLE ici : il n'y a pas de survol
 * au doigt. Rien à l'écran ne séparait une réimpression anodine d'une annulation. Le
 * superviseur a classé P2 en toute honnêteté, parce que `title` fournit tout de même un nom
 * accessible par repli ; le vrai défaut est qu'il est invisible là où on l'utilise.
 *
 * AB-015 — le nom du client s'affichait « Karim Bensa... », coupé par `text-overflow`, sans
 * aucun `title`. Le nom entier n'existait qu'indirectement dans l'infobulle du lien
 * téléphone voisin. Or c'est précisément le nom que le caissier lit pour appeler.
 *
 * Le patron suivi est celui que ce même fichier applique déjà au bouton « Rembourser » :
 * icône + libellé visible.
 */

const VUE = path.resolve(
    __dirname,
    '../../resources/js/components/admin/pos/PosOrdersTrackerComponent.vue'
);
const source = () => fs.readFileSync(VUE, 'utf8');

/** Le bloc d'un `<button>` identifié par son data-testid. */
function bouton(src, testid) {
    const i = src.indexOf(testid);
    if (i === -1) return null;
    const debut = src.lastIndexOf('<button', i);
    const fin = src.indexOf('</button>', i);
    return debut === -1 || fin === -1 ? null : src.slice(debut, fin + 9);
}

describe('suivi des commandes — les actions se nomment', () => {
    it('L\'ANNULATION porte un nom accessible explicite', () => {
        const b = bouton(source(), 'tracker-cancel-');
        expect(b, 'bouton d\'annulation introuvable').not.toBeNull();

        expect(
            /:aria-label=/.test(b),
            'RÉGRESSION AB-009 : le bouton qui ANNULE une commande n\'a plus de nom accessible '
            + 'explicite. Il ne reste que `title`, inatteignable au doigt : rien ne le distingue '
            + 'du bouton d\'impression collé à côté.'
        ).toBe(true);
    });

    it('L\'ANNULATION porte un libellé VISIBLE, pas seulement une icône', () => {
        const b = bouton(source(), 'tracker-cancel-');

        expect(
            /<span[^>]*>\{\{\s*\$t\(/.test(b),
            'l\'action destructrice doit afficher un mot, comme le fait déjà « Rembourser » '
            + 'quelques lignes plus bas dans ce même fichier. Une icône seule sur un écran '
            + 'tactile n\'est pas un libellé.'
        ).toBe(true);
    });

    it('le nom accessible DÉSIGNE LA COMMANDE, pas juste l\'action', () => {
        const b = bouton(source(), 'tracker-cancel-');

        // Trois boutons « Annuler » identiques au lecteur d'écran ne valent pas mieux qu'aucun.
        expect(
            /order\.(order_serial_no|id)/.test(b),
            'le nom accessible doit inclure le numéro de commande : sinon un lecteur d\'écran '
            + 'annonce « Annuler, bouton » trois fois de suite pour trois commandes différentes.'
        ).toBe(true);
    });

    it('LA RÉIMPRESSION aussi porte un nom accessible', () => {
        const b = bouton(source(), 'tracker-reprint-');
        expect(b, 'bouton de réimpression introuvable').not.toBeNull();
        expect(/:aria-label=/.test(b)).toBe(true);
    });

    it('AB-015 : le nom du client tronqué porte son texte complet', () => {
        const src = source();
        const bloc = src.match(/<div class="pos-tracker-card-customer"[\s\S]*?<\/div>/);
        expect(bloc, 'bloc client introuvable').not.toBeNull();

        expect(
            /<span :title="customerLabel\(order\)">/.test(bloc[0]),
            'RÉGRESSION AB-015 : le nom du client est coupé par `text-overflow` et ne porte plus '
            + 'son texte complet. « Karim Bensa... » sans moyen de lire la suite, sur l\'écran où '
            + 'le caissier cherche justement qui appeler.'
        ).toBe(true);
    });

    it('la troncature est bien la raison d\'être de ce garde', () => {
        const src = source();
        // Si la règle CSS de troncature disparaissait, le title deviendrait superflu — mais
        // tant qu'elle est là, il est indispensable. On documente le lien.
        expect(
            /\.pos-tracker-card-customer span\s*\{[^}]*text-overflow:\s*ellipsis/.test(src),
            'la règle de troncature a changé de forme : revérifier que le nom complet reste '
            + 'accessible, ou retirer ce garde devenu sans objet.'
        ).toBe(true);
    });
});
