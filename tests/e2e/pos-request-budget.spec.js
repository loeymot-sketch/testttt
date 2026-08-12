// FoodKing E2E — [GOAL-OPS-SWAP W3 2026-08-12] Budget de requêtes de la caisse
//
// POURQUOI CE BANC EXISTE — plainte owner : « trop de requêtes » sur la caisse.
//
// MESURÉ (origine correspondant à APP_URL) :
//   · avant correctif : 35 requêtes à l'ouverture, dont 7 endpoints appelés
//     DEUX FOIS à 0-1 ms d'écart
//   · après fusion des GET en vol : 29 requêtes, 1 seule paire restante
//     (213 ms d'écart — pas de chevauchement, donc non fusionnable)
//   · au repos : 5 req/min, avant comme après (la caisse est sobre au repos)
//
// LE MUR : `throttle:api` vaut 120/min en PRODUCTION et il est PAR COMPTE, pas
// par écran (`RouteServiceProvider.php:57`). À 29 requêtes par ouverture, un
// même compte tient 4 ouvertures par minute. Caisse + cuisine + écran client
// sous le MÊME login, plus un F5, et le caissier voit « Trop de requêtes ».
//
// ⛔ Ne JAMAIS « corriger » en masquant le message : il a été ajouté exprès
//    (`bootstrap.js:52-64`) après un P0 où la caisse avalait 7+ HTTP 429 en
//    silence. On retire des requêtes, jamais l'alerte.
//
// Ce banc empêche la rafale de regonfler. S'il rougit, ce n'est pas le plafond
// qu'il faut relever : c'est la nouvelle requête qu'il faut justifier.

const { test, expect } = require('@playwright/test');
const { loginAsPosOperator } = require('./helpers/login');
const { clearFoodKingRateLimits } = require('./helpers/rate-limit');

/**
 * Plafond d'ouverture. Mesuré à 29 ; on laisse une marge de 3 pour l'aléa de
 * course (une requête conditionnelle peut arriver ou non selon la latence).
 * Toute hausse durable au-delà doit être JUSTIFIÉE, pas absorbée.
 */
const BUDGET_OUVERTURE = 32;

/** Cadence au repos. Mesurée à 5/min ; marge pour le jitter des sondages. */
const BUDGET_REPOS_PAR_MINUTE = 12;

test.describe('Budget de requêtes de la caisse', () => {
  test('l’ouverture de la caisse reste sous le budget, et sans doublon simultané', async ({ page }) => {
    test.setTimeout(180_000);
    clearFoodKingRateLimits();

    const parCle = new Map();
    const horodatage = new Map();

    page.on('request', (req) => {
      const u = new URL(req.url());
      if (!u.pathname.startsWith('/api/')) return;
      const cle = `${req.method()} ${u.pathname}`;
      parCle.set(cle, (parCle.get(cle) || 0) + 1);
      const ts = horodatage.get(cle) || [];
      ts.push(Date.now());
      horodatage.set(cle, ts);
    });

    await loginAsPosOperator(page, 'pos@lecayenne.fr', '123456');
    await expect(page).toHaveURL(/\/admin\/pos/, { timeout: 30_000 });
    await page.waitForTimeout(10_000);

    const total = [...parCle.values()].reduce((s, n) => s + n, 0);

    // Doublons STRICTEMENT SIMULTANÉS (< 50 ms) : ceux-là sont fusionnables,
    // donc leur présence signale une régression de la fusion en vol.
    const simultanes = [];
    for (const [cle, ts] of horodatage.entries()) {
      for (let i = 1; i < ts.length; i += 1) {
        if (ts[i] - ts[i - 1] < 50) simultanes.push(`${cle} (+${ts[i] - ts[i - 1]} ms)`);
      }
    }

    const detail = [...parCle.entries()]
      .sort((a, b) => b[1] - a[1])
      .map(([k, n]) => `  ${n}×  ${k}`)
      .join('\n');

    expect(
      simultanes,
      `Des GET identiques repartent EN MÊME TEMPS — la fusion en vol ne s'applique plus `
      + `(resources/js/shared/inflight-dedupe.js) :\n  ${simultanes.join('\n  ')}`,
    ).toEqual([]);

    expect(
      total,
      `Ouvrir la caisse coûte ${total} requêtes (budget ${BUDGET_OUVERTURE}).\n`
      + `Le plafond production est de 120/min PAR COMPTE : à ce rythme, ouvrir `
      + `caisse + cuisine + écran client sous le même login déclenche « Trop de requêtes ».\n`
      + `Détail :\n${detail}`,
    ).toBeLessThanOrEqual(BUDGET_OUVERTURE);
  });

  test('la caisse reste sobre AU REPOS', async ({ page }) => {
    test.setTimeout(180_000);
    clearFoodKingRateLimits();

    await loginAsPosOperator(page, 'pos@lecayenne.fr', '123456');
    await expect(page).toHaveURL(/\/admin\/pos/, { timeout: 30_000 });
    await page.waitForTimeout(10_000);

    let compte = 0;
    page.on('request', (req) => {
      if (new URL(req.url()).pathname.startsWith('/api/')) compte += 1;
    });

    await page.waitForTimeout(60_000);

    expect(
      compte,
      `La caisse émet ${compte} requêtes/min au repos (budget ${BUDGET_REPOS_PAR_MINUTE}). `
      + 'Un sondage a été ajouté ou accéléré.',
    ).toBeLessThanOrEqual(BUDGET_REPOS_PAR_MINUTE);
  });
});
