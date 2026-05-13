// Le Cayenne — Catalogue mobile aligné FoodKing config/menu.php (SSOT)
// [MENU-RESET 2026-05-13] Restructuration globale : 9 catégories visibles.
//
// Source de vérité : /config/menu.php + artisan menu:reset-le-cayenne
// Catégories : Sandwich Cayenne, Galette, Sandwich Classique, Tacos,
//              Bols Gourmands, Frites, Suppléments, Desserts, Boissons.
// Viandes (4) : Poulet classic, Poulet curry, Poulet tandoori, Poulet crispy.
// Sauces (13) : Mayonnaise, Ketchup, Algérienne, Samouraï, Curry, Andalouse,
//               Harissa, Hannibal, Blanche, Tandoori, Fromagère, Pimentée, Cayenne.
// Bols : composer_profile custom (base frites/riz → sauce → suppléments → boisson optionnelle).
// Frites : composer_profile custom (style Nature/+Cheddar/+Cheddar+Oignons).

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

  // Items → images (réutilise generated_*.png existants où similaires)
  const ITEM_IMG = {
    // Sandwich Cayenne (1)
    'sandwich-cayenne-classique': 'generated_le-cayenne.png',
    // Galette (2)
    'galette-normale': 'generated_sandwich-classique-galette.png',
    'galette-cayenne': 'generated_sandwich-classique-galette.png',
    // Sandwich Classique (1)
    'sandwich-classique-faluche': 'generated_sandwich-classique-pain.png',
    // Tacos (2)
    'tacos-1-viande': 'generated_tacos-m-1-viande.png',
    'big-tacos-2-viandes': 'generated_tacos-l-2-viandes.png',
    // Bols (5)
    'bol-curry':    'generated_assiette-poulet.png',
    'bol-tandoori': 'generated_assiette-poulet.png',
    'bol-marine':   'generated_assiette-poulet.png',
    'bol-crousti':  'generated_assiette-poulet.png',
    'bol-gratine':  'generated_assiette-poulet.png',
    // Frites (2)
    'petite-frites': 'generated_frites-moyenne.png',
    'grande-frites': 'generated_frites-grande.png',
    // Desserts (existing kept)
    'glace': 'generated_glace.png',
    'tarte-daim': 'generated_tarte-daim.png',
    'tiramisu': 'generated_tiramisu.png',
    // Drinks (existing kept)
    'coca': 'generated_coca-cola-33cl.png',
    'coca-zero': 'generated_coca-cola-zero-33cl.png',
    'fanta': 'generated_fanta-orange-33cl.png',
    'sprite': 'generated_sprite-33cl.png',
    'oasis': 'generated_oasis-tropical-33cl.png',
    'orangina': 'generated_orangina-33cl.png',
    'eau-plate': 'generated_eau-plate-50cl.png',
    'capri-sun': 'generated_capri-sun.png',
    // Suppléments standalone
    'supp-cheddar': 'generated_fromage-supplementaire.png',
    'supp-raclette': 'generated_fromage-a-raclette.png',
    'supp-emmental': 'generated_fromage-supplementaire.png',
    'supp-oeuf': 'generated_oeuf.png',
    'supp-bacon': 'generated_jambon-de-dinde.png',
    'supp-legumes-sautes': 'generated_salade-verte.png',
    'supp-jambon': 'generated_jambon-de-dinde.png',
    'supp-oignons-frits': 'supplement_boursin.png',
    'supp-champignons': 'generated_omelette-champignons-fromage.png',
    'supp-boule-gratinee': 'generated_galette-pommes-de-terre.png',
  };

  // Signature heroes (bg-removed)
  const HERO_IMG = {
    'sandwich-cayenne-classique': 'signature/cayenne-hero.png',
    'galette-cayenne': 'signature/cayenne-hero.png',
    'tacos-1-viande': 'signature/tacos-hero.png',
    'big-tacos-2-viandes': 'signature/tacos-hero.png',
  };

  function imgFor(slug) {
    return ASSET_BASE + (ITEM_IMG[slug] || 'item-default.svg');
  }
  function heroFor(slug) {
    return HERO_IMG[slug] ? (ASSET_BASE + HERO_IMG[slug]) : imgFor(slug);
  }

  // -------------------------------------------------------------------------
  // SHARED OPTIONS — Le Cayenne 2026-05-13 canonical sets
  // -------------------------------------------------------------------------

  // 4 viandes canoniques (config/menu.php meats)
  const MEATS = [
    { id: 'm-classic',  name: 'Poulet classic',  price: 0, emoji: '🍗', image: ASSET_BASE + 'viande_escalope_poulet.png' },
    { id: 'm-curry',    name: 'Poulet curry',    price: 0, emoji: '🍛', image: ASSET_BASE + 'viande_escalope_poulet.png' },
    { id: 'm-tandoori', name: 'Poulet tandoori', price: 0, emoji: '🔥', image: ASSET_BASE + 'viande_escalope_poulet.png' },
    { id: 'm-crispy',   name: 'Poulet crispy',   price: 0, emoji: '🍗', image: ASSET_BASE + 'viande_tenders.png' },
  ];

  // 13 sauces canoniques
  const SAUCES = [
    { id: 's-mayo',       name: 'Mayonnaise', price: 0, image: ASSET_BASE + 'sauce_mayo.svg' },
    { id: 's-ketchup',    name: 'Ketchup',    price: 0, image: ASSET_BASE + 'sauce_ketchup.svg' },
    { id: 's-algerien',   name: 'Algérienne', price: 0, image: ASSET_BASE + 'sauce_algerienne.svg' },
    { id: 's-samurai',    name: 'Samouraï',   price: 0, image: ASSET_BASE + 'sauce_samourai.svg' },
    { id: 's-curry',      name: 'Curry',      price: 0, image: ASSET_BASE + 'sauce_curry.svg' },
    { id: 's-andalouse',  name: 'Andalouse',  price: 0, image: ASSET_BASE + 'sauce_andalouse.svg' },
    { id: 's-harissa',    name: 'Harissa',    price: 0, is_spicy: true, image: ASSET_BASE + 'sauce_harissa.svg' },
    { id: 's-hannibal',   name: 'Hannibal',   price: 0, is_spicy: true, image: ASSET_BASE + 'sauce_hannibal.svg' },
    { id: 's-blanche',    name: 'Blanche',    price: 0, image: ASSET_BASE + 'sauce_blanche.svg' },
    { id: 's-tandoori',   name: 'Tandoori',   price: 0, image: ASSET_BASE + 'sauce_curry.svg' },
    { id: 's-fromagere',  name: 'Fromagère',  price: 0, image: ASSET_BASE + 'sauce_blanche.svg' },
    { id: 's-pimentee',   name: 'Pimentée',   price: 0, is_spicy: true, image: ASSET_BASE + 'sauce_harissa.svg' },
    { id: 's-cayenne',    name: 'Cayenne',    price: 0, is_spicy: true, image: ASSET_BASE + 'sauce_harissa.svg' },
  ];

  // 4 crudités (Salade/Tomate/Oignon/Cornichon — Cornichon ajouté 2026-05-13)
  const CRUDITES = [
    { id: 'c-salade',    name: 'Salade',    default: true, image: ASSET_BASE + 'crudite_salade.png' },
    { id: 'c-tomate',    name: 'Tomate',    default: true, image: ASSET_BASE + 'crudite_tomate.png' },
    { id: 'c-oignon',    name: 'Oignon',    default: true, image: ASSET_BASE + 'crudite_oignon.png' },
    { id: 'c-cornichon', name: 'Cornichon', default: true, image: ASSET_BASE + 'crudite_oignon.png' },
  ];

  // 10 suppléments génériques (tous 1€)
  const SUPPLEMENTS = [
    { id: 'sup-cheddar',        name: 'Cheddar',        price: 1.00, image: ASSET_BASE + 'generated_fromage-supplementaire.png' },
    { id: 'sup-raclette',       name: 'Raclette',       price: 1.00, image: ASSET_BASE + 'supplement_raclette.png' },
    { id: 'sup-emmental',       name: 'Emmental',       price: 1.00, image: ASSET_BASE + 'supplement_fromage.png' },
    { id: 'sup-oeuf',           name: 'Œuf',            price: 1.00, image: ASSET_BASE + 'supplement_oeuf.png' },
    { id: 'sup-bacon',          name: 'Bacon',          price: 1.00, image: ASSET_BASE + 'supplement_jambon_dinde.png' },
    { id: 'sup-legumes-sautes', name: 'Légumes sautés', price: 1.00, image: ASSET_BASE + 'generated_salade-verte.png' },
    { id: 'sup-jambon',         name: 'Jambon',         price: 1.00, image: ASSET_BASE + 'supplement_jambon_dinde.png' },
    { id: 'sup-oignons-frits',  name: 'Oignons frits',  price: 1.00, image: ASSET_BASE + 'supplement_boursin.png' },
    { id: 'sup-champignons',    name: 'Champignons',    price: 1.00, image: ASSET_BASE + 'generated_omelette-champignons-fromage.png' },
    { id: 'sup-boule-gratinee', name: 'Boule gratinée', price: 1.00, image: ASSET_BASE + 'supplement_galette.png' },
  ];

  // Suppléments spécifiques aux bols (gratiné +2€)
  const SUPPLEMENTS_BOLS = [
    { id: 'sb-oignons-frits',  name: 'Oignons frits',   price: 1.00 },
    { id: 'sb-jambon',         name: 'Jambon',          price: 1.00 },
    { id: 'sb-champignons',    name: 'Champignons',     price: 1.00 },
    { id: 'sb-boule-gratinee', name: 'Boule gratinée',  price: 2.00 },
  ];

  // Formules menu (unchanged — addons existants)
  const FORMULES = [
    { id: 'f-menu',    name: 'Menu (Frites + Boisson)', price: 3.00, has_drink: true, has_fries: true },
    { id: 'f-frites',  name: 'Ajouter Frites',           price: 2.00, has_fries: true },
    { id: 'f-boisson', name: 'Ajouter Boisson',          price: 2.00, has_drink: true },
  ];

  // Frites styles (Nature / Cheddar +1€ / Cheddar+Oignons +2€) — owner update 2026-05-13
  const FRITES_STYLES = [
    { id: null,                name: 'Nature',                   price: 0,    is_default: true, emoji: '🍟', image: ASSET_BASE + 'frites.png' },
    { id: 'fs-cheddar',        name: 'Cheddar fondu',            price: 1.00, emoji: '🧀',                  image: ASSET_BASE + 'supplement_cheddar.png' },
    { id: 'fs-cheddar-oignon', name: 'Cheddar + Oignons frits',  price: 2.00, emoji: '🧅',                  image: ASSET_BASE + 'generated_frites-grande.png' },
  ];

  // Bases bols (Frites / Riz basmati)
  const BOL_BASES = [
    { id: 'bb-frites', name: 'Frites',       price: 0, image: ASSET_BASE + 'frites.png' },
    { id: 'bb-riz',    name: 'Riz basmati',  price: 0, image: ASSET_BASE + 'generated_assiette-poulet.png' },
  ];

  // Boissons formule menu
  const FORMULE_DRINKS = [
    { id: 'd-coca',      name: 'Coca-Cola 33cl',      emoji: '🥤', image: ASSET_BASE + 'coca_cola.png' },
    { id: 'd-coca-zero', name: 'Coca-Cola Zero 33cl', emoji: '🥤', image: ASSET_BASE + 'coca_zero.png' },
    { id: 'd-fanta',     name: 'Fanta Orange 33cl',   emoji: '🍊', image: ASSET_BASE + 'fanta.png' },
    { id: 'd-sprite',    name: 'Sprite 33cl',         emoji: '🍋', image: ASSET_BASE + 'sprite.png' },
    { id: 'd-oasis',     name: 'Oasis Tropical 33cl', emoji: '🌴', image: ASSET_BASE + 'oasis_tropical.png' },
    { id: 'd-orangina',  name: 'Orangina 33cl',       emoji: '🍊', image: ASSET_BASE + 'orangina.png' },
    { id: 'd-eau',       name: 'Eau Plate 50cl',      emoji: '💧', image: ASSET_BASE + 'eau.png' },
    { id: 'd-capri',     name: 'Capri-Sun',           emoji: '🧃', image: ASSET_BASE + 'capri_sun.png' },
  ];

  // -------------------------------------------------------------------------
  // CATEGORIES (9 nouvelles)
  // -------------------------------------------------------------------------
  const CATEGORIES = [
    { id: 1, slug: 'sandwich-cayenne',   name: 'Sandwich Cayenne',   icon: '🥖', sort: 1, wizard_template: 'sandwich', has_menu: true,  description: 'Sandwich signature avec sauce Cayenne maison', image: ASSET_BASE + 'generated_le-cayenne.png' },
    { id: 2, slug: 'galette',            name: 'Galette',            icon: '🌯', sort: 2, wizard_template: 'sandwich', has_menu: true,  description: 'Galette traditionnelle ou Cayenne',            image: ASSET_BASE + 'generated_sandwich-classique-galette.png' },
    { id: 3, slug: 'sandwich-classique', name: 'Sandwich Classique', icon: '🥖', sort: 3, wizard_template: 'sandwich', has_menu: true,  description: 'Sandwich classique en pain faluche',           image: ASSET_BASE + 'generated_sandwich-classique-pain.png' },
    { id: 4, slug: 'tacos',              name: 'Tacos',              icon: '🌮', sort: 4, wizard_template: 'tacos',    has_menu: true,  description: 'Tacos 1 viande ou Big Tacos 2 viandes',         image: ASSET_BASE + 'generated_category_nos-tacos.png' },
    { id: 5, slug: 'bols-gourmands',     name: 'Bols Gourmands',     icon: '🥣', sort: 5, wizard_template: 'custom',   has_menu: false, description: 'Curry / Tandoori / Mariné / Crousti / Gratiné', image: ASSET_BASE + 'generated_assiette-poulet.png' },
    { id: 6, slug: 'frites',             name: 'Frites',             icon: '🍟', sort: 6, wizard_template: 'custom',   has_menu: false, description: 'Petite ou Grande, style au choix',              image: ASSET_BASE + 'generated_category_frites-accompagnements.png' },
    { id: 7, slug: 'supplements',        name: 'Suppléments',        icon: '➕', sort: 7, wizard_template: 'simple',   has_menu: false, description: 'Suppléments commandables séparément',           image: ASSET_BASE + 'generated_category_supplements.png' },
    { id: 8, slug: 'desserts',           name: 'Desserts',           icon: '🍰', sort: 8, wizard_template: 'simple',   has_menu: false, description: 'Desserts gourmands',                            image: ASSET_BASE + 'generated_category_nos-desserts.png' },
    { id: 9, slug: 'boissons',           name: 'Boissons',           icon: '🥤', sort: 9, wizard_template: 'simple',   has_menu: false, description: 'Boissons fraîches',                             image: ASSET_BASE + 'generated_category_nos-boissons.png' },
  ];

  // -------------------------------------------------------------------------
  // ITEMS HELPER
  // -------------------------------------------------------------------------
  function defaultAllergensFor(cat, opts) {
    if (opts && opts.allergens !== undefined) return opts.allergens;
    switch (cat) {
      case 1: case 2: case 3: case 4: return ['gluten']; // Sandwich Cayenne/Galette/Classique/Tacos pain/galette
      case 5: return [];           // Bols (no bread, sauce in own field)
      case 6: return [];           // Frites
      case 7: return [];           // Supplements (per-item override)
      case 8: return ['gluten', 'lactose']; // Desserts
      case 9: return [];           // Boissons
      default: return [];
    }
  }

  function mkItem(id, slug, category_id, name, price, description, opts) {
    opts = opts || {};
    return {
      id, slug, category_id, name, price, description,
      thumb: 'item-' + slug,
      image: imgFor(slug),
      hero: heroFor(slug),
      kiosk_emoji: opts.emoji || '',
      time: opts.time !== undefined ? opts.time : 8,
      tags: opts.tags || [],
      is_featured: !!opts.is_featured,
      is_new: !!opts.is_new,
      is_spicy: !!opts.is_spicy,
      is_halal: opts.is_halal !== false,
      is_vegetarian: !!opts.is_vegetarian,
      viandes: opts.viandes ?? 0,
      has_sauce: opts.has_sauce !== false,
      has_crudites: !!opts.has_crudites,
      has_supplements: opts.has_supplements !== false,
      has_menu_addon: !!opts.has_menu_addon,
      has_frites_style: !!opts.has_frites_style,
      has_bol_wizard: !!opts.has_bol_wizard,
      sauce_locked: opts.sauce_locked || null,
      bol_meat_fixed: opts.bol_meat_fixed || null,
      bol_sauce_default: opts.bol_sauce_default || null,
      allergens: defaultAllergensFor(category_id, opts),
    };
  }

  // ====== SANDWICH CAYENNE (cat 1) ======
  const SANDWICH_CAYENNE = [
    mkItem(101, 'sandwich-cayenne-classique', 1, 'Sandwich Cayenne', 7.00,
      'Sauce Cayenne maison incluse · 1 viande au choix · Crudités · Suppléments optionnels',
      { viandes: 1, has_crudites: true, has_menu_addon: true, sauce_locked: 'Cayenne', has_sauce: false,
        is_featured: true, tags: ['SIGNATURE'], emoji: '🌶️', is_spicy: true, time: 10 }),
  ];

  // ====== GALETTE (cat 2) ======
  const GALETTE = [
    mkItem(201, 'galette-normale', 2, 'Galette Normale', 6.50,
      'Galette traditionnelle · 1 viande · Sauce au choix · Crudités · Suppléments optionnels',
      { viandes: 1, has_crudites: true, has_menu_addon: true, has_sauce: true, time: 8, emoji: '🌯' }),
    mkItem(202, 'galette-cayenne', 2, 'Galette Cayenne', 7.00,
      'Galette signature · 1 viande · Sauce Cayenne maison · Crudités · Suppléments optionnels',
      { viandes: 1, has_crudites: true, has_menu_addon: true, sauce_locked: 'Cayenne', has_sauce: false,
        is_featured: true, tags: ['SIGNATURE'], emoji: '🌶️', is_spicy: true, time: 8 }),
  ];

  // ====== SANDWICH CLASSIQUE (cat 3) ======
  const SANDWICH_CLASSIQUE = [
    mkItem(301, 'sandwich-classique-faluche', 3, 'Sandwich Classique', 6.50,
      'Pain faluche · 1 viande · Sauce au choix · Crudités · Suppléments optionnels',
      { viandes: 1, has_crudites: true, has_menu_addon: true, has_sauce: true, time: 8, emoji: '🥖' }),
  ];

  // ====== TACOS (cat 4) ======
  const TACOS = [
    mkItem(401, 'tacos-1-viande', 4, 'Tacos', 8.50,
      '1 viande au choix · Frites maison · Sauce fromagère maison',
      { viandes: 1, has_crudites: false, has_menu_addon: true, has_sauce: false,
        is_featured: true, tags: ['SIGNATURE'], emoji: '🌮', time: 10 }),
    mkItem(402, 'big-tacos-2-viandes', 4, 'Big Tacos', 11.50,
      '2 viandes au choix · Frites maison · Sauce fromagère maison',
      { viandes: 2, has_crudites: false, has_menu_addon: true, has_sauce: false,
        is_featured: true, tags: ['TOP'], emoji: '🌮', time: 12 }),
  ];

  // ====== BOLS GOURMANDS (cat 5) — composer profile (base + sauce + supp + drink) ======
  const BOLS = [
    mkItem(501, 'bol-curry',    5, 'Bol Curry',    10.50,
      'Poulet curry + sauce curry maison · Base au choix (Frites ou Riz) · Suppléments optionnels',
      { has_bol_wizard: true, bol_meat_fixed: 'Poulet curry',    bol_sauce_default: 'Curry',     has_sauce: true, has_crudites: false, has_menu_addon: false, has_supplements: true, emoji: '🥣', time: 10 }),
    mkItem(502, 'bol-tandoori', 5, 'Bol Tandoori', 10.50,
      'Poulet tandoori + sauce tandoori · Base au choix · Suppléments optionnels',
      { has_bol_wizard: true, bol_meat_fixed: 'Poulet tandoori', bol_sauce_default: 'Tandoori',  has_sauce: true, has_crudites: false, has_menu_addon: false, has_supplements: true, emoji: '🥣', time: 10 }),
    mkItem(503, 'bol-marine',   5, 'Bol Mariné',   10.50,
      'Poulet mariné + sauce blanche maison · Base au choix · Suppléments optionnels',
      { has_bol_wizard: true, bol_meat_fixed: 'Poulet classic',  bol_sauce_default: 'Blanche',   has_sauce: true, has_crudites: false, has_menu_addon: false, has_supplements: true, emoji: '🥣', time: 10 }),
    mkItem(504, 'bol-crousti',  5, 'Bol Crousti',  10.50,
      'Poulet crispy + sauce fromagère maison · Base au choix · Suppléments optionnels',
      { has_bol_wizard: true, bol_meat_fixed: 'Poulet crispy',   bol_sauce_default: 'Fromagère', has_sauce: true, has_crudites: false, has_menu_addon: false, has_supplements: true, emoji: '🥣', time: 10 }),
    mkItem(505, 'bol-gratine',  5, 'Bol Gratiné',  12.50,
      'Poulet mariné + sauce fromagère maison + boule gratinée incluse · Base au choix',
      { has_bol_wizard: true, bol_meat_fixed: 'Poulet classic',  bol_sauce_default: 'Fromagère', has_sauce: true, has_crudites: false, has_menu_addon: false, has_supplements: true, tags: ['TOP'], emoji: '🧀', time: 12 }),
  ];

  // ====== FRITES (cat 6) — composer profile (style upgrade) ======
  const FRITES = [
    mkItem(601, 'petite-frites', 6, 'Petite Frites', 2.50,
      'Portion petite · Style au choix (Nature / Cheddar +1€ / Cheddar+Oignons +2€)',
      { has_sauce: false, has_crudites: false, has_supplements: false, has_menu_addon: false, has_frites_style: true, time: 4, emoji: '🍟', is_vegetarian: true }),
    mkItem(602, 'grande-frites', 6, 'Grande Frites', 4.00,
      'Portion grande · Style au choix (Nature / Cheddar +1€ / Cheddar+Oignons +2€)',
      { has_sauce: false, has_crudites: false, has_supplements: false, has_menu_addon: false, has_frites_style: true, time: 5, emoji: '🍟', is_vegetarian: true }),
  ];

  // ====== SUPPLÉMENTS (cat 7) — items commandables seuls (10 items 1€) ======
  const SUPPLEMENTS_ITEMS = [
    mkItem(701,  'supp-cheddar',        7, 'Cheddar',        1.00, 'Supplément cheddar',         { has_sauce: false, has_crudites: false, has_supplements: false, has_menu_addon: false, time: 0, emoji: '🧀', allergens: ['lactose'] }),
    mkItem(702,  'supp-raclette',       7, 'Raclette',       1.00, 'Supplément fromage raclette', { has_sauce: false, has_crudites: false, has_supplements: false, has_menu_addon: false, time: 0, emoji: '🧀', allergens: ['lactose'] }),
    mkItem(703,  'supp-emmental',       7, 'Emmental',       1.00, 'Supplément emmental',        { has_sauce: false, has_crudites: false, has_supplements: false, has_menu_addon: false, time: 0, emoji: '🧀', allergens: ['lactose'] }),
    mkItem(704,  'supp-oeuf',           7, 'Œuf',            1.00, 'Supplément œuf',              { has_sauce: false, has_crudites: false, has_supplements: false, has_menu_addon: false, time: 0, emoji: '🥚', allergens: ['oeuf'] }),
    mkItem(705,  'supp-bacon',          7, 'Bacon',          1.00, 'Supplément bacon',            { has_sauce: false, has_crudites: false, has_supplements: false, has_menu_addon: false, time: 0, emoji: '🥓', allergens: [] }),
    mkItem(706,  'supp-legumes-sautes', 7, 'Légumes sautés', 1.00, 'Supplément légumes sautés',   { has_sauce: false, has_crudites: false, has_supplements: false, has_menu_addon: false, time: 0, emoji: '🥬', is_vegetarian: true, allergens: [] }),
    mkItem(707,  'supp-jambon',         7, 'Jambon',         1.00, 'Supplément jambon',           { has_sauce: false, has_crudites: false, has_supplements: false, has_menu_addon: false, time: 0, emoji: '🥓', allergens: [] }),
    mkItem(708,  'supp-oignons-frits',  7, 'Oignons frits',  1.00, 'Supplément oignons frits',    { has_sauce: false, has_crudites: false, has_supplements: false, has_menu_addon: false, time: 0, emoji: '🧅', is_vegetarian: true, allergens: [] }),
    mkItem(709,  'supp-champignons',    7, 'Champignons',    1.00, 'Supplément champignons',      { has_sauce: false, has_crudites: false, has_supplements: false, has_menu_addon: false, time: 0, emoji: '🍄', is_vegetarian: true, allergens: [] }),
    mkItem(710,  'supp-boule-gratinee', 7, 'Boule gratinée', 1.00, 'Supplément boule gratinée',   { has_sauce: false, has_crudites: false, has_supplements: false, has_menu_addon: false, time: 0, emoji: '🧀', allergens: ['lactose'] }),
  ];

  // ====== DESSERTS (cat 8) ======
  const DESSERTS = [
    mkItem(801, 'glace',      8, 'Glace',      3.80, 'Glace artisanale', { has_sauce: false, has_crudites: false, has_supplements: false, has_menu_addon: false, time: 0, emoji: '🍦', allergens: ['lactose'] }),
    mkItem(802, 'tarte-daim', 8, 'Tarte Daim', 3.80, 'Tarte au Daim',    { has_sauce: false, has_crudites: false, has_supplements: false, has_menu_addon: false, time: 0, emoji: '🍰', allergens: ['gluten', 'lactose'] }),
    mkItem(803, 'tiramisu',   8, 'Tiramisu',   3.80, 'Tiramisu maison',  { has_sauce: false, has_crudites: false, has_supplements: false, has_menu_addon: false, time: 0, emoji: '🍰', allergens: ['gluten', 'lactose', 'oeuf'] }),
  ];

  // ====== BOISSONS (cat 9) ======
  const DRINKS = [
    mkItem(901, 'coca',        9, 'Coca-Cola 33cl',      1.50, 'Coca-Cola original',   { has_sauce: false, has_crudites: false, has_supplements: false, has_menu_addon: false, time: 0, emoji: '🥤' }),
    mkItem(902, 'coca-zero',   9, 'Coca-Cola Zero 33cl', 1.50, 'Coca-Cola sans sucre', { has_sauce: false, has_crudites: false, has_supplements: false, has_menu_addon: false, time: 0, emoji: '🥤' }),
    mkItem(903, 'fanta',       9, 'Fanta Orange 33cl',   1.50, 'Fanta Orange',         { has_sauce: false, has_crudites: false, has_supplements: false, has_menu_addon: false, time: 0, emoji: '🍊' }),
    mkItem(904, 'sprite',      9, 'Sprite 33cl',         1.50, 'Sprite',               { has_sauce: false, has_crudites: false, has_supplements: false, has_menu_addon: false, time: 0, emoji: '🍋' }),
    mkItem(905, 'oasis',       9, 'Oasis Tropical 33cl', 1.50, 'Oasis Tropical',       { has_sauce: false, has_crudites: false, has_supplements: false, has_menu_addon: false, time: 0, emoji: '🌴' }),
    mkItem(906, 'orangina',    9, 'Orangina 33cl',       1.50, 'Orangina',             { has_sauce: false, has_crudites: false, has_supplements: false, has_menu_addon: false, time: 0, emoji: '🍊' }),
    mkItem(907, 'eau-plate',   9, 'Eau Plate 50cl',      1.00, 'Eau minérale',         { has_sauce: false, has_crudites: false, has_supplements: false, has_menu_addon: false, time: 0, emoji: '💧' }),
    mkItem(908, 'capri-sun',   9, 'Capri-Sun',           1.50, 'Capri-Sun 20cl',       { has_sauce: false, has_crudites: false, has_supplements: false, has_menu_addon: false, time: 0, emoji: '🧃' }),
  ];

  // -------------------------------------------------------------------------
  // ALL ITEMS (34 produits Le Cayenne 2026-05-13)
  // -------------------------------------------------------------------------
  const ITEMS = [
    ...SANDWICH_CAYENNE, ...GALETTE, ...SANDWICH_CLASSIQUE, ...TACOS,
    ...BOLS, ...FRITES, ...SUPPLEMENTS_ITEMS, ...DESSERTS, ...DRINKS,
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
    defaultCruditeIds,
    defaultSauceId,
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
