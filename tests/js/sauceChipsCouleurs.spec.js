import { describe, expect, it } from 'vitest';
import fs from 'node:fs';
import path from 'node:path';

/**
 * Sentinelle — chaque chemin de rendu des sauces pose la classe de couleur.
 *
 * Le défaut qu'elle attrape, tel qu'il s'est produit le 2026-08-28 : l'assistant de la
 * caisse rend les sauces par DEUX chemins — celui du sandwich et celui des frites. Les deux
 * partagent la grille `.sauce-chips-grid`. Le premier a reçu la classe `sauce--<nom>` qui
 * porte la couleur ; le second a été oublié. Résultat à l'écran : des pastilles blanches
 * juste sous des pastilles colorées, dans la même grille. Le propriétaire l'a vu tout de
 * suite (« non pas encore ! »).
 *
 * Le contrôle est STRUCTUREL et non pas nominatif : il ne connaît aucune sauce, et ne
 * casse donc pas quand la carte change. Il vérifie une seule chose, celle qui a lâché —
 * qu'aucune pastille ne soit construite sans sa classe de couleur.
 */

const RACINE = path.resolve(__dirname, '../..');
const JS = fs.readFileSync(path.join(RACINE, 'public/js/pos-wizard.js'), 'utf8');
const CSS = fs.readFileSync(path.join(RACINE, 'public/css/pos-wizard.css'), 'utf8');

/** Toutes les constructions de pastille de sauce, quel que soit le chemin. */
function pastillesConstruites() {
    return JS.match(/'<button type="button" class="sauce-chip[^']*'/g) || [];
}

describe('pastilles de sauce — la couleur sur tous les chemins', () => {
    it('trouve bien plusieurs chemins de rendu (sinon la sentinelle ne prouve rien)', () => {
        // Garde-fou : si un jour un seul chemin subsiste, le test ci-dessous devient
        // trivial. On veut le savoir plutôt que de garder un banc qui ne mord plus.
        expect(pastillesConstruites().length).toBeGreaterThanOrEqual(2);
    });

    it('aucune pastille n’est construite sans sa classe de couleur', () => {
        const sansCouleur = pastillesConstruites().filter((c) => !c.includes('sauce--'));

        expect(
            sansCouleur,
            `Ces constructions de pastille ne posent pas la classe « sauce--<nom> » : leurs\n`
                + `sauces s'afficheront en blanc à côté des autres, colorées.\n`
                + sansCouleur.join('\n'),
        ).toEqual([]);
    });

    it('la classe est dérivée du nom, accents et casse retirés', () => {
        // « Algérienne » et « algérienne » doivent tomber sur la même règle, sinon la
        // couleur dépendrait de la façon dont la sauce a été saisie en base.
        expect(JS).toMatch(/normalize\('NFD'\)/);
        expect(JS).toMatch(/\[\\u0300-\\u036f\]/);
    });

    it('la feuille de style porte réellement des couleurs de sauce', () => {
        const regles = CSS.match(/\.sauce-chip\.sauce--[a-z0-9-]+/g) || [];
        expect(regles.length).toBeGreaterThan(10);
    });

    it('une sauce inconnue reste sobre plutôt que fausse', () => {
        // Pas de règle générique `.sauce-chip[class*="sauce--"]` qui donnerait une teinte
        // au hasard : une sauce absente de la table garde le fond blanc par défaut.
        expect(CSS).not.toMatch(/\.sauce-chip\[class\*=/);
    });
});
