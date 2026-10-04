import { describe, it, expect } from 'vitest';
import fs from 'fs';
import path from 'path';
import { normalizeKdsStation, orderMatchesStationFilter } from '../../resources/js/helpers/kdsDisplay';

/**
 * [ONB-08 2026-08-28] Le filtre de poste de l'écran cuisine était un piège sans sortie.
 *
 * `normalizeKdsStation` déclare QUATRE postes — `bar`, `cuisine_chaude`,
 * `cuisine_froide` et `none` — et rabat toute valeur inconnue sur `none`. Le sélecteur
 * de l'écran, lui, n'en proposait que trois plus « Toutes ». `none` était absent des
 * options ET des deux listes blanches de restauration.
 *
 * Mesuré : 11 articles actifs portent `kds_station = 'none'`, dont **7 boissons
 * réellement vendables** (les autres sont de l'upsell technique et un fixture E2E). La
 * colonne est `NOT NULL DEFAULT 'none'` : tout article créé par un commerçant naît à
 * `none` et y reste, faute de champ d'administration.
 *
 * Conséquence en service : un cuisinier bascule une fois sur « Bar », voit 8 boissons
 * sur 15, et ne peut plus revoir les 7 autres autrement qu'en repassant sur
 * « Toutes ». Une commande composée uniquement d'articles `none` disparaît de TOUTE
 * vue filtrée. Le choix étant persisté par utilisateur, il survit au rechargement.
 *
 * ⚠️ Ce n'est PAS un défaut d'affichage par défaut : la vue par défaut vaut « Toutes »
 * et le ticket papier ignore complètement `kds_station` (il route sur la station de
 * l'imprimante). Rien ne disparaît tant qu'on ne filtre pas. C'est le filtre qui était
 * incomplet, pas l'écran qui cachait des commandes — la nuance a été vérifiée avant
 * d'écrire ce banc.
 */
describe('ONB-08 · filtre de poste de l\'écran cuisine', () => {
    const composant = fs.readFileSync(
        path.join(
            process.cwd(),
            'resources/js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue',
        ),
        'utf8',
    );

    it('le sélecteur propose tous les postes que le normaliseur déclare', () => {
        // La source de vérité est le normaliseur : tout poste qu'il peut produire doit
        // être atteignable depuis l'écran, sinon des articles deviennent introuvables.
        const postes = ['bar', 'cuisine_chaude', 'cuisine_froide', 'none'];

        for (const poste of postes) {
            expect(
                composant.includes(`<option value="${poste}">`),
                `le poste « ${poste} » n'est pas proposé dans le sélecteur : les articles `
                + 'qui le portent deviennent introuvables dès qu\'on filtre',
            ).toBe(true);
        }
    });

    it('le choix « sans poste » survit au rechargement', () => {
        // Deux listes blanches restaurent la préférence (clé courante et clé héritée).
        // Si `none` manque à l'une d'elles, le cuisinier retrouve « Toutes » au
        // rechargement sans comprendre pourquoi son filtre a sauté.
        const listes = composant.match(/=== "cuisine_froide"[^\n]*/g) || [];

        expect(listes.length).toBeGreaterThanOrEqual(2);
        for (const liste of listes) {
            expect(liste, 'une liste blanche de restauration ignore « none »').toContain('"none"');
        }
    });

    it('une commande sans poste est bien retenue par le filtre « sans poste »', () => {
        const commande = { order_items: [{ kds_station: 'none' }, { kds_station: null }] };

        expect(orderMatchesStationFilter(commande, 'none')).toBe(true);
        expect(orderMatchesStationFilter(commande, 'bar')).toBe(false);
        expect(orderMatchesStationFilter(commande, 'all')).toBe(true);
    });

    it('le normaliseur rabat bien le vide et l\'inconnu sur « sans poste »', () => {
        expect(normalizeKdsStation(null)).toBe('none');
        expect(normalizeKdsStation('')).toBe('none');
        expect(normalizeKdsStation('poste_invente')).toBe('none');
        expect(normalizeKdsStation('bar')).toBe('bar');
    });

    it('la clé de libellé existe en français ET en anglais', () => {
        for (const langue of ['fr', 'en']) {
            const json = JSON.parse(
                fs.readFileSync(
                    path.join(process.cwd(), `resources/js/languages/${langue}.json`),
                    'utf8',
                ),
            );

            expect(
                json.label?.kds_sans_poste,
                `${langue}.json : la clé manque — l'option afficherait la clé brute`,
            ).toBeTruthy();
        }
    });
});
