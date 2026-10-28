import { describe, it, expect } from 'vitest';
import fs from 'node:fs';
import path from 'node:path';

/**
 * L'ÉCRAN CUISINE DOIT SE LIRE ENTIÈREMENT, ET NE RIEN PROMETTRE QU'IL NE MONTRE.
 *
 * Deux constats du superviseur adverse, vérifiés à l'écran avant et après correction.
 *
 * E-011 — la fiche affichait « N° Commande: En Ligne ». Une étiquette qui promet un NUMÉRO
 * était suivie d'un TYPE de commande. Pire : la carte se trouvait dans la colonne
 * « À emporter » — elle se contredisait donc elle-même sur le canal, dans la colonne qui
 * l'annonce déjà. Le vrai identifiant existait deux lignes plus haut (« #AUDE-EXTRAS-1 »).
 *
 * E-012 — à 1280 px, le 4e onglet « Terminées » était recouvert par le champ de recherche.
 * Il a fallu TROIS essais et deux mesures pour trouver la vraie cause, et c'est instructif :
 *
 *   1. `min-w-0 flex-1` sur les onglets — sans effet : `lg:!w-full` est marqué IMPORTANT
 *      et force 100 % de largeur, `flex-1` ne peut rien contre.
 *   2. `xl:!w-auto` en plus — toujours tronqué : mesuré, le groupe faisait 595 px pour
 *      631 px de boutons. Sa boîte MENTAIT sur sa taille, donc le passage à la ligne ne se
 *      déclenchait pas et les boutons débordaient sous le champ.
 *   3. Le coupable était `flex-1` lui-même : `flex: 1 1 0%` donne la place RESTANTE
 *      (900 − 305 = 595), pas celle qu'il faut. Retiré, le groupe prend ses 643 px réels,
 *      le total dépasse le conteneur, et `flex-wrap` fait descendre le champ.
 *
 * La leçon tient en une ligne : on ne corrige pas une mise en page en empilant des classes,
 * on la mesure.
 */

const KDS = path.resolve(
    __dirname,
    '../../resources/js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue'
);
const source = () => fs.readFileSync(KDS, 'utf8');

describe('écran cuisine — la fiche ne ment pas sur le numéro', () => {
    it('E-011 : le CANAL ne remplace jamais le NUMÉRO de commande', () => {
        const src = source();

        // Le motif du défaut : le repli du jeton était le libellé du canal.
        const fautifs = src.match(/\.token\s*\?[^}]*\$t\("label\.online"\)/g) || [];
        expect(
            fautifs.length,
            `RÉGRESSION E-011 : ${fautifs.length} fiche(s) affichent le CANAL dans le champ du `
            + 'NUMÉRO de commande. « N° Commande: En Ligne » sur une carte rangée dans '
            + '« À emporter » : la fiche se contredit elle-même, et le caissier cherche un '
            + 'numéro qu\'on ne lui donne pas.'
        ).toBe(0);
    });

    it('E-011 : le repli est un tiret, pas une invention', () => {
        const src = source();
        const replis = src.match(/(dinein|takeaway)Order\.token \|\| '—'/g) || [];
        expect(
            replis.length,
            'les fiches sans jeton doivent afficher « — ». Un champ vide inquiète, un tiret '
            + 'dit « il n\'y en a pas ».'
        ).toBeGreaterThanOrEqual(2);
    });
});

describe('écran cuisine — les onglets se lisent en entier', () => {
    it('E-012 : le conteneur autorise le passage à la ligne', () => {
        const src = source();
        const conteneur = src.match(/<div class="swiper kitchen-swiper([^"]*)"/);
        expect(conteneur, 'conteneur des onglets introuvable').not.toBeNull();

        expect(
            /flex-wrap/.test(conteneur[1]),
            'RÉGRESSION E-012 : sans passage à la ligne, les quatre onglets (631 px) et le '
            + 'champ de recherche (305 px) se disputent 900 px. Le dernier onglet passe sous '
            + 'le champ et devient illisible.'
        ).toBe(true);
    });

    it('E-012 : le groupe d\'onglets n\'est PAS contraint à la place restante', () => {
        const src = source();
        const swiper = src.match(/<Swiper[^>]*class="([^"]*)"/);
        expect(swiper, 'groupe d\'onglets introuvable').not.toBeNull();

        expect(
            /xl:flex-1/.test(swiper[1]),
            'RÉGRESSION : `flex-1` est de retour sur le groupe d\'onglets. `flex: 1 1 0%` lui '
            + 'donne la place RESTANTE au lieu de celle qu\'il lui faut — sa boîte ment alors '
            + 'sur sa taille, le passage à la ligne ne se déclenche pas, et les boutons '
            + 'débordent sous le champ de recherche. Mesuré : 595 px de boîte pour 631 px de '
            + 'boutons.'
        ).toBe(false);

        expect(
            /xl:!w-auto/.test(swiper[1]),
            'le groupe doit prendre sa largeur de CONTENU au-delà de lg : sans cela le '
            + '`lg:!w-full` marqué important le force à 100 % et rien d\'autre n\'a d\'effet.'
        ).toBe(true);
    });
});
