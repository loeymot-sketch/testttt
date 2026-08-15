/**
 * [GOAL_CONFORT_MAX §4 Vague 3 T-3.1 2026-08-15] Sentinelle anti « preuve vacante ».
 *
 * Contexte : l'audit de reconnaissance a trouvé 15 fichiers Playwright avec des
 * `test(...)` réels (donc VERTS en CI) mais ZÉRO `expect()` — une preuve qui ne
 * prouve rien (`max-test-t2-pos-2026-05-28.spec.js` 23 tests/0 expect,
 * `goal-functional-pos-2026-05-28.spec.js` 8/0, 12× `zz-audit-caissier-s*`,
 * `final-borne-deep.spec.js` 1356 lignes/1 seul expect). Tous purgés en T-3.1.
 *
 * Cette sentinelle empêche la régression : tout NOUVEAU spec Playwright avec au
 * moins un `test(...)` réel (hors `test.skip`/`test.fixme`, qui s'auto-déclarent
 * incomplets sans mentir sur un résultat vert) doit contenir au moins un
 * `expect(`. Scope volontairement limité à `tests/e2e/` et `tests/Playwright/`
 * (Playwright) — PAS `tests/js/` (Vitest, déjà couvert par la discipline TDD
 * standard et par des dizaines de sentinelles existantes).
 */
import { describe, expect, it } from 'vitest';
import { readFileSync, readdirSync, statSync } from 'node:fs';
import { resolve, join } from 'node:path';

function listSpecFiles(dir) {
    const root = resolve(process.cwd(), dir);
    const out = [];
    const walk = (current) => {
        for (const entry of readdirSync(current)) {
            const full = join(current, entry);
            const st = statSync(full);
            if (st.isDirectory()) {
                if (entry === 'helpers' || entry === 'node_modules') continue;
                walk(full);
            } else if (entry.endsWith('.spec.js')) {
                out.push(full);
            }
        }
    };
    walk(root);
    return out;
}

// Un `test(` réel = pas immédiatement précédé de `.skip` ou `.fixme` sur le même
// appel (`test.skip(...)`, `test.fixme(...)`). `test.describe(` est exclu (ce
// n'est pas un test, c'est un groupe).
function countRealTests(source) {
    const matches = source.matchAll(/\btest(\.only)?\s*\(/g);
    let count = 0;
    for (const m of matches) {
        count += 1;
    }
    // Retire les test.skip(/test.fixme(/test.describe( déjà comptés par erreur
    // (le regex ci-dessus ne matche QUE `test(` ou `test.only(`, donc rien à
    // soustraire — gardé explicite pour lisibilité du contrat).
    return count;
}

function countExpects(source) {
    return (source.match(/\bexpect(\.soft)?\s*\(/g) || []).length;
}

describe('Sentinelle anti-preuve-vacante — specs Playwright', () => {
    const specDirs = ['tests/e2e', 'tests/Playwright'];
    const files = specDirs.flatMap((d) => {
        try {
            return listSpecFiles(d);
        } catch (e) {
            return [];
        }
    });

    it('au moins un spec Playwright existe (le scan lui-même doit trouver des fichiers)', () => {
        expect(files.length).toBeGreaterThan(0);
    });

    it.each(files.map((f) => [f]))('%s : au moins un test réel implique au moins un expect()', (file) => {
        const source = readFileSync(file, 'utf8');
        const realTests = countRealTests(source);
        const expects = countExpects(source);

        if (realTests === 0) {
            // Fichier composé uniquement de test.skip/test.fixme/test.describe —
            // s'auto-déclare incomplet, ne ment sur aucun résultat vert. Hors scope.
            return;
        }

        expect(
            expects,
            `${file} : ${realTests} test(s) réel(s) mais 0 expect() — preuve vacante (verte sans rien prouver). ` +
                `Si le test est volontairement incomplet, utiliser test.skip()/test.fixme() avec une raison, pas un test() muet.`,
        ).toBeGreaterThan(0);
    });
});
