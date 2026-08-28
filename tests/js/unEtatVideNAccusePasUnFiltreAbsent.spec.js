import { describe, it, expect } from 'vitest';
import fs from 'fs';
import path from 'path';

/**
 * [ONB 2026-08-28] Un écran vide ne doit pas accuser le commerçant d'un filtre
 * qu'il n'a jamais posé.
 *
 * DEUX ÉCRANS LE FAISAIENT, et c'est exactement ce que voit un commerçant qui
 * vient de terminer son installation — c'est-à-dire au moment où il a le moins
 * de contexte pour comprendre qu'on lui ment.
 *
 *   · `IngredientListComponent` — l'onglet par défaut est « Tous », et
 *     `fetchIngredients()` n'envoie alors AUCUN paramètre. L'écran affichait
 *     malgré tout « Aucun ingrédient trouvé pour ce filtre. » Le commerçant
 *     cherche le filtre. Il n'y en a pas.
 *
 *   · `CashOverviewComponent` — l'état vide était `v-else-if="!transactions.length"`,
 *     sans aucun égard aux filtres, et proposait un bouton « Réinitialiser les
 *     filtres ». Or `clearFilters()` pose `from = to = aujourd'hui, source = '',
 *     mode = ''` : c'est L'ÉTAT PAR DÉFAUT. Sur une installation neuve ce bouton
 *     ne faisait donc **rien**, tout en affirmant qu'il y avait quelque chose à
 *     réinitialiser.
 *
 * L'IRONIE DU SECOND : son propre commentaire de mai explique que cet état vide
 * a été « poli » pour remplacer un « Aucune donnée » jugé trop sec, qui « laissait
 * l'admin se demander si la page avait échoué en silence ». Le polissage a
 * remplacé une impasse VAGUE par une impasse TROMPEUSE — objectivement pire, car
 * la première n'envoyait au moins personne sur une fausse piste.
 *
 * CE BANC MORD : neutraliser l'une des deux conditions le fait rougir en nommant
 * l'écran et la phrase fautive.
 */
describe("un état vide n'accuse pas un filtre absent", () => {
    const racine = process.cwd();
    const lire = (p) => fs.readFileSync(path.join(racine, p), 'utf8');

    const INGREDIENTS = 'resources/js/components/admin/ingredients/IngredientListComponent.vue';
    const CAISSE = 'resources/js/components/admin/cashOverview/CashOverviewComponent.vue';
    const FR = 'resources/js/languages/fr.json';
    const EN = 'resources/js/languages/en.json';

    it("la liste d'ingrédients distingue « rien » de « rien qui corresponde »", () => {
        const source = lire(INGREDIENTS);

        // L'onglet par défaut doit rester « all » — sinon la distinction ci-dessous
        // porterait sur autre chose que ce qu'on croit mesurer.
        expect(
            source,
            "L'onglet par défaut n'est plus `all` : ce banc mesure alors autre chose.",
        ).toContain("this.$route?.params?.type || 'all'");

        expect(
            source,
            "L'état vide doit choisir sa phrase selon qu'un onglet est réellement\n"
            + 'sélectionné. Sans ce choix, un commerçant sans aucun ingrédient lit\n'
            + "« trouvé pour ce filtre » et part chercher un filtre inexistant.",
        ).toMatch(/activeTab === 'all'\s*\?\s*\$t\('label\.ingredient\.empty_all'\)/);
    });

    it("le journal de caisse ne propose pas de réinitialiser des filtres absents", () => {
        const source = lire(CAISSE);

        // Le repli par défaut, tel que `clearFilters()` l'écrit. S'il change, la
        // notion de « filtre actif » doit changer avec lui.
        expect(
            source,
            'Le repli de `clearFilters()` a changé : `unFiltreEstActif` doit suivre.',
        ).toContain("this.filters = { from: today, to: today, source: '', mode: '' };");

        expect(
            source,
            '`unFiltreEstActif` est absent : la copie et le bouton redeviennent\n'
            + 'inconditionnels, et mentent sur une installation neuve.',
        ).toContain('unFiltreEstActif()');

        expect(
            source,
            "La phrase affichée doit dépendre d'un filtre réellement posé.",
        ).toMatch(/unFiltreEstActif\s*\?\s*\$t\('label\.cash_overview_empty_copy'\)/);

        // Le bouton doit disparaître quand il n'a rien à réinitialiser : un bouton
        // qui ne fait rien coûte plus cher qu'un bouton absent.
        const bloc = source.slice(
            source.indexOf('data-testid="cash-overview-empty"'),
            source.indexOf('<!-- Transactions table -->'),
        );

        expect(bloc.length, "Le bloc d'état vide est introuvable.").toBeGreaterThan(200);
        expect(
            bloc,
            "Le bouton « Réinitialiser les filtres » doit être conditionné : sans\n"
            + 'filtre actif, `clearFilters()` réécrit exactement les valeurs déjà en\n'
            + "place et l'écran ne bouge pas. Le commerçant clique, rien ne se passe.",
        ).toContain('v-if="unFiltreEstActif"');
    });

    it('les trois phrases neuves existent dans les deux langues', () => {
        const cles = [
            ['label', 'ingredient', 'empty_all'],
            ['label', 'ingredient', 'empty_filtered'],
            ['label', 'cash_overview_empty_vierge'],
        ];

        for (const fichier of [FR, EN]) {
            const arbre = JSON.parse(lire(fichier));

            for (const chemin of cles) {
                const valeur = chemin.reduce((n, k) => (n ?? {})[k], arbre);

                expect(
                    typeof valeur,
                    `${fichier} n'a pas \`${chemin.join('.')}\` : l'écran afficherait la clé brute.`,
                ).toBe('string');

                expect(valeur.length, `${chemin.join('.')} est vide dans ${fichier}.`).toBeGreaterThan(20);

                // Le fond du sujet : la phrase du cas « aucune donnée » ne doit pas
                // parler de filtre, sous peine de reproduire le défaut à l'identique.
                if (chemin.at(-1) !== 'empty_filtered') {
                    expect(
                        valeur.toLowerCase(),
                        `${chemin.join('.')} parle encore de filtre alors qu'aucun n'est posé.`,
                    ).not.toMatch(/filtre|filter/);
                }
            }
        }
    });
});
