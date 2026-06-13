/**
 * [INT SF-02 2026-06-13] LoginComponent — la classe CSS `capitalize` corrompt le
 * français.
 * -----------------------------------------------------------------------------
 * Constat audit : le titre <h2> du login porte `class="capitalize ..."`. La
 * règle CSS `text-transform: capitalize` met une majuscule à CHAQUE mot →
 * « Bon retour » s'affiche « Bon Retour ». Le FR ne capitalise que le premier
 * mot d'un titre ; la traduction porte déjà la casse correcte (« Bon retour »).
 *
 * Invariant : le titre du login ne déclare PAS la classe `capitalize`
 * (la casse de la traduction FR est respectée telle quelle).
 */
import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

const src = readFileSync(
    resolve(process.cwd(), 'resources/js/components/frontend/auth/LoginComponent.vue'),
    'utf8',
);

// Isole le bloc <h2 ...> ... welcome_back ... </h2> du titre.
function titleTag() {
    const m = src.match(/<h2[^>]*welcome_back[\s\S]*?<\/h2>/);
    if (m) return m[0];
    // welcome_back peut être sur la ligne suivante : capture le <h2 ...> englobant.
    const m2 = src.match(/<h2[^>]*>[\s\S]*?welcome_back[\s\S]*?<\/h2>/);
    return m2 ? m2[0] : null;
}

describe('[SF-02] LoginComponent — titre sans capitalize', () => {
    it('le <h2> du titre login ne porte pas la classe `capitalize`', () => {
        const tag = titleTag();
        expect(tag).toBeTruthy();
        // classe `capitalize` isolée (pas un sous-mot type `capitalized`)
        expect(tag).not.toMatch(/\bcapitalize\b/);
    });
});
