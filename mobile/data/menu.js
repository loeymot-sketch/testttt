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
// Crudités (3) : Salade, Tomate, Oignon.
// Suppléments (9 @ 0.90€) : Oignons frits, Champignons, Jambon, Cheddar, Raclette,
//                            Emmental, Boursin, Œuf, Légumes sautés.
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
  const MEATS = [
    { id: 'm-mexicanos',    name: 'Mexicanos',     price: 0, emoji: '🌶️', image: ASSET_BASE + 'viande-marine.png' },
    { id: 'm-cordon-bleu',  name: 'Cordon Bleu',   price: 0, emoji: '🍗', image: ASSET_BASE + 'viande-crispy.png' },
    { id: 'm-viande-hachee', name: 'Viande Hachée', price: 0, emoji: '🥩', image: ASSET_BASE + 'viande-tandoori.png' },
    { id: 'm-nuggets',      name: 'Nuggets',       price: 0, emoji: '🍗', image: ASSET_BASE + 'viande-crispy.png' },
    { id: 'm-tenders',      name: 'Tenders',       price: 0, emoji: '🍗', image: ASSET_BASE + 'viande-marine.png' },
    { id: 'm-fricadelle',   name: 'Fricadelle',    price: 0, emoji: '🌭', image: ASSET_BASE + 'viande-curry.png' },
    { id: 'm-poulet-marine', name: 'Poulet mariné', price: 0, emoji: '🍗', image: ASSET_BASE + 'viande-marine.png' },
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

  // 3 crudités (seeder GARNITURES — Salade/Tomate/Oignon ; Cornichon supprimé).
  const CRUDITES = [
    { id: 'c-salade', name: 'Salade', default: true, image: ASSET_BASE + 'salade.png' },
    { id: 'c-tomate', name: 'Tomate', default: true, image: ASSET_BASE + 'tomate.png' },
    { id: 'c-oignon', name: 'Oignon', default: true, image: ASSET_BASE + 'oignon.png' },
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
    { id: 'sup-boursin',        name: 'Boursin',        price: 0.90, image: ASSET_BASE + 'boursin.png',           allergens: ['lactose'] },
    { id: 'sup-oeuf',           name: 'Œuf',            price: 0.90, image: ASSET_BASE + 'oeuf.png',              allergens: ['oeuf'] },
    { id: 'sup-legumes-sautes', name: 'Légumes sautés', price: 0.90, image: ASSET_BASE + 'legumes-sautes.png',    allergens: [] },
  ];

  // Suppléments spécifiques aux bols (seeder SUPP_GROUP_BOL : 9 suppléments + gratiné +2€ sur riz).
  const SUPPLEMENTS_BOLS = [
    { id: 'sb-oignons-frits',  name: 'Oignons frits',  price: 0.90, image: ASSET_BASE + 'oignons-frits.png' },
    { id: 'sb-champignons',    name: 'Champignons',    price: 0.90, image: ASSET_BASE + 'champignons.png' },
    { id: 'sb-jambon',         name: 'Jambon',         price: 0.90, image: ASSET_BASE + 'jambon-dinde.png' },
    { id: 'sb-cheddar',        name: 'Cheddar',        price: 0.90, image: ASSET_BASE + 'cheddar.png' },
    { id: 'sb-gratine',        name: 'Option Gratiné', price: 2.00, image: ASSET_BASE + 'bol-frites-gratine.png' },
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

  // Boissons formule menu
  const FORMULE_DRINKS = [
    { id: 'd-coca',      name: 'Coca-Cola 33cl',      emoji: '🥤', image: ASSET_BASE + 'coca.png' },
    { id: 'd-coca-zero', name: 'Coca-Cola Zero 33cl', emoji: '🥤', image: ASSET_BASE + 'coca-zero.png' },
    { id: 'd-fanta',     name: 'Fanta Orange 33cl',   emoji: '🍊', image: ASSET_BASE + 'fanta-orange.png' },
    { id: 'd-sprite',    name: 'Sprite 33cl',         emoji: '🍋', image: ASSET_BASE + 'sprite.png' },
    { id: 'd-oasis',     name: 'Oasis Tropical 33cl', emoji: '🌴', image: ASSET_BASE + 'oasis.png' },
    { id: 'd-orangina',  name: 'Orangina 33cl',       emoji: '🍊', image: ASSET_BASE + 'tropico.png' },
    { id: 'd-eau',       name: 'Eau Plate 50cl',      emoji: '💧', image: ASSET_BASE + 'eau.png' },
    { id: 'd-capri',     name: 'Capri-Sun',           emoji: '🧃', image: ASSET_BASE + 'capri-sun.png' },
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
    { id: 6,  slug: 'bols',         name: 'Bols',        icon: '🥣', sort: 5,  wizard_template: 'tacos',    has_menu: false, description: 'Bol Frites ou Bol Riz, viande au choix',          image: ASSET_BASE + 'cat-bols-gourmands.png' },
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
      is_halal: opts.is_halal !== false,
      is_vegetarian: !!opts.is_vegetarian,
      viandes: opts.viandes ?? 0,
      viande_count: opts.viandes ?? 0, // canonical field name (kiosk parity)
      has_sauce: opts.has_sauce !== false,
      has_crudites: !!opts.has_crudites,
      has_supplements: opts.has_supplements !== false,
      has_menu_addon: !!opts.has_menu_addon,
      has_frites_style: !!opts.has_frites_style,
      has_bol_wizard: !!opts.has_bol_wizard,
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
  function priceForDrinkAddon(formuleDrinkId) {
    const drinkPriceMap = {
      'd-coca': 1.90, 'd-coca-zero': 1.90, 'd-fanta': 1.90, 'd-sprite': 1.90,
      'd-oasis': 1.90, 'd-orangina': 1.90, 'd-eau': 1.00, 'd-capri': 1.90,
    };
    return drinkPriceMap[formuleDrinkId] !== undefined ? drinkPriceMap[formuleDrinkId] : 1.90;
  }

  // ====== SANDWICHS (cat 1) — seeder : Cayenne 7,40 / Suprême 7,00 / Méga 8,00 / Terminator 9,00
  //   Pain au choix (Pain/Galette) ; Méga & Terminator = 2 viandes au choix. Formule menu +2,50€. ======
  const SANDWICHS = [
    mkItem(101, 'cayenne', 1, 'Cayenne', 7.40,
      'Poulet mariné · Cheddar · Jambon · Oignons rouges · Tomates · Salade · Sauce fromagère maison',
      { viandes: 0, has_crudites: true, has_menu_addon: true, sauce_locked: 'Sauce fromagère maison', has_sauce: false,
        is_featured: true, tags: ['SIGNATURE'], emoji: '🌶️', is_spicy: true, time: 10 }),
    mkItem(102, 'supreme', 1, 'Suprême', 7.00,
      'Steak haché · Cordon bleu · Cheddar · Oignons rouges · Tomates · Salade · Sauce au choix',
      { viandes: 0, has_crudites: true, has_menu_addon: true, has_sauce: true, time: 10, emoji: '🥖' }),
    mkItem(103, 'mega', 1, 'Méga', 8.00,
      '2 viandes au choix · Cheddar · Œuf · Oignons rouges · Tomates · Salade · Sauce au choix',
      { viandes: 2, has_crudites: true, has_menu_addon: true, has_sauce: true,
        is_featured: true, tags: ['XL'], time: 12, emoji: '🥖' }),
    mkItem(104, 'terminator', 1, 'Terminator', 9.00,
      '2 viandes au choix · 2 cheddars · Œuf · Jambon de dinde · Oignons rouges · Tomates · Salade · Sauce au choix',
      { viandes: 2, has_crudites: true, has_menu_addon: true, has_sauce: true,
        is_featured: true, tags: ['XL'], time: 12, emoji: '🥖' }),
  ];

  // ====== GALETTE (cat 2) — conservée (gate owner) ; 7 viandes + 12 sauces (pools partagés). ======
  const GALETTE = [
    mkItem(201, 'galette-normale', 2, 'Galette Normale', 6.50,
      'Galette traditionnelle · 1 viande au choix · Sauce au choix · Crudités · Suppléments optionnels',
      { viandes: 1, has_crudites: true, has_menu_addon: true, has_sauce: true, time: 8, emoji: '🌯' }),
    mkItem(202, 'galette-cayenne', 2, 'Galette Cayenne', 7.00,
      'Galette signature · 1 viande au choix · Sauce au choix · Crudités · Suppléments optionnels',
      { viandes: 1, has_crudites: true, has_menu_addon: true, has_sauce: true,
        is_featured: true, tags: ['SIGNATURE'], emoji: '🌶️', is_spicy: true, time: 8 }),
  ];

  // ====== BURGERS (cat 4) — seeder : 6 burgers, compositions fixes (PAS de choix viande). ======
  const BURGERS = [
    mkItem(401, 'chicken-burger', 4, 'Chicken Burger', 4.90,
      'Salade · Tomate · Oignon · Sauce',
      { viandes: 0, has_crudites: true, has_menu_addon: true, has_sauce: true, emoji: '🍔', time: 10 }),
    mkItem(402, 'cheese-burger', 4, 'Cheese Burger', 6.00,
      'Steak · Cheddar · Salade · Tomate · Oignon · Sauce',
      { viandes: 0, has_crudites: true, has_menu_addon: true, has_sauce: true, emoji: '🍔', time: 10 }),
    mkItem(403, 'double-cheese', 4, 'Double Cheese', 7.00,
      '2 steaks · 2 cheddars · Salade · Tomate · Oignon · Sauce',
      { viandes: 0, has_crudites: true, has_menu_addon: true, has_sauce: true, is_featured: true, emoji: '🍔', time: 11 }),
    mkItem(404, 'fish-burger', 4, 'Fish Burger', 6.00,
      'Poisson pané · Cheddar · Salade · Tomate · Oignon · Sauce',
      { viandes: 0, has_crudites: true, has_menu_addon: true, has_sauce: true, emoji: '🐟', time: 10 }),
    mkItem(405, 'big-burger', 4, 'Big Burger', 9.00,
      '3 steaks · 3 cheddars · 2 jambons de dinde · Salade · Tomate · Oignon · Sauce',
      { viandes: 0, has_crudites: true, has_menu_addon: true, has_sauce: true, is_featured: true, tags: ['XL'], emoji: '🍔', time: 13 }),
    mkItem(406, 'grill-burger', 4, 'Grill Burger', 8.00,
      '2 steaks · 2 cheddars · Jambon de dinde · Salade · Tomate · Oignon · Sauce',
      { viandes: 0, has_crudites: true, has_menu_addon: true, has_sauce: true, emoji: '🍔', time: 12 }),
  ];

  // ====== TACOS (cat 5) — seeder : Tacos M 6,90 (1 viande) / Tacos L 7,90 (2 viandes).
  //   Galette de blé · frites maison · sauce. SANS crudités (owner). Formule menu +2,50€. ======
  const TACOS = [
    mkItem(501, 'tacos-m', 5, 'Tacos M', 6.90,
      'Galette de blé · 1 viande au choix · Frites maison · Sauce',
      { viandes: 1, has_crudites: false, has_menu_addon: true, has_sauce: true,
        is_featured: true, tags: ['SIGNATURE'], emoji: '🌮', time: 10 }),
    mkItem(502, 'tacos-l', 5, 'Tacos L', 7.90,
      'Galette de blé · 2 viandes au choix · Frites maison · Sauce',
      { viandes: 2, has_crudites: false, has_menu_addon: true, has_sauce: true,
        is_featured: true, tags: ['TOP'], emoji: '🌮', time: 12 }),
  ];

  // ====== BOLS (cat 6) — seeder : 2 produits @ 7,90, viande au choix (parmi 7), sauce, suppléments.
  //   Bol Riz : option gratiné (+2€, dans les suppléments du bol). Bol Frites : pas de gratiné.
  //   Note standalone : viande rendue via l'étape VIANDES du template 'tacos' (le renderer
  //   custom ne sait pas afficher un choix de viande) — choix parmi les 7 MEATS, requis. ======
  const BOLS = [
    mkItem(601, 'bol-frites', 6, 'Bol Frites', 7.90,
      'Frites maison · 1 viande au choix · Sauce · Suppléments optionnels',
      { viandes: 1, has_crudites: false, has_menu_addon: false, has_sauce: true, has_supplements: true, emoji: '🥣', time: 10 }),
    mkItem(602, 'bol-riz', 6, 'Bol Riz', 7.90,
      'Riz · 1 viande au choix · Sauce · Suppléments optionnels · Option gratiné',
      { viandes: 1, has_crudites: false, has_menu_addon: false, has_sauce: true, has_supplements: true, emoji: '🥣', time: 10 }),
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

  // ====== DESSERTS (cat 9) — seeder : 3,50€. ======
  const DESSERTS = [
    mkItem(901, 'glace',      9, 'Glace',      3.50, 'Glace artisanale', { has_sauce: false, has_crudites: false, has_supplements: false, has_menu_addon: false, time: 0, emoji: '🍦', allergens: ['lactose'] }),
    mkItem(902, 'tarte-daim', 9, 'Tarte Daim', 3.50, 'Tarte au Daim',    { has_sauce: false, has_crudites: false, has_supplements: false, has_menu_addon: false, time: 0, emoji: '🍰', allergens: ['gluten', 'lactose'] }),
    mkItem(903, 'tiramisu',   9, 'Tiramisu',   3.50, 'Tiramisu maison',  { has_sauce: false, has_crudites: false, has_supplements: false, has_menu_addon: false, time: 0, emoji: '🍰', allergens: ['gluten', 'lactose', 'oeuf'] }),
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
    mkItem(1008, 'capri-sun',   10, 'Capri-Sun',           1.90, 'Capri-Sun 20cl',       { has_sauce: false, has_crudites: false, has_supplements: false, has_menu_addon: false, time: 0, emoji: '🧃' }),
  ];

  // ====== MENU ENFANT (cat 11) — seeder : 2 SKU @ 4,90€ (Nuggets / Burger), frites + Capri-Sun inclus. ======
  const MENU_ENFANT = [
    mkItem(1101, 'menu-enfant-nuggets', 11, 'Menu Enfant Nuggets', 4.90,
      '6 nuggets · Frites · Capri-Sun',
      { has_sauce: false, has_crudites: false, has_supplements: false, has_menu_addon: false, time: 8, emoji: '🧒', tags: ['ENFANT'] }),
    mkItem(1102, 'menu-enfant-burger', 11, 'Menu Enfant Burger', 4.90,
      'Burger · Frites · Capri-Sun',
      { has_sauce: false, has_crudites: false, has_supplements: false, has_menu_addon: false, time: 8, emoji: '🧒', tags: ['ENFANT'] }),
  ];

  // -------------------------------------------------------------------------
  // ALL ITEMS ([MENU-CANON 2026-06-26] — 25 produits sur 9 catégories)
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
    // Sauces : 1 gratuite, sup 0.50€ chacune au-delà
    if (Array.isArray(opts.sauceIds) && opts.sauceIds.length > 1) {
      total += (opts.sauceIds.length - 1) * 0.50;
    }
    // Suppléments génériques
    (opts.supplementIds || []).forEach(id => {
      const s = SUPPLEMENTS.find(x => x.id === id);
      if (s) total += s.price;
    });
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
    // Sauce frites cascade
    if (Array.isArray(opts.fritesSauceIds) && opts.fritesSauceIds.length > 1) {
      total += (opts.fritesSauceIds.length - 1) * 0.50;
    }
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
