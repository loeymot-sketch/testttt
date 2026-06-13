/**
 * [INT SF-01 2026-06-13] Staff-only mode — allowlist alignée aux VRAIS noms de
 * route auth.
 * -----------------------------------------------------------------------------
 * Constat audit : en staff-only mode, router/index.js redirige toute route
 * frontend (meta.isFrontend === true) absente de STAFF_ONLY_FRONTEND_ALLOWLIST
 * vers /login. Or l'allowlist contient des noms FANTÔMES qui n'existent dans
 * AUCUN module de route :
 *   - `auth.signup`  → les vraies routes sont auth.signupPhone / signupVerify /
 *                       signupRegister
 *   - `auth.guest`   → les vraies routes sont auth.guestLogin / guestLoginVerify
 *   - `auth.verifyEmail` (étape /forget-password/verify du reset) MANQUE.
 * Conséquence live : reset-mdp cassé (verify inatteignable) + signup/guest
 * redirigés vers /login → inscription/invité impossibles en staff-only.
 *
 * Invariant : tous les noms de l'allowlist existent dans authRoutes ET toutes
 * les routes auth publiques (auth:false, isFrontend) du parcours
 * login/reset/signup/guest sont couvertes.
 */
import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

// On lit les modules en TEXTE (pas d'import : authRoutes tire des composants
// .vue qui chargent le router complet → bruit). On parse les noms de route.
const routerSrc = readFileSync(
    resolve(process.cwd(), 'resources/js/router/index.js'),
    'utf8',
);
const authRoutesSrc = readFileSync(
    resolve(process.cwd(), 'resources/js/router/modules/authRoutes.js'),
    'utf8',
);

// Extrait les littéraux "xxx" listés dans le Set STAFF_ONLY_FRONTEND_ALLOWLIST.
function extractAllowlist() {
    const block = routerSrc.match(/STAFF_ONLY_FRONTEND_ALLOWLIST\s*=\s*new Set\(\[([\s\S]*?)\]\)/);
    expect(block).toBeTruthy();
    return [...block[1].matchAll(/"([^"]+)"/g)].map((m) => m[1]);
}

// Extrait tous les `name: 'auth.xxx'` réellement déclarés dans authRoutes.
function extractAuthRouteNames() {
    return [...authRoutesSrc.matchAll(/name:\s*['"]([^'"]+)['"]/g)].map((m) => m[1]);
}

describe('[SF-01] staff-only frontend allowlist', () => {
    const allowlist = extractAllowlist();
    const realAuthNames = new Set(extractAuthRouteNames());

    it('ne contient AUCUN nom de route auth fantôme (chaque auth.* existe vraiment)', () => {
        const phantoms = allowlist.filter(
            (n) => n.startsWith('auth.') && !realAuthNames.has(n),
        );
        expect(phantoms).toEqual([]);
    });

    it('couvre la 2e étape du reset mot de passe (auth.verifyEmail)', () => {
        expect(allowlist).toContain('auth.verifyEmail');
    });

    it('couvre tout le parcours signup (phone/verify/register)', () => {
        expect(allowlist).toContain('auth.signupPhone');
        expect(allowlist).toContain('auth.signupVerify');
        expect(allowlist).toContain('auth.signupRegister');
    });

    it('couvre tout le parcours invité (guestLogin + verify)', () => {
        expect(allowlist).toContain('auth.guestLogin');
        expect(allowlist).toContain('auth.guestLoginVerify');
    });

    it('conserve login / forget / reset', () => {
        expect(allowlist).toContain('auth.login');
        expect(allowlist).toContain('auth.forgetPassword');
        expect(allowlist).toContain('auth.resetPassword');
    });
});
