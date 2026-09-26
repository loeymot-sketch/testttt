import { describe, it, expect } from 'vitest';
import fs from 'fs';
import path from 'path';

/**
 * [ONB-04 2026-08-28] L'assistant de missions locales : atteignable, et prudent.
 *
 * ═══ POURQUOI CE BANC LIT LES FICHIERS DE PRODUCTION ═══
 *
 * Deux écrans de cette voie ont été livrés SANS AUCUN LIEN :
 * `grep -rn "admin.items.import" resources/js/` hors routeur rendait **zéro**
 * résultat. L'écran d'import de carte, livré le 27, n'était atteignable qu'en
 * tapant son URL — exactement ce que l'audit ONB-05 reproche à la page TVA.
 *
 * Une fonction sans porte n'est pas livrée. Ce banc ferme cette classe de défaut
 * pour les deux écrans que cette voie possède.
 *
 * ⚠️ MESURE ÉCARTÉE, ET POURQUOI. J'ai d'abord voulu un relevé général « aucune
 * route admin nommée n'est orpheline ». Il accusait `admin.catalog.hub` et
 * `admin.ingredients.list` — à tort : `BackendMenuComponent.vue` lie par `url`
 * (`Object.freeze({ url: 'ingredients', … })`), pas par nom de route. Une sentinelle
 * bâtie sur cette mesure aurait accusé des écrans sains, et on aurait fini par
 * l'ignorer. Le relevé général est renvoyé à ONB-05, qui possède le menu.
 */
describe("l'assistant de missions locales est atteignable et propose avant d'écrire", () => {
    const racine = process.cwd();
    const lire = (p) => fs.readFileSync(path.join(racine, p), 'utf8');

    const STUDIO = 'resources/js/components/admin/items/CatalogStudioComponent.vue';
    const ASSISTANT = 'resources/js/components/admin/assistant/MissionLocaleComponent.vue';
    const ROUTES = 'resources/js/router/modules/itemRoutes.js';

    it('le relevé mord — sinon ce banc serait vert en ne lisant rien', () => {
        expect(lire(ASSISTANT).length, "Le composant est vide ou introuvable.").toBeGreaterThan(2000);
        expect(lire(STUDIO)).toContain('catalog-studio-add-product');
    });

    it('les deux écrans ont une porte depuis le Studio', () => {
        const studio = lire(STUDIO);

        expect(
            studio,
            "L'assistant n'est atteignable qu'en tapant son URL.",
        ).toContain("{ name: 'admin.items.assistant' }");

        expect(
            studio,
            "L'écran d'import de carte est resté sans lien depuis sa livraison du 27.",
        ).toContain("{ name: 'admin.items.import' }");
    });

    it('les deux routes existent réellement', () => {
        const routes = lire(ROUTES);

        expect(routes).toContain("name: 'admin.items.assistant'");
        expect(routes).toContain("name: 'admin.items.import'");
        expect(routes).toContain('MissionLocaleComponent');
    });

    it("l'écran demande le plan avant d'écrire, et n'écrit que sur confirmation", () => {
        const source = lire(ASSISTANT);

        // Deux temps, jamais un : une mission touche cinquante produits d'un coup.
        expect(source).toContain('admin/assistant/mission/lecture');
        expect(source).toContain('admin/assistant/mission/application');

        // Le préfixe `/api` est déjà posé par `axios-setup.js:75`. Le redoubler donne
        // `/api/api/…` et un 404 SILENCIEUX — le défaut qui avait rendu l'écran
        // d'import entièrement mort, sans un message.
        expect(
            source,
            "L'URL redouble le préfixe `/api` déjà posé par la configuration axios.",
        ).not.toMatch(/["']\/api\/admin\/assistant/);

        // L'application ne renvoie PAS le plan : le serveur le refait depuis la
        // phrase. Un diff modifié en route ferait sinon écrire n'importe quoi sous
        // couvert d'une confirmation humaine.
        expect(
            source,
            "L'écran renvoie le plan au serveur : il doit renvoyer la PHRASE.",
        ).toMatch(/phrase:\s*message\.phrase/);

        expect(source).toContain('confirmation: true');
    });

    it('le plan affiche ce qui est écarté, pas seulement ce qui change', () => {
        const source = lire(ASSISTANT);

        // Un plan qui cache ses exclusions ment par omission : le commerçant croirait
        // avoir couvert toute sa catégorie.
        expect(source).toContain('assistant-ecartes');
        expect(source).toMatch(/message\.plan\.ecartes/);
    });

    it('les libellés existent dans les deux langues', () => {
        const cles = [
            'assistant_mission_title', 'assistant_mission_subtitle', 'assistant_mission_exemple',
            'assistant_mission_vide', 'assistant_envoyer', 'assistant_confirmer',
            'assistant_avant', 'assistant_apres', 'assistant_ecartes', 'assistant_erreur',
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
