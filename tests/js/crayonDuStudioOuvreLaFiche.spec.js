import { describe, it, expect } from 'vitest';
import fs from 'fs';
import path from 'path';

/**
 * [ONB-02 2026-08-28] Le crayon « modifier » du Studio n'ouvrait aucune fiche.
 *
 * `CatalogStudioComponent::editCategory()` route vers l'écran Réglages › Catégories
 * avec `?edit=<id>`, et son commentaire affirmait ouvrir la modale « via le state
 * Vuex partagé ».
 *
 * C'était FAUX. `ItemCateogryListComponent::mounted()` n'appelait que `this.list()` :
 * le paramètre n'était lu par personne, et la seule ouverture possible restait le
 * clic dans le tableau. Le commerçant cliquait sur « modifier » à côté de « Tacos »,
 * se retrouvait éjecté sur un autre écran, et rien ne s'ouvrait — il devait
 * retrouver sa catégorie à la main dans une liste paginée.
 *
 * Une action nommée « modifier » qui change de page sans rien modifier.
 *
 * TROISIÈME COMMENTAIRE FAUX de cette session, après celui de `simpleList` (« l'admin
 * utilise list(), pas simpleList ») et celui du gabarit PDF. Un commentaire qui
 * décrit un comportement que le code n'a pas est plus dangereux qu'une absence de
 * commentaire : il ferme la question pour le prochain lecteur.
 */
describe('ONB-02 · le crayon du Studio ouvre bien la fiche', () => {
    const lire = (chemin) =>
        fs.readFileSync(path.join(process.cwd(), chemin), 'utf8');

    const studio = lire('resources/js/components/admin/items/CatalogStudioComponent.vue');
    const cible = lire(
        'resources/js/components/admin/settings/ItemCategory/ItemCateogryListComponent.vue',
    );

    it('le Studio route toujours avec le paramètre attendu', () => {
        expect(studio).toContain('admin.settings.itemCategory.list');
        expect(studio).toContain('query: { edit: String(category.id) }');
    });

    it("l'écran cible LIT ce paramètre", () => {
        // C'est le maillon qui manquait, et le seul qui compte.
        expect(
            cible,
            "L'écran cible ne lit pas `?edit` : le crayon du Studio éjecte le "
            + 'commerçant sur une liste paginée sans rien ouvrir.',
        ).toContain('ouvrirDepuisLUrl');

        expect(cible).toMatch(/\$route\?\.query\?\.edit/);
    });

    it("l'ouverture est appelée au montage", () => {
        // Déclarer la méthode sans l'appeler serait exactement le même défaut,
        // déplacé d'un cran.
        expect(cible).toMatch(/mounted\(\)\s*\{[\s\S]*?this\.ouvrirDepuisLUrl\(\)/);
    });

    it("elle attend la liste avant d'ouvrir", () => {
        // La modale a besoin de l'objet COMPLET (`wizard_template`, `has_menu`...),
        // pas seulement de l'identifiant : ouvrir avant le chargement donnerait un
        // formulaire vide.
        expect(cible).toContain('$watch');
        expect(cible).toMatch(/this\.edit\(trouvee\)/);
    });

    it('le paramètre est retiré après ouverture', () => {
        // Sans ça, rafraîchir la page rouvrirait la modale indéfiniment.
        expect(cible).toMatch(/\$router\.replace\(\{ query: \{\} \}\)/);
    });

    it("le commentaire du Studio ne décrit plus un mécanisme inexistant", () => {
        expect(
            studio.includes('ouvrant la modale d\'édition via le state Vuex partagé'),
            "Le commentaire affirmait un mécanisme qui n'existait pas. Un commentaire "
            + "faux ferme la question pour le prochain lecteur — c'est ce qui a permis "
            + 'au défaut de survivre.',
        ).toBe(false);
    });
});
