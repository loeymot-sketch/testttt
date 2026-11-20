/**
 * [QA 2026-09-28] Sentinelle — aucune spec ne doit pouvoir casser la COLLECTE.
 *
 * CE QUI A ÉTÉ CONSTATÉ
 * ---------------------
 * `npm run test:e2e:full` échouait **avant le moindre test**, pour tout le dépôt :
 *
 *     TypeError: The "path" argument must be of type string or an instance of
 *     Buffer or URL. Received undefined
 *       at Playwright/audit-dashboard-comptable-2026-08-29.spec.js:22
 *
 * La ligne fautive était `JSON.parse(fs.readFileSync(process.env.AUDIT_ROUTES, 'utf8'))`
 * au **niveau module**. Cette spec est un audit PONCTUEL piloté par une variable
 * d'environnement ; hors de cette invocation la variable est absente, et l'exception
 * remonte pendant la collecte — Playwright n'exécute alors RIEN.
 *
 * POURQUOI UNE SENTINELLE
 * -----------------------
 * C'est une RÉCIDIVE. Le dépôt a déjà payé exactement ce défaut (D9 : un
 * `fs.readFileSync` au niveau module rendait la collecte Playwright à 0 test).
 * Le motif est revenu dans un autre fichier, et un fichier d'audit jetable ne doit
 * jamais pouvoir rendre muette la suite entière.
 *
 * LA RÈGLE
 * --------
 * Interdiction de la forme DIRECTE `readFileSync(process.env.X, …)`. Lire la variable
 * dans une constante, la garder (absence + illisibilité), et faire que la spec
 * s'IGNORE avec un motif explicite. C'est ce que fait désormais le fichier ci-dessus.
 *
 * Cette sentinelle est volontairement textuelle : elle coûte quelques millisecondes,
 * là où la propriété réelle (« la collecte aboutit ») exige de lancer Playwright.
 * Les deux se complètent — la propriété réelle est vérifiée par la campagne E2E
 * elle-même, cette sentinelle attrape la régression au plus tôt et sans serveur.
 */
import { describe, it, expect } from 'vitest';
import { readdirSync, readFileSync, statSync } from 'fs';
import { resolve, join } from 'path';

const RACINE = resolve(__dirname, '../..');
const DOSSIERS = ['tests/Playwright', 'tests/e2e'];

/** Liste récursive des specs d'un dossier. */
function specs(dossierRelatif) {
  const base = join(RACINE, dossierRelatif);
  const trouves = [];

  const parcourir = (chemin) => {
    let entrees;
    try {
      entrees = readdirSync(chemin);
    } catch (_) {
      return; // dossier absent : rien à contrôler
    }
    for (const entree of entrees) {
      const complet = join(chemin, entree);
      let st;
      try {
        st = statSync(complet);
      } catch (_) {
        continue;
      }
      if (st.isDirectory()) {
        parcourir(complet);
      } else if (/\.spec\.(js|mjs|ts)$/.test(entree)) {
        trouves.push(complet);
      }
    }
  };

  parcourir(base);
  return trouves;
}

const TOUTES = DOSSIERS.flatMap(specs);

/**
 * Retire commentaires de bloc et de ligne avant analyse.
 *
 * Sans cela la sentinelle se déclenchait sur le fichier qu'elle venait de faire
 * corriger : son commentaire CITE l'ancienne ligne fautive pour expliquer le
 * défaut. Un instrument qu'un commentaire suffit à tromper — dans un sens comme
 * dans l'autre — ne mesure pas le produit. On mesure donc le CODE.
 */
function sansCommentaires(source) {
  return source
    .replace(/\/\*[\s\S]*?\*\//g, '')
    .replace(/(^|[^:])\/\/[^\n]*/g, '$1');
}

describe('Sentinelle — aucune spec ne casse la collecte Playwright', () => {
  it('trouve bien des specs à contrôler (sinon la sentinelle ne garde rien)', () => {
    expect(TOUTES.length).toBeGreaterThan(20);
  });

  it('aucune spec ne lit un chemin depuis process.env sans garde', () => {
    const fautives = [];

    for (const chemin of TOUTES) {
      const code = sansCommentaires(readFileSync(chemin, 'utf8'));
      // Forme directe et non gardée : readFileSync(process.env.X …)
      if (/readFileSync\s*\(\s*process\.env\./.test(code)) {
        fautives.push(chemin.replace(RACINE + '/', ''));
      }
    }

    expect(
      fautives,
      'Ces specs lisent un chemin directement depuis process.env : si la variable est '
      + 'absente, l\'exception remonte pendant la COLLECTE et toute la campagne E2E '
      + 'devient muette. Lire la variable dans une constante, la garder, et ignorer la '
      + 'spec avec un motif explicite (voir audit-dashboard-comptable-2026-08-29.spec.js).'
    ).toEqual([]);
  });

  it('la spec qui avait cassé la collecte porte désormais sa garde', () => {
    const chemin = join(RACINE, 'tests/Playwright/audit-dashboard-comptable-2026-08-29.spec.js');
    const source = readFileSync(chemin, 'utf8');

    expect(source).toContain('motifIgnore');
    expect(source).toContain('test.skip(motifIgnore !== null');
    // L'effet de bord de création de dossier ne doit plus avoir lieu à la collecte
    // quand la spec s'ignore.
    expect(source).toMatch(/if\s*\(!motifIgnore\)\s*\{[\s\S]{0,120}mkdirSync/);
  });
});
