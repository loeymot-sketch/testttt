#!/usr/bin/env node
// ===========================================================================
// GATE DE PARITÉ B3 — mirrors standalone (web + mobile) vs fixture canonique borne
// [GOAL-SYNC 2026-07-08]
//
// Compare les data mirrors menu.js (IIFE vanilla, évalués dans un vm avec stub
// window) à la fixture canonique extraite de la borne
// (reports/goal-web-app-sync/catalog-canonical.json — GET /api/frontend/menu).
//
// ⚠️ Ce gate encode l'ÉTAT CIBLE du contrat (CONTRACTS.md §5) : il peut être
//    ROUGE tant que les mirrors sont en cours de correction — c'est voulu.
//
// Node ≥ 18, ZÉRO dépendance npm. Lecture seule sur les mirrors.
//
// Usage :
//   node tools/parity/check-parity.mjs --surface=web|mobile|all [--fixture=<json>]
//        [--mirror=<menu.js>] [--report-dir=<dir>]
//   --mirror n'est valide qu'avec une surface unique (auto-test sur copie mutée).
//
// Sortie : reports/goal-web-app-sync/parity-report-<surface>.json + résumé console.
// Exit   : 0 seulement si 0 divergence sur toutes les surfaces demandées.
// ===========================================================================

import fs from 'node:fs';
import path from 'node:path';
import vm from 'node:vm';
import process from 'node:process';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const BACKEND = path.resolve(__dirname, '..', '..');

// Chemins par défaut (CONTRACTS.md §0)
const DEFAULTS = {
  fixture: path.join(BACKEND, 'reports', 'goal-web-app-sync', 'catalog-canonical.json'),
  reportDir: path.join(BACKEND, 'reports', 'goal-web-app-sync'),
  mirrors: {
    web: '/Users/1millnonstop/Downloads/web/data/menu.js',
    mobile: path.join(BACKEND, 'mobile', 'data', 'menu.js'),
  },
};

// ---------------------------------------------------------------------------
// CLI
// ---------------------------------------------------------------------------
function parseArgs(argv) {
  const args = { surface: 'all', fixture: DEFAULTS.fixture, mirror: null, reportDir: DEFAULTS.reportDir };
  for (const a of argv.slice(2)) {
    const m = /^--([a-z-]+)=(.*)$/.exec(a);
    if (!m) { fail(2, `Argument inconnu : ${a}`); }
    const [, k, v] = m;
    if (k === 'surface') args.surface = v;
    else if (k === 'fixture') args.fixture = path.resolve(v);
    else if (k === 'mirror') args.mirror = path.resolve(v);
    else if (k === 'report-dir') args.reportDir = path.resolve(v);
    else fail(2, `Option inconnue : --${k}`);
  }
  if (!['web', 'mobile', 'all'].includes(args.surface)) {
    fail(2, `--surface doit être web|mobile|all (reçu : ${args.surface})`);
  }
  if (args.mirror && args.surface === 'all') {
    fail(2, `--mirror exige une surface unique (--surface=web ou --surface=mobile)`);
  }
  return args;
}

function fail(code, msg) {
  console.error(`[parity] ERREUR : ${msg}`);
  process.exit(code);
}

// ---------------------------------------------------------------------------
// norm() — copie EXACTE de /Users/1millnonstop/Downloads/web/api.js:158
// (lowercase, sans accents, strip préfixe "sauce ", espaces normalisés)
// ---------------------------------------------------------------------------
function norm(s) {
  return String(s == null ? '' : s)
    .toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '')
    .replace(/^sauce\s+/, '').replace(/\s+/g, ' ').trim();
}

// Prix en centimes (comparaison au centime, jamais en float)
function cents(v) {
  const n = Number(v);
  return Number.isFinite(n) ? Math.round(n * 100) : NaN;
}
function eur(c) { return (c / 100).toFixed(2).replace('.', ',') + ' €'; }

// ---------------------------------------------------------------------------
// Modèle canonique — dérivé de la fixture (la fixture fait loi)
// ---------------------------------------------------------------------------
function buildCanon(fixturePath) {
  const raw = JSON.parse(fs.readFileSync(fixturePath, 'utf8'));
  const d = raw && raw.data;
  if (!d || !Array.isArray(d.items) || !Array.isArray(d.categories)) {
    fail(2, `Fixture invalide (data.items / data.categories absents) : ${fixturePath}`);
  }

  const catSlugById = {};
  d.categories.forEach((c) => { catSlugById[c.id] = c.slug; });

  const itemsByCat = {};   // slug -> [{name, norm, cents, avail, halal, spicy}]
  const allByNorm = new Map(); // norm -> {name, catSlug, cents, avail}
  const drinkCentsByNorm = new Map(); // boissons : norm name -> cents
  const meats = new Set();
  const sauces = new Set();      // 12 sauces (attribut "Sauce (1ère Gratuite)")
  const bolSaucesExact = new Set(); // 2 noms EXACTS (attribut "Sauce bol")
  let crudites = null;           // 4 crudités (extras group_label=crudite des tacos)
  const supplements090 = new Set(); // 9 suppléments à 0,90 €
  let bouleGratineeCents = null;
  let extraMeatCents = null;

  d.items.forEach((it) => {
    const catSlug = catSlugById[it.category_id] || `cat-${it.category_id}`;
    const entry = {
      name: it.name,
      norm: norm(it.name),
      cents: cents(it.flat_price != null ? it.flat_price : it.price),
      avail: it.is_available !== false,
      halal: it.is_halal === true || it.is_halal === 1,
      spicy: it.is_spicy === true || it.is_spicy === 1,
    };
    (itemsByCat[catSlug] = itemsByCat[catSlug] || []).push(entry);
    allByNorm.set(entry.norm, { ...entry, catSlug });
    if (catSlug === 'boissons') drinkCentsByNorm.set(entry.norm, entry.cents);

    (it.itemAttributes || []).forEach((a) => {
      const opts = (it.variations || []).filter((v) => v.attribute_id === a.id).map((v) => v.name);
      if (/^viande/i.test(a.name)) opts.forEach((n) => meats.add(norm(n)));
      else if (/^sauce/i.test(a.name) && !/bol/i.test(a.name)) opts.forEach((n) => sauces.add(norm(n)));
      else if (/^sauce/i.test(a.name) && /bol/i.test(a.name)) opts.forEach((n) => bolSaucesExact.add(n));
    });

    const cruds = (it.extras || []).filter((e) => e.group_label === 'crudite').map((e) => norm(e.name));
    // Les tacos (items 26/97) portent les 4 crudités canoniques dont "Oignons cuits"
    if (catSlug === 'tacos' && cruds.length && (crudites === null || cruds.length > crudites.size)) {
      crudites = new Set(cruds);
    }

    (it.extras || []).forEach((e) => {
      if (e.group_label !== 'supplement') return;
      const c = cents(e.price);
      const n = norm(e.name);
      if (c === 90) supplements090.add(n);
      if (n === 'boule gratinee') bouleGratineeCents = c;
      if (n === 'viande supplementaire') extraMeatCents = c;
    });
  });

  const fritesCents = new Set((itemsByCat['frites'] || []).map((e) => e.cents));

  return {
    path: fixturePath,
    categoriesCount: d.categories.length,
    itemsCount: d.items.length,
    catSlugById,
    itemsByCat,
    allByNorm,
    drinkCentsByNorm,
    meats,
    sauces,
    bolSaucesExact,
    crudites: crudites || new Set(),
    supplements090,
    bouleGratineeCents,
    extraMeatCents,
    fritesCents,
  };
}

// ---------------------------------------------------------------------------
// Évaluation d'un mirror IIFE dans un vm (stub window + document)
// ---------------------------------------------------------------------------
function loadMirror(file) {
  const code = fs.readFileSync(file, 'utf8');
  const sandbox = {
    window: {},
    document: { querySelector: () => null },
    console: { log() {}, warn() {}, error() {}, info() {} },
    navigator: { userAgent: 'parity-gate' },
  };
  vm.createContext(sandbox);
  vm.runInContext(code, sandbox, { filename: path.basename(file), timeout: 10000 });
  return sandbox.window;
}

// ---------------------------------------------------------------------------
// Comparaison — chaque divergence = {check, sujet, attendu, constate, detail}
// ---------------------------------------------------------------------------
function compareSurface(surface, mirrorPath, canon) {
  const divs = [];
  const div = (check, sujet, attendu, constate, detail) =>
    divs.push({ check, sujet, attendu, constate, ...(detail ? { detail } : {}) });

  let win;
  try {
    win = loadMirror(mirrorPath);
  } catch (e) {
    div('mirror_eval', mirrorPath, 'évaluation IIFE sans erreur', `crash : ${e.message}`);
    return { surface, mirrorPath, divs, mirrorStats: null };
  }

  const lc = win.LC && win.LC.menu;
  if (!lc || !Array.isArray(lc.items)) {
    div('export_lc_menu', 'window.LC.menu', 'objet exporté avec items[]', lc ? 'items[] absent' : 'window.LC.menu absent');
    return { surface, mirrorPath, divs, mirrorStats: null };
  }

  // --- Export legacy (web: W_ITEMS / mobile: ITEMS) ------------------------
  const legacy = surface === 'web' ? win.W_ITEMS : win.ITEMS;
  const legacyName = surface === 'web' ? 'window.W_ITEMS' : 'window.ITEMS';
  if (!Array.isArray(legacy)) {
    div('export_legacy', legacyName, 'tableau exporté', 'absent');
  } else if (legacy.length !== lc.items.length) {
    div('export_legacy', legacyName, `${lc.items.length} items (= LC.menu.items)`, `${legacy.length} items`);
  }

  // --- Index mirror --------------------------------------------------------
  const mirrorCatSlug = {};
  (lc.categories || []).forEach((c) => { mirrorCatSlug[c.id] = c.slug; });
  const mirrorByCat = {};        // slug -> Map(norm -> item)
  const mirrorAllByNorm = new Map(); // norm -> {item, catSlug}
  lc.items.forEach((it) => {
    const slug = mirrorCatSlug[it.category_id] || 'inconnu';
    (mirrorByCat[slug] = mirrorByCat[slug] || new Map()).set(norm(it.name), it);
    mirrorAllByNorm.set(norm(it.name), { it, catSlug: slug });
  });
  const mirrorAvail = (it) => it.is_available !== false;

  // --- 1) Items par catégorie (par NOM normalisé) — EXCEPTION frites -------
  for (const [catSlug, canonItems] of Object.entries(canon.itemsByCat)) {
    if (catSlug === 'frites') continue; // divergence structurelle acceptée → atteignabilité (cf. 2)
    const mirrorCat = mirrorByCat[catSlug] || new Map();
    for (const ci of canonItems) {
      const m = mirrorCat.get(ci.norm);
      if (!m) {
        const elsewhere = mirrorAllByNorm.get(ci.norm);
        if (elsewhere) {
          div('item_mauvaise_categorie', ci.name, `catégorie « ${catSlug} »`, `catégorie « ${elsewhere.catSlug} »`);
        } else {
          div('item_manquant', ci.name, `présent dans « ${catSlug} » à ${eur(ci.cents)}`, 'absent du mirror');
        }
        continue;
      }
      const mc = cents(m.price);
      if (mc !== ci.cents) {
        div('item_prix', ci.name, eur(ci.cents), Number.isNaN(mc) ? `prix invalide (${m.price})` : eur(mc));
      }
      if (mirrorAvail(m) !== ci.avail) {
        div('item_disponibilite', ci.name, `is_available=${ci.avail}`, `is_available=${mirrorAvail(m)}`);
      }
      // is_halal : allégation réglementaire — le mirror ne doit JAMAIS déclarer
      // halal un produit que le canonique ne déclare pas (CONTRACTS.md §5).
      if (m.is_halal === true && !ci.halal) {
        div('item_halal', ci.name, 'is_halal=false (canonique)', 'is_halal=true (mirror)');
      }
      if ((m.is_spicy === true) !== ci.spicy) {
        div('item_spicy', ci.name, `is_spicy=${ci.spicy}`, `is_spicy=${m.is_spicy === true}`);
      }
    }
    // Produits inventés / hors canon dans cette catégorie
    for (const [n, m] of mirrorCat) {
      if (!canon.allByNorm.has(n)) {
        div('item_invente', m.name, 'aucun (la fixture fait loi)', `présent dans « ${catSlug} » à ${eur(cents(m.price))}`);
      }
      // (mauvaise catégorie déjà signalée côté canonique)
    }
  }

  // --- 2) Frites : ATTEIGNABILITÉ des 6 prix canoniques via base + styles --
  {
    const fritesItems = [...(mirrorByCat['frites'] || new Map()).values()];
    const styles = Array.isArray(lc.fritesStyles) ? lc.fritesStyles : [];
    if (!fritesItems.length) {
      div('frites_atteignabilite', 'catégorie frites', 'items frites présents', 'aucun item frites dans le mirror');
    } else if (!styles.length) {
      div('frites_atteignabilite', 'FRITES_STYLES', 'pool de styles présent', 'absent/vide');
    } else {
      const reachable = new Set();
      fritesItems.forEach((b) => styles.forEach((s) => reachable.add(cents(b.price) + cents(s.price || 0))));
      for (const c of canon.fritesCents) {
        if (!reachable.has(c)) {
          div('frites_atteignabilite', `prix canonique ${eur(c)}`, 'atteignable via base + style', `inatteignable (bases×styles = ${[...reachable].sort((a, b) => a - b).map(eur).join(' / ')})`);
        }
      }
      for (const c of reachable) {
        if (!canon.fritesCents.has(c)) {
          div('frites_prix_hors_canon', `combinaison base+style = ${eur(c)}`, 'chaque combinaison correspond à un SKU canonique', 'prix sans équivalent canonique');
        }
      }
      fritesItems.forEach((b) => {
        if (!mirrorAvail(b)) div('item_disponibilite', b.name, 'is_available=true', 'is_available=false');
      });
    }
  }

  // --- 3) Pools : viandes (7), sauces (12), crudités (4) --------------------
  checkPoolSet(div, 'pool_viandes', lc.meats, canon.meats, '7 viandes canoniques');
  checkPoolSet(div, 'pool_sauces', lc.sauces, canon.sauces, '12 sauces canoniques');
  checkPoolSet(div, 'pool_crudites', lc.crudites, canon.crudites, "4 crudités canoniques (dont « Oignons cuits »)");

  // --- 4) SUPPLEMENTS : 9 × 0,90 € + Boule gratinée 1,00 € galette_only ----
  {
    const sup = Array.isArray(lc.supplements) ? lc.supplements : [];
    const supByNorm = new Map(sup.map((s) => [norm(s.name), s]));
    for (const n of canon.supplements090) {
      const s = supByNorm.get(n);
      if (!s) { div('supplements', n, 'présent à 0,90 €', 'absent'); continue; }
      if (cents(s.price) !== 90) div('supplements', s.name, '0,90 €', eur(cents(s.price)));
    }
    const boule = supByNorm.get('boule gratinee');
    if (!boule) {
      div('supplements', 'Boule gratinée', `présent à ${eur(canon.bouleGratineeCents ?? 100)} avec galette_only:true`, 'absent');
    } else {
      if (cents(boule.price) !== (canon.bouleGratineeCents ?? 100)) {
        div('supplements', 'Boule gratinée', eur(canon.bouleGratineeCents ?? 100), eur(cents(boule.price)));
      }
      if (boule.galette_only !== true) {
        div('supplements', 'Boule gratinée', 'galette_only:true', `galette_only:${JSON.stringify(boule.galette_only)}`);
      }
    }
    const boursin = supByNorm.get('boursin');
    if (boursin && boursin.galette_excluded !== true) {
      // CONTRACTS.md §5 : le canonique n'offre PAS Boursin sur les galettes
      div('supplements', 'Boursin', 'galette_excluded:true', `galette_excluded:${JSON.stringify(boursin.galette_excluded)}`);
    }
    const allowed = new Set([...canon.supplements090, 'boule gratinee']);
    sup.forEach((s) => {
      if (!allowed.has(norm(s.name))) {
        div('supplements', s.name, 'aucun supplément hors canon', `présent à ${eur(cents(s.price))}`);
      }
    });
  }

  // --- 5) EXTRA_MEAT_PRICE = 2,50 € + has_extra_meat sur 16 items ----------
  {
    const expected = canon.extraMeatCents ?? 250;
    if (cents(lc.extraMeatPrice) !== expected) {
      div('extra_meat_price', 'LC.menu.extraMeatPrice', eur(expected), lc.extraMeatPrice === undefined ? 'absent' : eur(cents(lc.extraMeatPrice)));
    }
    for (const catSlug of ['sandwichs', 'galette', 'burgers', 'tacos', 'bols']) {
      for (const ci of canon.itemsByCat[catSlug] || []) {
        const m = (mirrorByCat[catSlug] || new Map()).get(ci.norm);
        if (m && m.has_extra_meat !== true) {
          div('has_extra_meat', ci.name, 'has_extra_meat:true', `has_extra_meat:${JSON.stringify(m.has_extra_meat)}`);
        }
      }
    }
  }

  // --- 6) SUPPLEMENTS_BOLS : 9 × 0,90 € + Option Gratiné 2,00 € riz_only ---
  {
    const sb = Array.isArray(lc.supplementsBols) ? lc.supplementsBols : [];
    const sbByNorm = new Map(sb.map((s) => [norm(s.name), s]));
    for (const n of canon.supplements090) {
      const s = sbByNorm.get(n);
      if (!s) { div('supplements_bols', n, 'présent à 0,90 €', 'absent'); continue; }
      if (cents(s.price) !== 90) div('supplements_bols', s.name, '0,90 €', eur(cents(s.price)));
    }
    const gratine = sbByNorm.get('option gratine');
    if (!gratine) {
      div('supplements_bols', 'Option Gratiné', 'présent à 2,00 € avec riz_only:true', 'absent');
    } else {
      if (cents(gratine.price) !== 200) div('supplements_bols', 'Option Gratiné', '2,00 €', eur(cents(gratine.price)));
      if (gratine.riz_only !== true) div('supplements_bols', 'Option Gratiné', 'riz_only:true', `riz_only:${JSON.stringify(gratine.riz_only)}`);
    }
    const allowed = new Set([...canon.supplements090, 'option gratine']);
    sb.forEach((s) => {
      if (!allowed.has(norm(s.name))) {
        div('supplements_bols', s.name, 'aucun supplément bol hors canon', `présent à ${eur(cents(s.price))}`);
      }
    });
  }

  // --- 7) BOL_SAUCES : 2 noms EXACTS (attribut backend « Sauce bol ») ------
  {
    const bs = Array.isArray(lc.bolSauces) ? lc.bolSauces : null;
    if (!bs) {
      div('bol_sauces', 'LC.menu.bolSauces', `[${[...canon.bolSaucesExact].join(', ')}]`, 'absent');
    } else {
      const names = new Set(bs.map((s) => s.name));
      for (const n of canon.bolSaucesExact) {
        if (!names.has(n)) div('bol_sauces', n, 'nom EXACT présent (résolution par nom)', `absent (noms mirror : ${[...names].join(' | ') || 'aucun'})`);
      }
      for (const n of names) {
        if (!canon.bolSaucesExact.has(n)) div('bol_sauces', n, 'aucune sauce bol hors canon', 'présente dans le mirror');
      }
    }
  }

  // --- 8) PAINS + has_pain_choice sur les 4 sandwichs -----------------------
  {
    const pains = Array.isArray(lc.pains) ? lc.pains : null;
    if (!pains) {
      div('pains', 'LC.menu.pains', 'pool [Pain, Galette] présent', 'absent');
    } else {
      checkPoolSet(div, 'pains', pains, new Set(['pain', 'galette']), 'Pain + Galette');
    }
    for (const ci of canon.itemsByCat['sandwichs'] || []) {
      const m = (mirrorByCat['sandwichs'] || new Map()).get(ci.norm);
      if (m && m.has_pain_choice !== true) {
        div('has_pain_choice', ci.name, 'has_pain_choice:true', `has_pain_choice:${JSON.stringify(m.has_pain_choice)}`);
      }
    }
  }

  // --- 9) Tacos : has_crudites=true (REVERT backend 05e5cacd0) -------------
  for (const ci of canon.itemsByCat['tacos'] || []) {
    const m = (mirrorByCat['tacos'] || new Map()).get(ci.norm);
    if (m && m.has_crudites !== true) {
      div('tacos_crudites', ci.name, 'has_crudites:true', `has_crudites:${JSON.stringify(m.has_crudites)}`);
    }
  }

  // --- 10) Cayenne : sauce par défaut, plus de sauce_locked (CONTRACTS §5) --
  {
    const cay = (mirrorByCat['sandwichs'] || new Map()).get('cayenne');
    if (cay) {
      if (cay.has_sauce !== true) div('cayenne_sauce', 'Cayenne', 'has_sauce:true', `has_sauce:${JSON.stringify(cay.has_sauce)}`);
      if (norm(cay.sauce_default) !== 'fromagere maison') {
        div('cayenne_sauce', 'Cayenne', "sauce_default:'Sauce fromagère maison' (pré-sélectionnée)", `sauce_default:${JSON.stringify(cay.sauce_default)}`);
      }
      if (cay.sauce_locked) div('cayenne_sauce', 'Cayenne', 'sauce_locked retiré', `sauce_locked:${JSON.stringify(cay.sauce_locked)}`);
    }
  }

  // --- 11) Formule menu : f-menu à 2,50 € -----------------------------------
  {
    const formules = Array.isArray(lc.formules) ? lc.formules : [];
    const fmenu = formules.find((f) => f.id === 'f-menu');
    if (!fmenu) div('formule_menu', 'f-menu', 'présent à 2,50 €', 'absent');
    else if (cents(fmenu.price) !== 250) div('formule_menu', 'f-menu', '2,50 €', eur(cents(fmenu.price)));
  }

  // --- 12) Boissons formule : couverture 15 saveurs + priceForDrinkAddon ----
  {
    const fds = Array.isArray(lc.formuleDrinks) ? lc.formuleDrinks : [];
    const fdNorms = new Set(fds.map((d) => norm(d.name)));
    for (const [n, c] of canon.drinkCentsByNorm) {
      if (!fdNorms.has(n)) {
        div('formule_drinks', n, `saveur présente dans FORMULE_DRINKS (${eur(c)})`, 'absente');
      }
    }
    const fn = lc.priceForDrinkAddon;
    if (typeof fn !== 'function') {
      div('price_drink_addon', 'priceForDrinkAddon', 'fonction exportée', 'absente');
    } else {
      for (const d of fds) {
        const canonCents = canon.drinkCentsByNorm.get(norm(d.name));
        if (canonCents === undefined) {
          div('formule_drinks', d.name, 'saveur existante au catalogue canonique', 'saveur inconnue du canon');
          continue;
        }
        let got;
        try { got = cents(fn(d.id)); } catch (e) { got = NaN; }
        if (got !== canonCents) {
          div('price_drink_addon', `${d.name} (${d.id})`, eur(canonCents), Number.isNaN(got) ? 'prix invalide' : eur(got));
        }
      }
    }
  }

  // --- 13) « Menu Enfant Chicken Burger » : nom canonique EXACT --------------
  {
    const exact = lc.items.some((it) => it.name === 'Menu Enfant Chicken Burger');
    if (!exact) {
      const near = lc.items.find((it) => norm(it.name).startsWith('menu enfant') && norm(it.name) !== 'menu enfant nuggets');
      div('menu_enfant_nom_exact', 'Menu Enfant Chicken Burger', 'nom EXACT (résolution API par nom)', near ? `« ${near.name} »` : 'absent');
    }
  }

  return {
    surface,
    mirrorPath,
    divs,
    mirrorStats: { items: lc.items.length, categories: (lc.categories || []).length },
  };
}

// Égalité stricte d'un pool (noms normalisés) avec le set canonique
function checkPoolSet(div, check, pool, canonSet, label) {
  const arr = Array.isArray(pool) ? pool : null;
  if (!arr) {
    div(check, label, `${canonSet.size} entrées : ${[...canonSet].join(' | ')}`, 'pool absent');
    return;
  }
  const got = new Set(arr.map((e) => norm(e.name)));
  for (const n of canonSet) {
    if (!got.has(n)) div(check, n, `présent (${label})`, 'absent du mirror');
  }
  for (const n of got) {
    if (!canonSet.has(n)) div(check, n, `aucune entrée hors canon (${label})`, 'présent dans le mirror');
  }
}

// ---------------------------------------------------------------------------
// Rapport + console
// ---------------------------------------------------------------------------
function writeReport(reportDir, canon, result) {
  const byCheck = {};
  result.divs.forEach((d) => { byCheck[d.check] = (byCheck[d.check] || 0) + 1; });
  const report = {
    gate: 'parity-B3',
    version: 1,
    generated_at: new Date().toISOString(),
    surface: result.surface,
    fixture: canon.path,
    mirror: result.mirrorPath,
    canonical: { categories: canon.categoriesCount, items: canon.itemsCount },
    mirror_stats: result.mirrorStats,
    divergence_count: result.divs.length,
    divergences_par_check: byCheck,
    divergences: result.divs,
    ok: result.divs.length === 0,
  };
  fs.mkdirSync(reportDir, { recursive: true });
  const out = path.join(reportDir, `parity-report-${result.surface}.json`);
  fs.writeFileSync(out, JSON.stringify(report, null, 2) + '\n', 'utf8');
  return out;
}

function printSummary(result, reportPath) {
  const icon = result.divs.length === 0 ? 'VERT ✅' : 'ROUGE ❌';
  console.log(`\n[parity] ${result.surface.toUpperCase()} — ${icon} — ${result.divs.length} divergence(s)`);
  console.log(`[parity]   mirror  : ${result.mirrorPath}`);
  console.log(`[parity]   rapport : ${reportPath}`);
  if (result.divs.length) {
    const MAX = 60;
    result.divs.slice(0, MAX).forEach((d) => {
      console.log(`  - [${d.check}] ${d.sujet} — attendu : ${d.attendu} | constaté : ${d.constate}`);
    });
    if (result.divs.length > MAX) console.log(`  … et ${result.divs.length - MAX} autre(s) (voir le rapport JSON)`);
  }
}

// ---------------------------------------------------------------------------
// main
// ---------------------------------------------------------------------------
const args = parseArgs(process.argv);
if (!fs.existsSync(args.fixture)) fail(2, `Fixture introuvable : ${args.fixture}`);
const canon = buildCanon(args.fixture);
if (canon.itemsCount !== 42) {
  console.warn(`[parity] AVERTISSEMENT : la fixture contient ${canon.itemsCount} items (42 attendus) — vérifier l'extraction.`);
}

const surfaces = args.surface === 'all' ? ['web', 'mobile'] : [args.surface];
let totalDivs = 0;
for (const surface of surfaces) {
  const mirrorPath = args.mirror || DEFAULTS.mirrors[surface];
  if (!fs.existsSync(mirrorPath)) {
    fail(2, `Mirror ${surface} introuvable : ${mirrorPath}`);
  }
  const result = compareSurface(surface, mirrorPath, canon);
  const reportPath = writeReport(args.reportDir, canon, result);
  printSummary(result, reportPath);
  totalDivs += result.divs.length;
}

console.log(`\n[parity] TOTAL : ${totalDivs} divergence(s) sur ${surfaces.length} surface(s) — ${totalDivs === 0 ? 'GATE VERT (exit 0)' : 'GATE ROUGE (exit 1)'}`);
process.exit(totalDivs === 0 ? 0 : 1);
