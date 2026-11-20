import { describe, expect, it } from 'vitest';
import fs from 'node:fs';
import path from 'node:path';

/**
 * Sentinelle — aucune pastille de sauce ne peut être rendue sans sa couleur.
 *
 * LE DÉFAUT D'ORIGINE, le 2026-08-28 : l'assistant rendait les sauces par DEUX chemins —
 * sandwich et frites — partageant la grille `.sauce-chips-grid`. Le premier posait la
 * classe `sauce--<nom>` qui portait la couleur ; le second avait été oublié. À l'écran :
 * des pastilles blanches juste sous des pastilles colorées, dans la même grille.
 *
 * [2026-09-02] LA MÉCANIQUE A CHANGÉ, ET CETTE SENTINELLE NE LA MESURAIT PLUS.
 * La caisse est passée à UN SEUL constructeur, `renderSauceTile()`, qui pose la couleur en
 * style en ligne (`--sauce-bg` / `--sauce-fg` / `--sauce-border`) dérivé de
 * `sauceStyleFor(sauce.name)`, et non plus par une classe `sauce--<nom>`. Les deux cas qui
 * suivaient l'ancienne forme échouaient donc en permanence : ils exigeaient « au moins deux
 * chemins de rendu » alors que la réduction à un seul est justement le progrès, et
 * cherchaient une classe que le code n'écrit plus. Une sentinelle rouge en permanence finit
 * par être ignorée — elle est ici remise sur la garantie réelle.
 *
 * Le contrôle reste STRUCTUREL : il ne connaît aucune sauce, et ne casse pas quand la carte
 * change. Il vérifie que le chemin unique existe, qu'il pose toujours la couleur, et que
 * personne ne construit de pastille en le contournant.
 */

const RACINE = path.resolve(__dirname, '../..');
const JS = fs.readFileSync(path.join(RACINE, 'public/js/pos-wizard.js'), 'utf8');
const CSS = fs.readFileSync(path.join(RACINE, 'public/css/pos-wizard.css'), 'utf8');

/** Toutes les constructions de pastille de sauce, quel que soit le chemin. */
function pastillesConstruites() {
    return JS.match(/'<button type="button" class="sauce-chip[^']*'/g) || [];
}

describe('pastilles de sauce — la couleur sur tous les chemins', () => {
    it('la construction des pastilles passe par UN chemin unique', () => {
        // Si un second constructeur réapparaît, on veut le savoir : c'est exactement la
        // situation qui avait produit des pastilles blanches à côté de pastilles colorées.
        expect(pastillesConstruites().length).toBe(1);
        expect(JS).toMatch(/function renderSauceTile\(/);
    });

    it('le chemin unique pose toujours la couleur, dérivée du nom de la sauce', () => {
        const corps = JS.slice(JS.indexOf('function renderSauceTile('));
        const corpsFn = corps.slice(0, corps.indexOf('\n    }') + 6);

        // La couleur vient de la table canonique, pas d'un littéral posé à la main.
        expect(corpsFn).toMatch(/sauceStyleFor\(\s*sauce\.name\s*\)/);
        // Et elle est réellement écrite sur la pastille, sur les trois variables.
        for (const variable of ['--sauce-bg:', '--sauce-fg:', '--sauce-border:']) {
            expect(corpsFn, `renderSauceTile n'écrit pas ${variable}`).toContain(variable);
        }
        expect(corpsFn).toMatch(/class="sauce-chip[^"]*'\s*\+[\s\S]{0,80}style="/);
    });

    it('aucune grille de sauces ne construit ses pastilles sans passer par le chemin unique', () => {
        // Chaque rendu de `.sauce-chips-grid` doit appeler renderSauceTile dans la foulée :
        // c'est ce contournement-là qui avait produit le défaut du 2026-08-28.
        const grilles = [...JS.matchAll(/sauce-chips-grid/g)].map((m) => m.index);
        expect(grilles.length).toBeGreaterThanOrEqual(2);

        const sansAppel = grilles.filter((i) => !JS.slice(i, i + 2500).includes('renderSauceTile('));
        expect(
            sansAppel.length,
            'Une grille de sauces construit ses pastilles sans renderSauceTile() : ses sauces\n'
                + "s'afficheront en blanc à côté des autres, colorées.",
        ).toBe(0);
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
