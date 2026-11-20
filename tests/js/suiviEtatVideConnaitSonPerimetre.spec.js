import { describe, it, expect } from 'vitest';
import fs from 'node:fs';
import path from 'node:path';

/**
 * UN ÉTAT VIDE NE DOIT PAS CONTREDIRE LE BANDEAU QUI LE SURPLOMBE.
 *
 * AB-002, relevé par le superviseur adverse : deux affirmations absolues sur l'argent dû, à
 * QUARANTE PIXELS d'écart sur le même écran.
 *
 *   Bandeau  : « 💶 2 commande(s) à encaisser hors de ce tableau — ouvrir l'encaissement »
 *   Colonne  : « À encaisser » · badge « 0 » · « Aucune commande à encaisser. »
 *
 * Les deux chiffres sont JUSTES : le bandeau compte les commandes antérieures à la journée
 * de service, la colonne montre la journée. Mais rien ne le disait — et un lecteur d'écran
 * n'entendait que « 0 À encaisser ».
 *
 * Sur un écran de caisse, « 0 à encaisser » et « 2 à encaisser » côte à côte, c'est le genre
 * de contradiction qui fait douter de tout le reste de la page.
 *
 * Le composant savait pourtant faire : son état vide FILTRÉ dit déjà « Aucune commande
 * Téléphone dans « À encaisser » — filtre canal actif ». On s'aligne dessus.
 */

const VUE = path.resolve(
    __dirname,
    '../../resources/js/components/admin/pos/PosOrdersTrackerComponent.vue'
);
const FR = path.resolve(__dirname, '../../resources/js/languages/fr.json');

describe('suivi — l\'état vide « À encaisser » nomme son périmètre', () => {
    it('le message vide DÉPEND du compte des commandes antérieures', () => {
        const src = fs.readFileSync(VUE, 'utf8');
        const bloc = src.match(/emptyIcon: '✓',([\s\S]*?)\n                \},/);
        expect(bloc, 'colonne « À encaisser » introuvable').not.toBeNull();

        expect(
            /olderPendingCount\s*>\s*0/.test(bloc[1]),
            'RÉGRESSION AB-002 : le message vide ne consulte plus le compte des commandes '
            + 'antérieures. Il redira « Aucune commande à encaisser. » pendant que le bandeau, '
            + 'quarante pixels plus haut, en annonce deux.'
        ).toBe(true);

        expect(
            /empty_accept_avec_anterieures/.test(bloc[1]),
            'le message qualifié a disparu'
        ).toBe(true);
    });

    it('le message qualifié PORTE le nombre, il ne se contente pas d\'allusions', () => {
        const l = JSON.parse(fs.readFileSync(FR, 'utf8')).pos.tracker;
        const m = l['empty_accept_avec_anterieures'];
        expect(m, 'libellé qualifié absent').toBeTruthy();

        expect(
            m.includes('{count}'),
            `le message doit citer le NOMBRE de commandes antérieures, or : « ${m} ». `
            + 'Sans lui, le caissier sait qu\'il en reste sans savoir combien.'
        ).toBe(true);

        // Et il doit nommer le périmètre de la colonne, sinon on n'a rien expliqué.
        expect(
            /journée de service/i.test(m),
            `le message doit dire de quel périmètre il parle, or : « ${m} »`
        ).toBe(true);
    });

    it('le cas SANS antérieures garde son message court', () => {
        const l = JSON.parse(fs.readFileSync(FR, 'utf8')).pos.tracker;

        expect(l['empty_accept'], 'le message simple a disparu').toBeTruthy();
        expect(
            /journée de service|antérieure/i.test(l['empty_accept']),
            'quand il n\'y a AUCUNE commande antérieure, il n\'y a rien à qualifier : le '
            + 'message doit rester court. Alourdir un état vide qui ne cache rien, c\'est '
            + 'échanger une confusion contre une autre.'
        ).toBe(false);
    });

    it('les deux messages parlent bien de la MÊME chose que le bandeau', () => {
        const l = JSON.parse(fs.readFileSync(FR, 'utf8')).pos.tracker;

        // Le bandeau et le message qualifié doivent renvoyer au même écran de sortie.
        expect(l['older_pending']).toMatch(/encaissement/i);
        expect(l['empty_accept_avec_anterieures']).toMatch(/encaissement/i);
    });
});
