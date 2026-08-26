import fs from 'fs';
import path from 'path';
import { describe, expect, it } from 'vitest';

/**
 * [GOAL CONSOLIDATION_V1_PRODUCTION_20260825 — T-4.3.1 / T-4.3.2]
 * Sentinelle sur les INSTRUMENTS de mesure du harnais E2E.
 *
 * POURQUOI CE FICHIER EXISTE
 * --------------------------
 * Le pire défaut d'un audit n'est pas de rater un bug : c'est d'en inventer un. Pendant le cycle
 * `CAISSE-SUPERVISOR-CONTROL-20260823`, deux « défauts produit » signalés se sont révélés être des
 * instruments qui ne mesuraient rien. Corriger le produit sur cette base aurait cassé du code sain.
 *
 * PIÈGE 1 — `test.use({ reducedMotion: 'reduce' })` est INERTE dans ce dépôt.
 *   Prouvé par sonde isolée : la requête média `(prefers-reduced-motion: reduce)` restait
 *   `matches: false` malgré la directive. Les animations continuaient, les captures bougeaient,
 *   et l'instabilité était imputée au produit.
 *   ✅ Remède vérifié : `page.emulateMedia({ reducedMotion: 'reduce' })` — prouvé par
 *   `animationName: none` et une boîte englobante stable.
 *
 * PIÈGE 2 — `keyboard.press('F2')` est INERTE en mode sans affichage.
 *   Conclusion tirée puis rétractée : « les touches F1-F12 sont mortes ». Elles fonctionnent.
 *   ✅ Remède : prouver les raccourcis de fonction en test de COMPOSANT, jamais par navigateur
 *   sans affichage.
 *
 * PIÈGE 3 — mesurer contre des données qui n'existent pas.
 *   « La recherche ne tolère ni la casse ni les correspondances partielles » : faux. Les termes
 *   testés (`poulet`, `creme`) n'existent tout simplement pas au menu.
 *   ✅ Remède : vérifier la source (table `items`) AVANT de signaler un défaut de recherche.
 */

const racineE2E = path.resolve(process.cwd(), 'tests/e2e');

function listerSpecs(repertoire) {
    const sortie = [];
    for (const entree of fs.readdirSync(repertoire, { withFileTypes: true })) {
        if (entree.name === '__screenshots__' || entree.name === 'node_modules') continue;
        const complet = path.join(repertoire, entree.name);
        if (entree.isDirectory()) sortie.push(...listerSpecs(complet));
        else if (entree.name.endsWith('.spec.js')) sortie.push(complet);
    }
    return sortie;
}

/**
 * Retire commentaires de ligne et de bloc.
 *
 * Sans cela, la sentinelle se déclenchait sur ses propres AVERTISSEMENTS : les specs corrigées
 * citent `test.use({ reducedMotion })` dans un commentaire pour expliquer pourquoi il ne faut PAS
 * l'employer. Signaler ce texte comme une faute, c'est punir la documentation du piège — et c'est
 * exactement le genre d'artefact de mesure que ce fichier existe pour empêcher.
 */
function sansCommentaires(source) {
    // ⚠️ ORDRE IMPORTANT — commentaires de LIGNE d'abord, blocs ensuite.
    //
    // L'ordre inverse a réellement cassé cette sentinelle le 2026-08-25 : un commentaire de ligne
    // mentionnait un motif de route se terminant par `/` puis `*`. Ce `/`+`*` ouvrait un
    // commentaire de bloc aux yeux du dépouilleur, qui avalait tout jusqu'au `*` `/` suivant —
    // dont la ligne d'en-tête qu'on cherchait justement à détecter. Résultat : 8 faux positifs,
    // sur des fichiers déjà corrigés.
    //
    // En retirant les commentaires de ligne EN PREMIER, un tel motif disparaît avant de pouvoir
    // ouvrir quoi que ce soit.
    const sansLignes = source
        .split('\n')
        .map((ligne) => ligne.replace(/(^|[^:'"`\\])\/\/.*$/, '$1'))
        .join('\n');

    return sansLignes.replace(/\/\*[\s\S]*?\*\//g, ' ');
}

const specs = listerSpecs(racineE2E).map((c) => {
    const brut = fs.readFileSync(c, 'utf8');
    return {
        chemin: path.relative(process.cwd(), c),
        source: brut,
        code: sansCommentaires(brut),
    };
});

describe('Instruments de mesure du harnais E2E', () => {
    it('balaye un corpus vivant', () => {
        expect(specs.length).toBeGreaterThan(100);
    });

    it('n’utilise jamais test.use({ reducedMotion }) — inerte dans ce dépôt', () => {
        const fautifs = specs
            .filter(({ code }) => /test\.use\(\s*\{[^}]{0,200}?reducedMotion/s.test(code))
            .map(({ chemin }) => chemin);
        expect(
            fautifs,
            `test.use({ reducedMotion }) ne produit AUCUN effet ici (mesuré : matches=false).\n` +
                `Utilisez page.emulateMedia({ reducedMotion: 'reduce' }), qui est vérifié.\n  ` +
                fautifs.join('\n  '),
        ).toEqual([]);
    });

    it('utilise page.emulateMedia là où le mouvement doit être neutralisé', () => {
        const utilisateurs = specs.filter(({ source }) => source.includes('emulateMedia'));
        // Au moins les trois specs identifiées le 2026-08-25 doivent employer le bon instrument.
        expect(utilisateurs.length).toBeGreaterThanOrEqual(3);
        for (const { source, chemin } of utilisateurs) {
            expect(
                /emulateMedia\(\s*\{/.test(source),
                `${chemin} : emulateMedia doit être appelé avec un objet d'options`,
            ).toBe(true);
        }
    });

    it('ne conclut pas à des touches de fonction mortes depuis un navigateur sans affichage', () => {
        // `press('F1'..'F12')` ne remonte pas jusqu'à la page en headless. Une spec qui ASSERTE
        // un effet après un tel appui mesure l'instrument, pas le produit.
        const suspects = specs
            .filter(({ code }) => /keyboard\.press\(\s*['"]F(?:[1-9]|1[0-2])['"]\s*\)/.test(code))
            .filter(({ source }) => !/inerte|headless|sans affichage|composant/i.test(source))
            .map(({ chemin }) => chemin);
        expect(
            suspects,
            `Ces specs appuient sur une touche F sans documenter que c'est inerte en headless :\n  ` +
                suspects.join('\n  ') +
                `\nProuvez les raccourcis F1-F12 par un test de composant.`,
        ).toEqual([]);
    });

    it('garde les trois pièges documentés dans ce fichier', () => {
        const moi = fs.readFileSync(path.resolve(process.cwd(), 'tests/js/e2eInstrumentsDeMesureFiables.spec.js'), 'utf8');
        for (const piege of ['PIÈGE 1', 'PIÈGE 2', 'PIÈGE 3']) {
            expect(moi).toContain(piege);
        }
    });
});
