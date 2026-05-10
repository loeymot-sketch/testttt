// Le Cayenne — Catalogue mobile aligné FoodKing config/menu.php (SSOT)
//
// Source de vérité : /config/menu.php (Le Cayenne FR/EUR)
// Logique kiosk reflétée : viandes + sauce + crudités + extras + formule menu
//
// Mapping schéma :
//   categories      ↔ item_categories  (avec wizard_template + has_menu)
//   items           ↔ items + Item.is_featured/is_new/is_spicy/is_halal
//   meats           ↔ choix viande dynamique selon item.viandes
//   sauces          ↔ ItemAttribute "Sauce" min=1 max=1 (1 gratuite)
//   crudites        ↔ ItemAttribute "Crudités" toggleable (Salade/Tomate/Oignon)
//   supplements     ↔ ItemExtra (toggleable, prix unitaire)
//   addons          ↔ ItemAddon role=menu_component (formule +3€)

(function () {
  'use strict';

  // -------------------------------------------------------------------------
  // BRANCH (cf. BranchTableSeeder + design Hénin-Beaumont)
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
  // SHARED OPTIONS (cf. config/menu.php)
  // -------------------------------------------------------------------------

  // 9 viandes au choix (config/menu.php meats)
  const MEATS = [
    { id: 'm-merguez',   name: 'Merguez',           price: 0, is_spicy: true,  emoji: '🌶️' },
    { id: 'm-kefta',     name: 'Kefta',             price: 0, emoji: '🥩' },
    { id: 'm-mexicain',  name: 'Mexicain',          price: 0, is_spicy: true,  emoji: '🌶️' },
    { id: 'm-cordon',    name: 'Cordon Bleu',       price: 0, emoji: '🍗' },
    { id: 'm-hachee',    name: 'Viande Hachée',     price: 0, emoji: '🥩' },
    { id: 'm-nuggets',   name: 'Nuggets',           price: 0, emoji: '🍗' },
    { id: 'm-escalope',  name: 'Escalope de poulet', price: 0, emoji: '🍗' },
    { id: 'm-tenders',   name: 'Tenders',           price: 0, emoji: '🍗' },
    { id: 'm-fricand',   name: 'Fricandelle',       price: 0, emoji: '🥩' },
  ];

  // 15 sauces (1 gratuite, sup 0.50€)
  const SAUCES = [
    { id: 's-ketchup',    name: 'Ketchup',          price: 0 },
    { id: 's-mayo',       name: 'Mayonnaise',       price: 0 },
    { id: 's-algerien',   name: 'Algérienne',       price: 0 },
    { id: 's-curry',      name: 'Curry',            price: 0 },
    { id: 's-andalouse',  name: 'Andalouse',        price: 0 },
    { id: 's-burger',     name: 'Burger',           price: 0 },
    { id: 's-samurai',    name: 'Samouraï',         price: 0 },
    { id: 's-bbq',        name: 'Barbecue',         price: 0 },
    { id: 's-cocktail',   name: 'Cocktail',         price: 0 },
    { id: 's-americaine', name: 'Américaine',       price: 0 },
    { id: 's-hannibal',   name: 'Hannibal',         price: 0, is_spicy: true },
    { id: 's-harissa',    name: 'Harissa',          price: 0, is_spicy: true },
    { id: 's-blanche',    name: 'Blanche',          price: 0 },
    { id: 's-poivre',     name: 'Poivre',           price: 0 },
    { id: 's-sans',       name: 'Sans Sauce',       price: 0, is_no_sauce: true },
  ];

  // Crudités (toggle, default ON)
  const CRUDITES = [
    { id: 'c-salade', name: 'Salade',  default: true },
    { id: 'c-tomate', name: 'Tomate',  default: true },
    { id: 'c-oignon', name: 'Oignon',  default: true },
  ];

  // Suppléments payants (cf. config/menu.php supplements)
  const SUPPLEMENTS = [
    { id: 'sup-jambon',   name: 'Jambon de dinde',         price: 1.00, group: 'Suppléments' },
    { id: 'sup-boursin',  name: 'Boursin',                 price: 1.00, group: 'Fromages' },
    { id: 'sup-raclette', name: 'Fromage à raclette',      price: 1.00, group: 'Fromages' },
    { id: 'sup-fromage',  name: 'Fromage',                 price: 1.00, group: 'Fromages' },
    { id: 'sup-oeuf',     name: 'Œuf',                     price: 1.00, group: 'Suppléments' },
    { id: 'sup-galette',  name: 'Galette pommes de terre', price: 1.00, group: 'Suppléments' },
    { id: 'sup-sauce',    name: 'Sauce supplémentaire',    price: 0.50, group: 'Sauces' },
  ];

  // Formules menu (cf. config/menu.php addons)
  const FORMULES = [
    { id: 'f-menu',    name: 'Menu (Frites + Boisson)', price: 3.00, has_drink: true, has_fries: true },
    { id: 'f-frites',  name: 'Ajouter Frites',           price: 2.00, has_fries: true },
    { id: 'f-boisson', name: 'Ajouter Boisson',          price: 2.00, has_drink: true },
  ];

  // -------------------------------------------------------------------------
  // CATEGORIES (cf. config/menu.php categories — 13 catégories)
  // -------------------------------------------------------------------------
  const CATEGORIES = [
    { id: 1,  slug: 'nos-tacos',              name: 'Nos Tacos',              icon: '🌮', sort: 1,  wizard_template: 'tacos',    has_menu: true,  description: 'Nos délicieux tacos avec viandes au choix' },
    { id: 2,  slug: 'nos-sandwichs',          name: 'Nos Sandwichs',          icon: '🥖', sort: 2,  wizard_template: 'sandwich', has_menu: true,  description: 'Sandwichs gourmands et généreux' },
    { id: 3,  slug: 'nos-burgers',            name: 'Nos Burgers',            icon: '🍔', sort: 3,  wizard_template: 'burger',   has_menu: true,  description: 'Burgers maison 100% frais' },
    { id: 4,  slug: 'nos-assiettes',          name: 'Nos Assiettes',          icon: '🍽️', sort: 4,  wizard_template: 'assiette', has_menu: false, description: 'Assiettes complètes avec garnitures' },
    { id: 5,  slug: 'ojja',                   name: 'Ojja',                   icon: '🍳', sort: 5,  wizard_template: 'simple',   has_menu: false, description: 'Ojja traditionnelle' },
    { id: 6,  slug: 'omelettes',              name: 'Omelettes',              icon: '🥚', sort: 6,  wizard_template: 'omelette', has_menu: false, description: 'Omelettes faites maison' },
    { id: 7,  slug: 'nos-salades',            name: 'Nos Salades',            icon: '🥗', sort: 7,  wizard_template: 'salade',   has_menu: false, description: 'Salades fraîches et légères' },
    { id: 8,  slug: 'chicken-tenders',        name: 'Poulet croustillant',    icon: '🍗', sort: 8,  wizard_template: 'snacking', has_menu: false, description: 'Ailes et filets de poulet croustillants' },
    { id: 9,  slug: 'nos-menus-enfants',      name: 'Nos Menus Enfants',      icon: '🧒', sort: 9,  wizard_template: 'simple',   has_menu: false, description: 'Pour les petits gourmands' },
    { id: 10, slug: 'frites-accompagnements', name: 'Frites & Accompagnements', icon: '🍟', sort: 10, wizard_template: 'simple', has_menu: false, description: 'Frites et accompagnements' },
    { id: 11, slug: 'nos-desserts',           name: 'Nos Desserts',           icon: '🍰', sort: 11, wizard_template: 'simple',   has_menu: false, description: 'Desserts gourmands' },
    { id: 12, slug: 'nos-boissons',           name: 'Nos Boissons',           icon: '🥤', sort: 12, wizard_template: 'simple',   has_menu: false, description: 'Boissons fraîches' },
    { id: 13, slug: 'supplements',            name: 'Suppléments',            icon: '➕', sort: 13, wizard_template: 'simple',   has_menu: false, description: 'Suppléments commandables séparément' },
  ];

  // -------------------------------------------------------------------------
  // ITEMS (cf. config/menu.php items — 47 items réels)
  // Schéma item : { viandes (0-4), has_sauce, has_crudites, has_supplements, has_menu_addon }
  // -------------------------------------------------------------------------

  // Helper to build kiosk-aligned item
  function mkItem(id, slug, category_id, name, price, description, opts) {
    opts = opts || {};
    return {
      id, slug, category_id, name, price, description,
      thumb: 'item-' + slug,
      kiosk_emoji: opts.emoji || '',
      time: opts.time !== undefined ? opts.time : 8,
      tags: opts.tags || [],
      is_featured: !!opts.is_featured,
      is_new: !!opts.is_new,
      is_spicy: !!opts.is_spicy,
      is_halal: opts.is_halal !== false,    // default true
      is_vegetarian: !!opts.is_vegetarian,
      // Composition flags (cf. config/menu.php)
      viandes: opts.viandes ?? 0,
      has_sauce: opts.has_sauce !== false,
      has_crudites: !!opts.has_crudites,
      has_supplements: opts.has_supplements !== false,
      has_menu_addon: !!opts.has_menu_addon,
      // Allergens
      allergens: opts.allergens || ['gluten', 'lactose'],
    };
  }

  // ====== NOS TACOS (cat 1) ======
  const TACOS = [
    mkItem(101, 'tacos-m',   1, 'Tacos M (1 Viande)',  6.50, '1 Viande au choix · Sauce · Crudités',  { viandes: 1, has_crudites: true, has_menu_addon: true, time: 10, tags: ['SIGNATURE'], emoji: '🌮' }),
    mkItem(102, 'tacos-l',   1, 'Tacos L (2 Viandes)', 8.50, '2 Viandes au choix · Sauce · Crudités', { viandes: 2, has_crudites: true, has_menu_addon: true, time: 10, tags: ['TOP'],     emoji: '🌮' }),
    mkItem(103, 'tacos-xl',  1, 'Tacos XL (3 Viandes)', 10.50, '3 Viandes au choix · Sauce · Crudités', { viandes: 3, has_crudites: true, has_menu_addon: true, time: 12, emoji: '🌮' }),
    mkItem(104, 'tacos-xxl', 1, 'Tacos XXL (4 Viandes)', 12.50, '4 Viandes au choix · Sauce · Crudités', { viandes: 4, has_crudites: true, has_menu_addon: true, time: 14, tags: ['SPICY'], is_spicy: true, emoji: '🌮' }),
  ];

  // ====== NOS SANDWICHS (cat 2) ======
  const SANDWICHS = [
    mkItem(201, 'le-mega',          2, 'Le Méga',             8.00, '2 viandes au choix + Cheddar + Œuf',                                  { viandes: 2, has_crudites: true, has_menu_addon: true, time: 10, emoji: '🥖' }),
    mkItem(202, 'le-terminator',    2, 'Le Terminator',       9.00, '2 viandes au choix + 2 Cheddar + Œuf + Jambon de dinde',              { viandes: 2, has_crudites: true, has_menu_addon: true, time: 12, tags: ['TOP'], emoji: '💪' }),
    mkItem(203, 'le-supreme',       2, 'Le Suprême',          7.00, 'Steak + Cordon Bleu + Cheddar',                                       { viandes: 0, has_crudites: true, has_menu_addon: true, time: 10, emoji: '🥖' }),
    mkItem(204, 'le-cayenne',       2, 'Le Cayenne',          7.00, 'Viande hachée ou chicken + mozzarella + cheddar + crème fraîche',     { viandes: 1, has_crudites: true, has_menu_addon: true, time: 10, tags: ['SIGNATURE'], emoji: '🥖' }),
    mkItem(205, 'sandwich-froid',   2, 'Sandwich Froid',      4.50, 'Sandwich au Thon',                                                    { viandes: 0, has_crudites: true, has_menu_addon: true, time: 5,  emoji: '🥪' }),
    mkItem(206, 'panini',           2, 'Panini',              5.00, 'Thon · Jambon · Viande hachée · Fromage de chèvre · Saumon · Escalope', { viandes: 1, has_crudites: true, has_menu_addon: true, time: 8,  emoji: '🥪' }),
    mkItem(207, 'sandwich-pain',    2, 'Sandwich Classique (Pain)',    6.50, '1 Viande au choix dans un pain classique', { viandes: 1, has_crudites: true, has_menu_addon: true, time: 8, emoji: '🥖' }),
    mkItem(208, 'sandwich-galette', 2, 'Sandwich Classique (Galette)', 6.50, '1 Viande au choix dans une galette',       { viandes: 1, has_crudites: true, has_menu_addon: true, time: 8, emoji: '🌯' }),
  ];

  // ====== NOS BURGERS (cat 3) ======
  const BURGERS = [
    mkItem(301, 'burger-poulet',   3, 'Burger Poulet',  6.00, 'Poulet pané + Cheddar',                                    { viandes: 0, has_crudites: true, has_menu_addon: true, time: 10, emoji: '🍔' }),
    mkItem(302, 'cheese-burger',   3, 'Cheese Burger',  6.00, '1 Steak + 1 Cheddar',                                      { viandes: 0, has_crudites: true, has_menu_addon: true, time: 10, tags: ['SIGNATURE'], emoji: '🍔' }),
    mkItem(303, 'fish-burger',     3, 'Fish Burger',    6.00, 'Poisson pané + Cheddar',                                   { viandes: 0, has_crudites: true, has_menu_addon: true, time: 10, emoji: '🐟' }),
    mkItem(304, 'double-cheese',   3, 'Double Cheese',  7.00, '2 Steaks + 2 Cheddars',                                    { viandes: 0, has_crudites: true, has_menu_addon: true, time: 12, tags: ['TOP'], emoji: '🍔' }),
    mkItem(305, 'big-burger',      3, 'Big Burger',     9.00, '3 Steaks + 3 Cheddar + 2 Jambon de dinde',                 { viandes: 0, has_crudites: true, has_menu_addon: true, time: 14, tags: ['TOP'], emoji: '💪' }),
    mkItem(306, 'grill-burger',    3, 'Grill Burger',   8.00, '2 Steaks + 2 Cheddars + Jambon de dinde',                  { viandes: 0, has_crudites: true, has_menu_addon: true, time: 12, emoji: '🔥' }),
  ];

  // ====== NOS ASSIETTES (cat 4) ======
  const ASSIETTES = [
    mkItem(401, 'assiette-poulet',  4, 'Assiette Poulet',  12.50, 'Poulet (Nature · Curry · Paprika) + Frites + Salade + Pain + Sauce', { viandes: 0, has_crudites: false, has_menu_addon: false, time: 14, emoji: '🍽️' }),
    mkItem(402, 'assiette-kefta',   4, 'Assiette Kefta',   12.50, 'Kefta + Frites + Salade + Pain + Sauce',                             { viandes: 0, has_crudites: false, has_menu_addon: false, time: 14, emoji: '🍽️' }),
    mkItem(403, 'assiette-merguez', 4, 'Assiette Merguez', 12.50, 'Merguez + Frites + Salade + Pain + Sauce',                           { viandes: 0, has_crudites: false, has_menu_addon: false, time: 14, tags: ['SPICY'], is_spicy: true, emoji: '🌶️' }),
    mkItem(404, 'assiette-mixte',   4, 'Assiette Mixte',   14.50, 'Poulet · Kefta · Merguez + Frites + Salade + Pain + Sauce',          { viandes: 0, has_crudites: false, has_menu_addon: false, time: 16, tags: ['TOP'],   emoji: '🍽️' }),
  ];

  // ====== OJJA (cat 5) ======
  const OJJA = [
    mkItem(501, 'ojja-boeuf',   5, 'Ojja Bœuf',         13.50, 'Ojja avec Bœuf + Frites + Pain',         { viandes: 0, has_crudites: false, has_menu_addon: false, time: 15, emoji: '🍳' }),
    mkItem(502, 'ojja-poulet',  5, 'Ojja Poulet',       13.50, 'Ojja avec Poulet + Frites + Pain',       { viandes: 0, has_crudites: false, has_menu_addon: false, time: 15, emoji: '🍳' }),
    mkItem(503, 'ojja-hachee',  5, 'Ojja Viande Hachée', 13.50, 'Ojja avec Viande Hachée + Frites + Pain', { viandes: 0, has_crudites: false, has_menu_addon: false, time: 15, emoji: '🍳' }),
    mkItem(504, 'ojja-merguez', 5, 'Ojja Merguez',      13.50, 'Ojja avec Merguez + Frites + Pain',      { viandes: 0, has_crudites: false, has_menu_addon: false, time: 15, tags: ['SPICY'], is_spicy: true, emoji: '🌶️' }),
  ];

  // ====== OMELETTES (cat 6) ======
  const OMELETTES = [
    mkItem(601, 'omelette-nature',   6, 'Omelette Nature',              7.50, 'Omelette classique + Frites + Pain',           { viandes: 0, has_crudites: false, has_menu_addon: false, time: 8,  emoji: '🥚' }),
    mkItem(602, 'omelette-fromage',  6, 'Omelette Fromage',             8.50, 'Omelette avec Fromage + Frites + Pain',        { viandes: 0, has_crudites: false, has_menu_addon: false, time: 8,  emoji: '🧀' }),
    mkItem(603, 'omelette-champi',   6, 'Omelette Champignons Fromage', 9.50, 'Omelette avec Champignons et Fromage + Frites + Pain', { viandes: 0, has_crudites: false, has_menu_addon: false, time: 10, emoji: '🍄' }),
  ];

  // ====== NOS SALADES (cat 7) ======
  const SALADES = [
    mkItem(701, 'salade-chevre',    7, 'Salade Chèvre',    7.50, 'Laitue · Tomate · Chèvre · Croûtons · Vinaigrette · Maïs', { viandes: 0, has_crudites: false, has_menu_addon: false, time: 5, emoji: '🥗', is_vegetarian: true }),
    mkItem(702, 'salade-royale',    7, 'Salade Royale',    7.50, 'Laitue · Tomate · Maïs · Poulet · Olives',                  { viandes: 0, has_crudites: false, has_menu_addon: false, time: 5, emoji: '🥗' }),
    mkItem(703, 'salade-saumon',    7, 'Salade Saumon',    7.50, 'Laitue · Tomate · Maïs · Saumon · Olives',                  { viandes: 0, has_crudites: false, has_menu_addon: false, time: 5, emoji: '🥗' }),
    mkItem(704, 'salade-tunisienne', 7, 'Salade Tunisienne', 7.50, 'Concombre · Tomate · Oignon · Poivrons · Thon · Olives · Huile d\'Olive', { viandes: 0, has_crudites: false, has_menu_addon: false, time: 5, emoji: '🥗' }),
  ];

  // ====== POULET CROUSTILLANT (cat 8) ======
  const SNACKING = [
    mkItem(801, 'wings-6',   8, 'Ailes de poulet (6 pièces)',    6.00, '6 ailes de poulet croustillantes',  { viandes: 0, has_crudites: false, has_menu_addon: false, time: 8,  emoji: '🍗' }),
    mkItem(802, 'wings-12',  8, 'Ailes de poulet (12 pièces)',   10.50, '12 ailes de poulet croustillantes', { viandes: 0, has_crudites: false, has_menu_addon: false, time: 10, emoji: '🍗', tags: ['TOP'] }),
    mkItem(803, 'tenders-6', 8, 'Filets de poulet croustillants (6 pièces)',  7.50, '6 filets de poulet croustillants',  { viandes: 0, has_crudites: false, has_menu_addon: false, time: 8,  emoji: '🍗' }),
    mkItem(804, 'tenders-12', 8, 'Filets de poulet croustillants (12 pièces)', 13.50, '12 filets de poulet croustillants', { viandes: 0, has_crudites: false, has_menu_addon: false, time: 10, emoji: '🍗' }),
  ];

  // ====== MENUS ENFANTS (cat 9) ======
  const MENUS_ENFANTS = [
    mkItem(901, 'menu-cheese-enfant',  9, 'Menu Cheese Burger (Enfant)', 6.00, '1 steak + 1 cheddar + frites + Capri sun', { viandes: 0, has_crudites: false, has_sauce: false, has_menu_addon: false, time: 10, emoji: '🧒' }),
    mkItem(902, 'menu-nuggets-enfant', 9, 'Menu Nuggets (Enfant)',       6.00, '6 Nuggets de poulet + frites + Capri sun', { viandes: 0, has_crudites: false, has_sauce: false, has_menu_addon: false, time: 10, emoji: '🧒' }),
  ];

  // ====== FRITES & ACCOMPAGNEMENTS (cat 10) ======
  const SIDES = [
    mkItem(1001, 'frites-moyenne', 10, 'Frites Moyenne', 2.50, 'Portion moyenne de frites', { viandes: 0, has_crudites: false, has_sauce: false, has_supplements: false, has_menu_addon: false, time: 4, emoji: '🍟' }),
    mkItem(1002, 'frites-grande',  10, 'Frites Grande',  4.00, 'Grande portion de frites',  { viandes: 0, has_crudites: false, has_sauce: false, has_supplements: false, has_menu_addon: false, time: 5, emoji: '🍟' }),
  ];

  // ====== DESSERTS (cat 11) ======
  const DESSERTS = [
    mkItem(1101, 'glace',      11, 'Glace',      3.80, 'Glace artisanale', { viandes: 0, has_crudites: false, has_sauce: false, has_supplements: false, has_menu_addon: false, time: 0, emoji: '🍦' }),
    mkItem(1102, 'tarte-daim', 11, 'Tarte Daim', 3.80, 'Tarte au Daim',    { viandes: 0, has_crudites: false, has_sauce: false, has_supplements: false, has_menu_addon: false, time: 0, emoji: '🍰' }),
    mkItem(1103, 'tiramisu',   11, 'Tiramisu',   3.80, 'Tiramisu maison',  { viandes: 0, has_crudites: false, has_sauce: false, has_supplements: false, has_menu_addon: false, time: 0, emoji: '🍰' }),
  ];

  // ====== BOISSONS (cat 12) — pas de wizard, ajout direct ======
  const DRINKS = [
    mkItem(1201, 'coca',        12, 'Coca-Cola 33cl',       1.50, 'Coca-Cola original',    { viandes: 0, has_crudites: false, has_sauce: false, has_supplements: false, has_menu_addon: false, time: 0, emoji: '🥤' }),
    mkItem(1202, 'coca-zero',   12, 'Coca-Cola Zero 33cl',  1.50, 'Coca-Cola sans sucre',  { viandes: 0, has_crudites: false, has_sauce: false, has_supplements: false, has_menu_addon: false, time: 0, emoji: '🥤' }),
    mkItem(1203, 'fanta',       12, 'Fanta Orange 33cl',    1.50, 'Fanta Orange',          { viandes: 0, has_crudites: false, has_sauce: false, has_supplements: false, has_menu_addon: false, time: 0, emoji: '🍊' }),
    mkItem(1204, 'sprite',      12, 'Sprite 33cl',          1.50, 'Sprite',                { viandes: 0, has_crudites: false, has_sauce: false, has_supplements: false, has_menu_addon: false, time: 0, emoji: '🍋' }),
    mkItem(1205, 'oasis',       12, 'Oasis Tropical 33cl',  1.50, 'Oasis Tropical',        { viandes: 0, has_crudites: false, has_sauce: false, has_supplements: false, has_menu_addon: false, time: 0, emoji: '🌴' }),
    mkItem(1206, 'orangina',    12, 'Orangina 33cl',        1.50, 'Orangina',              { viandes: 0, has_crudites: false, has_sauce: false, has_supplements: false, has_menu_addon: false, time: 0, emoji: '🍊' }),
    mkItem(1207, 'eau-plate',   12, 'Eau Plate 50cl',       1.00, 'Eau minérale',          { viandes: 0, has_crudites: false, has_sauce: false, has_supplements: false, has_menu_addon: false, time: 0, emoji: '💧' }),
    mkItem(1208, 'capri-sun',   12, 'Capri-Sun',            1.50, 'Capri-Sun 20cl',        { viandes: 0, has_crudites: false, has_sauce: false, has_supplements: false, has_menu_addon: false, time: 0, emoji: '🧃' }),
  ];

  // ====== SUPPLÉMENTS (cat 13) — items commandables seuls ======
  const SUPPLEMENTS_ITEMS = [
    mkItem(1301, 'item-sauce-sup', 13, 'Sauce supplémentaire',     0.50, 'Sauce au choix en supplément', { viandes: 0, has_crudites: false, has_sauce: true, has_supplements: false, has_menu_addon: false, time: 0, emoji: '🥫' }),
    mkItem(1302, 'item-fromage',   13, 'Fromage supplémentaire',   1.00, 'Fromage en supplément',         { viandes: 0, has_crudites: false, has_sauce: false, has_supplements: false, has_menu_addon: false, time: 0, emoji: '🧀' }),
    mkItem(1303, 'item-jambon',    13, 'Jambon de dinde',          1.00, 'Supplément jambon de dinde',    { viandes: 0, has_crudites: false, has_sauce: false, has_supplements: false, has_menu_addon: false, time: 0, emoji: '🥓' }),
    mkItem(1304, 'item-boursin',   13, 'Boursin',                  1.00, 'Supplément Boursin',            { viandes: 0, has_crudites: false, has_sauce: false, has_supplements: false, has_menu_addon: false, time: 0, emoji: '🧀' }),
    mkItem(1305, 'item-raclette',  13, 'Fromage à raclette',       1.00, 'Supplément fromage à raclette', { viandes: 0, has_crudites: false, has_sauce: false, has_supplements: false, has_menu_addon: false, time: 0, emoji: '🧀' }),
    mkItem(1306, 'item-oeuf',      13, 'Œuf',                       1.00, 'Supplément œuf',                { viandes: 0, has_crudites: false, has_sauce: false, has_supplements: false, has_menu_addon: false, time: 0, emoji: '🥚' }),
    mkItem(1307, 'item-galette',   13, 'Galette pommes de terre',   1.00, 'Supplément galette pommes de terre', { viandes: 0, has_crudites: false, has_sauce: false, has_supplements: false, has_menu_addon: false, time: 0, emoji: '🥔' }),
    mkItem(1308, 'item-salade',    13, 'Salade verte',              2.00, 'Salade verte en accompagnement',     { viandes: 0, has_crudites: false, has_sauce: false, has_supplements: false, has_menu_addon: false, time: 0, emoji: '🥗', is_vegetarian: true }),
  ];

  // -------------------------------------------------------------------------
  // ALL ITEMS (47 produits Le Cayenne)
  // -------------------------------------------------------------------------
  const ITEMS = [
    ...TACOS, ...SANDWICHS, ...BURGERS, ...ASSIETTES, ...OJJA, ...OMELETTES,
    ...SALADES, ...SNACKING, ...MENUS_ENFANTS, ...SIDES, ...DESSERTS, ...DRINKS,
    ...SUPPLEMENTS_ITEMS,
  ];

  // -------------------------------------------------------------------------
  // PRICE CALCULATOR (V0 client-side ; en prod = PricingService backend)
  // -------------------------------------------------------------------------
  function priceFor(item, opts) {
    opts = opts || {};
    let total = item.price;
    // Sauces : 1 gratuite, sup 0.50€ chacune au-delà
    if (Array.isArray(opts.sauceIds) && opts.sauceIds.length > 1) {
      total += (opts.sauceIds.length - 1) * 0.50;
    }
    // Suppléments
    (opts.supplementIds || []).forEach(id => {
      const s = SUPPLEMENTS.find(x => x.id === id);
      if (s) total += s.price;
    });
    // Formule (menu/frites/boisson)
    if (opts.formuleId) {
      const f = FORMULES.find(x => x.id === opts.formuleId);
      if (f) total += f.price;
    }
    return total * (opts.qty || 1);
  }

  function defaultCruditeIds() {
    return CRUDITES.filter(c => c.default).map(c => c.id);
  }

  function defaultSauceId() {
    // 1 sauce par défaut au choix utilisateur (V0 = "Sans Sauce" pour ne pas forcer)
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
    formules: FORMULES,
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

  // Backwards-compat globals (utilisés par screens existants)
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
  }));
})();
