// @ts-check
const { test, expect } = require('@playwright/test');

/**
 * [D-PWD-RESET 2026-08-15 · GOAL_CONFORT_MAX] Preuve N2 (navigateur réel) que
 * STAFF_ONLY_FRONTEND_ALLOWLIST (resources/js/router/index.js) ne renvoie plus
 * silencieusement vers /login sur les routes auth réelles — "auth.signup" et
 * "auth.guest" ne correspondaient à AUCUNE route déclarée, et "auth.verifyEmail"
 * (où le personnel atterrit après avoir demandé une réinitialisation de mot de
 * passe) manquait. Requiert STAFF_ONLY_MODE=true (état réel du .env local) pour
 * exercer le garde qui a produit le bug.
 *
 * Méthodologie (affinée après investigation en direct) : on navigue DIRECTEMENT
 * vers chaque route étape 2/3 SANS passer par l'étape 1 — donc SANS l'état
 * client (Vuex) qu'un vrai parcours utilisateur aurait posé. Chaque composant
 * renvoie alors, LÉGITIMEMENT et SANS RAPPORT avec ce fix, vers sa PROPRE
 * étape 1 (ex. /guest-login/verify → /guest-login) : c'est un garde distinct,
 * déjà correct, pas celui qu'on répare ici. Ce qui est TESTÉ ET PROUVÉ, c'est
 * que plus AUCUNE de ces 4 routes ne finit sur /login — le seul symptôme réel
 * de l'incident (personnel bloqué en boucle sur la réinitialisation).
 */

const ROUTES_AUPARAVANT_MORTES = [
  { path: '/forget-password/verify', settledFallback: '/forget-password', label: 'vérification email (mot de passe oublié)' },
  { path: '/signup/verify', settledFallback: '/signup', label: 'vérification inscription' },
  { path: '/signup/register', settledFallback: '/signup', label: 'finalisation inscription' },
  { path: '/guest-login/verify', settledFallback: '/guest-login', label: 'vérification connexion invité' },
];

for (const { path, settledFallback, label } of ROUTES_AUPARAVANT_MORTES) {
  test(`staff-only : ${path} (${label}) ne finit plus JAMAIS sur /login`, async ({ page }) => {
    await page.goto(path, { waitUntil: 'commit' });
    // Attente robuste (pas networkidle, trop court côté SPA/CSP report-only) :
    // laisse le temps au garde de chaque composant de se déclencher et de se
    // stabiliser sur sa destination finale, quelle qu'elle soit.
    await page.waitForTimeout(1800);

    const finalUrl = page.url();
    expect(finalUrl, `${path} a atterri sur /login — l'allowlist a régressé`).not.toMatch(/\/login(?:$|[/?#])/);
    // Destination acceptée : soit la route demandée elle-même (si le composant
    // ne dépend d'aucun état préalable), soit le repli légitime propre à SON
    // parcours (état manquant car navigation directe, sans passer par l'étape 1).
    expect(
      finalUrl.includes(path) || finalUrl.endsWith(settledFallback),
      `${path} a atterri sur une destination inattendue : ${finalUrl}`,
    ).toBe(true);
  });
}

test('staff-only : /forget-password (déjà fonctionnel) reste fonctionnel — non-régression', async ({ page }) => {
  await page.goto('/forget-password', { waitUntil: 'commit' });
  await page.waitForTimeout(1200);
  expect(page.url()).not.toMatch(/\/login(?:$|[/?#])/);
  expect(page.url()).toContain('/forget-password');
});

test('staff-only : une route hors allowlist EST toujours bloquée (le garde reste actif)', async ({ page }) => {
  await page.goto('/menu', { waitUntil: 'commit' });
  await page.waitForTimeout(1200);
  expect(page.url(), 'la vitrine client doit rester bloquée en staff-only-mode').toMatch(/\/login(?:$|[/?#])/);
});
