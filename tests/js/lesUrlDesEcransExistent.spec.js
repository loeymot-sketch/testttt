import { describe, it, expect } from 'vitest';
import fs from 'fs';
import path from 'path';
import { execFileSync } from 'child_process';

/**
 * [ONB-04 2026-08-28] Une URL appelée par un écran doit exister côté serveur.
 *
 * MON ÉCRAN D'IMPORT DE CARTE ÉTAIT ENTIÈREMENT MORT et je ne l'ai pas vu.
 *
 * `axios.defaults.baseURL` vaut déjà `<hôte>/api` (`shared/axios-setup.js:75`).
 * J'avais écrit `axios.post("/api/admin/assistant/menu/lecture")` — axios combine
 * donc en `/api/api/admin/…` et le serveur répond 404. Les trois appels de l'écran
 * étaient dans ce cas. Tous les autres composants du dépôt écrivent `"admin/…"`
 * sans préfixe ; j'étais le seul à le poser.
 *
 * Et la route des taxes n'existe pas sous `admin/taxes` : elle vit sous
 * `admin/setting/tax`. Le `catch` vidait la liste EN SILENCE, le menu déroulant
 * TVA restait vide, et le bouton « Créer ces produits » restait définitivement
 * grisé sans un mot d'explication.
 *
 * POURQUOI RIEN NE L'A VU :
 *   · les bancs PHPUnit tapent les routes DIRECTEMENT, sans passer par axios ;
 *   · ma capture Playwright ne montrait que l'étape 1 — son propre commentaire
 *     disait « aucun formulaire n'est soumis ».
 * J'avais vérifié que l'écran S'AFFICHE, pas qu'il MARCHE.
 *
 * ⚠️ PREMIÈRE VERSION DE CE BANC : elle analysait `routes/api.php` à la main et ne
 * retrouvait ni `admin` ni `assistant` — elle accusait 40 écrans sains. On lit
 * désormais les routes RÉELLES via `php artisan route:list`, qui fait autorité.
 * Deviner la forme d'un fichier source était exactement l'erreur que ce banc
 * dénonce ailleurs.
 */
describe('les URL appelées par les écrans existent', () => {
    const racine = process.cwd();

    /** Le préfixe déjà posé par la configuration axios partagée. */
    const PREFIXE_AXIOS = '/api';

    /** Les URI réellement servies, lues depuis Laravel lui-même. */
    const uriServies = () => {
        const brut = execFileSync('php', ['artisan', 'route:list', '--json'], {
            cwd: racine,
            encoding: 'utf8',
            maxBuffer: 32 * 1024 * 1024,
        });

        return JSON.parse(brut).map((r) => String(r.uri));
    };

    /** Toutes les URL littérales passées à axios dans les composants. */
    const urlsAppelees = () => {
        const trouvees = [];
        const motif = /axios\s*\.?\s*(?:get|post|put|patch|delete)\(\s*["']([^"'`]+)["']/g;

        const parcourir = (dossier) => {
            for (const entree of fs.readdirSync(dossier, { withFileTypes: true })) {
                const complet = path.join(dossier, entree.name);

                if (entree.isDirectory()) {
                    parcourir(complet);
                    continue;
                }
                if (!/\.(vue|js)$/.test(entree.name)) {
                    continue;
                }

                const source = fs.readFileSync(complet, 'utf8');
                motif.lastIndex = 0;
                let m;

                while ((m = motif.exec(source)) !== null) {
                    trouvees.push({ url: m[1], fichier: path.relative(racine, complet) });
                }
            }
        };

        parcourir(path.join(racine, 'resources/js'));

        return trouvees;
    };

    /** `admin/setting/tax` → `api/admin/setting/tax`, sans paramètre ni requête. */
    const normaliser = (url) =>
        ('api/' + url.split('?')[0].replace(/^\/+/, '')).replace(/\/+$/, '');

    /** Une URI servie peut porter des `{param}` : on compare segment à segment. */
    const estServie = (candidate, servies) =>
        servies.some((uri) => {
            const a = uri.split('/');
            const b = candidate.split('/');

            if (a.length !== b.length) {
                return false;
            }

            return a.every((seg, i) => seg.startsWith('{') || seg === b[i]);
        });

    it("l'extraction mord — sinon ce banc serait vert en ne mesurant rien", () => {
        const appels = urlsAppelees();
        const servies = uriServies();

        expect(appels.length, 'Aucun appel axios trouvé : la lecture est cassée.').toBeGreaterThan(10);
        expect(servies.length, "route:list n'a rien rendu.").toBeGreaterThan(100);

        // Deux témoins : une route que je viens d'ajouter, une qui préexiste.
        expect(servies).toContain('api/admin/assistant/menu/lecture');
        expect(servies).toContain('api/admin/setting/tax');
    });

    it('aucune URL ne redouble le préfixe /api déjà posé par axios', () => {
        // LE DÉFAUT EXACT. `baseURL` vaut déjà `<hôte>/api` : une URL commençant par
        // `/api/` produit `/api/api/…` et un 404 silencieux.
        const fautives = urlsAppelees()
            .filter(({ url }) => url.startsWith(PREFIXE_AXIOS + '/'))
            .map(({ url, fichier }) => `${fichier} → ${url}`);

        expect(
            fautives,
            "Ces appels redoublent le préfixe `/api` que `axios-setup.js:75` pose déjà\n"
            + "sur `baseURL` : axios les combine en `/api/api/…` et le serveur répond 404.\n"
            + 'Écrivez `"admin/…"` sans préfixe, comme le reste du dépôt.\n'
            + fautives.join('\n'),
        ).toEqual([]);
    });

    it("l'écran d'import de carte appelle des routes qui existent vraiment", () => {
        // Contrôle nommé sur le cas qui a révélé la classe entière. On ne juge que ce
        // fichier : élargir à tout le dépôt ferait de ce banc un chantier, alors que
        // son rôle est d'empêcher que MON défaut revienne.
        const servies = uriServies();

        const siennes = urlsAppelees().filter(
            ({ fichier }) => fichier.includes('assistant/MenuImportComponent.vue'),
        );

        expect(siennes.length, 'Les appels de MenuImportComponent sont introuvables.').toBe(3);

        const mortes = siennes
            .filter(({ url }) => !/^https?:/.test(url) && !estServie(normaliser(url), servies))
            .map(({ url }) => `${url}  →  ${normaliser(url)} n'est servie par aucune route`);

        expect(
            mortes,
            "L'écran appelle une route qui n'existe pas. L'échec est SILENCIEUX : un\n"
            + "`catch` vide la liste, le bouton reste grisé, et rien ne l'explique.\n"
            + mortes.join('\n'),
        ).toEqual([]);
    });
});
