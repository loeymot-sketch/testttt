// Le Cayenne — Catalogue mobile aligné FoodKing system central (SSOT = DB seed commands)
// [MENU-RESET 2026-05-13] Restructuration globale : 9 catégories visibles.
// [HEAL-LIGHT V2 2026-05-14] Owner-validated spec: 11 catégories (Burgers + Menu enfant).
// [MOBILE-REALIGNMENT 2026-05-16] composer_profile hardcoded mirroring DB shape for
// future API wireup (when owner connects mobile to backend, swap data source — render
// layer stays identical, no rewrites). Mobile stays standalone for now.
//
// SSOT (source of truth) = system central :
//   - app/Console/Commands/MenuResetLeCayenneCommand.php (2026-05-13)
//   - app/Console/Commands/MenuHealLightV2Command.php (2026-05-14)
//   - DB tables: items, item_variations, item_extras, item_addons,
//     item_wizard_profiles, item_wizard_steps
// /config/menu.php = STALE pre-reset documentation (15 sauces / €1 supps) — DO NOT trust.
//
// Catégories : Sandwich Cayenne, Galette, Sandwich Classique, Burgers, Tacos,
//              Bols Gourmands, Frites, Suppléments, Desserts, Boissons, Menu enfant.
// Viandes (4) : Poulet mariné, Poulet curry, Poulet tandoori, Poulet crispy.
// Sauces (11) : Mayonnaise, Ketchup, Algérienne, Samouraï, Curry, Andalouse,
//               Harissa, Hannibal, Blanche, Sauce fromagère maison, Spicy.
// Suppléments (9 @ 0.90€) : Cheddar, Raclette, Emmental, Œuf, Boursin, Légumes sautés,
//                            Jambon, Oignon frais, Champignons.
// Suppléments bols (4) : Oignon frais 0.90€, Jambon 0.90€, Champignons 0.90€, Boule gratinée 2.00€.
// Bols : 8 items (Frites/Riz × 4 viandes) @ 8.90€ + composer 'custom' (sauce + supp_bols + drink).
// Frites : composer 'custom' (style Nature/+Cheddar/+Cheddar+Oignons).
// Drink addon Bols : opt-in optional, 1 boisson du catalogue Boissons (8 choix) au prix unitaire.

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

  // Items → images ([BOARD-REPOINT 2026-05-30] canonical board photos, board filenames preserved)
  const ITEM_IMG = {
    // Sandwich Cayenne (1 + Big)
    'sandwich-cayenne-classique': 'sandwich-cayenne.png',
    'big-cayenne':                'sandwich-cayenne-maxi.png',
    // Galette (2)
    'galette-normale': 'galette.png',
    'galette-cayenne': 'galette.png',
    // Sandwich Classique (1 + Big)
    'sandwich-classique-faluche': 'sandwich-classique.png',
    'big-classique':              'sandwich-classique-maxi.png',
    // Burgers (2 — heal-light v2 NEW)
    'chicken-burger': 'burger-cheese.png',
    'big-chicken':    'burger-big.png',
    // Tacos (2) — board deliberately shares one tacos photo
    'tacos-1-viande': 'tacos.png',
    'big-tacos-2-viandes': 'tacos.png',
    // Bols (8 — board: all frites-bowls share bol-frites.png, all riz-bowls share bol-riz.png)
    'bowl-frites-marine':   'bol-frites.png',
    'bowl-frites-curry':    'bol-frites.png',
    'bowl-frites-tandoori': 'bol-frites.png',
    'bowl-frites-crispy':   'bol-frites.png',
    'bowl-riz-marine':      'bol-riz.png',
    'bowl-riz-curry':       'bol-riz.png',
    'bowl-riz-tandoori':    'bol-riz.png',
    'bowl-riz-crispy':      'bol-riz.png',
    // Frites (2)
    'petite-frites': 'frites.png',
    'grande-frites': 'frites.png',
    // Desserts
    'glace': 'ben-jerrys.png',
    'tarte-daim': 'tarte.png',
    'tiramisu': 'tiramisu.png',
    // Drinks
    'coca': 'coca.png',
    'coca-zero': 'coca-zero.png',
    'fanta': 'fanta-orange.png',
    'sprite': 'sprite.png',
    'oasis': 'oasis.png',
    'orangina': 'tropico.png',
    'eau-plate': 'eau.png',
    'capri-sun': 'capri-sun.png',
    // Suppléments standalone
    'supp-cheddar': 'cheddar.png',
    'supp-raclette': 'raclette.png',
    'supp-emmental': 'fromage.png',
    'supp-oeuf': 'oeuf.png',
    'supp-boursin': 'boursin.png',
    'supp-legumes-sautes': 'legumes-sautes.png',
    'supp-jambon': 'jambon-dinde.png',
    'supp-oignon-frais': 'oignons-frits.png',
    'supp-champignons': 'champignons.png',
    'supp-boule-gratinee': 'bol-frites-gratine.png',
    // Menu enfant (heal-light v2 NEW)
    'menu-nuggets': 'nuggets.png',
  };

  // Signature heroes (bg-removed)
  const HERO_IMG = {
    'sandwich-cayenne-classique': 'signature/cayenne-hero.png',
    'big-cayenne': 'signature/cayenne-hero.png',
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

  // 4 viandes canoniques (config/menu.php meats — heal-light v2 2026-05-14)
  const MEATS = [
    { id: 'm-marine',   name: 'Poulet mariné',   price: 0, emoji: '🍗', image: ASSET_BASE + 'viande-marine.png' },
    { id: 'm-curry',    name: 'Poulet curry',    price: 0, emoji: '🍛', image: ASSET_BASE + 'viande-curry.png' },
    { id: 'm-tandoori', name: 'Poulet tandoori', price: 0, emoji: '🔥', image: ASSET_BASE + 'viande-tandoori.png' },
    { id: 'm-crispy',   name: 'Poulet crispy',   price: 0, emoji: '🍗', image: ASSET_BASE + 'viande-crispy.png' },
  ];

  // 11 sauces canoniques (heal-light v2 2026-05-14) — Tandoori + Cayenne supprimées (viande/sandwich, pas sauce)
  const SAUCES = [
    { id: 's-mayo',       name: 'Mayonnaise',             price: 0, image: ASSET_BASE + 'sauce-mayonnaise.png' },
    { id: 's-ketchup',    name: 'Ketchup',                price: 0, image: ASSET_BASE + 'sauce-ketchup.png' },
    { id: 's-algerien',   name: 'Algérienne',             price: 0, image: ASSET_BASE + 'sauce-algerienne.png' },
    { id: 's-samurai',    name: 'Samouraï',               price: 0, image: ASSET_BASE + 'sauce-samurai.png' },
    { id: 's-curry',      name: 'Curry',                  price: 0, image: ASSET_BASE + 'sauce-curry.png' },
    { id: 's-andalouse',  name: 'Andalouse',              price: 0, image: ASSET_BASE + 'sauce-andalouse.png' },
    { id: 's-harissa',    name: 'Harissa',                price: 0, is_spicy: true, image: ASSET_BASE + 'sauce-harissa.png' },
    { id: 's-hannibal',   name: 'Hannibal',               price: 0, is_spicy: true, image: ASSET_BASE + 'sauce-hannibal.png' },
    { id: 's-blanche',    name: 'Blanche',                price: 0, image: ASSET_BASE + 'sauce-blanche.png' },
    { id: 's-fromagere',  name: 'Sauce fromagère maison', price: 0, image: ASSET_BASE + 'sauce-fromagere-maison.png' },
    { id: 's-spicy',      name: 'Spicy',                  price: 0, is_spicy: true, image: ASSET_BASE + 'sauce-spicy-maison.png' },
  ];

  // 4 crudités (Salade/Tomate/Oignon/Cornichon — Cornichon ajouté 2026-05-13)
  const CRUDITES = [
    { id: 'c-salade',    name: 'Salade',    default: true, image: ASSET_BASE + 'salade.png' },
    { id: 'c-tomate',    name: 'Tomate',    default: true, image: ASSET_BASE + 'tomate.png' },
    { id: 'c-oignon',    name: 'Oignon',    default: true, image: ASSET_BASE + 'oignon.png' },
    { id: 'c-cornichon', name: 'Cornichon', default: true, image: ASSET_BASE + 'cornichon.png' },
  ];

  // 9 suppléments génériques (heal-light v2 2026-05-14 — Bacon supprimé, Boursin ajouté, prix 0.90€)
  // [MASSIVE-LOGIC HEAL 2026-05-17 P0] allergens added per FIC 1169/2011 — aggregation reads from this pool
  const SUPPLEMENTS = [
    { id: 'sup-cheddar',        name: 'Cheddar',        price: 0.90, image: ASSET_BASE + 'cheddar.png',          allergens: ['lactose'] },
    { id: 'sup-raclette',       name: 'Raclette',       price: 0.90, image: ASSET_BASE + 'raclette.png',         allergens: ['lactose'] },
    { id: 'sup-emmental',       name: 'Emmental',       price: 0.90, image: ASSET_BASE + 'fromage.png',          allergens: ['lactose'] },
    { id: 'sup-oeuf',           name: 'Œuf',            price: 0.90, image: ASSET_BASE + 'oeuf.png',             allergens: ['oeuf'] },
    { id: 'sup-boursin',        name: 'Boursin',        price: 0.90, image: ASSET_BASE + 'boursin.png',          allergens: ['lactose'] },
    { id: 'sup-legumes-sautes', name: 'Légumes sautés', price: 0.90, image: ASSET_BASE + 'legumes-sautes.png',   allergens: [] },
    { id: 'sup-jambon',         name: 'Jambon',         price: 0.90, image: ASSET_BASE + 'jambon-dinde.png',     allergens: [] },
    { id: 'sup-oignon-frais',   name: 'Oignon frais',   price: 0.90, image: ASSET_BASE + 'oignons-frits.png',    allergens: [] },
    { id: 'sup-champignons',    name: 'Champignons',    price: 0.90, image: ASSET_BASE + 'champignons.png',      allergens: [] },
  ];

  // Suppléments spécifiques aux bols (heal-light v2 2026-05-14 — gratiné +2€ bol-specific)
  const SUPPLEMENTS_BOLS = [
    { id: 'sb-oignon-frais',   name: 'Oignon frais',    price: 0.90, image: ASSET_BASE + 'oignons-frits.png' },
    { id: 'sb-jambon',         name: 'Jambon',          price: 0.90, image: ASSET_BASE + 'jambon-dinde.png' },
    { id: 'sb-champignons',    name: 'Champignons',     price: 0.90, image: ASSET_BASE + 'champignons.png' },
    { id: 'sb-boule-gratinee', name: 'Boule gratinée',  price: 2.00, image: ASSET_BASE + 'bol-frites-gratine.png' },
  ];

  // Formules menu (heal-light v2 2026-05-14 — menu addon 3.00 → 2.50€)
  const FORMULES = [
    { id: 'f-menu',    name: 'Menu (Frites + Boisson)', price: 3.00, has_drink: true, has_fries: true },
    { id: 'f-frites',  name: 'Ajouter Frites',           price: 2.00, has_fries: true },
    { id: 'f-boisson', name: 'Ajouter Boisson',          price: 2.00, has_drink: true },
  ];

  // Frites styles (Nature / Cheddar +1€ / Cheddar+Oignons +2€) — owner update 2026-05-13
  const FRITES_STYLES = [
    { id: null,                name: 'Nature',                   price: 0,    is_default: true, emoji: '🍟', image: ASSET_BASE + 'frites.png' },
    { id: 'fs-cheddar',        name: 'Cheddar fondu',            price: 1.00, emoji: '🧀',                  image: ASSET_BASE + 'frites-cheddar.png' },
    { id: 'fs-cheddar-oignon', name: 'Cheddar + Oignons frits',  price: 2.00, emoji: '🧅',                  image: ASSET_BASE + 'frites-cheddar-oignons.png' },
  ];

  // Bases bols (Frites / Riz basmati)
  const BOL_BASES = [
    { id: 'bb-frites', name: 'Frites',       price: 0, image: ASSET_BASE + 'frites.png' },
    { id: 'bb-riz',    name: 'Riz basmati',  price: 0, image: ASSET_BASE + 'bol-riz.png' },
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
  // CATEGORIES (11 catégories — heal-light v2 2026-05-14)
  // -------------------------------------------------------------------------
  const CATEGORIES = [
    { id: 1,  slug: 'sandwich-cayenne',   name: 'Sandwich Cayenne',   icon: '🥖', sort: 1,  wizard_template: 'sandwich', has_menu: true,  description: 'Sandwich signature avec sauce Cayenne maison',   image: ASSET_BASE + 'cat-sandwich-cayenne.png' },
    { id: 2,  slug: 'galette',            name: 'Galette',            icon: '🌯', sort: 2,  wizard_template: 'sandwich', has_menu: true,  description: 'Galette traditionnelle ou Cayenne',              image: ASSET_BASE + 'cat-galette.png' },
    { id: 3,  slug: 'sandwich-classique', name: 'Sandwich Classique', icon: '🥖', sort: 3,  wizard_template: 'sandwich', has_menu: true,  description: 'Sandwich classique en pain faluche',             image: ASSET_BASE + 'cat-sandwich-classique.png' },
    { id: 4,  slug: 'burgers',            name: 'Burgers',            icon: '🍔', sort: 4,  wizard_template: 'sandwich', has_menu: true,  description: 'Chicken Burger ou Big Chicken, pain brioché',    image: ASSET_BASE + 'cat-burgers.png' },
    { id: 5,  slug: 'tacos',              name: 'Tacos',              icon: '🌮', sort: 5,  wizard_template: 'tacos',    has_menu: true,  description: 'Tacos M ou Tacos L, sauce fromagère maison',     image: ASSET_BASE + 'cat-tacos.png' },
    { id: 6,  slug: 'bols-gourmands',     name: 'Bols Gourmands',     icon: '🥣', sort: 6,  wizard_template: 'custom',   has_menu: false, description: 'Bowl Frites ou Riz × 4 viandes au choix',        image: ASSET_BASE + 'cat-bols-gourmands.png' },
    { id: 7,  slug: 'frites',             name: 'Frites',             icon: '🍟', sort: 7,  wizard_template: 'custom',   has_menu: false, description: 'Petite ou Grande, style au choix',               image: ASSET_BASE + 'cat-frites.png' },
    { id: 8,  slug: 'supplements',        name: 'Suppléments',        icon: '➕', sort: 8,  wizard_template: 'simple',   has_menu: false, description: 'Suppléments commandables séparément',            image: ASSET_BASE + 'cat-supplements.png' },
    { id: 9,  slug: 'desserts',           name: 'Desserts',           icon: '🍰', sort: 9,  wizard_template: 'simple',   has_menu: false, description: 'Desserts gourmands',                             image: ASSET_BASE + 'cat-desserts.png' },
    { id: 10, slug: 'boissons',           name: 'Boissons',           icon: '🥤', sort: 10, wizard_template: 'simple',   has_menu: false, description: 'Boissons fraîches',                              image: ASSET_BASE + 'cat-boissons.png' },
    { id: 11, slug: 'menu-enfant',        name: 'Menu enfant',        icon: '🧒', sort: 11, wizard_template: 'simple',   has_menu: false, description: 'Menu enfant nuggets + frites + Capri-Sun',       image: ASSET_BASE + 'cat-menu-enfant.png' },
  ];

  // -------------------------------------------------------------------------
  // ITEMS HELPER
  // -------------------------------------------------------------------------
  function defaultAllergensFor(cat, opts) {
    if (opts && opts.allergens !== undefined) return opts.allergens;
    // [HEAL-LIGHT V2 2026-05-14] 11 cats. Cat id 4=Burgers, 5=Tacos, ..., 11=Menu enfant
    switch (cat) {
      case 1: case 2: case 3: case 4: case 5: return ['gluten']; // Sandwich Cayenne/Galette/Classique/Burgers/Tacos (pain/galette)
      case 6: return [];           // Bols (no bread, sauce in own field)
      case 7: return [];           // Frites
      case 8: return [];           // Supplements (per-item override)
      case 9: return ['gluten', 'lactose']; // Desserts
      case 10: return [];          // Boissons
      case 11: return ['gluten'];  // Menu enfant (Nuggets pain/pané)
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
    if (opts.has_bol_wizard) {
      item.composer_profile = buildBolComposerProfile(item, opts);
    } else if (opts.has_frites_style && category_id === 7) {
      item.composer_profile = buildFritesComposerProfile(item, opts);
    }
    return item;
  }

  // -------------------------------------------------------------------------
  // COMPOSER PROFILE HELPERS — mirror DB shape (item_wizard_profiles)
  // Future API wireup : swap these for `item.composer_profile` from API response.
  // Shape: { template, version, is_published, steps: [{ step_key, label,
  //         source_type, position, min_select, max_select, addon_role?, choices[] }] }
  // -------------------------------------------------------------------------
  function buildBolComposerProfile(item, opts) {
    // [MASSIVE-LOGIC HEAL 2026-05-17 P0] Bol sauce default lookup with safe fallback :
    // if name lookup fails (sauce renamed/deleted), fall back to first SAUCE rather
    // than `{}` which leaves user with no pre-selection + no error feedback.
    let defaultSauce = null;
    if (opts.bol_sauce_default) {
      defaultSauce = SAUCES.find(s => s.name === opts.bol_sauce_default);
      if (!defaultSauce) {
        console.warn('[buildBolComposerProfile] bol_sauce_default "' + opts.bol_sauce_default + '" not found in SAUCES for item ' + item.slug + ' — falling back to first sauce');
        defaultSauce = SAUCES[0];
      }
    } else {
      defaultSauce = SAUCES[0];
    }
    return {
      template: 'bol',
      version: 1,
      is_published: true,
      steps: [
        {
          step_key: 'sauce',
          label: 'Sauce',
          source_type: 'item_attribute',
          position: 1,
          min_select: 1,
          max_select: 1,
          allow_repeat: false,
          addon_role: null,
          default_choice_id: (defaultSauce && defaultSauce.id) || null,
          choices: SAUCES.map(s => ({
            id: s.id, name: s.name, price: 0, image: s.image,
            is_default: !!(defaultSauce && s.id === defaultSauce.id),
            is_spicy: !!s.is_spicy,
          })),
        },
        {
          step_key: 'bol_supplements',
          label: 'Suppléments du bol',
          source_type: 'extra_group',
          position: 2,
          min_select: 0,
          max_select: 4,
          allow_repeat: false,
          addon_role: null,
          default_choice_id: null,
          choices: SUPPLEMENTS_BOLS.map(s => ({
            id: s.id, name: s.name, price: s.price, image: s.image || null, is_default: false,
          })),
        },
        {
          step_key: 'bol_drink',
          label: 'Ajouter une boisson',
          source_type: 'addon',
          addon_role: 'drink',
          position: 3,
          min_select: 0,
          max_select: 1,
          allow_repeat: false,
          default_choice_id: null,
          choices: FORMULE_DRINKS.map(d => ({
            id: d.id, name: d.name, price: priceForDrinkAddon(d.id), image: d.image, emoji: d.emoji, is_default: false,
          })),
        },
      ],
    };
  }

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
  // Used by composer profile builder above. Returns the catalogue price for the drink
  // (e.g. d-coca → 1.50€, d-eau → 1.00€). Same drink slugs as FORMULE_DRINKS.
  function priceForDrinkAddon(formuleDrinkId) {
    const drinkSlugMap = {
      'd-coca': 1.50, 'd-coca-zero': 1.50, 'd-fanta': 1.50, 'd-sprite': 1.50,
      'd-oasis': 1.50, 'd-orangina': 1.50, 'd-eau': 1.00, 'd-capri': 1.50,
    };
    return drinkSlugMap[formuleDrinkId] !== undefined ? drinkSlugMap[formuleDrinkId] : 1.50;
  }

  // ====== SANDWICH CAYENNE (cat 1) — [CAISSE-SYNC 2026-05-30] prix DB 7.00 (était 7.50) + Big Cayenne 9.50 ======
  const SANDWICH_CAYENNE = [
    mkItem(101, 'sandwich-cayenne-classique', 1, 'Sandwich Cayenne', 7.00,
      'Sauce Cayenne maison incluse · 1 viande au choix · Crudités · Suppléments optionnels',
      { viandes: 1, has_crudites: true, has_menu_addon: true, sauce_locked: 'Cayenne', has_sauce: false,
        is_featured: true, tags: ['SIGNATURE'], emoji: '🌶️', is_spicy: true, time: 10 }),
    mkItem(102, 'big-cayenne', 1, 'Big Cayenne', 9.50,
      'Sandwich signature XL · 2 viandes · Sauce Cayenne maison · INCLUS : Cheddar + Œuf + Jambon · Crudités · Suppléments optionnels',
      { viandes: 2, has_crudites: true, has_menu_addon: true, sauce_locked: 'Cayenne', has_sauce: false,
        is_featured: true, tags: ['SIGNATURE', 'XL'], emoji: '🌶️', is_spicy: true, time: 12 }),
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

  // ====== SANDWICH CLASSIQUE (cat 3) — [CAISSE-SYNC 2026-05-30] prix DB 6.50 (était 7.00) + Big Classique 9.00 ======
  const SANDWICH_CLASSIQUE = [
    mkItem(301, 'sandwich-classique-faluche', 3, 'Sandwich Classique', 6.50,
      'Pain faluche · 1 viande · Sauce au choix · Crudités · Suppléments optionnels',
      { viandes: 1, has_crudites: true, has_menu_addon: true, has_sauce: true, time: 8, emoji: '🥖' }),
    mkItem(302, 'big-classique', 3, 'Big Classique', 9.00,
      'Sandwich classique XL en pain faluche · 2 viandes · Sauce libre · INCLUS : Cheddar + Œuf + Jambon · Crudités · Suppléments optionnels',
      { viandes: 2, has_crudites: true, has_menu_addon: true, has_sauce: true, is_featured: true, tags: ['XL'], time: 10, emoji: '🥖' }),
  ];

  // ====== BURGERS (cat 4) — heal-light v2 NEW catégorie ======
  const BURGERS = [
    mkItem(401, 'chicken-burger', 4, 'Chicken Burger', 6.90,
      'Burger pain brioché · Poulet crispy · Sauce libre · Crudités · Suppléments optionnels',
      { viandes: 1, has_crudites: true, has_menu_addon: true, has_sauce: true, is_featured: true, tags: ['NEW'], emoji: '🍔', time: 10 }),
    mkItem(402, 'big-chicken', 4, 'Big Chicken', 8.90,
      'Big Burger pain brioché · Poulet crispy · Sauce libre · INCLUS : Cheddar + Jambon + Œuf · Crudités · Suppléments optionnels',
      { viandes: 1, has_crudites: true, has_menu_addon: true, has_sauce: true, is_featured: true, tags: ['NEW', 'XL'], emoji: '🍔', time: 12 }),
  ];

  // ====== TACOS (cat 5) — owner decision 2026-06-04 : Tacos M (1 viande) 6,90 · Tacos L (2 viandes) 8,90 (prix "seul" / à la carte)
  //   Owner a OVERRIDÉ le caisse-sync 2026-05-30 (DB items 26/27 = 8,50/11,50) → prix retail canonique = 6,90/8,90.
  //   ⚠️ La caisse/borne DB porte ENCORE 8,50/11,50 : à corriger côté caisse (owner-gated) pour cohérence SSOT. ======
  const TACOS = [
    mkItem(501, 'tacos-1-viande', 5, 'Tacos M', 6.90,
      '1 viande au choix · Frites maison · Sauce fromagère maison',
      { viandes: 1, has_crudites: false, has_menu_addon: true, has_sauce: false,
        is_featured: true, tags: ['SIGNATURE'], emoji: '🌮', time: 10 }),
    mkItem(502, 'big-tacos-2-viandes', 5, 'Tacos L', 8.90,
      '2 viandes au choix · Frites maison · Sauce fromagère maison',
      { viandes: 2, has_crudites: false, has_menu_addon: true, has_sauce: false,
        is_featured: true, tags: ['TOP'], emoji: '🌮', time: 12 }),
  ];

  // ====== BOLS GOURMANDS (cat 6) — heal-light v2 restructure 5 → 8 items @ 8.90€ ======
  const BOLS = [
    mkItem(601, 'bowl-frites-marine',   6, 'Bowl Frites Poulet mariné',   8.90, 'Poulet mariné · Frites · Sauce + Suppléments + Drink + Gratiné optionnels',   { has_bol_wizard: true, bol_meat_fixed: 'Poulet mariné',   bol_sauce_default: 'Sauce fromagère maison', has_sauce: true, has_crudites: false, has_menu_addon: false, has_supplements: true, emoji: '🥣', time: 10 }),
    mkItem(602, 'bowl-frites-curry',    6, 'Bowl Frites Poulet curry',    8.90, 'Poulet curry · Frites · Sauce + Suppléments + Drink + Gratiné optionnels',    { has_bol_wizard: true, bol_meat_fixed: 'Poulet curry',    bol_sauce_default: 'Curry',                  has_sauce: true, has_crudites: false, has_menu_addon: false, has_supplements: true, emoji: '🥣', time: 10 }),
    mkItem(603, 'bowl-frites-tandoori', 6, 'Bowl Frites Poulet tandoori', 8.90, 'Poulet tandoori · Frites · Sauce + Suppléments + Drink + Gratiné optionnels', { has_bol_wizard: true, bol_meat_fixed: 'Poulet tandoori', bol_sauce_default: 'Sauce fromagère maison', has_sauce: true, has_crudites: false, has_menu_addon: false, has_supplements: true, emoji: '🥣', time: 10 }),
    mkItem(604, 'bowl-frites-crispy',   6, 'Bowl Frites Poulet crispy',   8.90, 'Poulet crispy · Frites · Sauce + Suppléments + Drink + Gratiné optionnels',   { has_bol_wizard: true, bol_meat_fixed: 'Poulet crispy',   bol_sauce_default: 'Sauce fromagère maison', has_sauce: true, has_crudites: false, has_menu_addon: false, has_supplements: true, emoji: '🥣', time: 10 }),
    mkItem(605, 'bowl-riz-marine',      6, 'Bowl Riz Poulet mariné',      8.90, 'Poulet mariné · Riz basmati · Sauce + Suppléments + Drink + Gratiné',         { has_bol_wizard: true, bol_meat_fixed: 'Poulet mariné',   bol_sauce_default: 'Sauce fromagère maison', has_sauce: true, has_crudites: false, has_menu_addon: false, has_supplements: true, emoji: '🥣', time: 10 }),
    mkItem(606, 'bowl-riz-curry',       6, 'Bowl Riz Poulet curry',       8.90, 'Poulet curry · Riz basmati · Sauce + Suppléments + Drink + Gratiné',          { has_bol_wizard: true, bol_meat_fixed: 'Poulet curry',    bol_sauce_default: 'Curry',                  has_sauce: true, has_crudites: false, has_menu_addon: false, has_supplements: true, emoji: '🥣', time: 10 }),
    mkItem(607, 'bowl-riz-tandoori',    6, 'Bowl Riz Poulet tandoori',    8.90, 'Poulet tandoori · Riz basmati · Sauce + Suppléments + Drink + Gratiné',       { has_bol_wizard: true, bol_meat_fixed: 'Poulet tandoori', bol_sauce_default: 'Sauce fromagère maison', has_sauce: true, has_crudites: false, has_menu_addon: false, has_supplements: true, emoji: '🥣', time: 10 }),
    mkItem(608, 'bowl-riz-crispy',      6, 'Bowl Riz Poulet crispy',      8.90, 'Poulet crispy · Riz basmati · Sauce + Suppléments + Drink + Gratiné',         { has_bol_wizard: true, bol_meat_fixed: 'Poulet crispy',   bol_sauce_default: 'Sauce fromagère maison', has_sauce: true, has_crudites: false, has_menu_addon: false, has_supplements: true, emoji: '🥣', time: 10 }),
  ];

  // ====== FRITES (cat 7) ======
  const FRITES = [
    mkItem(701, 'petite-frites', 7, 'Petite Frites', 2.50,
      'Portion petite · Style au choix (Nature / Cheddar +1€ / Cheddar+Oignons +2€)',
      { has_sauce: false, has_crudites: false, has_supplements: false, has_menu_addon: false, has_frites_style: true, time: 4, emoji: '🍟', is_vegetarian: true }),
    mkItem(702, 'grande-frites', 7, 'Grande Frites', 4.00,
      'Portion grande · Style au choix (Nature / Cheddar +1€ / Cheddar+Oignons +2€)',
      { has_sauce: false, has_crudites: false, has_supplements: false, has_menu_addon: false, has_frites_style: true, time: 5, emoji: '🍟', is_vegetarian: true }),
  ];

  // ====== SUPPLÉMENTS (cat 8) — heal-light v2 9 items 0.90€ (Bacon archived, Boursin added, Oignons frits → Oignon frais) ======
  const SUPPLEMENTS_ITEMS = [
    mkItem(801,  'supp-cheddar',        8, 'Cheddar',        0.90, 'Supplément cheddar',          { has_sauce: false, has_crudites: false, has_supplements: false, has_menu_addon: false, time: 0, emoji: '🧀', allergens: ['lactose'] }),
    mkItem(802,  'supp-raclette',       8, 'Raclette',       0.90, 'Supplément fromage raclette', { has_sauce: false, has_crudites: false, has_supplements: false, has_menu_addon: false, time: 0, emoji: '🧀', allergens: ['lactose'] }),
    mkItem(803,  'supp-emmental',       8, 'Emmental',       0.90, 'Supplément emmental',         { has_sauce: false, has_crudites: false, has_supplements: false, has_menu_addon: false, time: 0, emoji: '🧀', allergens: ['lactose'] }),
    mkItem(804,  'supp-oeuf',           8, 'Œuf',            0.90, 'Supplément œuf',              { has_sauce: false, has_crudites: false, has_supplements: false, has_menu_addon: false, time: 0, emoji: '🥚', allergens: ['oeuf'] }),
    mkItem(805,  'supp-boursin',        8, 'Boursin',        0.90, 'Supplément Boursin',          { has_sauce: false, has_crudites: false, has_supplements: false, has_menu_addon: false, time: 0, emoji: '🧀', allergens: ['lactose'] }),
    mkItem(806,  'supp-legumes-sautes', 8, 'Légumes sautés', 0.90, 'Supplément légumes sautés',   { has_sauce: false, has_crudites: false, has_supplements: false, has_menu_addon: false, time: 0, emoji: '🥬', is_vegetarian: true, allergens: [] }),
    mkItem(807,  'supp-jambon',         8, 'Jambon',         0.90, 'Supplément jambon',           { has_sauce: false, has_crudites: false, has_supplements: false, has_menu_addon: false, time: 0, emoji: '🥓', allergens: [] }),
    mkItem(808,  'supp-oignon-frais',   8, 'Oignon frais',   0.90, 'Supplément oignon frais',     { has_sauce: false, has_crudites: false, has_supplements: false, has_menu_addon: false, time: 0, emoji: '🧅', is_vegetarian: true, allergens: [] }),
    mkItem(809,  'supp-champignons',    8, 'Champignons',    0.90, 'Supplément champignons',      { has_sauce: false, has_crudites: false, has_supplements: false, has_menu_addon: false, time: 0, emoji: '🍄', is_vegetarian: true, allergens: [] }),
  ];

  // ====== DESSERTS (cat 9) ======
  const DESSERTS = [
    mkItem(901, 'glace',      9, 'Glace',      3.80, 'Glace artisanale', { has_sauce: false, has_crudites: false, has_supplements: false, has_menu_addon: false, time: 0, emoji: '🍦', allergens: ['lactose'] }),
    mkItem(902, 'tarte-daim', 9, 'Tarte Daim', 3.80, 'Tarte au Daim',    { has_sauce: false, has_crudites: false, has_supplements: false, has_menu_addon: false, time: 0, emoji: '🍰', allergens: ['gluten', 'lactose'] }),
    mkItem(903, 'tiramisu',   9, 'Tiramisu',   3.80, 'Tiramisu maison',  { has_sauce: false, has_crudites: false, has_supplements: false, has_menu_addon: false, time: 0, emoji: '🍰', allergens: ['gluten', 'lactose', 'oeuf'] }),
  ];

  // ====== BOISSONS (cat 10) ======
  const DRINKS = [
    mkItem(1001, 'coca',        10, 'Coca-Cola 33cl',      1.50, 'Coca-Cola original',   { has_sauce: false, has_crudites: false, has_supplements: false, has_menu_addon: false, time: 0, emoji: '🥤' }),
    mkItem(1002, 'coca-zero',   10, 'Coca-Cola Zero 33cl', 1.50, 'Coca-Cola sans sucre', { has_sauce: false, has_crudites: false, has_supplements: false, has_menu_addon: false, time: 0, emoji: '🥤' }),
    mkItem(1003, 'fanta',       10, 'Fanta Orange 33cl',   1.50, 'Fanta Orange',         { has_sauce: false, has_crudites: false, has_supplements: false, has_menu_addon: false, time: 0, emoji: '🍊' }),
    mkItem(1004, 'sprite',      10, 'Sprite 33cl',         1.50, 'Sprite',               { has_sauce: false, has_crudites: false, has_supplements: false, has_menu_addon: false, time: 0, emoji: '🍋' }),
    mkItem(1005, 'oasis',       10, 'Oasis Tropical 33cl', 1.50, 'Oasis Tropical',       { has_sauce: false, has_crudites: false, has_supplements: false, has_menu_addon: false, time: 0, emoji: '🌴' }),
    mkItem(1006, 'orangina',    10, 'Orangina 33cl',       1.50, 'Orangina',             { has_sauce: false, has_crudites: false, has_supplements: false, has_menu_addon: false, time: 0, emoji: '🍊' }),
    mkItem(1007, 'eau-plate',   10, 'Eau Plate 50cl',      1.00, 'Eau minérale',         { has_sauce: false, has_crudites: false, has_supplements: false, has_menu_addon: false, time: 0, emoji: '💧' }),
    mkItem(1008, 'capri-sun',   10, 'Capri-Sun',           1.50, 'Capri-Sun 20cl',       { has_sauce: false, has_crudites: false, has_supplements: false, has_menu_addon: false, time: 0, emoji: '🧃' }),
  ];

  // ====== MENU ENFANT (cat 11) — heal-light v2 NEW catégorie ======
  const MENU_ENFANT = [
    mkItem(1101, 'menu-nuggets', 11, 'Menu Nuggets', 6.00,
      'Menu enfant : 6 nuggets de poulet · Frites · Capri-Sun',
      { has_sauce: false, has_crudites: false, has_supplements: false, has_menu_addon: false, time: 8, emoji: '🧒', tags: ['ENFANT'] }),
  ];

  // -------------------------------------------------------------------------
  // ALL ITEMS (heal-light v2 2026-05-14 ; recompté 2026-05-29 — 41 entrées sur 11 catégories)
  // -------------------------------------------------------------------------
  const ITEMS = [
    ...SANDWICH_CAYENNE, ...GALETTE, ...SANDWICH_CLASSIQUE, ...BURGERS, ...TACOS,
    ...BOLS, ...FRITES, ...SUPPLEMENTS_ITEMS, ...DESSERTS, ...DRINKS, ...MENU_ENFANT,
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
    // Bol drink addon (optionnel — prix catalogue Boissons : 1.50€ standard / 1.00€ eau)
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
    buildBolComposerProfile,
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
