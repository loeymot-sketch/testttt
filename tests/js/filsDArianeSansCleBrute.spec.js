import { describe, it, expect } from 'vitest';
import fs from 'fs';
import path from 'path';

/**
 * [ONB 2026-08-28] Aucun fil d'Ariane ne doit afficher une clé de traduction brute.
 *
 * TROUVÉ EN REGARDANT UN ÉCRAN, pas en lisant du code.
 *
 * `BreadcrumbComponent.vue:12` rend `$t('menu.' + val.meta.breadcrumb)`. La clé est
 * donc CONSTRUITE à l'exécution — aucune sentinelle qui balaie les `$t('...')`
 * littéraux ne peut la voir, y compris celle que j'ai écrite cette nuit
 * (`aucuneCleAfficheeNeManqueEnFrancais`). C'est précisément par ce trou que ma
 * propre route est passée : j'avais posé `breadcrumb: 'menu_import_title'` avec la
 * traduction sous `label.`, et l'écran affichait « Menu.Menu_import_title » en
 * toutes lettres, en haut de la page.
 *
 * Mesure au moment de l'écriture : **10 des 81** `meta.breadcrumb` n'avaient pas de
 * clé `menu.*` en français. Neuf existaient AVANT moi, et sur des chemins que le
 * commerçant emprunte tous les jours — dont `menu.create`, l'écran de création de
 * produit, et `menu.order_details`, présent dans six fichiers de routes.
 *
 * Ce banc couvre la classe entière : il lit les routes, construit la clé comme le
 * composant le fait, et vérifie qu'elle existe.
 */
describe('fils d\'Ariane · aucune clé brute', () => {
    const racine = process.cwd();

    const langue = (code) =>
        JSON.parse(
            fs.readFileSync(path.join(racine, `resources/js/languages/${code}.json`), 'utf8'),
        );

    /** Tous les `meta.breadcrumb` déclarés par les modules de routes. */
    const breadcrumbsDeclares = () => {
        const trouves = new Map();
        const motif = /breadcrumb:\s*['"]([^'"]+)['"]/g;

        const parcourir = (dossier) => {
            for (const entree of fs.readdirSync(dossier, { withFileTypes: true })) {
                const complet = path.join(dossier, entree.name);

                if (entree.isDirectory()) {
                    parcourir(complet);
                    continue;
                }

                if (!entree.name.endsWith('.js')) {
                    continue;
                }

                const source = fs.readFileSync(complet, 'utf8');
                motif.lastIndex = 0;
                let m;

                while ((m = motif.exec(source)) !== null) {
                    const cle = m[1].trim();

                    if (cle === '') {
                        continue; // `breadcrumb: ''` = pas de segment, volontaire
                    }

                    if (!trouves.has(cle)) {
                        trouves.set(cle, new Set());
                    }
                    trouves.get(cle).add(entree.name);
                }
            }
        };

        parcourir(path.join(racine, 'resources/js/router'));

        return trouves;
    };

    it("l'extraction mord — sinon ce banc serait vert en ne mesurant rien", () => {
        const declares = breadcrumbsDeclares();

        expect(
            declares.size,
            'Aucun meta.breadcrumb trouvé : la lecture des routes est cassée et ce '
            + 'banc ne garde plus rien.',
        ).toBeGreaterThan(50);

        // Deux témoins dont on sait qu'ils existent.
        expect(declares.has('items')).toBe(true);
        expect(declares.has('menu_import_title')).toBe(true);
    });

    it('chaque breadcrumb a sa clé menu.* en FRANÇAIS', () => {
        const fr = langue('fr').menu || {};
        const declares = breadcrumbsDeclares();

        const brutes = [...declares.keys()].filter((cle) => !(cle in fr)).sort();

        expect(
            brutes,
            "Ces breadcrumbs afficheront la CLÉ BRUTE en haut de la page — le composant\n"
            + "rend `$t('menu.' + meta.breadcrumb)` et la clé n'existe pas :\n"
            + brutes.map((c) => `  menu.${c}  (${[...declares.get(c)].join(', ')})`).join('\n'),
        ).toEqual([]);
    });

    it('chaque breadcrumb a sa clé menu.* en ANGLAIS', () => {
        const en = langue('en').menu || {};
        const declares = breadcrumbsDeclares();

        const brutes = [...declares.keys()].filter((cle) => !(cle in en)).sort();

        expect(brutes, 'Manquantes en anglais : ' + brutes.join(', ')).toEqual([]);
    });

    it("le composant construit bien la clé de cette façon", () => {
        // Contrôle de câblage : si le composant changeait de préfixe, ce banc
        // garderait un espace de noms que plus personne n'utilise.
        const source = fs.readFileSync(
            path.join(racine, 'resources/js/components/admin/components/BreadcrumbComponent.vue'),
            'utf8',
        );

        expect(source).toContain("$t('menu.'+val.meta.breadcrumb)");
    });
});
