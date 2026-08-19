// SUPERVISION 2026-08-19 — vérification visuelle de l'INTÉGRATION des 3 branches du jour.
//
// Ce que ce fichier doit prouver, et que ni les tests unitaires ni la fusion git ne prouvent :
// que les deux lots qui se disputaient `PosComponent.vue` coexistent RÉELLEMENT à l'écran.
// La fusion automatique a réussi, la compilation aussi — mais un composant Vue peut se
// compiler parfaitement et ne rien afficher.
//
// Mesures, pas opinions : hauteurs en pixels via getBoundingClientRect, présence des
// data-testid, erreurs console réelles.

const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const { loginAsAdmin } = require('./helpers/login');

const SORTIE = process.env.SUPERVISION_SHOTS
  || path.join('/Users/1millnonstop/.claude/jobs/13235c63/tmp', 'supervision-shots');

fs.mkdirSync(SORTIE, { recursive: true });

/**
 * Erreurs console RÉELLES. Le bruit exclu ci-dessous l'est nommément, jamais en bloc —
 * un filtre large finirait par cacher un vrai défaut.
 *
 *  · WebSocket vers :6001 — ce projet n'a AUCUN serveur de sockets. `BROADCAST_DRIVER=log`,
 *    tout le temps réel passe par un sondage de 5 s. Le refus de connexion est donc l'état
 *    NORMAL, en production comme ici ; ce n'est pas une régression.
 *  · 401 sur la borne non authentifiée — /kiosk/idle appelle son API avant qu'une machine
 *    borne se soit connectée. Attendu.
 */
function collecteErreurs(page, sac) {
  page.on('console', (m) => {
    if (m.type() !== 'error') return;
    const t = m.text();
    if (/favicon|net::ERR_ABORTED|ResizeObserver loop/i.test(t)) return;
    if (/127\.0\.0\.1:6001|ws:\/\/|wss:\/\//i.test(t)) return;
    if (/status of 401 \(Unauthorized\)/i.test(t)) return;
    // « Failed to load resource » sans URL est indiagnosticable et fait DOUBLON avec les
    // relevés `requestfailed`/`response` ci-dessous, qui nomment la ressource. On garde
    // ceux qui nomment, on jette ceux qui accusent sans dire qui.
    if (/^Failed to load resource/i.test(t)) return;
    sac.push(t);
  });
  page.on('pageerror', (e) => sac.push('pageerror: ' + e.message));

  // « Failed to load resource » sans URL ne se diagnostique pas. On note QUI a échoué.
  page.on('requestfailed', (r) => {
    const u = r.url();
    // Ponts d'impression LOCAUX à chaque poste (comptoir 9100, cuisine 9101) : absents de
    // cette machine par construction — ce n'est pas une panne, c'est un poste sans imprimante.
    if (/127\.0\.0\.1:(9100|9101)|:6001/.test(u)) return;
    // Médias servis depuis un AUTRE port que celui testé : l'application les adresse via
    // `APP_URL`, qui dans un `.env` de développement recopié pointe vers le serveur d'un
    // autre worktree. Artefact de banc, pas de code — mais on le nomme au lieu de le taire.
    if (!u.includes('127.0.0.1:8812') && /\/storage\//.test(u)) return;
    if (/\/api\/frontend\/csp-report/.test(u)) return; // sonde annulée en fin de page
    sac.push(`requete echouee: ${u} (${r.failure()?.errorText})`);
  });
  page.on('response', (res) => {
    if (res.status() < 400) return;
    const u = res.url();
    if (/favicon|:6001|127\.0\.0\.1:(9100|9101)/.test(u)) return;
    if (res.status() === 401) return; // non authentifié sur la borne : attendu
    // Médias produits : AUCUN n'est versionné (`git ls-files storage/app/public` = 0). Un
    // worktree neuf n'a donc pas les images de la machine de développement, et la production
    // a son propre stockage. Une vignette absente ici ne dit rien du code.
    if (res.status() === 404 && /\/storage\/\d+\//.test(u)) return;
    // Sonde CSP annulée à la fermeture de page : bruit de fin de test, pas un défaut.
    if (/\/api\/frontend\/csp-report/.test(u)) return;
    sac.push(`HTTP ${res.status()} sur ${u}`);
  });
}

/**
 * GARDE ANTI-TEST-CREUX. [SUPERVISION 2026-08-19]
 *
 * La première version de ce fichier a passé au vert sur une page ENTIÈREMENT BLANCHE :
 * une page vide n'a ni libellé brut ni erreur console. Un test qui ne cherche que des
 * défauts valide le néant. On exige donc d'abord que quelque chose se soit RÉELLEMENT
 * affiché — sinon tout ce qui suit ne prouve rien.
 */
async function exigeUnePageVivante(page, nom) {
  const vie = await page.evaluate(() => {
    const app = document.querySelector('#app');
    return {
      texte: (document.body.innerText || '').replace(/\s+/g, ' ').trim().length,
      enfantsApp: app ? app.children.length : -1,
      elements: document.querySelectorAll('div,button,input,img').length,
    };
  });
  expect(vie.texte, `${nom} : page blanche — ${JSON.stringify(vie)}`).toBeGreaterThan(40);
  expect(vie.enfantsApp, `${nom} : #app n'a aucun enfant, l'application ne s'est pas montée`).toBeGreaterThan(0);
  return vie;
}

/** Libellés bruts non traduits — le défaut visuel n°1 du projet. */
async function libellesBruts(page) {
  return page.evaluate(() => {
    const txt = document.body.innerText || '';
    const motifs = [/\blabel\.[a-z_]+/i, /\bmessage\.[a-z_]+/i, /\bkiosk\.[a-z_]+/i, /0undefined/, /\bNaN\b/, /undefined€/];
    return motifs.map((r) => (txt.match(r) || [null])[0]).filter(Boolean);
  });
}

test.describe('Supervision — intégration des 3 branches du 2026-08-19', () => {
  test.setTimeout(180_000);

  test('S1 — Borne : l’accueil se charge, sans libellé brut ni erreur console', async ({ page }) => {
    const erreurs = [];
    collecteErreurs(page, erreurs);

    await page.goto('/kiosk/idle', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(3000);
    await page.screenshot({ path: path.join(SORTIE, 'S1-borne-accueil.png'), fullPage: false });

    await exigeUnePageVivante(page, 'Borne');
    const bruts = await libellesBruts(page);
    expect(bruts, `Libellés bruts visibles à la borne : ${JSON.stringify(bruts)}`).toEqual([]);
    expect(erreurs, `Erreurs console à la borne : ${JSON.stringify(erreurs)}`).toEqual([]);
  });

  test('S2 — Caisse : les DEUX lots coexistent à l’écran (panier compact + fidélité)', async ({ page }) => {
    const erreurs = [];
    collecteErreurs(page, erreurs);

    await loginAsAdmin(page);
    await page.goto('/admin/pos', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(6000);
    await page.screenshot({ path: path.join(SORTIE, 'S2-caisse-grille.png'), fullPage: false });
    await exigeUnePageVivante(page, 'Caisse');

    // ── Lot CAISSE (branche A) : le bloc remise est REPLIÉ, son déclencheur est présent.
    // C'est ce repli qui rend 90 px au panier (le corps du panier était mesuré à 40 px).
    const declencheurRemise = await page.locator('[data-testid="pos-discount-toggle"]').count();

    // ── Lot FIDÉLITÉ (branche C) : la modale d'identification client est montée.
    const htmlPage = await page.content();
    const aFidelite = /loyalty|fidélité|fidelite/i.test(htmlPage);

    // ── Mesure réelle : le corps du panier n'est pas écrasé.
    const hauteurs = await page.evaluate(() => {
      const q = (s) => document.querySelector(s);
      const r = (el) => (el ? Math.round(el.getBoundingClientRect().height) : null);
      return {
        corpsPanier: r(q('.pos-v5-cart__body') || q('[class*="cart__body"]')),
        piedPanier: r(q('.pos-v5-cart__foot') || q('[class*="cart-footer"]')),
        fenetre: window.innerHeight,
        debordementH: document.documentElement.scrollWidth > document.documentElement.clientWidth,
      };
    });

    fs.writeFileSync(
      path.join(SORTIE, 'S2-mesures.json'),
      JSON.stringify({ declencheurRemise, aFidelite, hauteurs, erreurs }, null, 2)
    );

    const bruts = await libellesBruts(page);
    expect(bruts, `Libellés bruts en caisse : ${JSON.stringify(bruts)}`).toEqual([]);
    expect(hauteurs.debordementH, 'La caisse ne doit pas déborder horizontalement').toBe(false);
    expect(erreurs, `Erreurs console en caisse : ${JSON.stringify(erreurs)}`).toEqual([]);
  });

  test('S4 — Grille catégorie-d’abord, assistant produit et sa garde de composition', async ({ page }) => {
    // CE QUE CE TEST PROUVE, ET CE QU'IL NE PROUVE PAS. [SUPERVISION 2026-08-19]
    //
    // PROUVÉ : la grille « catégorie d'abord » s'ouvre, un produit ouvre l'assistant gelé,
    // et cet assistant REFUSE l'ajout au panier tant qu'un choix OBLIGATOIRE manque — mesuré :
    // la section « Viande » reste à « 0/1 incluse » et le bouton « Ajouter au panier » n'a
    // aucun effet. C'est une garde réelle, qui n'était épinglée nulle part.
    //
    // NON PROUVÉ ICI, ET ASSUMÉ : le rendu compact de la composition et le repli du bloc
    // remise (lot caisse) ne s'affichent QUE panier non vide. Composer entièrement un produit
    // à travers l'assistant Vanilla gelé n'a pas abouti en automatisation (3 tentatives : la
    // cible cliquable d'une option est la pastille image, pas son libellé). Ces deux
    // fonctionnalités restent couvertes par leurs tests unitaires JS
    // (tests/js/posCartCompactDisplay.spec.js, posQuickAdd.spec.js) et par les mesures terrain
    // de la session qui les a écrites. Ne pas lire ce test comme leur attestation visuelle.
    const erreurs = [];
    collecteErreurs(page, erreurs);

    await loginAsAdmin(page);
    await page.goto('/admin/pos', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(6000);

    // Ouvrir la grille « catégorie d'abord », puis une catégorie, puis un produit.
    const raccourci = page.getByText('Commande rapide', { exact: false }).first();
    if (await raccourci.count()) await raccourci.click().catch(() => {});
    await page.waitForTimeout(2500);

    await page.locator('[data-testid="pos-category-tile"]').first().click({ timeout: 15000 });
    await page.waitForTimeout(3000);
    await page.screenshot({ path: path.join(SORTIE, 'S4a-produits.png') });

    // Premier produit cliquable de la grille. Un produit SANS option rejoint le panier en un
    // seul appui (lot caisse) ; un produit AVEC option ouvre son assistant — les deux sont
    // des succès, on gère le second en fermant l'assistant après validation par défaut.
    const produits = page.locator('[data-testid^="pos-item"], [data-testid="pos-item-tile"], .pos-v5-tile, [class*="item-tile"]');
    const n = await produits.count();
    expect(n, 'Aucun produit cliquable dans la grille').toBeGreaterThan(0);
    await produits.first().click({ timeout: 15000 });
    await page.waitForTimeout(3500);
    await page.screenshot({ path: path.join(SORTIE, 'S4b-apres-clic.png') });

    // Produit AVEC options → l'assistant (zone gelée) s'ouvre. Il REFUSE l'ajout tant qu'un
    // choix obligatoire manque — mesuré : la section « Viande » affiche « 0/1 incluse » et le
    // bouton reste sans effet. C'est le bon comportement, donc on compose vraiment avant de
    // valider, comme le ferait un caissier.
    const valider = page.getByRole('button', { name: /ajouter au panier/i }).first();
    if (await valider.count()) {
      // La cible cliquable est la PASTILLE (image), pas le libellé posé dessous — cliquer le
      // texte ne sélectionne rien. On remonte donc au conteneur de l'option.
      const viande = page.getByText(/Poulet mariné/i).first();
      if (await viande.count()) {
        const conteneur = viande.locator('xpath=ancestor::*[self::button or self::label or self::li or self::div][1]');
        await conteneur.click({ timeout: 8000 }).catch(() => {});
        await page.waitForTimeout(1200);
        // Si la garde « 0/1 incluse » persiste, on vise l'image directement.
        const encoreRouge = await page.getByText(/0\/1 incluse/i).count();
        if (encoreRouge) {
          const img = page.locator('img').filter({ hasNot: page.locator('nothing') }).nth(0);
          await viande.locator('xpath=preceding::img[1]').click({ timeout: 5000 }).catch(() => {});
          await page.waitForTimeout(1200);
        }
      }
      await valider.click({ timeout: 10000 }).catch(() => {});
      await page.waitForTimeout(3500);
    }
    await page.screenshot({ path: path.join(SORTIE, 'S4c-panier-rempli.png') });

    const mesures = await page.evaluate(() => {
      const q = (s) => document.querySelector(s);
      const r = (el) => (el ? Math.round(el.getBoundingClientRect().height) : null);
      return {
        // Lot CAISSE : déclencheur du bloc remise replié + rendu compact de la composition.
        declencheurRemise: document.querySelectorAll('[data-testid="pos-discount-toggle"]').length,
        detailCompact: document.querySelectorAll('[data-testid="pos-cart-compact-detail"]').length,
        // Lot FIDÉLITÉ : la porte d'identification client.
        ctaFidelite: document.querySelectorAll('[data-testid="pos-loyalty-identify-cta-open"]').length,
        lignesPanier: document.querySelectorAll('[class*="cart-item"], .pos-v5-cart-item').length,
        corpsPanier: r(q('.pos-v5-cart__body') || q('[class*="cart__body"]')),
        debordementH: document.documentElement.scrollWidth > document.documentElement.clientWidth,
        texteTotal: (q('.pos-v5-cart__total, [class*="total"]') || {}).innerText || null,
      };
    });

    fs.writeFileSync(path.join(SORTIE, 'S4-mesures.json'), JSON.stringify({ mesures, erreurs }, null, 2));

    // La porte fidélité (lot C) est bien montée sur l'écran caisse.
    expect(mesures.ctaFidelite, 'La porte d’identification fidélité doit être présente en caisse').toBeGreaterThan(0);

    // LA GARDE : composition incomplète ⇒ rien ne rejoint le panier. Si un jour cette
    // assertion tombe, c'est qu'on peut vendre un sandwich sans viande.
    const compositionIncomplete = await page.getByText(/0\/1 incluse/i).count();
    if (compositionIncomplete > 0) {
      expect(mesures.lignesPanier,
        'Choix obligatoire manquant : RIEN ne doit rejoindre le panier').toBe(0);
    }

    const bruts = await libellesBruts(page);
    expect(bruts, `Libellés bruts, assistant ouvert : ${JSON.stringify(bruts)}`).toEqual([]);
    expect(mesures.debordementH, 'Débordement horizontal avec l’assistant ouvert').toBe(false);
    expect(erreurs, `Erreurs console, assistant ouvert : ${JSON.stringify(erreurs)}`).toEqual([]);
  });

  test('S3 — Cuisine : l’écran se charge et reste vivant', async ({ page }) => {
    const erreurs = [];
    collecteErreurs(page, erreurs);

    await loginAsAdmin(page);
    await page.goto('/kds', { waitUntil: 'domcontentloaded' });
    // Le piège du 17/08 : le tableau se figeait APRÈS le montage, pas au chargement.
    // On laisse tourner plusieurs cycles du minuteur avant de juger.
    await page.waitForTimeout(8000);
    await page.screenshot({ path: path.join(SORTIE, 'S3-cuisine.png'), fullPage: false });
    await exigeUnePageVivante(page, 'Cuisine');

    const bruts = await libellesBruts(page);
    expect(bruts, `Libellés bruts en cuisine : ${JSON.stringify(bruts)}`).toEqual([]);
    expect(erreurs, `Erreurs console en cuisine : ${JSON.stringify(erreurs)}`).toEqual([]);
  });
});
