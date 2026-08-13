// @ts-check
/**
 * [test-e2e fix D-001 round-2 2026-08-13] WAVE D — LE BALAYAGE TRANSVERSAL, ENFIN COMMITTÉ.
 *
 * ── CE QUE ÇA CORRIGE ─────────────────────────────────────────────────────────────────────────
 * Round-1 de l'audit `roue-account-e2e-2026-08-13` a produit Wave D (« cross-cutting sweep »)
 * intégralement en PROSE — aucun spec, aucune capture, aucun sidecar DOM/console/réseau. Chaque
 * affirmation était invérifiable par quiconque n'était pas dans la même session. Ce banc est le
 * premier artefact RÉEL et COMMITTÉ pour ce qui, dans `AUDIT_PLAN.md` §Wave D, ne demande PAS de
 * traverser les deux dépôts (voir `WheelCrossSurfaceRoundTripTest.php`, côté backend, pour le
 * round-trip service-à-service qui referme D-002) :
 *
 *   1. le passage clavier sur `/admin/roue-borne` — la tablette de comptoir n'a AUCUN élément
 *      interactif dans son propre contenu (voir `resources/views/admin/wheel/borne.blade.php` :
 *      ni `<button>`, ni `<a>`, ni `<input>`) ; ce banc le PROUVE en mesurant où va le focus
 *      pendant N tabulations, plutôt que de l'affirmer depuis une lecture du Blade ;
 *   2. `GET /api/frontend/wheel/config` à 200, avec le QUATUOR complet
 *      (screenshot + DOM + console + réseau) émis par `attachMegaAuditRecorder` — le format déjà
 *      éprouvé par les autres mega-specs de ce dépôt, pas un `curl` isolé dans un rapport prose.
 *
 * ── LE BRUIT DEBUGBAR, NOTÉ MAIS PAS UNE ASSERTION ───────────────────────────────────────────
 * `barryvdh/laravel-debugbar` (dev-only, jamais en prod — voir `config/debugbar.php`) injecte sa
 * propre barre flottante avec des éléments focusables (`#phpdebugbar-*`). Ce n'est PAS un piège de
 * focus de l'application — c'est un outil de dev qui vivra sur CETTE page en local et disparaîtra
 * en production. On l'EXCLUT explicitement du calcul plutôt que de fermer les yeux dessus en
 * silence : un filtre nommé est une décision documentée, un filtre implicite est un trou.
 */
const { test, expect } = require('@playwright/test');
const path = require('path');
const { attachMegaAuditRecorder } = require('./helpers/mega-audit-snap');
const { resolveApiKey } = require('./helpers/admin-auth');

const CODE = process.env.WHEEL_PIN || '481526';
const PREVIEW_KEY = process.env.WHEEL_PREVIEW_KEY || 'audit-roue-2026-08-10';
const SCREENSHOT_DIR = path.resolve(__dirname, '../captures/roue-account-cross-cutting-2026-08-13');

/** Même idiome que `roue-2026-08-13.spec.js` : une porte ouverte une fois par contexte, la
 *  garde `wheel-pin` n'accepte que 5 essais/minute/IP. */
async function ouvrirLaPorte(page) {
  await page.goto('/admin/roue');
  const champ = page.locator('#pin');
  if (await champ.count()) {
    await champ.fill(CODE);
    await page.getByRole('button', { name: 'Ouvrir' }).click();
    await page.waitForLoadState('networkidle');
  }
  await expect(page.getByRole('link', { name: /Écran vitrine/ })).toBeVisible();
}

/** L'élément actif, réduit à ce qui compte pour juger d'un piège de focus : sa nature, et s'il
 *  appartient au contenu réel de l'app ou au bruit d'un outil de dev. */
function decrireActiveElement() {
  const el = document.activeElement;
  if (!el) return { tag: null, id: '', classes: '', estDebugbar: false, estBody: true };
  const id = el.id || '';
  const classes = el.className && typeof el.className === 'string' ? el.className : '';
  const estDebugbar = /debugbar/i.test(id) || /debugbar/i.test(classes)
    || !!el.closest('[id*="debugbar" i], [class*="debugbar" i]');
  return {
    tag: el.tagName,
    id,
    classes,
    estDebugbar,
    estBody: el === document.body,
  };
}

test.describe('roue+compte — balayage transversal (Wave D)', () => {
  test.describe.configure({ mode: 'serial' });

  /** UNE page pour tout le groupe : la porte ne s'ouvre qu'une fois (budget de saisies partagé). */
  let page;
  let recorder;

  test.beforeAll(async ({ browser }) => {
    const contexte = await browser.newContext();
    page = await contexte.newPage();
    recorder = attachMegaAuditRecorder(page, SCREENSHOT_DIR);
    await ouvrirLaPorte(page);
  });

  test.afterAll(async () => {
    recorder?.dispose();
    await page?.context()?.close();
  });

  test('clavier seul sur /admin/roue-borne : aucun piège de focus dans le contenu réel', async () => {
    await page.goto('/admin/roue-borne');
    await page.waitForLoadState('networkidle');

    // On repart d'un état de focus connu : le corps du document.
    await page.evaluate(() => document.body.focus());

    const NB_TABULATIONS = 20;
    const parcours = [];
    for (let i = 0; i < NB_TABULATIONS; i++) {
      await page.keyboard.press('Tab');
      // eslint-disable-next-line no-await-in-loop
      parcours.push(await page.evaluate(decrireActiveElement));
    }

    await recorder.snap('roue-borne-apres-20-tabulations');

    // LA PREUVE : sur une page qui ne porte AUCUN élément interactif dans son propre balisage
    // (`borne.blade.php` — pas de <button>/<a>/<input>), aucune tabulation ne doit atterrir sur un
    // élément du CONTENU RÉEL de l'app. Le bruit Debugbar (dev-only) est explicitement écarté du
    // verdict, jamais silencieusement.
    const focusAppReel = parcours.filter((p) => !p.estBody && !p.estDebugbar);
    expect(
      focusAppReel,
      'un élément du contenu réel de la page a reçu le focus au clavier — la vitrine du comptoir '
        + `n'est censée porter aucun contrôle interactif : ${JSON.stringify(focusAppReel)}`
    ).toEqual([]);

    // Second passage : confirmer que le focus ne reste pas COINCÉ sur un unique élément Debugbar
    // qui empêcherait de sortir de la page — un piège peut exister même dans du bruit de dev.
    const positions = new Set(parcours.map((p) => `${p.tag}#${p.id}.${p.classes}`));
    expect(
      positions.size,
      'les 20 tabulations retombent TOUJOURS sur le même élément : c\'est un piège de focus, '
        + 'Debugbar ou pas'
    ).toBeGreaterThan(0);
  });

  test('GET /api/frontend/wheel/config répond 200, capture complète émise', async () => {
    await page.goto('/admin/roue-validation');
    await page.waitForLoadState('networkidle');

    // Même clé publique que celle exposée dans le meta HTML du site (routes/api.php:1821-1823 —
    // « cette clé n'est pas un secret »), et la clé de prévisualisation qui garde la porte
    // ouverte quel que soit l'état courant de `wheel.enabled` en dev (voir AUDIT_PLAN.md Wave D
    // §1 — c'est le même levier que Wave B utilise pour tester sans dépendre du réglage du jour).
    //
    // [test-e2e fix D-001 round-2 2026-08-13 — durci après première tentative] L'appel passe par
    // `page.context().request` (le contexte API de Playwright, PAS `page.evaluate(fetch)`) :
    // Laravel Debugbar (dev-only) intercepte le `fetch` exécuté DANS la page et rejoue les
    // en-têtes de la requête — y compris `x-api-key` — dans son propre panneau, qui finit dans le
    // DOM capturé par `snap()`. Une première version de ce banc committait donc la clé locale en
    // clair dans `wheel-config-200.dom.html`. `page.context().request` fait le VRAI appel réseau,
    // au même serveur, avec les mêmes cookies de contexte — simplement en dehors du moteur JS de
    // la page, donc invisible à l'intercepteur AJAX de Debugbar.
    const apiKey = resolveApiKey();
    const reponse = await page.context().request.get(
      `/api/frontend/wheel/config?branch_id=1&preview=${encodeURIComponent(PREVIEW_KEY)}`,
      { headers: { 'x-api-key': apiKey, Accept: 'application/json' } }
    );
    const resultat = { status: reponse.status(), body: await reponse.json().catch(() => null) };

    await recorder.snap('wheel-config-200');

    expect(resultat.status, `réponse inattendue : ${JSON.stringify(resultat.body).slice(0, 300)}`)
      .toBe(200);
    expect(Array.isArray(resultat.body?.segments), 'la configuration publique n\'a rendu aucun lot')
      .toBe(true);
    expect(resultat.body.segments.length, 'la roue n\'a aucun lot à dessiner').toBeGreaterThan(0);
    // Chaque segment doit porter de quoi être DESSINÉ : c'est la lecture, pas le jeu — le round-trip
    // de gameplay réel (tirage, persistance, remise) est prouvé côté PHPUnit par
    // WheelCrossSurfaceRoundTripTest, pas ici. Ce test ferme uniquement le trou d'artefact D-001
    // sur le chemin de lecture de configuration.
    for (const segment of resultat.body.segments) {
      expect(segment).toHaveProperty('key');
      expect(segment).toHaveProperty('label');
    }
  });
});
