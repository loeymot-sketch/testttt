/**
 * VAGUE B — ÉCRAN DE CAISSE · phase CAPTURE (superviseur, 2026-08-24)
 *
 * Ce fichier NE CORRIGE RIEN. Il ouvre les surfaces de caisse, il photographie,
 * il MESURE, et il écrit les chiffres sur le disque. Toute conclusion se lit dans
 * `reports/test-e2e/supervisor-caisse-2026-08-24/round-1/wave-B-capture.md`.
 *
 * Surfaces : /admin/pos · /admin/pos-v4 · /admin/pos/floorplan · /admin/encaissement
 * (cette dernière uniquement pour photographier `CaisseSecondaryNav`, qui n'est
 * PAS monté sur /admin/pos — il vit sur les pages secondaires de la caisse).
 *
 * Zones gelées lues, jamais touchées : public/js/pos-wizard.js, public/css/pos-wizard.css,
 * resources/views/admin-pos-v4.blade.php, PaymentComponent.vue, v5/PosV5TrancheRow.vue.
 *
 * Aucune donnée n'est semée : le panier de l'état 5 n'est jamais encaissé, donc
 * aucune commande n'est créée. Rien à nettoyer (préfixe AUDB- non employé).
 */

const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const { loginAsAdmin } = require('./helpers/login');
const { attachMegaAuditRecorder } = require('./helpers/mega-audit-snap');

const SHOTS_DIR = path.resolve(__dirname, '__screenshots__/test-e2e-waveB');
const MESURES = [];

const GABARIT_COMPTOIR = { width: 1366, height: 768 };
const GABARIT_TABLETTE = { width: 1024, height: 600 };

test.describe.configure({ mode: 'serial', timeout: 240_000 });

/* -------------------------------------------------------------------------- */
/* Instrument de mesure — exécuté DANS la page, aucun DOM n'est modifié.        */
/* -------------------------------------------------------------------------- */
function releverMesures() {
  const rd = (n) => (typeof n === 'number' && Number.isFinite(n) ? Math.round(n) : null);
  const q = (s) => document.querySelector(s);
  const boite = (el) => {
    if (!el) return null;
    const r = el.getBoundingClientRect();
    return {
      haut: rd(r.top), bas: rd(r.bottom), gauche: rd(r.left), droite: rd(r.right),
      largeur: rd(r.width), hauteur: rd(r.height),
    };
  };
  const px = (v) => {
    const n = parseFloat(String(v || ''));
    return Number.isFinite(n) ? Math.round(n) : null;
  };

  const vue = { largeur: window.innerWidth, hauteur: window.innerHeight };
  const panier = q('#pos-cart');
  const tete = q('.pos-v5-cart__head');
  const corps = q('.pos-v5-cart__body');
  const pied = q('.pos-v5-cart__foot');
  const grille = q('[data-testid="pos-category-grid"]');
  const nom = q('[data-testid="pos-customer-name"]');
  const raccourcis = q('[data-testid="pos-shortcuts"]');

  /* Le champ « Nom du client » est-il atteignable SANS UN SEUL GESTE ?
     Trois conditions cumulatives, toutes vérifiables :
       1. la page n'a pas été défilée (scrollY === 0) et l'en-tête non plus ;
       2. son rectangle tient entièrement dans la fenêtre ;
       3. il tient entièrement dans la zone NON ROGNÉE de l'en-tête (l'en-tête
          peut être lui-même un défileur : `overflow-y:auto`, max-height 20/30vh) ;
       4. contrôle final au pixel : elementFromPoint sur son centre le retrouve. */
  let champNom = null;
  if (nom) {
    const r = nom.getBoundingClientRect();
    const rt = tete ? tete.getBoundingClientRect() : null;
    const cx = Math.round(r.left + r.width / 2);
    const cy = Math.round(r.top + r.height / 2);
    const touche = (cx >= 0 && cy >= 0 && cx < vue.largeur && cy < vue.hauteur)
      ? document.elementFromPoint(cx, cy) : null;
    champNom = {
      boite: boite(nom),
      dans_fenetre: r.top >= 0 && r.bottom <= vue.hauteur && r.left >= 0 && r.right <= vue.largeur,
      dans_zone_visible_entete: rt ? (r.top >= rt.top - 1 && r.bottom <= rt.bottom + 1) : null,
      test_au_pixel: touche ? (touche === nom || nom.contains(touche) || touche.contains(nom)) : false,
      element_touche: touche ? (touche.getAttribute('data-testid') || touche.tagName.toLowerCase()) : null,
      defilement_page: rd(window.scrollY),
      defilement_entete: tete ? rd(tete.scrollTop) : null,
    };
  }

  const styleCorps = corps ? getComputedStyle(corps) : null;
  const planchierCorps = styleCorps ? px(styleCorps.minHeight) : null;

  /* DIAGNOSTIC D'ÉCHELLE. Les hauteurs mesurées au rectangle (ce que l'œil voit)
     et les hauteurs CSS (`clientHeight`, contre lesquelles les plafonds/planchers
     `vh` sont évalués) ne coïncident pas ici. On relève le facteur et l'ancêtre
     qui le porte, pour que le rapport ne confonde jamais les deux unités. */
  let echelle = null;
  if (corps && corps.offsetHeight > 0) {
    const r = corps.getBoundingClientRect();
    // On remonte TOUTE la chaîne et on ignore les transformations IDENTITÉ
    // (`matrix(1, 0, 0, 1, 0, 0)`) : s'arrêter à la première rencontrée ferait
    // désigner un innocent et manquer le vrai responsable, plus haut.
    const porteurs = [];
    for (let el = corps; el; el = el.parentElement) {
      const st = getComputedStyle(el);
      const t = st.transform || 'none';
      const z = st.zoom || '1';
      const identite = t === 'none' || t === 'matrix(1, 0, 0, 1, 0, 0)';
      const zoomNeutre = z === '1' || z === 'normal' || z === '';
      if (!identite || !zoomNeutre) {
        porteurs.push({
          selecteur: el.tagName.toLowerCase()
            + (el.id ? '#' + el.id : '')
            + (el.className && typeof el.className === 'string' ? '.' + el.className.trim().split(/\s+/).slice(0, 3).join('.') : ''),
          transform: t,
          zoom: z,
        });
      }
      if (el === document.documentElement) break;
    }
    const porteur = porteurs.length ? porteurs : null;
    echelle = {
      facteur: Math.round((r.height / corps.offsetHeight) * 1000) / 1000,
      rect_hauteur: rd(r.height),
      offset_hauteur: rd(corps.offsetHeight),
      porteur,
      dpr: window.devicePixelRatio,
      viewport_visuel: window.visualViewport
        ? { echelle: window.visualViewport.scale, largeur: Math.round(window.visualViewport.width) }
        : null,
      racine_zoom: getComputedStyle(document.documentElement).zoom,
      corps_zoom: getComputedStyle(document.body).zoom,
    };
  }

  return {
    vue,
    echelle,
    champ_nom: champNom,
    panier: boite(panier),
    entete_panier: tete ? {
      ...boite(tete),
      hauteur_visible: rd(tete.clientHeight),
      hauteur_contenu: rd(tete.scrollHeight),
      pixels_caches: rd(Math.max(0, tete.scrollHeight - tete.clientHeight)),
      plafond_css: tete ? px(getComputedStyle(tete).maxHeight) : null,
      defile: tete ? getComputedStyle(tete).overflowY : null,
    } : null,
    corps_panier: corps ? {
      ...boite(corps),
      hauteur_visible: rd(corps.clientHeight),
      hauteur_contenu: rd(corps.scrollHeight),
      plancher_css_px: planchierCorps,
      plancher_css_brut: styleCorps ? styleCorps.minHeight : null,
      au_dessus_du_plancher: planchierCorps != null ? rd(corps.clientHeight) >= planchierCorps : null,
    } : null,
    pied_panier: pied ? {
      ...boite(pied),
      entierement_visible: pied.getBoundingClientRect().bottom <= vue.hauteur + 1,
      marge_sous_le_pied: rd(vue.hauteur - pied.getBoundingClientRect().bottom),
    } : null,
    grille_categories: grille ? {
      ...boite(grille),
      // « position du haut de la grille vs le bas de la fenêtre » : combien de
      // pixels de fenêtre restent SOUS le haut de la grille. Négatif ⇒ la grille
      // commence déjà hors écran.
      px_disponibles_sous_le_haut: rd(vue.hauteur - grille.getBoundingClientRect().top),
      tuiles: document.querySelectorAll('[data-testid="pos-category-tile"]').length,
      premiere_tuile: boite(document.querySelector('[data-testid="pos-category-tile"]')),
      derniere_tuile: boite(
        document.querySelectorAll('[data-testid="pos-category-tile"]')[
          document.querySelectorAll('[data-testid="pos-category-tile"]').length - 1
        ] || null,
      ),
    } : null,
    panneaux_suivi: raccourcis ? {
      ...boite(raccourcis),
      prets: boite(q('[data-testid="pos-shortcuts-ready"]')),
      borne: boite(q('[data-testid="pos-shortcuts-cash"]')),
      web: boite(q('[data-testid="pos-shortcuts-web"]')),
      web_payees: boite(q('[data-testid="pos-shortcuts-web-paid"]')),
      vides: {
        prets: !!q('[data-testid="pos-shortcuts-ready-empty"]'),
        borne: !!q('[data-testid="pos-shortcuts-cash-empty"]'),
        web: !!q('[data-testid="pos-shortcuts-web-empty"]'),
        web_payees: !!q('[data-testid="pos-shortcuts-web-paid-empty"]'),
      },
      textes_vides: {
        prets: (q('[data-testid="pos-shortcuts-ready-empty"]') || {}).textContent?.trim() || null,
        borne: (q('[data-testid="pos-shortcuts-cash-empty"]') || {}).textContent?.trim() || null,
        web: (q('[data-testid="pos-shortcuts-web-empty"]') || {}).textContent?.trim() || null,
        web_payees: (q('[data-testid="pos-shortcuts-web-paid-empty"]') || {}).textContent?.trim() || null,
      },
      titres: Array.from(document.querySelectorAll('[data-testid="pos-shortcuts"] .pos-shortcuts__title'))
        .map((h) => h.textContent.replace(/\s+/g, ' ').trim()),
    } : null,
    lignes_panier: document.querySelectorAll('.pos-v5-cart-item').length,
    total_affiche: (q('[data-testid="pos-grand-total"]') || {}).textContent?.replace(/\s+/g, ' ').trim() || null,
    debordement_horizontal: document.documentElement.scrollWidth > document.documentElement.clientWidth + 1,
    // Libellés bruts non traduits (Label.x, message.y, 0undefined…) visibles à l'écran.
    libelles_bruts: (document.body.innerText.match(/\b(?:label|message|button|menu|pos|a11y)\.[a-z0-9_.]+/gi) || [])
      .slice(0, 12),
  };
}

async function mesurer(page, etat) {
  const m = await page.evaluate(releverMesures);
  MESURES.push({ etat, ...m });
  fs.writeFileSync(
    path.join(SHOTS_DIR, `${etat}.mesures.json`),
    JSON.stringify(m, null, 2),
  );
  return m;
}

/* -------------------------------------------------------------------------- */
/* GARDE D'ENVIRONNEMENT — 2026-08-24.                                          */
/* Le `vendor/` du worktree a été trouvé amputé de 1 244 fichiers : l'autoloader */
/* échouait, l'application rendait un simple avertissement PHP… sous un HTTP 200. */
/* Une sonde de statut mentait donc, et une capture prise là-dessus aurait fait   */
/* accuser le produit d'un défaut d'environnement. On vérifie le CORPS, pas le    */
/* code de statut, avant toute photographie.                                      */
/* -------------------------------------------------------------------------- */
const SIGNATURES_PANNE = [
  /Warning:\s*require/i,
  /Fatal error/i,
  /Failed to open stream/i,
  /Uncaught\s+Error:\s*Failed opening required/i,
  /Whoops, looks like something went wrong/i,
];

async function garderContreAppCassee(page, ou) {
  const html = await page.content();
  const coupables = SIGNATURES_PANNE.filter((rx) => rx.test(html)).map(String);
  if (coupables.length > 0) {
    const extrait = html.replace(/\s+/g, ' ').slice(0, 900);
    throw new Error(
      `[GARDE ENVIRONNEMENT] Application cassée sur ${ou} — capture ABANDONNÉE. `
      + `Signatures : ${coupables.join(', ')}. Extrait : ${extrait}`,
    );
  }
  // Un HTML squelettique (< 2 Ko) sur une surface applicative est le second visage
  // de la même panne : l'autoloader meurt avant que Vue ne soit servi.
  if (html.length < 2000) {
    throw new Error(
      `[GARDE ENVIRONNEMENT] HTML anormalement court (${html.length} octets) sur ${ou} — `
      + `capture ABANDONNÉE. Extrait : ${html.replace(/\s+/g, ' ').slice(0, 600)}`,
    );
  }
}

let page;
let rec;

test.beforeAll(async ({ browser }) => {
  fs.mkdirSync(SHOTS_DIR, { recursive: true });
  const context = await browser.newContext({ viewport: GABARIT_COMPTOIR });
  page = await context.newPage();
  rec = attachMegaAuditRecorder(page, SHOTS_DIR);
  await page.goto('/login', { waitUntil: 'domcontentloaded' });
  await garderContreAppCassee(page, '/login (sonde d\u2019environnement)');
  await loginAsAdmin(page);
});

test.afterAll(async () => {
  fs.writeFileSync(
    path.join(SHOTS_DIR, '_mesures-vague-B.json'),
    JSON.stringify(MESURES, null, 2),
  );
  if (rec) rec.dispose();
  if (page) await page.context().close();
});

async function ouvrirCaisse(page) {
  await page.goto('/admin/pos', { waitUntil: 'domcontentloaded' });
  await garderContreAppCassee(page, '/admin/pos');
  await expect(page.locator('#pos-cart')).toBeVisible({ timeout: 45_000 });
  // Le panneau catégories arrive après le fetch des items : on attend le vrai contenu.
  await page.locator('[data-testid="pos-category-grid"], [data-testid="pos-shortcuts"]')
    .first().waitFor({ state: 'visible', timeout: 45_000 });
  await page.waitForTimeout(2500);
}

/* ═══════════════ ÉTAT 1 — /admin/pos au chargement, 1366×768 ═══════════════ */
test('B01 — /admin/pos au chargement (1366×768)', async () => {
  await page.setViewportSize(GABARIT_COMPTOIR);
  await ouvrirCaisse(page);
  await rec.snap('01-pos-chargement-1366x768');
  const m = await mesurer(page, '01-pos-chargement-1366x768');

  expect(m.vue, 'gabarit comptoir').toEqual({
    largeur: GABARIT_COMPTOIR.width, hauteur: GABARIT_COMPTOIR.height,
  });
  expect(m.entete_panier, 'en-tête du panier présent').not.toBeNull();
  expect(m.corps_panier, 'corps du panier présent').not.toBeNull();
  console.log('[B01]', JSON.stringify({
    entete: m.entete_panier, corps: m.corps_panier, pied: m.pied_panier,
    grille: m.grille_categories && {
      haut: m.grille_categories.haut,
      px_sous_le_haut: m.grille_categories.px_disponibles_sous_le_haut,
      tuiles: m.grille_categories.tuiles,
    },
    champ_nom: m.champ_nom,
  }, null, 2));
});

/* ═══════ ÉTAT 2 — 1024×600 : le champ « Nom du client » sans un geste ═══════ */
test('B02 — /admin/pos à 1024×600, champ « Nom du client » visible sans geste', async () => {
  await page.setViewportSize(GABARIT_TABLETTE);
  await ouvrirCaisse(page);
  await rec.snap('02-pos-chargement-1024x600');
  const m = await mesurer(page, '02-pos-chargement-1024x600');

  const champ = await page.evaluate(() => {
    const el = document.querySelector('[data-testid="pos-customer-name"]');
    if (!el) return { present: false };
    const r = el.getBoundingClientRect();
    return {
      present: true,
      rect: { haut: Math.round(r.top), bas: Math.round(r.bottom), hauteur: Math.round(r.height) },
      fenetre: window.innerHeight,
    };
  });
  fs.writeFileSync(
    path.join(SHOTS_DIR, '02-champ-nom-client.json'),
    JSON.stringify({ champ, mesure: m }, null, 2),
  );

  // CONSTAT, PAS CORRECTION : si le champ exige un geste, on le PROUVE en le
  // faisant apparaître par défilement et en photographiant les deux moments.
  const nom = page.locator('[data-testid="pos-customer-name"]');
  if (await nom.count()) {
    await nom.scrollIntoViewIfNeeded().catch(() => {});
    await page.waitForTimeout(600);
    await rec.snap('02b-pos-1024x600-apres-defilement-vers-champ-nom');
    await mesurer(page, '02b-pos-1024x600-apres-defilement-vers-champ-nom');
  }
  console.log('[B02] champ nom =', JSON.stringify(m.champ_nom ?? champ, null, 2));
});

/* ═════════ ÉTAT 3 — la grille des catégories aux DEUX gabarits ═════════ */
test('B03 — grille des catégories : atteignable sans défiler ?', async () => {
  const releves = {};

  for (const [nom, gabarit] of [['1366x768', GABARIT_COMPTOIR], ['1024x600', GABARIT_TABLETTE]]) {
    await page.setViewportSize(gabarit);
    await ouvrirCaisse(page);
    await rec.snap(`03-grille-categories-${nom}`);
    const m = await mesurer(page, `03-grille-categories-${nom}`);
    releves[nom] = m.grille_categories;

    // Combien de tuiles sont RÉELLEMENT dans la fenêtre, sans un geste ?
    const dansFenetre = await page.evaluate(() => {
      const tuiles = Array.from(document.querySelectorAll('[data-testid="pos-category-tile"]'));
      const h = window.innerHeight;
      return {
        total: tuiles.length,
        entierement_visibles: tuiles.filter((t) => {
          const r = t.getBoundingClientRect();
          return r.top >= 0 && r.bottom <= h;
        }).length,
        partiellement_visibles: tuiles.filter((t) => {
          const r = t.getBoundingClientRect();
          return r.bottom > 0 && r.top < h;
        }).length,
        noms: tuiles.slice(0, 12).map((t) => t.getAttribute('title') || t.innerText.trim()),
      };
    });
    releves[nom] = { ...(releves[nom] || {}), tuiles_dans_fenetre: dansFenetre };
  }

  fs.writeFileSync(
    path.join(SHOTS_DIR, '03-grille-categories.json'),
    JSON.stringify(releves, null, 2),
  );
  console.log('[B03]', JSON.stringify(releves, null, 2));
});

/* ═════════ ÉTAT 4 — les 4 panneaux de suivi et leurs états vides ═════════ */
test('B04 — panneaux « Prêts · À encaisser borne · Commandes web · Web payées »', async () => {
  await page.setViewportSize(GABARIT_COMPTOIR);
  await ouvrirCaisse(page);

  const raccourcis = page.locator('[data-testid="pos-shortcuts"]');
  await expect(raccourcis).toBeAttached({ timeout: 30_000 });
  await raccourcis.scrollIntoViewIfNeeded().catch(() => {});
  await page.waitForTimeout(1200);

  await rec.snap('04-panneaux-suivi-1366x768');
  const m = await mesurer(page, '04-panneaux-suivi-1366x768');

  const detail = await page.evaluate(() => {
    const ids = {
      prets: 'pos-shortcuts-ready',
      borne: 'pos-shortcuts-cash',
      web: 'pos-shortcuts-web',
      web_payees: 'pos-shortcuts-web-paid',
    };
    const out = {};
    for (const [cle, id] of Object.entries(ids)) {
      const el = document.querySelector(`[data-testid="${id}"]`);
      if (!el) { out[cle] = { monte: false }; continue; }
      const r = el.getBoundingClientRect();
      out[cle] = {
        monte: true,
        titre: (el.querySelector('.pos-shortcuts__title') || {}).textContent?.replace(/\s+/g, ' ').trim() || null,
        aria_label: el.getAttribute('aria-label'),
        classe_vide: el.className.includes('pos-shortcuts__panel--empty'),
        lignes: el.querySelectorAll('.pos-shortcuts__item').length,
        texte_vide: (el.querySelector('[data-testid$="-empty"]') || {}).textContent?.trim() || null,
        horodatage: (el.querySelector('[data-testid$="-refresh"]') || {}).textContent?.trim() || null,
        rect: { haut: Math.round(r.top), bas: Math.round(r.bottom), largeur: Math.round(r.width), hauteur: Math.round(r.height) },
        dans_fenetre: r.top >= 0 && r.bottom <= window.innerHeight,
      };
    }
    return out;
  });
  fs.writeFileSync(path.join(SHOTS_DIR, '04-panneaux-suivi.json'), JSON.stringify(detail, null, 2));
  console.log('[B04]', JSON.stringify(detail, null, 2));

  // Les 4 panneaux DOIVENT être montés (Q10 : toujours rendus, même vides).
  expect(detail.prets.monte, 'panneau « Prêts » monté').toBe(true);
  expect(detail.borne.monte, 'panneau « À encaisser borne » monté').toBe(true);
  expect(m.panneaux_suivi, 'conteneur des raccourcis').not.toBeNull();
});

/* ═══ ÉTAT 4bis — les 4 états VIDES, forcés SANS toucher aux données ═══ */
/* Deux des quatre panneaux sont peuplés dans cet environnement (comptoir : 2,
   web : 77). Pour photographier leur état vide sans supprimer une seule ligne
   en base, on intercepte les DEUX réponses côté navigateur et on les rend vides.
   C'est une lentille posée sur le transport, pas une mutation : rien n'est écrit,
   et l'interception est retirée juste après. */
test('B04bis — les quatre états VIDES (réponses vidées côté navigateur)', async () => {
  await page.setViewportSize(GABARIT_COMPTOIR);

  const vider = async (route) => {
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({ data: [] }),
    });
  };
  await page.route('**/admin/pos/counter-collect/pending*', vider);
  await page.route('**/admin/pos/web-orders/pending*', vider);
  await page.route('**/admin/pos/web-orders/paid*', vider);

  try {
    await ouvrirCaisse(page);
    await page.locator('[data-testid="pos-shortcuts"]').scrollIntoViewIfNeeded().catch(() => {});
    await page.waitForTimeout(3000);
    await rec.snap('04b-panneaux-suivi-etats-vides');
    const m = await mesurer(page, '04b-panneaux-suivi-etats-vides');

    const vides = await page.evaluate(() => {
      const ids = ['ready', 'cash', 'web', 'web-paid'];
      const out = {};
      for (const id of ids) {
        const panneau = document.querySelector(`[data-testid="pos-shortcuts-${id}"]`);
        const vide = document.querySelector(`[data-testid="pos-shortcuts-${id}-empty"]`);
        out[id] = {
          panneau_monte: !!panneau,
          classe_vide: panneau ? panneau.className.includes('pos-shortcuts__panel--empty') : null,
          titre: panneau ? (panneau.querySelector('.pos-shortcuts__title') || {}).textContent?.replace(/\s+/g, ' ').trim() : null,
          message_vide: vide ? vide.textContent.trim() : null,
          horodatage: panneau ? (panneau.querySelector('[data-testid$="-refresh"]') || {}).textContent?.trim() || null : null,
        };
      }
      return out;
    });
    fs.writeFileSync(path.join(SHOTS_DIR, '04b-etats-vides.json'), JSON.stringify(vides, null, 2));
    console.log('[B04bis]', JSON.stringify(vides, null, 2));

    for (const id of ['ready', 'cash', 'web', 'web-paid']) {
      expect(vides[id].panneau_monte, `panneau ${id} monté`).toBe(true);
      expect(vides[id].message_vide, `message d'état vide du panneau ${id}`).not.toBeNull();
    }
    expect(m.panneaux_suivi.vides, 'les quatre états vides simultanés').toEqual({
      prets: true, borne: true, web: true, web_payees: true,
    });
  } finally {
    await page.unroute('**/admin/pos/counter-collect/pending*').catch(() => {});
    await page.unroute('**/admin/pos/web-orders/pending*').catch(() => {});
    await page.unroute('**/admin/pos/web-orders/paid*').catch(() => {});
  }
});

/* ═══════════════════ ÉTAT 6 — /admin/pos-v4 au chargement ═══════════════════ */
test('B06 — /admin/pos-v4 au chargement', async () => {
  await page.setViewportSize(GABARIT_COMPTOIR);
  const reponse = await page.goto('/admin/pos-v4', { waitUntil: 'domcontentloaded' });
  await garderContreAppCassee(page, '/admin/pos-v4');
  await page.waitForTimeout(6000);
  await rec.snap('06-pos-v4-1366x768');

  const etat = await page.evaluate(() => ({
    url: location.href,
    titre: document.title,
    statut_visible: document.body.innerText.replace(/\s+/g, ' ').trim().slice(0, 400),
    a_panier: !!document.querySelector('#pos-cart'),
    a_wizard_script: Array.from(document.querySelectorAll('script[src]'))
      .map((s) => s.getAttribute('src')).filter((s) => /pos-wizard/.test(s || '')),
    a_grille_categories: !!document.querySelector('[data-testid="pos-category-grid"]'),
    debordement_horizontal: document.documentElement.scrollWidth > document.documentElement.clientWidth + 1,
  }));
  fs.writeFileSync(
    path.join(SHOTS_DIR, '06-pos-v4.json'),
    JSON.stringify({ http: reponse ? reponse.status() : null, etat }, null, 2),
  );
  await mesurer(page, '06-pos-v4-1366x768');
  console.log('[B06]', JSON.stringify({ http: reponse ? reponse.status() : null, etat }, null, 2));
});

/* ═══════════════════ ÉTAT 7 — /admin/pos/floorplan ═══════════════════ */
test('B07 — /admin/pos/floorplan', async () => {
  await page.setViewportSize(GABARIT_COMPTOIR);
  const reponse = await page.goto('/admin/pos/floorplan', { waitUntil: 'domcontentloaded' });
  await garderContreAppCassee(page, '/admin/pos/floorplan');
  await page.waitForTimeout(6000);
  await rec.snap('07-floorplan-1366x768');

  const etat = await page.evaluate(() => ({
    url: location.href,
    texte: document.body.innerText.replace(/\s+/g, ' ').trim().slice(0, 500),
    // [AUDIT-SUPERVISEUR 2026-08-25 · AB-014] Ce compteur MENTAIT d'un facteur 24 : le
    // sélecteur `[class*="table"]` attrapait toutes les classes contenant « table »
    // (db-table-responsive, db-table-head, db-table-body-tr…) sur une page qui affiche
    // UNE table de salle. Une donnée de synthèse dont le nom ne décrit pas ce qu'elle
    // compte est pire qu'une donnée absente : une porte de validation qui la lit croit
    // mesurer le plan de salle.
    tables: document.querySelectorAll('.pos-v5-floorplan-table').length,
    debordement_horizontal: document.documentElement.scrollWidth > document.documentElement.clientWidth + 1,
  }));
  fs.writeFileSync(
    path.join(SHOTS_DIR, '07-floorplan.json'),
    JSON.stringify({ http: reponse ? reponse.status() : null, etat }, null, 2),
  );
  console.log('[B07]', JSON.stringify({ http: reponse ? reponse.status() : null, etat }, null, 2));
});

/* ═══════ ÉTAT 8 — la barre de navigation secondaire caisse ═══════ */
test('B08 — CaisseSecondaryNav et ses liens', async () => {
  await page.setViewportSize(GABARIT_COMPTOIR);
  await page.goto('/admin/encaissement', { waitUntil: 'domcontentloaded' });
  await garderContreAppCassee(page, '/admin/encaissement');
  await page.waitForTimeout(6000);
  await rec.snap('08-caisse-secondary-nav-1366x768');

  const nav = await page.evaluate(() => {
    const el = document.querySelector('[data-testid="caisse-secondary-nav"]');
    if (!el) return { monte: false, url: location.href };
    const r = el.getBoundingClientRect();
    return {
      monte: true,
      url: location.href,
      aria_label: el.getAttribute('aria-label'),
      rect: { haut: Math.round(r.top), bas: Math.round(r.bottom), largeur: Math.round(r.width), hauteur: Math.round(r.height) },
      dans_fenetre: r.top >= 0 && r.bottom <= window.innerHeight,
      liens: Array.from(el.querySelectorAll('a')).map((a) => ({
        testid: a.getAttribute('data-testid'),
        libelle: a.innerText.replace(/\s+/g, ' ').trim(),
        href: a.getAttribute('href'),
        cible: a.getAttribute('target'),
        actif: a.className.includes('active'),
        aria_current: a.getAttribute('aria-current'),
      })),
    };
  });
  fs.writeFileSync(path.join(SHOTS_DIR, '08-caisse-secondary-nav.json'), JSON.stringify(nav, null, 2));
  console.log('[B08]', JSON.stringify(nav, null, 2));

  expect(nav.monte, 'CaisseSecondaryNav monté sur /admin/encaissement').toBe(true);
});

/* ══════════════ ÉTAT 5 — un panier composé (≥ 2 produits) ══════════════ */
/* Placé EN DERNIER volontairement. C'est le seul état qui pilote l'assistant
   produit (zone gelée `public/js/pos-wizard.js`, lu, jamais modifié) : c'est donc
   le plus lent et le plus susceptible de traîner. En queue de suite, un incident
   ici ne prive plus le rapport des sept autres états. Budget dur ci-dessous. */

const BUDGET_PANIER_MS = 120_000;

/**
 * Photographie l'état interne de l'assistant produit, sans rien modifier.
 * Rendu de PRODUCTION = « S25-SinglePage » : tout tient sur une page, avec un
 * badge de quota `.quota-badge` (« 0/1 incluse ») et des pastilles `.sauce-chip`
 * — et NON le rendu multi-étapes (`.wizard-option[data-type="sauce"]`), qui
 * n'est jamais servi en caisse. Se tromper de rendu, c'est croire l'assistant
 * cassé alors qu'il refuse simplement une composition incomplète.
 */
function etatAssistant() {
  const q = (s) => document.querySelector(s);
  const badge = q('.viande-section .quota-badge');
  return {
    section_viande: !!q('.viande-section'),
    quota: badge ? badge.textContent.replace(/\s+/g, ' ').trim() : null,
    quota_complet: badge ? badge.className.includes('complete') : null,
    tuiles_viande: document.querySelectorAll('.wizard-viande-tile').length,
    tuiles_viande_actives: document.querySelectorAll('.wizard-viande-tile.active').length,
    sauces: document.querySelectorAll('.sauce-chip').length,
    sauces_choisies: document.querySelectorAll('.sauce-chip.selected').length,
    crudites: document.querySelectorAll('.garniture-toggle-btn').length,
    total_assistant: (q('.sticky-total .total-value') || {}).textContent?.trim() || null,
    apercu_ticket: (q('.wizard-ticket-preview .ticket-content') || {}).textContent?.replace(/\s+/g, ' ').trim().slice(0, 200) || null,
  };
}

/**
 * Compose l'assistant comme le ferait un caissier : viande(s) jusqu'au quota,
 * une sauce, puis « Ajouter au panier ». Aucune API n'est appelée directement —
 * tout passe par des clics réels. Les échecs de clic sont CONSIGNÉS, jamais
 * avalés : un clic silencieusement perdu ferait accuser le produit à tort.
 * @returns {{issue: string, diag: object}}
 */
async function composerAssistant(page) {
  const diag = { erreurs: [] };
  const bouton = page.locator('[data-action="add-to-cart"]').first();
  if (!(await bouton.isVisible({ timeout: 4000 }).catch(() => false))) {
    return { issue: 'pas-d-assistant', diag };
  }
  diag.avant = await page.evaluate(etatAssistant);

  const tuiles = page.locator('.wizard-viande-tile button[data-action="plus"]');
  for (let k = 0; k < 4; k += 1) {
    const etat = await page.evaluate(etatAssistant);
    if (!etat.section_viande || etat.quota_complet) break;
    if (!(await tuiles.count().catch(() => 0))) break;
    try {
      await tuiles.first().click({ timeout: 5000 });
    } catch (e) {
      diag.erreurs.push('viande: ' + String(e && e.message).replace(/\s+/g, ' ').slice(0, 220));
      break;
    }
    await page.waitForTimeout(500);
  }
  diag.apres_viande = await page.evaluate(etatAssistant);

  const sauce = page.locator('.sauce-chip').first();
  if (await sauce.count().catch(() => 0)) {
    try {
      await sauce.click({ timeout: 4000 });
    } catch (e) {
      diag.erreurs.push('sauce: ' + String(e && e.message).replace(/\s+/g, ' ').slice(0, 220));
    }
    await page.waitForTimeout(400);
  }
  diag.apres_sauce = await page.evaluate(etatAssistant);

  try {
    await bouton.click({ timeout: 6000 });
  } catch (e) {
    diag.erreurs.push('ajouter: ' + String(e && e.message).replace(/\s+/g, ' ').slice(0, 220));
  }
  const referme = await bouton.waitFor({ state: 'hidden', timeout: 9000 })
    .then(() => true).catch(() => false);

  if (!referme) {
    // L'assistant REFUSE : `singlePageCanAddToCart()` bloque tant qu'une étape
    // requise manque (pos-wizard.js, garde LOCK 2026-07-04). C'est le BON
    // comportement produit — on sort sans forcer, et on note pourquoi.
    diag.au_refus = await page.evaluate(etatAssistant);
    await page.locator('[data-action="cancel-wizard"], .wizard-btn-cancel').first()
      .click({ timeout: 4000 }).catch(() => {});
    await page.keyboard.press('Escape').catch(() => {});
    await page.waitForTimeout(900);
    return { issue: 'assistant-refuse', diag };
  }
  await page.waitForTimeout(1200);
  return { issue: 'assistant-valide', diag };
}

test('B05 — panier composé : au moins 2 produits avec options', async () => {
  test.setTimeout(240_000);
  await page.setViewportSize(GABARIT_COMPTOIR);
  await ouvrirCaisse(page);

  const echeance = Date.now() + BUDGET_PANIER_MS;
  const journal = [];
  const lignes = () => page.locator('.pos-v5-cart-item').count();
  const fichier = path.join(SHOTS_DIR, '05-panier-compose.json');
  // Le journal est écrit APRÈS CHAQUE PRODUIT. Si le budget expire ou si la
  // suite est interrompue, la preuve reste sur le disque : un audit qui ne
  // laisse aucune trace de ses tentatives ne vaut rien.
  const consigner = (extra) => fs.writeFileSync(
    fichier,
    JSON.stringify({ budget_restant_ms: echeance - Date.now(), journal, ...(extra || {}) }, null, 2),
  );

  const nbCategories = await page.locator('[data-testid="pos-category-tile"]').count();
  journal.push({ etape: 'grille', categories: nbCategories });
  consigner();

  let ajoutes = 0;
  let assistantPhotographie = false;

  for (let c = 0; c < Math.min(nbCategories, 4) && ajoutes < 2 && Date.now() < echeance; c += 1) {
    // Après un ajout, le composant REVIENT de lui-même à la grille des catégories
    // (`onProductAddedReturnToCategories`). Si ce n'est pas le cas, on y retourne.
    const retour = page.locator('[data-testid="pos-browse-back"]');
    if (await retour.isVisible({ timeout: 1200 }).catch(() => false)) {
      await retour.click({ timeout: 3000 }).catch(() => {});
      await page.waitForTimeout(800);
    }

    const cat = page.locator('[data-testid="pos-category-tile"]').nth(c);
    const nomCat = await cat.getAttribute('title', { timeout: 4000 }).catch(() => null);
    await cat.click({ timeout: 6000 }).catch(() => {});
    await page.waitForTimeout(1500);

    const produits = page.locator('button.pos-v5-tile[data-pos-item-id]:not([disabled])');
    const nbProduits = await produits.count().catch(() => 0);
    journal.push({ etape: 'categorie', index: c, nom: nomCat, produits: nbProduits });
    consigner();

    for (let i = 0; i < Math.min(nbProduits, 2) && ajoutes < 2 && Date.now() < echeance; i += 1) {
      // Après un ajout réussi, le composant retourne DE LUI-MÊME à la grille des
      // catégories : les tuiles produits n'existent plus. Sans ce garde-fou, le
      // locator suivant attend une tuile qui ne reviendra jamais.
      if ((await produits.count().catch(() => 0)) <= i) break;
      const avant = await lignes();
      const nomProduit = await produits.nth(i).innerText({ timeout: 3000 })
        .then((t) => String(t).replace(/\s+/g, ' ').trim().slice(0, 60))
        .catch(() => '');
      await produits.nth(i).click({ timeout: 6000 }).catch(() => {});
      await page.waitForTimeout(1400);

      if (!assistantPhotographie) {
        const visible = await page.locator('[data-action="add-to-cart"]').first()
          .isVisible({ timeout: 2000 }).catch(() => false);
        if (visible) {
          await rec.snap('05a-assistant-produit-ouvert');
          assistantPhotographie = true;
        }
      }

      const { issue, diag } = await composerAssistant(page);
      const apres = await lignes();
      journal.push({
        etape: 'produit', categorie: nomCat, produit: nomProduit,
        issue, lignes_avant: avant, lignes_apres: apres, diagnostic: diag,
      });
      consigner();
      if (apres > avant) {
        ajoutes += 1;
        break; // retour automatique à la grille : on change de catégorie
      }
    }
  }

  await page.waitForTimeout(1000);
  await rec.snap('05-panier-compose-1366x768');
  // Vue rapprochée du seul panneau panier : c'est là que se juge la mise en page.
  await page.locator('#pos-cart').screenshot({
    path: path.join(SHOTS_DIR, '05b-panneau-panier-plein.png'),
  }).catch(() => {});
  const m = await mesurer(page, '05-panier-compose-1366x768');

  const contenu = await page.evaluate(() => {
    const l = Array.from(document.querySelectorAll('.pos-v5-cart-item')).map((el) => ({
      texte: el.innerText.replace(/\s+/g, ' ').trim().slice(0, 240),
      detail_compact: !!el.querySelector('[data-testid="pos-cart-compact-detail"]'),
      extras_groupes: !!el.querySelector('[data-testid="pos-cart-bundled-extras"]'),
    }));
    const corps = document.querySelector('.pos-v5-cart__body');
    /* CHEVAUCHEMENT. Au chargement (panier vide) l'en-tête tient dans sa boîte.
       Dès qu'un article entre, le corps réclame son plancher, le pied ne cède
       jamais, et l'en-tête — `flex: 0 1 auto` — est COMPRIMÉ. Or au-dessus de
       760 px de haut il est en `overflow: visible` : son contenu n'est pas
       rogné, il se PEINT par-dessus le corps et le pied. On mesure de combien. */
    const tete = document.querySelector('.pos-v5-cart__head');
    const pied = document.querySelector('.pos-v5-cart__foot');
    let chevauchement = null;
    if (tete && corps) {
      const rt = tete.getBoundingClientRect();
      const rc = corps.getBoundingClientRect();
      const rp = pied ? pied.getBoundingClientRect() : null;
      const st = getComputedStyle(tete);
      /* DEUX UNITÉS, JAMAIS MÉLANGÉES. `body { zoom: 0.9 }` (helper caisseZoom.js,
         mandat propriétaire) fait que `scrollHeight`/`clientHeight` sont en pixels
         CSS tandis que `getBoundingClientRect()` rend des pixels ÉCRAN. Additionner
         `rect.top + scrollHeight` donnerait un chiffre faux de ~11 %. On mesure donc
         le bas RÉEL du contenu de l'en-tête par le rectangle de son DERNIER ENFANT :
         pixels écran de bout en bout, rien à convertir, rien à supposer. */
      const enfants = Array.from(tete.children).filter((e) => e.getBoundingClientRect().height > 0);
      const dernier = enfants.length ? enfants[enfants.length - 1] : null;
      const basContenuEcran = dernier ? dernier.getBoundingClientRect().bottom : rt.bottom;
      chevauchement = {
        unite: 'pixels écran (le zoom 0.9 de la caisse est déjà appliqué)',
        entete_boite_px: Math.round(rt.height),
        entete_contenu_px: Math.round(basContenuEcran - rt.top),
        entete_deborde_de_px: Math.round(Math.max(0, basContenuEcran - rt.bottom)),
        entete_overflow: st.overflow + ' / ' + st.overflowY,
        entete_max_height: st.maxHeight,
        bas_du_contenu_entete: Math.round(basContenuEcran),
        haut_du_corps: Math.round(rc.top),
        bas_du_corps: Math.round(rc.bottom),
        px_de_recouvrement_sur_le_corps: Math.round(
          Math.max(0, Math.min(basContenuEcran, rc.bottom) - rc.top),
        ),
        corps_recouvert_en_totalite: basContenuEcran >= rc.bottom - 1,
        haut_du_pied: rp ? Math.round(rp.top) : null,
        px_de_recouvrement_sur_le_pied: rp
          ? Math.round(Math.max(0, Math.min(basContenuEcran, rp.bottom) - rp.top)) : null,
        // Rappel des mêmes hauteurs en pixels CSS, pour comparer aux règles `vh`.
        css: {
          entete_client: Math.round(tete.clientHeight),
          entete_scroll: Math.round(tete.scrollHeight),
          corps_client: Math.round(corps.clientHeight),
          corps_min_height: getComputedStyle(corps).minHeight,
        },
      };
    }
    return {
      lignes: l,
      chevauchement,
      corps_doit_defiler: corps ? corps.scrollHeight > corps.clientHeight + 1 : null,
      corps_visible_css: corps ? Math.round(corps.clientHeight) : null,
      corps_contenu_css: corps ? Math.round(corps.scrollHeight) : null,
      total: (document.querySelector('[data-testid="pos-grand-total"]') || {}).innerText?.replace(/\s+/g, ' ').trim() || null,
    };
  });

  consigner({ ajoutes, budget_epuise: Date.now() >= echeance, contenu, mesure: m });
  console.log('[B05]', JSON.stringify({ ajoutes, contenu, journal }, null, 2));

  expect(m.corps_panier, 'corps du panier présent').not.toBeNull();
  expect(ajoutes, 'au moins 2 produits ont rejoint le panier').toBeGreaterThanOrEqual(2);
});
