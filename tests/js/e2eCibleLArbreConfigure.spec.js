/**
 * [QA 2026-09-28] Sentinelle — une spec E2E doit mesurer l'arbre CONFIGURÉ.
 *
 * CE QUI A ÉTÉ CONSTATÉ
 * ---------------------
 * 28 specs backend portaient une URL de base en CONSTANTE DURE
 * (`const BASE = 'http://127.0.0.1:8766';`, aussi 8000 et 8899). Elles
 * ignoraient donc totalement `PLAYWRIGHT_BASE_URL`.
 *
 * Conséquence mesurée : en lançant le groupe caisse depuis ce worktree avec
 * `PLAYWRIGHT_BASE_URL=http://127.0.0.1:8000`, les erreurs console citaient
 * `127.0.0.1:8766/js/pos-app.js` — l'arbre PRINCIPAL. Les « 6/6 verts »
 * obtenus ne disaient donc RIEN du code de ce worktree. Après correctif, la
 * même commande ne cite plus que `:8000` (30 occurrences, 0 sur 8766), et le
 * hash du bundle servi change — preuve que la cible a bougé.
 *
 * POURQUOI LA GARDE EXISTANTE NE SUFFISAIT PAS
 * ---------------------------------------------
 * `tests/Playwright/global-setup.js` vérifie déjà, via un jeton déposé dans
 * `public/`, que le serveur ciblé sert bien CET arbre — mais il valide l'URL
 * **configurée**. Une spec qui code sa propre URL en dur **échappe** à cette
 * garde : le setup dit « bon arbre », puis la spec va mesurer ailleurs. C'est
 * la « confiance mal placée » que documente déjà
 * `tests/js/e2eGardeMemeArbreDeTravail.spec.js` — ici par un autre chemin.
 *
 * LA RÈGLE
 * --------
 * Toute URL de base visant le BACKEND doit passer par `PLAYWRIGHT_BASE_URL`,
 * avec l'ancienne valeur en repli (donc rétrocompatible : sans la variable,
 * comportement inchangé).
 *
 * EXCLUSIONS ASSUMÉES : les ports 8081 (mobile) et 8082 (web standalone) sont
 * d'AUTRES services, codebases séparées par mandat propriétaire
 * (CLAUDE.md §3bis). Les rediriger vers le backend serait un défaut, pas un
 * correctif — ils sont donc volontairement hors périmètre.
 */
import { describe, it, expect } from 'vitest';
import { readdirSync, readFileSync, statSync } from 'fs';
import { resolve, join } from 'path';

const RACINE = resolve(__dirname, '../..');
const DOSSIERS = ['tests/Playwright', 'tests/e2e'];

/** Ports du backend sous test. 8081/8082 = mobile / web standalone, hors périmètre. */
const PORTS_BACKEND = ['8000', '8766', '8899'];

function specs(dossierRelatif) {
  const base = join(RACINE, dossierRelatif);
  const trouves = [];
  const parcourir = (chemin) => {
    let entrees;
    try {
      entrees = readdirSync(chemin);
    } catch (_) {
      return;
    }
    for (const entree of entrees) {
      const complet = join(chemin, entree);
      let st;
      try {
        st = statSync(complet);
      } catch (_) {
        continue;
      }
      if (st.isDirectory()) parcourir(complet);
      else if (/\.spec\.(js|mjs|ts)$/.test(entree)) trouves.push(complet);
    }
  };
  parcourir(base);
  return trouves;
}

/** Retire les commentaires : on mesure le CODE, pas la prose qui cite le défaut. */
function sansCommentaires(source) {
  return source
    .replace(/\/\*[\s\S]*?\*\//g, '')
    .replace(/(^|[^:])\/\/[^\n]*/g, '$1');
}

const TOUTES = DOSSIERS.flatMap(specs);

describe('Sentinelle — les specs E2E ciblent l’arbre configuré', () => {
  it('trouve bien des specs à contrôler', () => {
    expect(TOUTES.length).toBeGreaterThan(20);
  });

  it('aucune spec ne code une URL de base backend en constante dure', () => {
    const motif = new RegExp(
      String.raw`^[ \t]*(?:const|let|var)[ \t]+\w+[ \t]*=[ \t]*['"]http://(?:127\.0\.0\.1|localhost):(?:${PORTS_BACKEND.join('|')})`,
      'm'
    );

    const fautives = [];
    for (const chemin of TOUTES) {
      const code = sansCommentaires(readFileSync(chemin, 'utf8'));
      if (motif.test(code)) fautives.push(chemin.replace(RACINE + '/', ''));
    }

    expect(
      fautives,
      'Ces specs codent une URL backend en dur : elles échappent à '
      + 'PLAYWRIGHT_BASE_URL et à la garde d\'arbre de travail du global-setup, '
      + 'donc elles peuvent mesurer un AUTRE worktree que celui sous test. '
      + 'Écrire : const BASE = (process.env.PLAYWRIGHT_BASE_URL || \'<ancienne url>\').replace(/\\/$/, \'\');'
    ).toEqual([]);
  });

  it('les URLs mobile (:8081) et web standalone (:8082) restent hors périmètre', () => {
    // Codebases séparées par mandat propriétaire : elles NE doivent PAS suivre
    // PLAYWRIGHT_BASE_URL, qui désigne le backend.
    let autresServices = 0;
    for (const chemin of TOUTES) {
      const code = sansCommentaires(readFileSync(chemin, 'utf8'));
      if (/http:\/\/(?:127\.0\.0\.1|localhost):(?:8081|8082)/.test(code)) autresServices += 1;
    }

    // On documente leur existence : si ce nombre tombe à 0, quelqu'un les a
    // probablement redirigées à tort vers le backend.
    expect(autresServices).toBeGreaterThan(0);
  });
});
