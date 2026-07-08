// Le Cayenne — Catalogue mobile aligné FoodKing system central (SSOT = DB seed commands)
// [MENU-RESET 2026-05-13] Restructuration globale.
// [HEAL-LIGHT V2 2026-05-14] Owner-validated spec: 11 catégories (Burgers + Menu enfant).
// [MOBILE-REALIGNMENT 2026-05-16] composer_profile hardcoded mirroring DB shape for
// future API wireup (when owner connects mobile to backend, swap data source — render
// layer stays identical, no rewrites). Mobile stays standalone for now.
// [MENU-CANON 2026-06-26] Réalignement complet sur le VRAI menu Le Cayenne.
//   SSOT = database/seeders/OwnerMenuUpdate20260623Seeder.php.
//   Changements : 7 viandes mixtes (au lieu de 4 poulet-only), 12 sauces, 3 crudités,
//   formule menu +2,50, Tacos L 7,90, Chicken Burger 4,90, Desserts 3,50, canettes 1,90,
//   +5 burgers, +3 sandwichs (Suprême/Méga/Terminator), Bols 8→2 (viande au choix),
//   Menu enfant 1→2 SKU @ 4,90, extra "Viande supplémentaire" +2,50.
//   Catégories purgées : "Sandwich Classique" (cat3), "Suppléments" vendable (cat8).
//   Catégories renommées : "Sandwich Cayenne"→"Sandwichs", "Bols Gourmands"→"Bols".
//   ⛔ STANDALONE — aucun wireup API V1.
// [GOAL-SYNC 2026-07-08] Rattrapage complet du mirror mobile sur le canon borne
//   (fixture reports/goal-web-app-sync/catalog-canonical.json + CONTRACTS.md §5) —
//   le mobile n'avait jamais reçu les fixes web WEB-SYNC-CAISSE 2026-06-26/27 :
//   +7 boissons (ids 1009-1015 @ 1,90€) + FORMULE_DRINKS 15 saveurs, Capri-Sun addon 1,50€ (FIX P0),
//   CRUDITES 4 (+Oignons cuits), PAINS (Pain/Galette) + has_pain_choice sandwichs,
//   EXTRA_MEAT_PRICE 2,50€ + has_extra_meat (16 items) + priceFor extraMeatIds,
//   SUPPLEMENTS +Boule gratinée 1,00€ (galette_only) / Boursin galette_excluded,
//   SUPPLEMENTS_BOLS 5→10 (9×0,90€ + Gratiné 2,00€ riz_only), BOL_SAUCES (2, pool bol),
//   cat 6 wizard_template 'bol', Tacos M/L crudités restaurées (revert backend 05e5cacd0),
//   Cayenne sauce au choix (défaut Sauce fromagère maison) + is_spicy:false,
//   multi-sauces +0,50€ SUPPRIMÉE (canon = 1 sauce max), 'Menu Enfant Chicken Burger',
//   is_halal défaut false (canon), desserts is_vegetarian:true, images viandes distinctes.
//
// SSOT (source of truth) = system central :
//   - database/seeders/OwnerMenuUpdate20260623Seeder.php (2026-06-23/24) — menu canon
//   - DB tables: items, item_variations, item_extras, item_addons,
//     item_wizard_profiles, item_wizard_steps
//
// Catégories visibles (9) : Sandwichs, Galette, Burgers, Tacos, Bols, Frites,
//              Desserts, Boissons, Menu enfant.
// Viandes (7) : Mexicanos, Cordon Bleu, Viande Hachée, Nuggets, Tenders, Fricadelle, Poulet mariné.
// Sauces (12) : Mayonnaise, Ketchup, Blanche, Hannibal, Samouraï, Algérienne, Andalouse,
//               Curry, Barbecue, Harissa, Fromagère maison, Spicy maison.
// Crudités (4) : Salade, Tomate, Oignon, Oignons cuits.
// Suppléments (9 @ 0.90€ + Boule gratinée 1,00€ galette-only) : Oignons frits, Champignons,
//                            Jambon, Cheddar, Raclette, Emmental, Boursin, Œuf, Légumes sautés.
// Formule menu (frites + boisson) +2,50€. Viande supplémentaire +2,50€.

(function () {
  'use strict';

  // -------------------------------------------------------------------------
  // BRANCH
  // -------------------------------------------------------------------------
  const BRANCH = {
    id: 1,
    name: 'Le Cayenne',
    city: 'Hénin-Beaumont',
    zip: '62210',
    address: 'Le Cayenne, 62210 Hénin-Beaumont',
    phone: '+33 6 51 30 00 00',
    is_open: true,
    hours: '11h — 00h',
    currency: '€',
    chef: 'Abdoullah',
    locale: 'fr',
  };

  // -------------------------------------------------------------------------
  // IMAGE ASSET PATHS — réutilisation assets existants quand pertinent
  // -------------------------------------------------------------------------
  const ASSET_BASE = 'assets/menu/';

  // Items → images ([MENU-CANON 2026-06-26] board photos réutilisées, fallback item-default)
  const ITEM_IMG = {
    // Sandwichs (cat 1)
    'cayenne':     'sandwich-cayenne.png',
    'supreme':     'sandwich-classique.png',
    'mega':        'sandwich-cayenne-maxi.png',
    'terminator':  'sandwich-classique-maxi.png',
    // Galette (cat 2)
    'galette-normale': 'galette.png',
    'galette-cayenne': 'galette.png',
    // Burgers (cat 4)
    'chicken-burger': 'burger-cheese.png',
    'cheese-burger':  'burger-cheese.png',
    'double-cheese':  'burger-big.png',
    'fish-burger':    'burger-cheese.png',
    'big-burger':     'burger-big.png',
    'grill-burger':   'burger-big.png',
    // Tacos (cat 5) — board deliberately shares one tacos photo
    'tacos-m': 'tacos.png',
    'tacos-l': 'tacos.png',
    // Bols (cat 6)
    'bol-frites': 'bol-frites.png',
    'bol-riz':    'bol-riz.png',
    // Frites (cat 7)
    'petite-frites': 'frites.png',
    'grande-frites': 'frites.png',
    // Desserts (cat 9)
    'glace': 'ben-jerrys.png',
    'tarte-daim': 'tarte.png',
    'tiramisu': 'tiramisu.png',
    // Drinks (cat 10)
    'coca': 'coca.png',
    'coca-zero': 'coca-zero.png',
    'fanta': 'fanta-orange.png',
    'sprite': 'sprite.png',
    'oasis': 'oasis.png',
    'orangina': 'tropico.png',
    'eau-plate': 'eau.png',
    'capri-sun': 'capri-sun.png',
    // [GOAL-SYNC 2026-07-08] +7 boissons canon — assets EXISTANTS vérifiés (ls assets/menu),
    // fallback générique boisson.png quand aucun visuel dédié n'existe (jamais d'asset inventé).
    'coca-cherry':   'coca.png',
    'tropico':       'tropico.png',
    'ice-tea-peche': 'boisson.png',
    'fanta-citron':  'fanta.png',
    'fuze-tea':      'boisson.png',
    'hawai':         'boisson.png',
    'perrier':       'eau_gazeuse.png',
    // Menu enfant (cat 11)
    'menu-enfant-nuggets': 'nuggets.png',
    'menu-enfant-burger':  'burger-cheese.png',
  };

  // Signature heroes (bg-removed)
  const HERO_IMG = {
    'cayenne': 'signature/cayenne-hero.png',
    'mega': 'signature/cayenne-hero.png',
    'terminator': 'signature/cayenne-hero.png',
    'galette-cayenne': 'signature/cayenne-hero.png',
    'tacos-m': 'signature/tacos-hero.png',
    'tacos-l': 'signature/tacos-hero.png',
  };

  function imgFor(slug) {
    return ASSET_BASE + (ITEM_IMG[slug] || 'item-default.svg');
  }
  function heroFor(slug) {
    return HERO_IMG[slug] ? (ASSET_BASE + HERO_IMG[slug]) : imgFor(slug);
  }

  // -------------------------------------------------------------------------
  // SHARED OPTIONS — Le Cayenne canon (OwnerMenuUpdate20260623Seeder.php)
  // -------------------------------------------------------------------------

  // 7 viandes au choix (seeder MEATS) — viandes mixtes, plus poulet-only.
  // [GOAL-SYNC 2026-07-08] image DISTINCTE par viande (parité IMG-SYNC-CAISSE 2026-06-27 du web),
  //   limitée aux assets PRÉSENTS dans mobile/assets/menu (vérifié ls — les fichiers web
  //   viande-mexicanos.png etc. n'existent pas côté mobile ; équivalents locaux utilisés).
  const MEATS = [
    { id: 'm-mexicanos',    name: 'Mexicanos',     price: 0, emoji: '🌶️', image: ASSET_BASE + 'viande_mexicain.png' },
    { id: 'm-cordon-bleu',  name: 'Cordon Bleu',   price: 0, emoji: '🍗', image: ASSET_BASE + 'viande_cordon.png' },
    { id: 'm-viande-hachee', name: 'Viande Hachée', price: 0, emoji: '🥩', image: ASSET_BASE + 'viande_hachee.png' },
    { id: 'm-nuggets',      name: 'Nuggets',       price: 0, emoji: '🍗', image: ASSET_BASE + 'viande_nuggets.png' },
    { id: 'm-tenders',      name: 'Tenders',       price: 0, emoji: '🍗', image: ASSET_BASE + 'viande_tenders.png' },
    { id: 'm-fricadelle',   name: 'Fricadelle',    price: 0, emoji: '🌭', image: ASSET_BASE + 'viande_fricandelle.png' },
    { id: 'm-poulet-marine', name: 'Poulet mariné', price: 0, emoji: '🍗', image: ASSET_BASE + 'viande_poulet.png' },
  ];

  // 12 sauces incluses (seeder SAUCES) — 1ère gratuite, +0,50€ chacune au-delà.
  const SAUCES = [
    { id: 's-mayo',       name: 'Mayonnaise',        price: 0, image: ASSET_BASE + 'sauce-mayonnaise.png' },
    { id: 's-ketchup',    name: 'Ketchup',           price: 0, image: ASSET_BASE + 'sauce-ketchup.png' },
    { id: 's-blanche',    name: 'Blanche',           price: 0, image: ASSET_BASE + 'sauce-blanche.png' },
    { id: 's-hannibal',   name: 'Hannibal',          price: 0, is_spicy: true, image: ASSET_BASE + 'sauce-hannibal.png' },
    { id: 's-samurai',    name: 'Samouraï',          price: 0, is_spicy: true, image: ASSET_BASE + 'sauce-samurai.png' },
    { id: 's-algerien',   name: 'Algérienne',        price: 0, image: ASSET_BASE + 'sauce-algerienne.png' },
    { id: 's-andalouse',  name: 'Andalouse',         price: 0, image: ASSET_BASE + 'sauce-andalouse.png' },
    { id: 's-curry',      name: 'Curry',             price: 0, image: ASSET_BASE + 'sauce-curry.png' },
    { id: 's-barbecue',   name: 'Barbecue',          price: 0, image: ASSET_BASE + 'sauce-barbecue.png' },
    { id: 's-harissa',    name: 'Harissa',           price: 0, is_spicy: true, image: ASSET_BASE + 'sauce-harissa.png' },
    { id: 's-fromagere',  name: 'Fromagère maison',  price: 0, image: ASSET_BASE + 'sauce-fromagere-maison.png' },
    { id: 's-spicy',      name: 'Spicy maison',      price: 0, is_spicy: true, image: ASSET_BASE + 'sauce-spicy-maison.png' },
  ];

  // 4 crudités — [GOAL-SYNC 2026-07-08] +Oignons cuits (canon extras group_label='crudite' ×4,
  //   fixture items 22/26/97…). Non incluse par défaut (ajout optionnel gratuit).
  const CRUDITES = [
    { id: 'c-salade',        name: 'Salade',        default: true, image: ASSET_BASE + 'salade.png' },
    { id: 'c-tomate',        name: 'Tomate',        default: true, image: ASSET_BASE + 'tomate.png' },
    { id: 'c-oignon',        name: 'Oignon',        default: true, image: ASSET_BASE + 'oignon.png' },
    { id: 'c-oignons-cuits', name: 'Oignons cuits', price: 0,      image: ASSET_BASE + 'oignon.png' },
  ];

  // 9 suppléments payants +0,90€ (seeder SUPPLEMENTS ; "Oignon frais"→"Oignons frits").
  // [FIC 1169/2011] allergènes exposés (agrégation lit ce pool).
  const SUPPLEMENTS = [
    { id: 'sup-oignons-frits',  name: 'Oignons frits',  price: 0.90, image: ASSET_BASE + 'oignons-frits.png',     allergens: [] },
    { id: 'sup-champignons',    name: 'Champignons',    price: 0.90, image: ASSET_BASE + 'champignons.png',       allergens: [] },
    { id: 'sup-jambon',         name: 'Jambon',         price: 0.90, image: ASSET_BASE + 'jambon-dinde.png',      allergens: [] },
    { id: 'sup-cheddar',        name: 'Cheddar',        price: 0.90, image: ASSET_BASE + 'cheddar.png',           allergens: ['lactose'] },
    { id: 'sup-raclette',       name: 'Raclette',       price: 0.90, image: ASSET_BASE + 'raclette.png',          allergens: ['lactose'] },
    { id: 'sup-emmental',       name: 'Emmental',       price: 0.90, image: ASSET_BASE + 'fromage.png',           allergens: ['lactose'] },
    // [GOAL-SYNC 2026-07-08] Boursin ABSENT des galettes au canon → galette_excluded (filtre wizard).
    { id: 'sup-boursin',        name: 'Boursin',        price: 0.90, image: ASSET_BASE + 'boursin.png',           allergens: ['lactose'], galette_excluded: true },
    { id: 'sup-oeuf',           name: 'Œuf',            price: 0.90, image: ASSET_BASE + 'oeuf.png',              allergens: ['oeuf'] },
    { id: 'sup-legumes-sautes', name: 'Légumes sautés', price: 0.90, image: ASSET_BASE + 'legumes-sautes.png',    allergens: [] },
    // [GOAL-SYNC 2026-07-08] Boule gratinée 1,00€ — réservée aux galettes (canon).
    { id: 'sup-boule-gratinee', name: 'Boule gratinée', price: 1.00, image: ASSET_BASE + 'supplement_galette.png', allergens: ['lactose'], galette_only: true },
  ];

  // Suppléments spécifiques aux bols (seeder SUPP_GROUP_BOL).
  // [GOAL-SYNC 2026-07-08] complété 5→10 (parité WEB-SYNC-CAISSE 2026-06-26 F1) :
  //   9 suppléments ×0,90€ + Gratiné 2,00€ réservé au Bol Riz (riz_only, filtré par le wizard).
  const SUPPLEMENTS_BOLS = [
    { id: 'sb-oignons-frits',  name: 'Oignons frits',  price: 0.90, image: ASSET_BASE + 'oignons-frits.png' },
    { id: 'sb-champignons',    name: 'Champignons',    price: 0.90, image: ASSET_BASE + 'champignons.png' },
    { id: 'sb-jambon',         name: 'Jambon',         price: 0.90, image: ASSET_BASE + 'jambon-dinde.png' },
    { id: 'sb-cheddar',        name: 'Cheddar',        price: 0.90, image: ASSET_BASE + 'cheddar.png' },
    { id: 'sb-raclette',       name: 'Raclette',       price: 0.90, image: ASSET_BASE + 'raclette.png' },
    { id: 'sb-emmental',       name: 'Emmental',       price: 0.90, image: ASSET_BASE + 'fromage.png' },
    { id: 'sb-boursin',        name: 'Boursin',        price: 0.90, image: ASSET_BASE + 'boursin.png' },
    { id: 'sb-oeuf',           name: 'Œuf',            price: 0.90, image: ASSET_BASE + 'oeuf.png' },
    { id: 'sb-legumes-sautes', name: 'Légumes sautés', price: 0.90, image: ASSET_BASE + 'legumes-sautes.png' },
    { id: 'sb-gratine',        name: 'Option Gratiné', price: 2.00, image: ASSET_BASE + 'bol-frites-gratine.png', riz_only: true },
  ];

  // [GOAL-SYNC 2026-07-08] Choix du pain (canon attr 6 Pain/Galette, gratuit) —
  //   exposé sur les 4 Sandwichs via has_pain_choice (parité WEB-SYNC-CAISSE 2026-06-26 L4).
  const PAINS = [
    { id: 'pain-classique', name: 'Pain',    price: 0, is_default: true, image: ASSET_BASE + 'sandwich-classique.png' },
    { id: 'pain-galette',   name: 'Galette', price: 0,                   image: ASSET_BASE + 'galette.png' },
  ];

  // [GOAL-SYNC 2026-07-08] Viande supplémentaire (canon extra +2,50€) — sandwichs, galettes,
  //   burgers, tacos, bols (has_extra_meat). Chiffrée via opts.extraMeatIds dans priceFor.
  const EXTRA_MEAT_PRICE = 2.50;

  // [GOAL-SYNC 2026-07-08] Sauces du BOL = attribut canon « Sauce bol » (attr 8) : SEULEMENT
  //   2 options (≠ les 12 sauces sandwich). Les bols (has_sauce) sont servis par CE pool.
  //   Noms EXACTS backend pour que la résolution par nom matche au wireup.
  const BOL_SAUCES = [
    { id: 'bs-fromagere', name: 'Sauce fromagère maison', price: 0, image: ASSET_BASE + 'sauce-fromagere-maison.png' },
    { id: 'bs-spicy',     name: 'Sauce spicy',            price: 0, is_spicy: true, image: ASSET_BASE + 'sauce-spicy-maison.png' },
  ];

  // Formule menu (seeder : Option Menu frites + boisson +2,50€).
  const FORMULES = [
    { id: 'f-menu',    name: 'Menu (Frites + Boisson)', price: 2.50, has_drink: true, has_fries: true },
    { id: 'f-frites',  name: 'Ajouter Frites',           price: 2.00, has_fries: true },
    { id: 'f-boisson', name: 'Ajouter Boisson',          price: 2.00, has_drink: true },
  ];

  // Frites styles (Nature / Cheddar fondu +1€ / Cheddar+Oignons +2€) — option client standalone.
  const FRITES_STYLES = [
    { id: null,                name: 'Nature',                   price: 0,    is_default: true, emoji: '🍟', image: ASSET_BASE + 'frites.png' },
    { id: 'fs-cheddar',        name: 'Cheddar fondu',            price: 1.00, emoji: '🧀',                  image: ASSET_BASE + 'frites-cheddar.png' },
    { id: 'fs-cheddar-oignon', name: 'Cheddar + Oignons frits',  price: 2.00, emoji: '🧅',                  image: ASSET_BASE + 'frites-cheddar-oignons.png' },
  ];

  // Bases bols (Frites / Riz)
  const BOL_BASES = [
    { id: 'bb-frites', name: 'Frites', price: 0, image: ASSET_BASE + 'frites.png' },
    { id: 'bb-riz',    name: 'Riz',    price: 0, image: ASSET_BASE + 'bol-riz.png' },
  ];

  // Boissons formule menu — [GOAL-SYNC 2026-07-08] étendu 8→15 saveurs (canon boissons 33cl).
  const FORMULE_DRINKS = [
    { id: 'd-coca',          name: 'Coca-Cola 33cl',      emoji: '🥤', image: ASSET_BASE + 'coca.png' },
    { id: 'd-coca-zero',     name: 'Coca-Cola Zero 33cl', emoji: '🥤', image: ASSET_BASE + 'coca-zero.png' },
    { id: 'd-fanta',         name: 'Fanta Orange 33cl',   emoji: '🍊', image: ASSET_BASE + 'fanta-orange.png' },
    { id: 'd-sprite',        name: 'Sprite 33cl',         emoji: '🍋', image: ASSET_BASE + 'sprite.png' },
    { id: 'd-oasis',         name: 'Oasis Tropical 33cl', emoji: '🌴', image: ASSET_BASE + 'oasis.png' },
    { id: 'd-orangina',      name: 'Orangina 33cl',       emoji: '🍊', image: ASSET_BASE + 'tropico.png' },
    { id: 'd-eau',           name: 'Eau Plate 50cl',      emoji: '💧', image: ASSET_BASE + 'eau.png' },
    { id: 'd-capri',         name: 'Capri-Sun',           emoji: '🧃', image: ASSET_BASE + 'capri-sun.png' },
    { id: 'd-coca-cherry',   name: 'Coca Cherry 33cl',    emoji: '🥤', image: ASSET_BASE + 'coca.png' },
    { id: 'd-tropico',       name: 'Tropico 33cl',        emoji: '🌴', image: ASSET_BASE + 'tropico.png' },
    { id: 'd-ice-tea-peche', name: 'Ice Tea Pêche 33cl',  emoji: '🥤', image: ASSET_BASE + 'boisson.png' },
    { id: 'd-fanta-citron',  name: 'Fanta Citron 33cl',   emoji: '🍋', image: ASSET_BASE + 'fanta.png' },
    { id: 'd-fuze-tea',      name: 'Fuze Tea 33cl',       emoji: '🥤', image: ASSET_BASE + 'boisson.png' },
    { id: 'd-hawai',         name: 'Hawaï 33cl',          emoji: '🌴', image: ASSET_BASE + 'boisson.png' },
    { id: 'd-perrier',       name: 'Perrier 33cl',        emoji: '💧', image: ASSET_BASE + 'eau_gazeuse.png' },
  ];

  // -------------------------------------------------------------------------
  // CATEGORIES (9 visibles — [MENU-CANON 2026-06-26])
  //   cat 3 "Sandwich Classique" + cat 8 "Suppléments" RETIRÉES (seeder : masquées).
  //   cat 1 renommée "Sandwichs" ; cat 6 renommée "Bols".
  //   Les ids backend sont préservés (1,2,4,5,6,7,9,10,11).
  // -------------------------------------------------------------------------
  const CATEGORIES = [
    { id: 1,  slug: 'sandwichs',    name: 'Sandwichs',   icon: '🥖', sort: 1,  wizard_template: 'sandwich', has_menu: true,  description: 'Cayenne, Suprême, Méga, Terminator — pain ou galette au choix',  image: ASSET_BASE + 'cat-sandwich-cayenne.png' },
    { id: 2,  slug: 'galette',      name: 'Galette',     icon: '🌯', sort: 2,  wizard_template: 'sandwich', has_menu: true,  description: 'Galette traditionnelle ou Cayenne',              image: ASSET_BASE + 'cat-galette.png' },
    { id: 4,  slug: 'burgers',      name: 'Burgers',     icon: '🍔', sort: 3,  wizard_template: 'burger',   has_menu: true,  description: 'Chicken, Cheese, Double Cheese, Fish, Big, Grill', image: ASSET_BASE + 'cat-burgers.png' },
    { id: 5,  slug: 'tacos',        name: 'Tacos',       icon: '🌮', sort: 4,  wizard_template: 'tacos',    has_menu: true,  description: 'Tacos M (1 viande) ou Tacos L (2 viandes)',       image: ASSET_BASE + 'cat-tacos.png' },
    // [GOAL-SYNC 2026-07-08] wizard_template 'tacos'→'bol' (aligné web — sauces servies par BOL_SAUCES).
    { id: 6,  slug: 'bols',         name: 'Bols',        icon: '🥣', sort: 5,  wizard_template: 'bol',      has_menu: false, description: 'Bol Frites ou Bol Riz, viande au choix',          image: ASSET_BASE + 'cat-bols-gourmands.png' },
    { id: 7,  slug: 'frites',       name: 'Frites',      icon: '🍟', sort: 6,  wizard_template: 'custom',   has_menu: false, description: 'Petite ou Grande, style au choix',               image: ASSET_BASE + 'cat-frites.png' },
    { id: 9,  slug: 'desserts',     name: 'Desserts',    icon: '🍰', sort: 7,  wizard_template: 'simple',   has_menu: false, description: 'Desserts gourmands',                             image: ASSET_BASE + 'cat-desserts.png' },
    { id: 10, slug: 'boissons',     name: 'Boissons',    icon: '🥤', sort: 8,  wizard_template: 'simple',   has_menu: false, description: 'Boissons fraîches',                              image: ASSET_BASE + 'cat-boissons.png' },
    { id: 11, slug: 'menu-enfant',  name: 'Menu enfant', icon: '🧒', sort: 9,  wizard_template: 'simple',   has_menu: false, description: 'Menu enfant : Nuggets ou Burger + frites + Capri-Sun', image: ASSET_BASE + 'cat-menu-enfant.png' },
  ];

  // -------------------------------------------------------------------------
  // ITEMS HELPER
  // -------------------------------------------------------------------------
  function defaultAllergensFor(cat, opts) {
    if (opts && opts.allergens !== undefined) return opts.allergens;
    switch (cat) {
      case 1: case 2: case 4: case 5: return ['gluten']; // Sandwichs/Galette/Burgers/Tacos (pain/galette)
      case 6: return [];           // Bols (no bread)
      case 7: return [];           // Frites
      case 9: return ['gluten', 'lactose']; // Desserts
      case 10: return [];          // Boissons
      case 11: return ['gluten'];  // Menu enfant (Nuggets/burger pané)
      default: return [];
    }
  }

  function mkItem(id, slug, category_id, name, price, description, opts) {
    opts = opts || {};
    const item = {
      id, slug, category_id, name, price, description,
      thumb: 'item-' + slug,
      image: imgFor(slug),
      hero: heroFor(slug),
      kiosk_emoji: opts.emoji || '', // legacy field used by screens-item-steps.jsx:841
      // [ULTRA-FRONTENDS HEAL 2026-05-18 P1] also expose `emoji` for web parity (consumers
      // expecting item.emoji per web mkItem shape — mobile consumers may use either field).
      emoji: opts.emoji || '',
      time: opts.time !== undefined ? opts.time : 8,
      tags: opts.tags || [],
      is_featured: !!opts.is_featured,
      is_new: !!opts.is_new,
      is_spicy: !!opts.is_spicy,
      // [GOAL-SYNC 2026-07-08] défaut halal true→false (canon is_halal:false partout —
      // allégation réglementaire, jamais affirmée par défaut).
      is_halal: !!opts.is_halal,
      is_vegetarian: !!opts.is_vegetarian,
      viandes: opts.viandes ?? 0,
      viande_count: opts.viandes ?? 0, // canonical field name (kiosk parity)
      has_sauce: opts.has_sauce !== false,
      has_crudites: !!opts.has_crudites,
      has_supplements: opts.has_supplements !== false,
      has_menu_addon: !!opts.has_menu_addon,
      has_frites_style: !!opts.has_frites_style,
      has_bol_wizard: !!opts.has_bol_wizard,
      // [GOAL-SYNC 2026-07-08] parité web : choix du pain (pool PAINS) + viande supplémentaire
      // (+2,50€ EXTRA_MEAT_PRICE) + sauce pré-sélectionnée mais modifiable (Cayenne).
      has_pain_choice: !!opts.has_pain_choice,
      has_extra_meat: !!opts.has_extra_meat,
      sauce_default: opts.sauce_default || null,
      sauce_locked: opts.sauce_locked || null,
      bol_meat_fixed: opts.bol_meat_fixed || null,
      bol_sauce_default: opts.bol_sauce_default || null,
      wizard_template: opts.wizard_template || null, // item-level override (kiosk parity)
      allergens: defaultAllergensFor(category_id, opts),
    };
    // [MOBILE-REALIGNMENT 2026-05-16] composer_profile hardcoded for V0 standalone,
    // mirrors DB shape (item_wizard_profiles + item_wizard_steps) so future API wireup
    // = just swap data source. Render layer reads item.composer_profile.steps[].
    if (opts.has_frites_style && category_id === 7) {
      item.composer_profile = buildFritesComposerProfile(item, opts);
    }
    return item;
  }

  // -------------------------------------------------------------------------
  // COMPOSER PROFILE HELPERS — mirror DB shape (item_wizard_profiles)
  // -------------------------------------------------------------------------
  function buildFritesComposerProfile(item, opts) {
    return {
      template: 'frites',
      version: 1,
      is_published: true,
      steps: [
        {
          step_key: 'frites_style',
          label: 'Style de frites',
          source_type: 'item_attribute',
          position: 1,
          min_select: 1,
          max_select: 1,
          allow_repeat: false,
          addon_role: null,
          default_choice_id: null, // Nature = id null
          choices: FRITES_STYLES.map(fs => ({
            id: fs.id, name: fs.name, price: fs.price, image: fs.image, emoji: fs.emoji,
            is_default: !!fs.is_default,
          })),
        },
      ],
    };
  }

  // Drink prices for bol addon — read from Boissons catalogue (cat 10) by slug match.
  // (e.g. d-coca → 1.90€, d-eau → 1.00€). Same drink slugs as FORMULE_DRINKS.
  // [GOAL-SYNC 2026-07-08] FIX P0 : d-capri 1,90→1,50€ (prix catalogue Capri-Sun canon)
  //   + 7 nouvelles saveurs @ 1,90€.
  function priceForDrinkAddon(formuleDrinkId) {
    const drinkPriceMap = {
      'd-coca': 1.90, 'd-coca-zero': 1.90, 'd-fanta': 1.90, 'd-sprite': 1.90,
      'd-oasis': 1.90, 'd-orangina': 1.90, 'd-eau': 1.00, 'd-capri': 1.50,
      'd-coca-cherry': 1.90, 'd-tropico': 1.90, 'd-ice-tea-peche': 1.90,
      'd-fanta-citron': 1.90, 'd-fuze-tea': 1.90, 'd-hawai': 1.90, 'd-perrier': 1.90,
    };
    return drinkPriceMap[formuleDrinkId] !== undefined ? drinkPriceMap[formuleDrinkId] : 1.90;
  }

  // ====== SANDWICHS (cat 1) — seeder : Cayenne 7,40 / Suprême 7,00 / Méga 8,00 / Terminator 9,00
  //   Pain au choix (Pain/Galette, has_pain_choice) ; Méga & Terminator = 2 viandes au choix.
  //   Formule menu +2,50€ ; viande supplémentaire +2,50€ (has_extra_meat).
  //   [GOAL-SYNC 2026-07-08] Cayenne : sauce AU CHOIX (12) pré-sélectionnée « Sauce fromagère
  //   maison » (sauce_default, canon attr 5 complet) — sauce_locked retiré ; is_spicy:false (canon). ======
  const SANDWICHS = [
    mkItem(101, 'cayenne', 1, 'Cayenne', 7.40,
      'Poulet mariné · Cheddar · Jambon · Oignons rouges · Tomates · Salade · Sauce fromagère maison',
      { viandes: 0, has_crudites: true, has_menu_addon: true, has_sauce: true,
        sauce_default: 'Sauce fromagère maison', has_pain_choice: true, has_extra_meat: true,
        is_featured: true, tags: ['SIGNATURE'], emoji: '🌶️', time: 10 }),
    mkItem(102, 'supreme', 1, 'Suprême', 7.00,
      'Steak haché · Cordon bleu · Cheddar · Oignons rouges · Tomates · Salade · Sauce au choix',
      { viandes: 0, has_crudites: true, has_menu_addon: true, has_sauce: true,
        has_pain_choice: true, has_extra_meat: true, time: 10, emoji: '🥖' }),
    mkItem(103, 'mega', 1, 'Méga', 8.00,
      '2 viandes au choix · Cheddar · Œuf · Oignons rouges · Tomates · Salade · Sauce au choix',
      { viandes: 2, has_crudites: true, has_menu_addon: true, has_sauce: true,
        has_pain_choice: true, has_extra_meat: true,
        is_featured: true, tags: ['XL'], time: 12, emoji: '🥖' }),
    mkItem(104, 'terminator', 1, 'Terminator', 9.00,
      '2 viandes au choix · 2 cheddars · Œuf · Jambon de dinde · Oignons rouges · Tomates · Salade · Sauce au choix',
      { viandes: 2, has_crudites: true, has_menu_addon: true, has_sauce: true,
        has_pain_choice: true, has_extra_meat: true,
        is_featured: true, tags: ['XL'], time: 12, emoji: '🥖' }),
  ];

  // ====== GALETTE (cat 2) — conservée (gate owner) ; 7 viandes + 12 sauces (pools partagés). ======
  const GALETTE = [
    mkItem(201, 'galette-normale', 2, 'Galette Normale', 6.50,
      'Galette traditionnelle · 1 viande au choix · Sauce au choix · Crudités · Suppléments optionnels',
      { viandes: 1, has_crudites: true, has_menu_addon: true, has_sauce: true, has_extra_meat: true, time: 8, emoji: '🌯' }),
    mkItem(202, 'galette-cayenne', 2, 'Galette Cayenne', 7.00,
      'Galette signature · 1 viande au choix · Sauce au choix · Crudités · Suppléments optionnels',
      { viandes: 1, has_crudites: true, has_menu_addon: true, has_sauce: true, has_extra_meat: true,
        // [GOAL-SYNC 2026-07-08] is_spicy retiré — canon Galette Cayenne is_spicy:false (gate parity).
        is_featured: true, tags: ['SIGNATURE'], emoji: '🌶️', time: 8 }),
  ];

  // ====== BURGERS (cat 4) — seeder : 6 burgers, compositions fixes (PAS de choix viande). ======
  const BURGERS = [
    mkItem(401, 'chicken-burger', 4, 'Chicken Burger', 4.90,
      'Salade · Tomate · Oignon · Sauce',
      { viandes: 0, has_crudites: true, has_menu_addon: true, has_sauce: true, has_extra_meat: true, emoji: '🍔', time: 10 }),
    mkItem(402, 'cheese-burger', 4, 'Cheese Burger', 6.00,
      'Steak · Cheddar · Salade · Tomate · Oignon · Sauce',
      { viandes: 0, has_crudites: true, has_menu_addon: true, has_sauce: true, has_extra_meat: true, emoji: '🍔', time: 10 }),
    mkItem(403, 'double-cheese', 4, 'Double Cheese', 7.00,
      '2 steaks · 2 cheddars · Salade · Tomate · Oignon · Sauce',
      { viandes: 0, has_crudites: true, has_menu_addon: true, has_sauce: true, has_extra_meat: true, is_featured: true, emoji: '🍔', time: 11 }),
    mkItem(404, 'fish-burger', 4, 'Fish Burger', 6.00,
      'Poisson pané · Cheddar · Salade · Tomate · Oignon · Sauce',
      { viandes: 0, has_crudites: true, has_menu_addon: true, has_sauce: true, has_extra_meat: true, emoji: '🐟', time: 10 }),
    mkItem(405, 'big-burger', 4, 'Big Burger', 9.00,
      '3 steaks · 3 cheddars · 2 jambons de dinde · Salade · Tomate · Oignon · Sauce',
      { viandes: 0, has_crudites: true, has_menu_addon: true, has_sauce: true, has_extra_meat: true, is_featured: true, tags: ['XL'], emoji: '🍔', time: 13 }),
    mkItem(406, 'grill-burger', 4, 'Grill Burger', 8.00,
      '2 steaks · 2 cheddars · Jambon de dinde · Salade · Tomate · Oignon · Sauce',
      { viandes: 0, has_crudites: true, has_menu_addon: true, has_sauce: true, has_extra_meat: true, emoji: '🍔', time: 12 }),
  ];

  // ====== TACOS (cat 5) — seeder : Tacos M 6,90 (1 viande) / Tacos L 7,90 (2 viandes).
  //   Galette de blé · frites maison · sauce. Formule menu +2,50€.
  //   [GOAL-SYNC 2026-07-08] crudités RESTAURÉES (revert backend 05e5cacd0 2026-07-07 —
  //   fixture canon : extras group_label='crudite' ×4 sur items 26/97). ======
  const TACOS = [
    mkItem(501, 'tacos-m', 5, 'Tacos M', 6.90,
      'Galette de blé · 1 viande au choix · Frites maison · Sauce',
      { viandes: 1, has_crudites: true, has_menu_addon: true, has_sauce: true, has_extra_meat: true,
        is_featured: true, tags: ['SIGNATURE'], emoji: '🌮', time: 10 }),
    mkItem(502, 'tacos-l', 5, 'Tacos L', 7.90,
      'Galette de blé · 2 viandes au choix · Frites maison · Sauce',
      { viandes: 2, has_crudites: true, has_menu_addon: true, has_sauce: true, has_extra_meat: true,
        is_featured: true, tags: ['TOP'], emoji: '🌮', time: 12 }),
  ];

  // ====== BOLS (cat 6) — seeder : 2 produits @ 7,90, viande au choix (parmi 7), sauce, suppléments.
  //   Bol Riz : option gratiné (+2€, dans les suppléments du bol). Bol Frites : pas de gratiné.
  //   Note standalone : viande rendue via l'étape VIANDES du template 'tacos' (le renderer
  //   custom ne sait pas afficher un choix de viande) — choix parmi les 7 MEATS, requis. ======
  // [GOAL-SYNC 2026-07-08] has_sauce des bols servi par le pool BOL_SAUCES (2 options, attr 8
  //   canon) — PAS par les 12 sauces sandwich. has_extra_meat +2,50€ (canon).
  const BOLS = [
    mkItem(601, 'bol-frites', 6, 'Bol Frites', 7.90,
      'Frites maison · 1 viande au choix · Sauce · Suppléments optionnels',
      { viandes: 1, has_crudites: false, has_menu_addon: false, has_sauce: true, has_supplements: true,
        has_extra_meat: true, emoji: '🥣', time: 10 }),
    mkItem(602, 'bol-riz', 6, 'Bol Riz', 7.90,
      'Riz · 1 viande au choix · Sauce · Suppléments optionnels · Option gratiné',
      { viandes: 1, has_crudites: false, has_menu_addon: false, has_sauce: true, has_supplements: true,
        has_extra_meat: true, emoji: '🥣', time: 10 }),
  ];

  // ====== FRITES (cat 7) — Petite/Grande, style au choix (option client standalone). ======
  const FRITES = [
    mkItem(701, 'petite-frites', 7, 'Petite Frites', 2.50,
      'Portion petite · Style au choix (Nature / Cheddar fondu +1€ / Cheddar+Oignons +2€)',
      { has_sauce: false, has_crudites: false, has_supplements: false, has_menu_addon: false, has_frites_style: true, time: 4, emoji: '🍟', is_vegetarian: true }),
    mkItem(702, 'grande-frites', 7, 'Grande Frites', 4.00,
      'Portion grande · Style au choix (Nature / Cheddar fondu +1€ / Cheddar+Oignons +2€)',
      { has_sauce: false, has_crudites: false, has_supplements: false, has_menu_addon: false, has_frites_style: true, time: 5, emoji: '🍟', is_vegetarian: true }),
  ];

  // ====== DESSERTS (cat 9) — seeder : 3,50€. [GOAL-SYNC 2026-07-08] is_vegetarian:true (canon). ======
  const DESSERTS = [
    mkItem(901, 'glace',      9, 'Glace',      3.50, 'Glace artisanale', { has_sauce: false, has_crudites: false, has_supplements: false, has_menu_addon: false, is_vegetarian: true, time: 0, emoji: '🍦', allergens: ['lactose'] }),
    mkItem(902, 'tarte-daim', 9, 'Tarte Daim', 3.50, 'Tarte au Daim',    { has_sauce: false, has_crudites: false, has_supplements: false, has_menu_addon: false, is_vegetarian: true, time: 0, emoji: '🍰', allergens: ['gluten', 'lactose'] }),
    mkItem(903, 'tiramisu',   9, 'Tiramisu',   3.50, 'Tiramisu maison',  { has_sauce: false, has_crudites: false, has_supplements: false, has_menu_addon: false, is_vegetarian: true, time: 0, emoji: '🍰', allergens: ['gluten', 'lactose', 'oeuf'] }),
  ];

  // ====== BOISSONS (cat 10) — seeder : canettes 1,90€ ; Eau 1,00€ (Capri-Sun inchangé). ======
  const DRINKS = [
    mkItem(1001, 'coca',        10, 'Coca-Cola 33cl',      1.90, 'Coca-Cola original',   { has_sauce: false, has_crudites: false, has_supplements: false, has_menu_addon: false, time: 0, emoji: '🥤' }),
    mkItem(1002, 'coca-zero',   10, 'Coca-Cola Zero 33cl', 1.90, 'Coca-Cola sans sucre', { has_sauce: false, has_crudites: false, has_supplements: false, has_menu_addon: false, time: 0, emoji: '🥤' }),
    mkItem(1003, 'fanta',       10, 'Fanta Orange 33cl',   1.90, 'Fanta Orange',         { has_sauce: false, has_crudites: false, has_supplements: false, has_menu_addon: false, time: 0, emoji: '🍊' }),
    mkItem(1004, 'sprite',      10, 'Sprite 33cl',         1.90, 'Sprite',               { has_sauce: false, has_crudites: false, has_supplements: false, has_menu_addon: false, time: 0, emoji: '🍋' }),
    mkItem(1005, 'oasis',       10, 'Oasis Tropical 33cl', 1.90, 'Oasis Tropical',       { has_sauce: false, has_crudites: false, has_supplements: false, has_menu_addon: false, time: 0, emoji: '🌴' }),
    mkItem(1006, 'orangina',    10, 'Orangina 33cl',       1.90, 'Orangina',             { has_sauce: false, has_crudites: false, has_supplements: false, has_menu_addon: false, time: 0, emoji: '🍊' }),
    mkItem(1007, 'eau-plate',   10, 'Eau Plate 50cl',      1.00, 'Eau minérale',         { has_sauce: false, has_crudites: false, has_supplements: false, has_menu_addon: false, time: 0, emoji: '💧' }),
    mkItem(1008, 'capri-sun',   10, 'Capri-Sun',           1.50, 'Capri-Sun 20cl',       { has_sauce: false, has_crudites: false, has_supplements: false, has_menu_addon: false, time: 0, emoji: '🧃' }),
    // [GOAL-SYNC 2026-07-08] +7 boissons canon (fixture ids 119-125) @ 1,90€ — noms/desc EXACTS.
    mkItem(1009, 'coca-cherry',   10, 'Coca Cherry 33cl',   1.90, 'Coca-Cola Cherry',     { has_sauce: false, has_crudites: false, has_supplements: false, has_menu_addon: false, time: 0, emoji: '🥤' }),
    mkItem(1010, 'tropico',       10, 'Tropico 33cl',       1.90, 'Tropico',              { has_sauce: false, has_crudites: false, has_supplements: false, has_menu_addon: false, time: 0, emoji: '🌴' }),
    mkItem(1011, 'ice-tea-peche', 10, 'Ice Tea Pêche 33cl', 1.90, 'Ice Tea saveur pêche', { has_sauce: false, has_crudites: false, has_supplements: false, has_menu_addon: false, time: 0, emoji: '🥤' }),
    mkItem(1012, 'fanta-citron',  10, 'Fanta Citron 33cl',  1.90, 'Fanta Citron',         { has_sauce: false, has_crudites: false, has_supplements: false, has_menu_addon: false, time: 0, emoji: '🍋' }),
    mkItem(1013, 'fuze-tea',      10, 'Fuze Tea 33cl',      1.90, 'Fuze Tea',             { has_sauce: false, has_crudites: false, has_supplements: false, has_menu_addon: false, time: 0, emoji: '🥤' }),
    mkItem(1014, 'hawai',         10, 'Hawaï 33cl',         1.90, 'Hawaï',                { has_sauce: false, has_crudites: false, has_supplements: false, has_menu_addon: false, time: 0, emoji: '🌴' }),
    mkItem(1015, 'perrier',       10, 'Perrier 33cl',       1.90, 'Perrier (eau gazeuse)', { has_sauce: false, has_crudites: false, has_supplements: false, has_menu_addon: false, time: 0, emoji: '💧' }),
  ];

  // ====== MENU ENFANT (cat 11) — seeder : 2 SKU @ 4,90€ (Nuggets / Burger), frites + Capri-Sun inclus. ======
  const MENU_ENFANT = [
    mkItem(1101, 'menu-enfant-nuggets', 11, 'Menu Enfant Nuggets', 4.90,
      '6 nuggets · Frites · Capri-Sun',
      { has_sauce: false, has_crudites: false, has_supplements: false, has_menu_addon: false, time: 8, emoji: '🧒', tags: ['ENFANT'] }),
    // [GOAL-SYNC 2026-07-08] renommé 'Menu Enfant Burger'→'Menu Enfant Chicken Burger'
    // (nom canonique EXACT — la résolution API au wireup se fait PAR NOM ; slug conservé).
    mkItem(1102, 'menu-enfant-burger', 11, 'Menu Enfant Chicken Burger', 4.90,
      'Chicken burger · Frites · Capri-Sun',
      { has_sauce: false, has_crudites: false, has_supplements: false, has_menu_addon: false, time: 8, emoji: '🧒', tags: ['ENFANT'] }),
  ];

  // -------------------------------------------------------------------------
  // ALL ITEMS ([GOAL-SYNC 2026-07-08] — 38 produits sur 9 catégories ; les 4 SKU
  //   « Frites Cheddar » canoniques restent servis par le wizard frites_style —
  //   divergence STRUCTURELLE ACCEPTÉE, prix atteignables au centime, cf. CONTRACTS.md §5)
  // -------------------------------------------------------------------------
  const ITEMS = [
    ...SANDWICHS, ...GALETTE, ...BURGERS, ...TACOS,
    ...BOLS, ...FRITES, ...DESSERTS, ...DRINKS, ...MENU_ENFANT,
  ];

  // -------------------------------------------------------------------------
  // PRICE CALCULATOR
  // -------------------------------------------------------------------------
  function priceFor(item, opts) {
    opts = opts || {};
    let total = item.price;
    // [GOAL-SYNC 2026-07-08] Multi-sauces +0,50€ SUPPRIMÉE : canon = 1 sauce max
    // (min1/max1) sur sandwich/tacos/burger — aucun template ne la prévoit.
    // Suppléments génériques
    (opts.supplementIds || []).forEach(id => {
      const s = SUPPLEMENTS.find(x => x.id === id);
      if (s) total += s.price;
    });
    // [GOAL-SYNC 2026-07-08] Viande supplémentaire +2,50€ par viande ajoutée (canon).
    if (Array.isArray(opts.extraMeatIds)) {
      total += opts.extraMeatIds.length * EXTRA_MEAT_PRICE;
    }
    // Suppléments bols (gratiné +2€)
    (opts.bolSupplementIds || []).forEach(id => {
      const s = SUPPLEMENTS_BOLS.find(x => x.id === id);
      if (s) total += s.price;
    });
    // Bol drink addon (optionnel — prix catalogue Boissons : 1.90€ standard / 1.00€ eau)
    if (opts.bolDrinkId) {
      total += priceForDrinkAddon(opts.bolDrinkId);
    }
    // Formule menu
    if (opts.formuleId) {
      const f = FORMULES.find(x => x.id === opts.formuleId);
      if (f) total += f.price;
    }
    // Frites style upgrade
    if (opts.fritesStyleId) {
      const fs = FRITES_STYLES.find(x => x.id === opts.fritesStyleId);
      if (fs) total += fs.price;
    }
    // [GOAL-SYNC 2026-07-08] Cascade sauce des frites du menu = instruction GRATUITE
    // (canon) — l'ancienne surtaxe +0,50€/sauce supplémentaire est retirée.
    return total * (opts.qty || 1);
  }

  function defaultCruditeIds() {
    return CRUDITES.filter(c => c.default).map(c => c.id);
  }

  function defaultSauceId() {
    return null;
  }

  // -------------------------------------------------------------------------
  // EXPORT
  // -------------------------------------------------------------------------
  window.LC = window.LC || {};
  window.LC.menu = {
    branch: BRANCH,
    categories: CATEGORIES,
    items: ITEMS,
    meats: MEATS,
    sauces: SAUCES,
    crudites: CRUDITES,
    supplements: SUPPLEMENTS,
    supplementsBols: SUPPLEMENTS_BOLS,
    // [GOAL-SYNC 2026-07-08] nouveaux pools canon (mêmes noms d'export que le web).
    bolSauces: BOL_SAUCES,
    pains: PAINS,
    extraMeatPrice: EXTRA_MEAT_PRICE,
    bolBases: BOL_BASES,
    formules: FORMULES,
    fritesStyles: FRITES_STYLES,
    formuleDrinks: FORMULE_DRINKS,
    findItem(idOrSlug) {
      return ITEMS.find(i => i.id === idOrSlug || i.slug === idOrSlug);
    },
    findCategory(idOrSlug) {
      return CATEGORIES.find(c => c.id === idOrSlug || c.slug === idOrSlug);
    },
    itemsForCategory(categoryId) {
      return ITEMS.filter(i => i.category_id === categoryId);
    },
    priceFor,
    priceForDrinkAddon, // bol drink addon pricing (mirror DB addon item price)
    defaultCruditeIds,
    defaultSauceId,
    // [MOBILE-REALIGNMENT 2026-05-16] composer profile helpers — exposed so wizard
    // render layer can lazy-build from item OR read API response in future wireup.
    buildFritesComposerProfile,
  };

  // Backwards-compat globals
  window.ITEMS = ITEMS.map(i => ({
    ...i,
    cat: (CATEGORIES.find(c => c.id === i.category_id) || {}).slug || 'other',
    desc: i.description,
    slot: i.thumb,
  }));
  window.CATS = CATEGORIES.map(c => ({
    id: c.slug,
    label: c.name,
    icon: c.icon,
    sort: c.sort,
    backendId: c.id,
    wizard_template: c.wizard_template,
    has_menu: c.has_menu,
    image: c.image,
  }));
})();
