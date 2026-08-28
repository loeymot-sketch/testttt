import { describe, it, expect } from 'vitest';
import fs from 'fs';
import path from 'path';

/**
 * [ONB-02/05 2026-08-28] Le Studio catalogue ouvre les portes dont le commerçant a
 * besoin là où il travaille.
 *
 * ═══ POURQUOI CE BANC EXISTE ═══
 *
 * Trois écrans livrés et utiles n'avaient **aucun lien** dans toute l'application.
 * `grep -rn "<nom de route>" resources/js/` hors routeur rendait zéro résultat :
 *
 *   · `admin.items.import`     — l'import de carte par photo, livré le 27 ;
 *   · `admin.items.assistant`  — l'assistant de missions locales, livré le 28 ;
 *   · `admin.settings.tax`     — **la page TVA**, dont le seul lien vit dans le menu
 *                                Réglages, gardé par `!isSettingHidden('tax')` — et
 *                                `tax` fait partie des 19 entrées masquées.
 *
 * Le dernier est le plus coûteux : **sans taux de TVA, `PricingService` facture à
 * 0 %** — le trou exact qu'ONB-02 a passé la nuit à fermer. Une page de réglage
 * fiscal atteignable seulement en tapant son URL n'est pas un manque de confort.
 *
 * Une fonction sans porte n'est pas livrée.
 *
 * ═══ POURQUOI DEPUIS LE STUDIO, ET PAS DEPUIS LE MENU ═══
 *
 * Le dé-masquage du menu appartient à ONB-05 (gate G-CACHE ; `v1-hidden-modules.js`
 * et `settings/MenuComponent.vue` sont sa voie, §2.2). On ne l'y touche pas.
 *
 * Le Studio, lui, est dans la voie catalogue — et c'est l'écran où le commerçant se
 * trouve quand il pense au prix d'un produit ou à sa carte. Le motif existe déjà dans
 * le dépôt : `VIRTUAL_CHILDREN_BY_URL` réinjecte `item-attributes` sous Catalogue
 * alors qu'il est masqué côté Réglages.
 */
describe('le Studio ouvre les portes dont le commerçant a besoin', () => {
    const racine = process.cwd();
    const lire = (p) => fs.readFileSync(path.join(racine, p), 'utf8');

    const STUDIO = 'resources/js/components/admin/items/CatalogStudioComponent.vue';

    /** Chaque porte : sa route, et ce qu'elle coûte de ne pas l'avoir. */
    const PORTES = [
        ['admin.settings.tax', "la page TVA — sans taux, `PricingService` facture à 0 %"],
        ['admin.items.import', "l'import de carte par photo"],
        ['admin.items.assistant', "l'assistant de missions locales"],
    ];

    it('le relevé mord — sinon ce banc serait vert en ne lisant rien', () => {
        const s = lire(STUDIO);

        expect(s.length, 'Le Studio est vide ou introuvable.').toBeGreaterThan(10000);
        // Témoin : un bouton présent de longue date.
        expect(s).toContain('catalog-studio-add-product');
    });

    it.each(PORTES)('la porte vers %s existe', (route, pourquoi) => {
        const s = lire(STUDIO);

        expect(
            s,
            `Aucun lien vers \`${route}\` : ${pourquoi}.\n`
            + "Cet écran ne serait atteignable qu'en tapant son URL — une fonction sans\n"
            + 'porte n\'est pas livrée.',
        ).toContain(`{ name: '${route}' }`);
    });

    it('chaque porte est gardée par la permission de sa route', () => {
        const s = lire(STUDIO);

        // La route TVA exige `settings` (`settingRoutes.js:406`). Le Studio calcule
        // déjà `canCreateCategory = permissionChecker("settings")` : on réutilise, au
        // lieu d'afficher un lien qui mènerait à un refus.
        expect(s).toMatch(/v-if="canCreateCategory"\s+:to="\{ name: 'admin\.settings\.tax' \}"/);

        // Les deux écrans d'assistance touchent le catalogue : `items` suffit, et
        // c'est ce que porte `canCreateItem`.
        expect(s).toMatch(/v-if="canCreateItem"\s+:to="\{ name: 'admin\.items\.assistant' \}"/);
        expect(s).toMatch(/v-if="canCreateItem"\s+:to="\{ name: 'admin\.items\.import' \}"/);
    });

    it('les icônes de ces portes existent dans la fonte', () => {
        const s = lire(STUDIO);
        const css = lire('public/themes/default/fonts/lab/lab.css');

        const utilisees = [...s.matchAll(/class="lab (lab-[a-z0-9-]+)"/g)].map((m) => m[1]);

        expect(utilisees.length, 'Aucune icône trouvée dans le Studio.').toBeGreaterThan(2);

        const fantomes = [...new Set(utilisees)].filter((c) => !css.includes('.' + c));

        expect(
            fantomes,
            `Ces classes n'existent pas dans la fonte : le bouton afficherait un carré\n`
            + `vide. ${fantomes.join(', ')}`,
        ).toEqual([]);
    });

    it("l'écran des rôles a enfin une porte, sur l'écran de l'équipe", () => {
        // [ONB-06 2026-08-28] `v1-hidden-modules.js` masque `settings.role`, ce qui
        // condamne son unique entrée externe (le menu Réglages). Un balayage par
        // CHEMIN rendait zéro résultat : la route, l'écran et la permission
        // existent, et il fallait connaître l'URL.
        //
        // Le commerçant qui recrute ne pouvait donc ni lire ni ajuster ce qu'un
        // « Caissier » a le droit de faire — alors qu'il vient d'attribuer ce rôle
        // à quelqu'un, sur cet écran même.
        const s = lire('resources/js/components/admin/employees/EmployeeListComponent.vue');

        expect(
            s,
            "L'écran de l'équipe n'ouvre pas les rôles : le commerçant qui recrute\n"
            + "ne peut pas voir ce que le rôle qu'il attribue autorise.",
        ).toContain("data-testid=\"employees-roles\"");

        expect(s).toContain("{ name: 'admin.settings.role' }");

        // ET LE POINT DÉLICAT : la permission du LIEN doit être celle de la ROUTE.
        // `settingRoutes.js:467` porte `permissionUrl: "settings"`. Garder le lien
        // par une autre permission produirait soit un lien mort — visible, puis
        // refusé par la garde — soit un écran invisible à qui y a pourtant droit.
        const bloc = s.slice(
            s.indexOf('employees-roles') - 400,
            s.indexOf('employees-roles') + 200,
        );

        expect(
            bloc,
            "Le lien vers les rôles n'est pas gardé par `settings`, la permission que\n"
            + 'porte la route. Un lien et sa cible gardés différemment font toujours\n'
            + "une porte qui ment — dans un sens ou dans l'autre.",
        ).toContain("permissionChecker('settings')");
    });
});
