import { describe, it, expect } from 'vitest';
import fs from 'fs';
import path from 'path';

/**
 * [ONB 2026-08-28] Aucun bouton ne doit se rendre SANS son icône.
 *
 * TROUVÉ EN REGARDANT UNE CAPTURE, pas en lisant du code — et c'est le point.
 *
 * Le Studio catalogue affichait, à côté de chaque catégorie, un crayon orange
 * correct et, juste à sa droite, une **pastille rose vide**. Le bouton existait, il
 * était cliquable, il supprimait bien — mais il ne montrait rien. Sa classe était
 * `lab-delete-line`, et la fonte d'icônes ne définit que `lab-delete`,
 * `lab-delete-bold` et `lab-trash-line-2`.
 *
 * Une classe d'icône inexistante ne LÈVE RIEN : pas d'erreur console, pas d'échec
 * de test, pas de régression fonctionnelle. Le pictogramme est simplement absent.
 * C'est la définition même du défaut qu'aucune suite ne peut voir.
 *
 * Mesure au moment de l'écriture : **22 classes utilisées sans exister**, dans 11
 * fichiers, sur des écrans que le commerçant utilise tous les jours — dupliquer un
 * produit, exporter le catalogue, télécharger un rapport Z, rafraîchir la vue,
 * ajouter une imprimante. Toutes préexistaient à cette session.
 */
describe('icônes · aucune classe fantôme', () => {
    const racine = process.cwd();

    /** Les classes que la fonte définit réellement. */
    const definies = () => {
        const css = fs.readFileSync(
            path.join(racine, 'public/themes/default/fonts/lab/lab.css'),
            'utf8',
        );

        return new Set([...css.matchAll(/\.(lab-[a-z0-9-]+):before/g)].map((m) => m[1]));
    };

    /** Les classes que les composants utilisent, avec leur fichier. */
    const utilisees = () => {
        const trouvees = new Map();
        const motif = /class="lab (lab-[a-z0-9-]+)"/g;

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
                    if (!trouvees.has(m[1])) {
                        trouvees.set(m[1], new Set());
                    }
                    trouvees.get(m[1]).add(path.relative(racine, complet));
                }
            }
        };

        parcourir(path.join(racine, 'resources/js'));

        return trouvees;
    };

    it("l'extraction mord — sinon ce banc serait vert en ne mesurant rien", () => {
        const d = definies();
        const u = utilisees();

        expect(d.size, "La fonte d'icônes n'a pas pu être lue.").toBeGreaterThan(100);
        expect(u.size, 'Aucune icône trouvée dans les composants.').toBeGreaterThan(20);

        // Deux témoins connus.
        expect(d.has('lab-trash-line-2')).toBe(true);
        expect(d.has('lab-delete-line')).toBe(false);
    });

    it('chaque icône utilisée existe dans la fonte', () => {
        const d = definies();
        const u = utilisees();

        const fantomes = [...u.keys()].filter((c) => !d.has(c)).sort();

        expect(
            fantomes,
            "Ces classes n'existent pas dans public/themes/default/fonts/lab/lab.css :\n"
            + "le bouton se rend VIDE, sans pictogramme, et rien ne le signale —\n"
            + "ni erreur console, ni échec de test.\n"
            + fantomes
                .map((c) => `  ${c}  →  ${[...u.get(c)].join(', ')}`)
                .join('\n'),
        ).toEqual([]);
    });

    it("le Studio n'utilise plus la classe de suppression inexistante", () => {
        // Contrôle nommé sur le cas qui a révélé toute la classe.
        const studio = fs.readFileSync(
            path.join(racine, 'resources/js/components/admin/items/CatalogStudioComponent.vue'),
            'utf8',
        );

        expect(studio).not.toContain('lab-delete-line');
        expect(studio).toContain('lab-trash-line-2');
    });
});
