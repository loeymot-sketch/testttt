import { describe, it, expect } from 'vitest';
import fs from 'fs';
import path from 'path';

/**
 * [ONB 2026-08-28] Le formulaire produit envoie bien les allergènes.
 *
 * ⚠️ CE BANC LIT LES FICHIERS DE PRODUCTION, délibérément.
 *
 * Le banc voisin `itemChannelsField.spec.js` réimplémente `hydrateFormChannels()`
 * et `appendChannelsToFormData()` DANS LE FICHIER DE TEST, puis les vérifie. Il est
 * vert quoi qu'il arrive au composant : son propre docblock l'admet à demi-mot
 * (« Mounting those requires Vuex/Router/API mocks beyond the scope of a contract
 * spec »). Un banc qui vérifie une copie du code ne protège pas le code.
 *
 * On ne reproduit pas ce motif. On lit `ItemCreateComponent.vue` et
 * `ItemListComponent.vue` tels qu'ils sont livrés.
 *
 * CE QUI EST EN JEU : toute la chaîne allergènes existait déjà — colonne,
 * validation, observateur, pivot, affichage caisse et cuisine, et jusqu'au FILTRE
 * ALLERGÈNES DE LA BORNE. Il ne manquait que l'écran par lequel un humain entre la
 * vérité. Tant qu'il manquait, la borne présentait à un client allergique des
 * correspondances que le seed qualifie lui-même de « guessed mappings ».
 */
describe('le formulaire produit envoie les allergènes et le poste de cuisine', () => {
    const racine = process.cwd();
    const lire = (p) => fs.readFileSync(path.join(racine, p), 'utf8');

    const FORMULAIRE = 'resources/js/components/admin/items/ItemCreateComponent.vue';
    const LISTE = 'resources/js/components/admin/items/ItemListComponent.vue';

    it('le relevé mord — sinon ce banc serait vert en ne lisant rien', () => {
        const source = lire(FORMULAIRE);

        expect(source.length, 'Le composant est vide ou introuvable.').toBeGreaterThan(5000);
        // Témoin : un champ qu'on sait envoyé depuis longtemps.
        expect(source).toContain("fd.append('item_type'");
    });

    it("l'écran charge le référentiel légal sans redoubler le préfixe /api", () => {
        const source = lire(FORMULAIRE);

        expect(
            source,
            'Sans cet appel, la liste de cases est vide et le champ est inutilisable.',
        ).toContain('axios.get("admin/item/allergens")');

        // Le piège qui a rendu mon écran d'import entièrement mort le même jour :
        // `baseURL` vaut déjà `<hôte>/api`, donc une URL préfixée donne `/api/api/…`.
        expect(
            source,
            "L'URL redouble le préfixe `/api` que `axios-setup.js:75` pose déjà.",
        ).not.toContain('axios.get("/api/admin/item/allergens")');
    });

    it('les trois champs partent réellement dans le formulaire envoyé', () => {
        const source = lire(FORMULAIRE);

        expect(
            source,
            "`allergen_flags[]` n'est pas envoyé : les cases seraient décoratives.",
        ).toContain("fd.append('allergen_flags[]', code)");

        expect(
            source,
            "`kds_station` n'est pas envoyé : tous les produits resteraient sur le poste\n"
            + 'par défaut, alors que le KDS sait router.',
        ).toContain("fd.append('kds_station'");

        // LE POINT SUBTIL. Décocher la DERNIÈRE case n'ajoute aucune entrée
        // `allergen_flags[]` — indiscernable, côté serveur, d'un formulaire qui ignore
        // le champ (`validated()` ne contient que les clés présentes). Sans ce témoin,
        // un commerçant ne pourrait JAMAIS retirer un allergène déclaré par erreur.
        // Or une déclaration fausse est pire qu'une déclaration absente : elle écarte
        // un client d'un plat qu'il pouvait manger, et fait douter des autres.
        expect(
            source,
            "Le témoin `allergen_flags_defini` est absent : retirer le dernier allergène\n"
            + "deviendrait impossible depuis l'écran.",
        ).toContain("fd.append('allergen_flags_defini', '1')");
    });

    it("le tiroir d'édition hydrate les deux champs depuis l'API", () => {
        const source = lire(LISTE);

        // Sans hydratation, le formulaire afficherait des cases vides et les
        // renverrait telles quelles : corriger une faute dans le NOM d'un produit
        // effacerait ses allergènes. C'est le défaut exact corrigé le même jour sur
        // `siret`, sur les réglages de borne et sur `channels` — trois fois la même
        // mécanique, et la quatrième aurait porté sur une information légalement due.
        expect(
            source,
            "`allergen_flags` n'est pas hydraté : le formulaire l'effacerait au premier\n"
            + 'enregistrement, sans que rien ne le signale.',
        ).toMatch(/allergen_flags:\s*Array\.isArray\(item\.allergen_flags\)/);

        expect(
            source,
            "`kds_station` n'est pas hydraté : le rouvrir le remettrait à « aucun ».",
        ).toMatch(/kds_station:\s*item\.kds_station\s*\|\|\s*'none'/);
    });

    it('les huit libellés existent dans les deux langues', () => {
        const cles = [
            'allergens_title', 'allergens_help',
            'kds_station', 'kds_station_help',
            'kds_station_none', 'kds_station_bar', 'kds_station_hot', 'kds_station_cold',
        ];

        for (const fichier of ['resources/js/languages/fr.json', 'resources/js/languages/en.json']) {
            const label = JSON.parse(lire(fichier)).label;

            for (const cle of cles) {
                expect(
                    typeof label[cle],
                    `${fichier} n'a pas \`label.${cle}\` : l'écran afficherait la clé brute.`,
                ).toBe('string');
            }
        }
    });
});
