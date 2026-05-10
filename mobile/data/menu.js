// Le Cayenne — Catalogue mobile (V0 standalone, schéma aligné FoodKing)
//
// Mapping vers schéma backend (cf. CONNECTION_PLAN.md) :
//   categories  ↔ item_categories
//   items       ↔ items + media
//   variations  ↔ item_variations (taille / déclinaison)
//   extras      ↔ item_extras (suppléments)
//   addons      ↔ item_addons (composants boxe : drink/side/dessert)
//   wizard      ↔ item_wizard_profiles + item_wizard_steps
//   attributes  ↔ item_attributes (règles min/max sur variations)
//
// Le calcul de prix reste autorité backend (PricingService) en prod ;
// ici simulation locale pour V0 (le total affiché est indicatif).

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
    address: 'Rue principale, 62210 Hénin-Beaumont',
    phone: '+33 6 51 30 00 00',
    is_open: true,
    hours: '11h — 00h',
    currency: '€',
    chef: 'Abdoullah',
    locale: 'fr',
  };

  // -------------------------------------------------------------------------
  // CATEGORIES (cf. ItemCategory)
  // -------------------------------------------------------------------------
  const CATEGORIES = [
    { id: 1, slug: 'box',           name: 'Box',           icon: '🔥', sort: 1, kiosk_label: 'Box' },
    { id: 2, slug: 'smash-burgers', name: 'Smash burgers', icon: '🍔', sort: 2, kiosk_label: 'Smash' },
    { id: 3, slug: 'tacos',         name: 'Tacos',         icon: '🌮', sort: 3, kiosk_label: 'Tacos' },
    { id: 4, slug: 'bowls',         name: 'Bowls',         icon: '🥣', sort: 4, kiosk_label: 'Bowls' },
    { id: 5, slug: 'wraps',         name: 'Wraps',         icon: '🌯', sort: 5, kiosk_label: 'Wraps' },
    { id: 6, slug: 'buckets',       name: 'Buckets',       icon: '🍗', sort: 6, kiosk_label: 'Buckets' },
    { id: 7, slug: 'sides',         name: 'Accompagnements', icon: '🍟', sort: 7, kiosk_label: 'Sides' },
    { id: 8, slug: 'drinks',        name: 'Boissons',      icon: '🥤', sort: 8, kiosk_label: 'Boissons' },
    { id: 9, slug: 'desserts',      name: 'Desserts',      icon: '🍩', sort: 9, kiosk_label: 'Desserts' },
  ];

  // -------------------------------------------------------------------------
  // ATTRIBUTES (cf. ItemAttribute) — règles min/max appliquées aux variations
  // -------------------------------------------------------------------------
  const ATTRIBUTES = [
    { id: 1, name: 'Taille',  min_select: 1, max_select: 1, allow_repeat: false },
    { id: 2, name: 'Cuisson', min_select: 1, max_select: 1, allow_repeat: false },
    { id: 3, name: 'Viande',  min_select: 1, max_select: 3, allow_repeat: true  },
  ];

  // -------------------------------------------------------------------------
  // EXTRAS LIBRARY (cf. ItemExtra) — suppléments réutilisés sur plusieurs items
  // -------------------------------------------------------------------------
  const EXTRAS_BURGER = [
    { id: 101, name: 'Oignon caramélisé',         price: 0.00, group_label: 'Garniture', default: true  },
    { id: 102, name: 'Oignons frits',             price: 0.00, group_label: 'Garniture', default: true  },
    { id: 103, name: 'Cornichons',                price: 0.00, group_label: 'Garniture', default: false },
    { id: 104, name: 'Salade',                    price: 0.00, group_label: 'Garniture', default: true  },
    { id: 105, name: 'Tomate',                    price: 0.00, group_label: 'Garniture', default: true  },
    { id: 110, name: 'Cheddar fondu',             price: 1.00, group_label: 'Fromage' },
    { id: 111, name: 'Raclette',                  price: 1.00, group_label: 'Fromage' },
    { id: 112, name: 'Fromage de chèvre',         price: 1.00, group_label: 'Fromage' },
    { id: 113, name: 'Mozzarella',                price: 1.00, group_label: 'Fromage' },
    { id: 120, name: 'Bacon',                     price: 1.50, group_label: 'Charcuterie', is_pork: true },
    { id: 121, name: 'Pastrami',                  price: 2.00, group_label: 'Charcuterie' },
    { id: 122, name: 'Viande hachée supplémentaire', price: 2.50, group_label: 'Viande' },
    { id: 130, name: 'Jalapeños',                 price: 1.00, group_label: 'Épicé', is_spicy: true },
    { id: 131, name: 'Galette de pomme de terre', price: 1.50, group_label: 'Garniture' },
    { id: 132, name: 'Œuf',                       price: 1.00, group_label: 'Garniture' },
    { id: 140, name: 'Sauce maison',              price: 0.00, group_label: 'Sauce', default: true },
  ];

  const EXTRAS_TACOS = [
    { id: 201, name: 'Cheddar fondu',             price: 1.00, group_label: 'Fromage' },
    { id: 202, name: 'Raclette',                  price: 1.00, group_label: 'Fromage' },
    { id: 203, name: 'Mozzarella',                price: 1.00, group_label: 'Fromage' },
    { id: 204, name: 'Viande hachée supplémentaire', price: 2.50, group_label: 'Viande' },
    { id: 205, name: 'Jalapeños',                 price: 1.00, group_label: 'Épicé', is_spicy: true },
    { id: 206, name: 'Œuf',                       price: 1.00, group_label: 'Garniture' },
  ];

  const EXTRAS_BOWL = [
    { id: 301, name: 'Cheddar supplémentaire',    price: 1.00, group_label: 'Fromage' },
    { id: 302, name: 'Avocat',                    price: 2.00, group_label: 'Garniture' },
    { id: 303, name: 'Œuf',                       price: 1.00, group_label: 'Garniture' },
    { id: 304, name: 'Sauce maison',              price: 0.00, group_label: 'Sauce', default: true },
    { id: 305, name: 'Jalapeños',                 price: 1.00, group_label: 'Épicé', is_spicy: true },
  ];

  // -------------------------------------------------------------------------
  // ITEMS (cf. Item) — projection plate alignée KioskMenuService::projectItems
  // -------------------------------------------------------------------------

  // SMASH BURGERS (cat 2) — utilisés comme items autonomes ET comme options pour les Box
  const SMASH = [
    {
      id: 1001, slug: 'cheese-smash', name: 'Le Cheese Smash', category_id: 2,
      price: 9.50, description: 'Pain brioché smashé, double cheddar fondant, sauce maison.',
      thumb: 'item-cheese', kiosk_emoji: '🍔', time: 12,
      tags: ['SIGNATURE'],
      is_featured: true, is_new: false, is_spicy: false, is_halal: true, is_vegetarian: false,
      variations: [],
      extras: EXTRAS_BURGER,
      addons: [],
      wizard_profile: null,
      allergens: ['gluten', 'lactose', 'œuf'],
    },
    {
      id: 1002, slug: 'gourmet', name: 'Le Gourmet', category_id: 2,
      price: 10.50, description: 'Smash beef, oignon caramélisé, raclette, sauce truffe.',
      thumb: 'item-gourmet', kiosk_emoji: '🧀', time: 14,
      tags: ['NOUVEAU'],
      is_featured: false, is_new: true, is_spicy: false, is_halal: true,
      variations: [],
      extras: EXTRAS_BURGER,
      addons: [],
      wizard_profile: null,
      allergens: ['gluten', 'lactose', 'œuf'],
    },
    {
      id: 1003, slug: 'chevre-miel', name: 'Chèvre Miel', category_id: 2,
      price: 9.50, description: 'Smash beef, fromage de chèvre, miel, roquette.',
      thumb: 'item-chevre', kiosk_emoji: '🍯', time: 12,
      tags: [],
      is_featured: false, is_new: false, is_spicy: false, is_halal: true,
      variations: [],
      extras: EXTRAS_BURGER,
      addons: [],
      wizard_profile: null,
      allergens: ['gluten', 'lactose'],
    },
    {
      id: 1004, slug: 'big-smash', name: 'Big Smash', category_id: 2,
      price: 11.50, description: 'Double steak smashé, double cheddar, sauce big.',
      thumb: 'item-big-smash', kiosk_emoji: '💪', time: 14,
      tags: ['TOP'],
      is_featured: true, is_halal: true,
      variations: [],
      extras: EXTRAS_BURGER,
      addons: [],
      wizard_profile: null,
      allergens: ['gluten', 'lactose', 'œuf'],
    },
    {
      id: 1005, slug: 'spicy-smash', name: 'Spicy Smash', category_id: 2,
      price: 10.00, description: 'Smash beef, jalapeños, sauce piquante maison, cheddar.',
      thumb: 'item-spicy', kiosk_emoji: '🌶️', time: 12,
      tags: ['SPICY'],
      is_spicy: true, is_halal: true,
      variations: [],
      extras: EXTRAS_BURGER,
      addons: [],
      wizard_profile: null,
      allergens: ['gluten', 'lactose'],
    },
    {
      id: 1006, slug: 'veggie-smash', name: 'Veggie Smash', category_id: 2,
      price: 9.00, description: 'Steak végétal smashé, cheddar, oignons frits, sauce maison.',
      thumb: 'item-veggie', kiosk_emoji: '🌱', time: 12,
      tags: [],
      is_vegetarian: true, is_halal: true,
      variations: [],
      extras: EXTRAS_BURGER.filter(e => !e.is_pork),
      addons: [],
      wizard_profile: null,
      allergens: ['gluten', 'lactose', 'soja'],
    },
  ];

  // BOISSONS (cat 8)
  const DRINKS = [
    { id: 8001, slug: 'coca',        name: 'Coca-Cola 33cl',     category_id: 8, price: 2.50, description: 'Canette 33cl', thumb: 'drink-coca',     kiosk_emoji: '🥤', time: 0, tags: [], variations: [], extras: [], addons: [], wizard_profile: null, allergens: [] },
    { id: 8002, slug: 'coca-zero',   name: 'Coca Zero 33cl',     category_id: 8, price: 2.50, description: 'Canette 33cl', thumb: 'drink-cocazero', kiosk_emoji: '🥤', time: 0, tags: [], variations: [], extras: [], addons: [], wizard_profile: null, allergens: [] },
    { id: 8003, slug: 'fanta',       name: 'Fanta Orange 33cl',  category_id: 8, price: 2.50, description: 'Canette 33cl', thumb: 'drink-fanta',    kiosk_emoji: '🍊', time: 0, tags: [], variations: [], extras: [], addons: [], wizard_profile: null, allergens: [] },
    { id: 8004, slug: 'sprite',      name: 'Sprite 33cl',        category_id: 8, price: 2.50, description: 'Canette 33cl', thumb: 'drink-sprite',   kiosk_emoji: '🍋', time: 0, tags: [], variations: [], extras: [], addons: [], wizard_profile: null, allergens: [] },
    { id: 8005, slug: 'eau-plate',   name: 'Eau plate 50cl',     category_id: 8, price: 1.50, description: 'Bouteille 50cl', thumb: 'drink-eau',     kiosk_emoji: '💧', time: 0, tags: [], variations: [], extras: [], addons: [], wizard_profile: null, allergens: [] },
    { id: 8006, slug: 'ice-tea',     name: 'Ice Tea pêche 33cl', category_id: 8, price: 2.50, description: 'Canette 33cl', thumb: 'drink-icetea',   kiosk_emoji: '🍑', time: 0, tags: [], variations: [], extras: [], addons: [], wizard_profile: null, allergens: [] },
    { id: 8007, slug: 'oasis',       name: 'Oasis tropical 33cl', category_id: 8, price: 2.50, description: 'Canette 33cl', thumb: 'drink-oasis',   kiosk_emoji: '🌴', time: 0, tags: [], variations: [], extras: [], addons: [], wizard_profile: null, allergens: [] },
  ];

  // SIDES (cat 7) — frites & accompagnements
  const SIDES = [
    {
      id: 7001, slug: 'frite', name: 'Frites maison', category_id: 7,
      price: 3.50, description: 'Frites fraîches maison, croustillantes.',
      thumb: 'side-frite', kiosk_emoji: '🍟', time: 5, tags: [],
      variations: [
        { id: 70011, name: 'M (medium)',  price: 3.50, attribute_id: 1, sort: 1 },
        { id: 70012, name: 'L (large)',   price: 4.50, attribute_id: 1, sort: 2 },
        { id: 70013, name: 'XXL',         price: 6.00, attribute_id: 1, sort: 3 },
      ],
      itemAttributes: [{ id: 1, name: 'Taille', min_select: 1, max_select: 1, allow_repeat: false }],
      extras: [
        { id: 701, name: 'Cheddar fondu sauce', price: 1.50, group_label: 'Topping' },
        { id: 702, name: 'Bacon émietté',       price: 1.50, group_label: 'Topping', is_pork: true },
        { id: 703, name: 'Cheddar + bacon',     price: 2.50, group_label: 'Topping', is_pork: true },
      ],
      addons: [],
      wizard_profile: null,
      allergens: ['gluten'],
    },
    {
      id: 7002, slug: 'frite-cheddar', name: 'Frites cheddar', category_id: 7,
      price: 5.50, description: 'Frites maison, cheddar fondu coulant.',
      thumb: 'side-frite-cheddar', kiosk_emoji: '🧀', time: 5, tags: ['TOP'],
      variations: [],
      extras: [],
      addons: [],
      wizard_profile: null,
      allergens: ['gluten', 'lactose'],
    },
    {
      id: 7003, slug: 'tenders-x6', name: 'Tenders × 6', category_id: 7,
      price: 7.50, description: '6 tenders panés croustillants, sauce au choix.',
      thumb: 'side-tenders', kiosk_emoji: '🍗', time: 8, tags: [],
      variations: [],
      extras: [],
      addons: [
        { id: 70031, role: 'side', addon_item_id: 9001, name: 'Sauce' },
      ],
      wizard_profile: null,
      allergens: ['gluten'],
    },
    {
      id: 7004, slug: 'wings-x6', name: 'Wings × 6', category_id: 7,
      price: 7.50, description: '6 wings sauce piquante BBQ ou nashville.',
      thumb: 'side-wings', kiosk_emoji: '🥢', time: 8, tags: ['SPICY'], is_spicy: true,
      variations: [
        { id: 70041, name: 'BBQ',       price: 7.50, attribute_id: 1, sort: 1 },
        { id: 70042, name: 'Nashville', price: 7.50, attribute_id: 1, sort: 2 },
      ],
      itemAttributes: [{ id: 1, name: 'Sauce', min_select: 1, max_select: 1, allow_repeat: false }],
      extras: [],
      addons: [],
      wizard_profile: null,
      allergens: [],
    },
    {
      id: 7005, slug: 'nuggets-x6', name: 'Nuggets × 6', category_id: 7,
      price: 5.50, description: '6 nuggets de poulet panés, sauce au choix.',
      thumb: 'side-nuggets', kiosk_emoji: '🍗', time: 6, tags: [],
      variations: [],
      extras: [],
      addons: [],
      wizard_profile: null,
      allergens: ['gluten'],
    },
  ];

  // BUCKETS (cat 6)
  const BUCKETS = [
    {
      id: 6001, slug: 'bucket-tenders', name: 'Bucket Tenders', category_id: 6,
      price: 14.00, description: '12 tenders panés, 2 sauces au choix.',
      thumb: 'bucket-tenders', kiosk_emoji: '🪣', time: 12, tags: ['TOP'],
      variations: [],
      extras: [],
      addons: [],
      wizard_profile: null,
      allergens: ['gluten'],
    },
    {
      id: 6002, slug: 'bucket-wings', name: 'Bucket Wings', category_id: 6,
      price: 14.00, description: '12 wings BBQ ou Nashville, 2 sauces.',
      thumb: 'bucket-wings', kiosk_emoji: '🔥', time: 12, tags: ['SPICY'], is_spicy: true,
      variations: [
        { id: 60021, name: 'BBQ',       price: 14.00, attribute_id: 1, sort: 1 },
        { id: 60022, name: 'Nashville', price: 14.00, attribute_id: 1, sort: 2 },
        { id: 60023, name: 'Mixte',     price: 14.00, attribute_id: 1, sort: 3 },
      ],
      itemAttributes: [{ id: 1, name: 'Sauce', min_select: 1, max_select: 1, allow_repeat: false }],
      extras: [],
      addons: [],
      wizard_profile: null,
      allergens: [],
    },
  ];

  // BOWLS (cat 4)
  const BOWLS = [
    {
      id: 4001, slug: 'bowl-cheesy', name: 'Bowl Cheesy', category_id: 4,
      price: 11.50, description: 'Riz, poulet croustillant, cheddar fondu, mayo épicée.',
      thumb: 'bowl-cheesy', kiosk_emoji: '🥣', time: 10, tags: ['NOUVEAU'],
      is_new: true, is_halal: true,
      variations: [],
      extras: EXTRAS_BOWL,
      addons: [],
      wizard_profile: null,
      allergens: ['gluten', 'lactose'],
    },
    {
      id: 4002, slug: 'bowl-gratine', name: 'Bowl Gratiné', category_id: 4,
      price: 11.50, description: 'Riz, viande hachée, mozzarella et cheddar gratinés.',
      thumb: 'bowl-gratine', kiosk_emoji: '🧀', time: 10, tags: [],
      is_halal: true,
      variations: [],
      extras: EXTRAS_BOWL,
      addons: [],
      wizard_profile: null,
      allergens: ['lactose'],
    },
    {
      id: 4003, slug: 'bowl-veggie', name: 'Bowl Veggie', category_id: 4,
      price: 10.50, description: 'Riz, falafels, avocat, sauce yaourt-menthe.',
      thumb: 'bowl-veggie', kiosk_emoji: '🥑', time: 10, tags: [],
      is_vegetarian: true, is_halal: true,
      variations: [],
      extras: EXTRAS_BOWL,
      addons: [],
      wizard_profile: null,
      allergens: ['gluten', 'lactose'],
    },
  ];

  // WRAPS (cat 5)
  const WRAPS = [
    {
      id: 5001, slug: 'wrap-poulet', name: 'Wrap Poulet', category_id: 5,
      price: 8.50, description: 'Tortilla, poulet pané, cheddar, salade, sauce maison.',
      thumb: 'wrap-poulet', kiosk_emoji: '🌯', time: 8, tags: [],
      is_halal: true,
      variations: [],
      extras: EXTRAS_TACOS,
      addons: [],
      wizard_profile: null,
      allergens: ['gluten', 'lactose'],
    },
    {
      id: 5002, slug: 'wrap-tex-mex', name: 'Wrap Tex-Mex', category_id: 5,
      price: 9.00, description: 'Tortilla, viande hachée, haricots rouges, cheddar, sauce piquante.',
      thumb: 'wrap-texmex', kiosk_emoji: '🌶️', time: 8, tags: ['SPICY'],
      is_spicy: true, is_halal: true,
      variations: [],
      extras: EXTRAS_TACOS,
      addons: [],
      wizard_profile: null,
      allergens: ['gluten'],
    },
    {
      id: 5003, slug: 'wrap-veggie', name: 'Wrap Veggie', category_id: 5,
      price: 8.00, description: 'Tortilla, falafels, salade, sauce yaourt-menthe.',
      thumb: 'wrap-veggie', kiosk_emoji: '🌿', time: 8, tags: [],
      is_vegetarian: true, is_halal: true,
      variations: [],
      extras: EXTRAS_TACOS,
      addons: [],
      wizard_profile: null,
      allergens: ['gluten', 'lactose'],
    },
  ];

  // TACOS (cat 3) — French Tacos avec variations de taille + viandes multiples
  const TACOS = [
    {
      id: 3001, slug: 'tacos-1-viande', name: 'Tacos 1 viande', category_id: 3,
      price: 7.50, description: 'Tortilla grillée, frites, fromage, 1 viande au choix, sauce maison.',
      thumb: 'tacos-1', kiosk_emoji: '🌮', time: 10, tags: [],
      is_halal: true,
      variations: [
        { id: 30011, name: 'M (medium)', price: 7.50,  attribute_id: 1, sort: 1 },
        { id: 30012, name: 'L (large)',  price: 9.00,  attribute_id: 1, sort: 2 },
        { id: 30013, name: 'XL',         price: 11.00, attribute_id: 1, sort: 3 },
      ],
      itemAttributes: [
        { id: 1, name: 'Taille', min_select: 1, max_select: 1, allow_repeat: false },
        { id: 3, name: 'Viande', min_select: 1, max_select: 1, allow_repeat: false },
      ],
      extras: EXTRAS_TACOS,
      addons: [
        { id: 30019, role: 'menu_component', addon_item_id: null, name: 'Viande au choix',
          options: [
            { id: 30015, name: 'Steak haché halal', price: 0 },
            { id: 30016, name: 'Poulet pané',       price: 0 },
            { id: 30017, name: 'Cordon bleu',       price: 0 },
            { id: 30018, name: 'Merguez',           price: 0 },
          ]
        },
      ],
      wizard_profile: null,
      allergens: ['gluten', 'lactose'],
    },
    {
      id: 3002, slug: 'tacos-2-viandes', name: 'Tacos 2 viandes', category_id: 3,
      price: 9.00, description: 'Tortilla grillée, frites, fromage, 2 viandes au choix.',
      thumb: 'tacos-2', kiosk_emoji: '🌮', time: 10, tags: ['TOP'],
      is_halal: true,
      variations: [
        { id: 30021, name: 'M (medium)', price: 9.00,  attribute_id: 1, sort: 1 },
        { id: 30022, name: 'L (large)',  price: 10.50, attribute_id: 1, sort: 2 },
        { id: 30023, name: 'XL',         price: 12.50, attribute_id: 1, sort: 3 },
      ],
      itemAttributes: [
        { id: 1, name: 'Taille', min_select: 1, max_select: 1, allow_repeat: false },
        { id: 3, name: 'Viandes', min_select: 2, max_select: 2, allow_repeat: true },
      ],
      extras: EXTRAS_TACOS,
      addons: [
        { id: 30029, role: 'menu_component', addon_item_id: null, name: 'Viandes au choix',
          options: [
            { id: 30025, name: 'Steak haché halal', price: 0 },
            { id: 30026, name: 'Poulet pané',       price: 0 },
            { id: 30027, name: 'Cordon bleu',       price: 0 },
            { id: 30028, name: 'Merguez',           price: 0 },
          ]
        },
      ],
      wizard_profile: null,
      allergens: ['gluten', 'lactose'],
    },
    {
      id: 3003, slug: 'tacos-3-viandes', name: 'Tacos 3 viandes', category_id: 3,
      price: 11.00, description: 'Tortilla grillée, frites, fromage, 3 viandes au choix. Le tacos king.',
      thumb: 'tacos-3', kiosk_emoji: '🌮', time: 12, tags: ['SIGNATURE'],
      is_halal: true, is_featured: true,
      variations: [
        { id: 30031, name: 'L (large)', price: 11.00, attribute_id: 1, sort: 1 },
        { id: 30032, name: 'XL',        price: 13.50, attribute_id: 1, sort: 2 },
      ],
      itemAttributes: [
        { id: 1, name: 'Taille', min_select: 1, max_select: 1, allow_repeat: false },
        { id: 3, name: 'Viandes', min_select: 3, max_select: 3, allow_repeat: true },
      ],
      extras: EXTRAS_TACOS,
      addons: [
        { id: 30039, role: 'menu_component', addon_item_id: null, name: 'Viandes au choix',
          options: [
            { id: 30035, name: 'Steak haché halal', price: 0 },
            { id: 30036, name: 'Poulet pané',       price: 0 },
            { id: 30037, name: 'Cordon bleu',       price: 0 },
            { id: 30038, name: 'Merguez',           price: 0 },
          ]
        },
      ],
      wizard_profile: null,
      allergens: ['gluten', 'lactose'],
    },
  ];

  // BOX (cat 1) — utilisent wizard_profile pour la composition
  // Le wizard est la pièce maîtresse : choisir N burgers, 1 frite, N boissons.
  const BOXES = [
    {
      id: 2001, slug: 'box-solo', name: 'Box Solo', category_id: 1,
      price: 13.50, description: '1 smash + frite M + 1 boisson. Le combo malin.',
      thumb: 'box-solo', kiosk_emoji: '📦', time: 12, tags: [],
      is_halal: true,
      variations: [],
      extras: [],
      addons: [],
      wizard_profile: {
        id: 9001, version: 1, is_published: true,
        steps: [
          {
            id: 90011, step_key: 'burger', label: 'Choisis ton smash',
            source_type: 'addon', addon_role: 'menu_component',
            min_select: 1, max_select: 1, allow_repeat: false, position: 1,
            options: SMASH.map(s => ({ id: s.id, name: s.name, price: 0, slug: s.slug, kiosk_emoji: s.kiosk_emoji })),
          },
          {
            id: 90012, step_key: 'side', label: 'Frite',
            source_type: 'addon', addon_role: 'side',
            min_select: 1, max_select: 1, allow_repeat: false, position: 2,
            options: [
              { id: 70011, name: 'Frite M', price: 0,    slug: 'frite-m',   kiosk_emoji: '🍟' },
              { id: 70012, name: 'Frite L', price: 1.00, slug: 'frite-l',   kiosk_emoji: '🍟' },
              { id: 70013, name: 'Frite XXL', price: 2.50, slug: 'frite-xxl', kiosk_emoji: '🍟' },
            ],
          },
          {
            id: 90013, step_key: 'drink', label: 'Choisis ta boisson',
            source_type: 'addon', addon_role: 'drink',
            min_select: 1, max_select: 1, allow_repeat: false, position: 3,
            options: DRINKS.map(d => ({ id: d.id, name: d.name, price: 0, slug: d.slug, kiosk_emoji: d.kiosk_emoji })),
          },
        ],
      },
      allergens: ['gluten', 'lactose'],
    },
    {
      id: 2002, slug: 'box-nashville', name: 'Box Nashville', category_id: 1,
      price: 15.00, description: '2 tenders Nashville, frite, smash au choix et boisson.',
      thumb: 'item-nashville', kiosk_emoji: '🌶️', time: 15, tags: ['SPICY','TOP'],
      is_spicy: true, is_halal: true, is_featured: true,
      variations: [],
      extras: [],
      addons: [],
      wizard_profile: {
        id: 9002, version: 1, is_published: true,
        steps: [
          {
            id: 90021, step_key: 'burger', label: 'Choisis ton smash',
            source_type: 'addon', addon_role: 'menu_component',
            min_select: 1, max_select: 1, allow_repeat: false, position: 1,
            options: SMASH.map(s => ({ id: s.id, name: s.name, price: 0, slug: s.slug, kiosk_emoji: s.kiosk_emoji })),
          },
          {
            id: 90022, step_key: 'side', label: 'Frite',
            source_type: 'addon', addon_role: 'side',
            min_select: 1, max_select: 1, allow_repeat: false, position: 2,
            options: [
              { id: 70011, name: 'Frite M', price: 0,    slug: 'frite-m',   kiosk_emoji: '🍟' },
              { id: 70012, name: 'Frite L', price: 1.00, slug: 'frite-l',   kiosk_emoji: '🍟' },
            ],
          },
          {
            id: 90023, step_key: 'drink', label: 'Choisis ta boisson',
            source_type: 'addon', addon_role: 'drink',
            min_select: 1, max_select: 1, allow_repeat: false, position: 3,
            options: DRINKS.map(d => ({ id: d.id, name: d.name, price: 0, slug: d.slug, kiosk_emoji: d.kiosk_emoji })),
          },
        ],
      },
      allergens: ['gluten', 'lactose'],
    },
    {
      id: 2003, slug: 'box-familiale', name: 'Box Familiale', category_id: 1,
      price: 29.00, description: '4 smash, 5 wings, 5 tenders, frite XXL, 4 boissons.',
      thumb: 'item-familiale', kiosk_emoji: '🍔🍔🍔🍔', time: 20, tags: ['SIGNATURE'],
      is_featured: true, is_halal: true,
      variations: [],
      extras: [],
      addons: [],
      wizard_profile: {
        id: 9003, version: 1, is_published: true,
        steps: [
          {
            id: 90031, step_key: 'burger_1', label: 'Smash 1/4',
            source_type: 'addon', addon_role: 'menu_component',
            min_select: 1, max_select: 1, allow_repeat: false, position: 1,
            options: SMASH.map(s => ({ id: s.id, name: s.name, price: 0, slug: s.slug, kiosk_emoji: s.kiosk_emoji })),
          },
          {
            id: 90032, step_key: 'burger_2', label: 'Smash 2/4',
            source_type: 'addon', addon_role: 'menu_component',
            min_select: 1, max_select: 1, allow_repeat: false, position: 2,
            options: SMASH.map(s => ({ id: s.id, name: s.name, price: 0, slug: s.slug, kiosk_emoji: s.kiosk_emoji })),
          },
          {
            id: 90033, step_key: 'burger_3', label: 'Smash 3/4',
            source_type: 'addon', addon_role: 'menu_component',
            min_select: 1, max_select: 1, allow_repeat: false, position: 3,
            options: SMASH.map(s => ({ id: s.id, name: s.name, price: 0, slug: s.slug, kiosk_emoji: s.kiosk_emoji })),
          },
          {
            id: 90034, step_key: 'burger_4', label: 'Smash 4/4',
            source_type: 'addon', addon_role: 'menu_component',
            min_select: 1, max_select: 1, allow_repeat: false, position: 4,
            options: SMASH.map(s => ({ id: s.id, name: s.name, price: 0, slug: s.slug, kiosk_emoji: s.kiosk_emoji })),
          },
          {
            id: 90035, step_key: 'drink_1', label: 'Boisson 1/4',
            source_type: 'addon', addon_role: 'drink',
            min_select: 1, max_select: 1, allow_repeat: false, position: 5,
            options: DRINKS.map(d => ({ id: d.id, name: d.name, price: 0, slug: d.slug, kiosk_emoji: d.kiosk_emoji })),
          },
          {
            id: 90036, step_key: 'drink_2', label: 'Boisson 2/4',
            source_type: 'addon', addon_role: 'drink',
            min_select: 1, max_select: 1, allow_repeat: false, position: 6,
            options: DRINKS.map(d => ({ id: d.id, name: d.name, price: 0, slug: d.slug, kiosk_emoji: d.kiosk_emoji })),
          },
          {
            id: 90037, step_key: 'drink_3', label: 'Boisson 3/4',
            source_type: 'addon', addon_role: 'drink',
            min_select: 1, max_select: 1, allow_repeat: false, position: 7,
            options: DRINKS.map(d => ({ id: d.id, name: d.name, price: 0, slug: d.slug, kiosk_emoji: d.kiosk_emoji })),
          },
          {
            id: 90038, step_key: 'drink_4', label: 'Boisson 4/4',
            source_type: 'addon', addon_role: 'drink',
            min_select: 1, max_select: 1, allow_repeat: false, position: 8,
            options: DRINKS.map(d => ({ id: d.id, name: d.name, price: 0, slug: d.slug, kiosk_emoji: d.kiosk_emoji })),
          },
        ],
      },
      allergens: ['gluten', 'lactose'],
    },
  ];

  // DESSERTS (cat 9)
  const DESSERTS = [
    { id: 9001, slug: 'brownie', name: 'Brownie chocolat', category_id: 9, price: 4.50, description: 'Brownie chocolat noir, fondant.',  thumb: 'dessert-brownie', kiosk_emoji: '🍫', time: 0, tags: [],            variations: [], extras: [], addons: [], wizard_profile: null, allergens: ['gluten', 'lactose', 'œuf'] },
    { id: 9002, slug: 'cookie',  name: 'Cookie XL',        category_id: 9, price: 3.50, description: 'Gros cookie pépites de chocolat.', thumb: 'dessert-cookie',  kiosk_emoji: '🍪', time: 0, tags: ['NOUVEAU'], is_new: true, variations: [], extras: [], addons: [], wizard_profile: null, allergens: ['gluten', 'lactose', 'œuf'] },
    { id: 9003, slug: 'glace',   name: 'Glace 2 boules',   category_id: 9, price: 4.00, description: 'Vanille, chocolat, fraise au choix.', thumb: 'dessert-glace', kiosk_emoji: '🍦', time: 0, tags: [],         variations: [
      { id: 90031, name: 'Vanille',   price: 4.00, attribute_id: 1, sort: 1 },
      { id: 90032, name: 'Chocolat',  price: 4.00, attribute_id: 1, sort: 2 },
      { id: 90033, name: 'Fraise',    price: 4.00, attribute_id: 1, sort: 3 },
      { id: 90034, name: '2 parfums', price: 4.50, attribute_id: 1, sort: 4 },
    ], itemAttributes: [{ id: 1, name: 'Parfum', min_select: 1, max_select: 1, allow_repeat: false }], extras: [], addons: [], wizard_profile: null, allergens: ['lactose'] },
  ];

  // -------------------------------------------------------------------------
  // ITEMS — assembly + helpers
  // -------------------------------------------------------------------------
  const ITEMS = [...BOXES, ...SMASH, ...TACOS, ...BOWLS, ...WRAPS, ...BUCKETS, ...SIDES, ...DRINKS, ...DESSERTS];

  // Helper de calcul de prix (V0 simulation client-side ; en prod = PricingService backend)
  function priceFor(item, opts) {
    opts = opts || {};
    let base = item.price;
    // variation override
    if (opts.variationId && item.variations) {
      const v = item.variations.find(x => x.id === opts.variationId);
      if (v) base = v.price;
    }
    // extras
    let extras = 0;
    (opts.extraIds || []).forEach(id => {
      const e = (item.extras || []).find(x => x.id === id);
      if (e) extras += e.price;
    });
    // wizard step selections (addons price deltas)
    let stepDeltas = 0;
    if (opts.wizardSelections && item.wizard_profile) {
      Object.keys(opts.wizardSelections).forEach(stepKey => {
        const step = item.wizard_profile.steps.find(s => s.step_key === stepKey);
        if (!step) return;
        const selected = opts.wizardSelections[stepKey];
        const ids = Array.isArray(selected) ? selected : [selected];
        ids.forEach(optId => {
          const opt = step.options.find(o => o.id === optId);
          if (opt) stepDeltas += opt.price;
        });
      });
    }
    const qty = opts.qty || 1;
    return (base + extras + stepDeltas) * qty;
  }

  // Default extras IDs (what's pre-toggled when opening the item detail screen)
  function defaultExtraIds(item) {
    return (item.extras || []).filter(e => e.default).map(e => e.id);
  }

  // -------------------------------------------------------------------------
  // EXPORT
  // -------------------------------------------------------------------------
  window.LC = window.LC || {};
  window.LC.menu = {
    branch: BRANCH,
    categories: CATEGORIES,
    attributes: ATTRIBUTES,
    items: ITEMS,
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
    defaultExtraIds,
  };

  // Backwards-compat globals used by existing screens-main.jsx
  // (mapping vers le format simplifié original : { id, name, cat, price, desc, tags, slot, time })
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
  }));
})();
