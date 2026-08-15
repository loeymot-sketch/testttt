import { describe, expect, it } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

/**
 * [D-PWD-RESET 2026-08-15 · GOAL_CONFORT_MAX] STAFF_ONLY_FRONTEND_ALLOWLIST
 * (router/index.js) citait "auth.signup" et "auth.guest" — deux noms qui ne
 * correspondaient à AUCUNE route déclarée (vrais noms : auth.signupPhone,
 * auth.signupVerify, auth.signupRegister, auth.guestLogin,
 * auth.guestLoginVerify). Un nom faux dans cette liste blanche renvoie
 * silencieusement l'utilisateur vers /login — aucune erreur, aucune trace :
 * c'est ainsi que le personnel s'est retrouvé bloqué en boucle sur la
 * réinitialisation de mot de passe (auth.verifyEmail manquait aussi).
 *
 * Cette sentinelle lit les fichiers source EN TEXTE (convention de ce projet,
 * cf. staffOnlyLandingRedirect.spec.js) plutôt que d'importer le router — évite
 * de démarrer store/i18n juste pour un test de nommage.
 */
function readSource(relativePath) {
    return readFileSync(resolve(process.cwd(), relativePath), 'utf8');
}

function extractAllowlist(routerIndexSource) {
    const match = routerIndexSource.match(
        /STAFF_ONLY_FRONTEND_ALLOWLIST\s*=\s*new Set\(\[([\s\S]*?)\]\)/,
    );
    if (!match) return [];
    return Array.from(match[1].matchAll(/["']([\w.]+)["']/g)).map((m) => m[1]);
}

function extractDeclaredRouteNames(moduleSource) {
    return Array.from(moduleSource.matchAll(/name:\s*['"]([\w.]+)['"]/g)).map((m) => m[1]);
}

describe('STAFF_ONLY_FRONTEND_ALLOWLIST — chaque nom cité doit exister vraiment', () => {
    const routerSource = readSource('resources/js/router/index.js');
    const authRoutesSource = readSource('resources/js/router/modules/authRoutes.js');

    const allowlist = extractAllowlist(routerSource);
    const authRouteNames = extractDeclaredRouteNames(authRoutesSource);
    // Les 2 entrées non-auth de la liste blanche sont déclarées directement dans
    // router/index.js (route.notFound / route.exception), pas dans un module.
    const localRouteNames = extractDeclaredRouteNames(routerSource);
    const allDeclaredNames = new Set([...authRouteNames, ...localRouteNames]);

    it('la liste blanche n\'est pas vide (garde contre un regex cassé)', () => {
        expect(allowlist.length).toBeGreaterThanOrEqual(9);
    });

    it.each(allowlist.length ? allowlist : ['(extraction failed)'])(
        '"%s" correspond à une route réellement déclarée',
        (name) => {
            expect(allDeclaredNames.has(name)).toBe(true);
        },
    );

    it('les 9 routes réelles du module auth sont TOUTES dans la liste blanche (login/reset/signup/guest complets)', () => {
        // Pas seulement "au moins une par famille" : un maillon manquant dans une
        // chaîne (ex. verifyEmail absent) suffit à bloquer tout le parcours, comme
        // le prouve l'incident réel qui a motivé cette sentinelle.
        for (const name of authRouteNames) {
            expect(allowlist, `"${name}" est une route auth réelle absente de la liste blanche`).toContain(name);
        }
    });

    it('les noms morts historiques ne reviennent jamais', () => {
        expect(allowlist).not.toContain('auth.signup');
        expect(allowlist).not.toContain('auth.guest');
    });
});
