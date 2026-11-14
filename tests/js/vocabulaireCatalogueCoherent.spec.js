import { describe, it, expect } from 'vitest';
import fs from 'fs';
import path from 'path';

/**
 * [ONB-11 2026-08-28] Trois concepts, trois noms, et chacun sur le bon.
 *
 * CE QUE LE COMMERÇANT VOYAIT, sur un seul écran :
 *
 *   onglet « Supplément »  → modale « Suppléments »
 *                          → bloc d'aide titré « Addon » (mot anglais)
 *                          → corps de l'aide : « une consigne de préparation GRATUITE
 *                            (Sans oignons, Bien cuit) »
 *                          → champ : « Article supplémentaire », qui lui demande de
 *                            choisir un PRODUIT DU CATALOGUE
 *
 *   onglet « Extra »       → modale « Extras » (mot anglais)
 *                          → aide : « un supplément PAYANT à la carte (Cheddar +0,50 €) »
 *
 * Trois choses différentes annoncées sur la même modale — et le mot français qui
 * signifie « supplément payant » collé au concept qui n'en est pas un. Le commerçant
 * qui veut facturer 0,50 € de cheddar ouvre logiquement « Suppléments », et tombe sur
 * un formulaire qui lui demande autre chose, sous une aide qui décrit un troisième
 * sujet.
 *
 * CE QU'EST RÉELLEMENT UN ADDON, vérifié dans le schéma : `item_addons.addon_item_id`
 * est une clé étrangère vers `items`
 * (`2022_11_17_120627_create_item_addons_table.php:19`). Un addon n'a pas de prix
 * propre — c'est un AUTRE PRODUIT de la carte proposé avec celui-ci, et son prix est
 * celui du produit référencé. Le semeur en crée trois, tous payants : « Menu (Frites
 * + Boisson) 3 € », « Frites Seules 2 € », « Boisson Seule 2 € ». Le corps de l'aide
 * décrivait donc quelque chose qui **n'existe pas dans ce système**.
 *
 * Ce banc ne juge pas du style : il verrouille l'attribution. « Supplément » doit
 * désigner le concept PAYANT, et rien d'autre.
 */
describe('ONB-11 · vocabulaire du catalogue', () => {
    const fr = JSON.parse(
        fs.readFileSync(path.join(process.cwd(), 'resources/js/languages/fr.json'), 'utf8'),
    );

    it('« Supplément » désigne le concept PAYANT (extra), pas le produit associé', () => {
        // C'est l'inversion qui coûtait : le mot le plus clair du métier était posé
        // sur le mauvais formulaire.
        expect(fr.label.extra).toContain('Supplément');
        expect(fr.menu.extras).toContain('Supplément');

        expect(
            fr.label.addon,
            "« Supplément » est reparti sur l'addon, qui n'est pas un supplément payant "
            + "mais un autre produit de la carte.",
        ).not.toContain('Supplément');

        expect(fr.menu.addons).not.toContain('Supplément');
    });

    it("l'addon se nomme par ce qu'il est : un produit de la carte", () => {
        expect(fr.label.addon).toBe('Produit associé');
        expect(fr.menu.addons).toBe('Produits associés');

        // Le champ demande de choisir un produit existant : le libellé doit le dire.
        expect(fr.label.addon_item).toBe('Produit à proposer');
    });

    it("aucun des trois onglets ne porte un mot anglais", () => {
        for (const cle of ['addon', 'extra', 'variation']) {
            expect(
                ['Addon', 'Extra', 'Variation', 'Addons', 'Extras', 'Variations'],
                `label.${cle} vaut « ${fr.label[cle]} », qui est le mot anglais`,
            ).not.toContain(fr.label[cle]);
        }
    });

    it("l'aide de l'addon ne décrit plus une consigne gratuite qui n'existe pas", () => {
        const aide = fr.admin.help.addon;

        expect(aide.title).toBe('Produit associé');

        expect(
            aide.body,
            "L'aide décrivait « une consigne de préparation gratuite (Sans oignons) ». "
            + "Ce formulaire n'existe pas : `item_addons.addon_item_id` est une clé "
            + 'étrangère vers `items`. Le commerçant cherchait une fonction absente.',
        ).not.toMatch(/gratuit|Sans oignons/i);

        // Et elle doit dire d'où vient le prix — c'est la question que le commerçant
        // se pose immédiatement.
        expect(aide.body).toMatch(/prix/i);
    });

    it('les trois blocs d\'aide portent un titre français', () => {
        for (const concept of ['addon', 'extra', 'variation']) {
            const titre = fr.admin.help[concept].title;

            expect(
                ['Addon', 'Extra', 'Variation'],
                `admin.help.${concept}.title vaut « ${titre} », qui est le mot anglais`,
            ).not.toContain(titre);
        }
    });

    it("les deux modales lisent bien ces clés-là", () => {
        // Contrôle de câblage : si une modale changeait de clé, ce banc garderait
        // des libellés que plus personne n'affiche.
        const addon = fs.readFileSync(
            path.join(process.cwd(), 'resources/js/components/admin/items/addon/ItemAddonCreateComponent.vue'),
            'utf8',
        );
        const extra = fs.readFileSync(
            path.join(process.cwd(), 'resources/js/components/admin/items/extra/ItemExtraCreateComponent.vue'),
            'utf8',
        );

        expect(addon).toContain('$t("menu.addons")');
        expect(addon).toContain('concept="addon"');
        expect(extra).toContain('$t("menu.extras")');
        expect(extra).toContain('concept="extra"');
    });
});
