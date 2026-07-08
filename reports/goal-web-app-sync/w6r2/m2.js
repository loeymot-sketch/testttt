// Le Cayenne — Web data layer aligned FoodKing central system (SSOT mirror)
// [GOAL LONG-TERM 2026-05-16] Mirror EXACT de mobile/data/menu.js.
// [MENU-CANON 2026-06-26] Réalignement complet sur le VRAI menu Le Cayenne.
//   SSOT = database/seeders/OwnerMenuUpdate20260623Seeder.php.
//   Changements : 7 viandes mixtes (au lieu de 4 poulet-only), 12 sauces, 3 crudités,
//   formule menu +2,50, Tacos L 7,90, Chicken Burger 4,90, Desserts 3,50, canettes 1,90,
//   +5 burgers, +3 sandwichs (Suprême/Méga/Terminator), Bols 8→2 (viande au choix),
//   Menu enfant 1→2 SKU @ 4,90.
//   Catégories purgées : "Sandwich Classique" (cat3), "Suppléments" vendable (cat8).
//   Catégories renommées : "Sandwich Cayenne"→"Sandwichs", "Bols Gourmands"→"Bols".
//   ⛔ STANDALONE — aucun wireup API V1.
// [GOAL-SYNC 2026-07-08] Réalignement fixture canonique borne (catalog-canonical.json) :
//   +7 boissons (Coca Cherry/Tropico/Ice Tea Pêche/Fanta Citron/Fuze Tea/Hawaï/Perrier @1,90),
//   FORMULE_DRINKS 8→15 saveurs, Capri-Sun addon 1,50 (FIX P0), CRUDITES 3→4 (+Oignons cuits),
//   SUPPLEMENTS +Boule gratinée 1,00 (galette_only) + Boursin galette_excluded, Tacos M/L
//   has_crudites:true (revert 05e5cacd0), Cayenne sauce au choix (défaut fromagère) + non-épicé,
//   1 sauce max sandwich/tacos/burger (fin du +0,50/sauce), Menu Enfant Chicken Burger,
//   is_halal défaut false, desserts is_vegetarian:true.
//
// Catégories visibles (9) : Sandwichs, Galette, Burgers, Tacos, Bols, Frites,
//              Desserts, Boissons, Menu enfant.

(function () {
  'use strict';

  // -------------------------------------------------------------------------
  // BRAND
  // -------------------------------------------------------------------------
  const BRAND = {
    name: 'Le Cayenne',
    city: 'Hénin-Beaumont',
    zip: '62210',
    address: '14 rue de la République, 62210 Hénin-Beaumont',
    phone: '+33 6 51 30 00 00',
    chef: 'Abdoullah',
    catchphrase: 'Du peuple, pour le peuple',
    hours: '11h — 00h',
    locale: 'fr',
    currency: '€',
  };

  // -------------------------------------------------------------------------
  // IMAGE ASSETS — [IMG-SYNC-CAISSE 2026-06-27] source = SERVEUR D'IMAGES DE LA CAISSE.
  //   L'owner met à jour les images côté backend ; le web les sert directement (mêmes photos,
  //   toujours synchronisées, distinctes par viande/produit). Configurable via
  //   <meta name="menu-image-base">. Défaut = backend local :8766 ; fallback = local 'assets/menu/'.
  //   (Les filenames d'ITEM_IMG/MEATS sont alignés exactement sur les thumbs backend.)
  // -------------------------------------------------------------------------
  const ASSET_BASE = (function () {
    try {
      var el = document.querySelector('meta[name="menu-image-base"]');
      if (el && el.content) return el.content.replace(/\/?$/, '/');
    } catch (e) {}
    return 'http://127.0.0.1:8766/images/menu/';
  })();

  // [MENU-CANON 2026-06-26] board photos réutilisées (mirror mobile)
  // [IMG-SYNC-CAISSE 2026-06-27] Filenames alignés EXACTEMENT sur les thumbs backend/caisse
  //   (GET /api/frontend/item .thumb) — l'owner a mis à jour toutes les images produits.
  //   Avant : plusieurs produits réutilisaient des images génériques (burger-cheese/burger-big,
  //   sandwich-classique, tacos.png) ≠ caisse. Désormais image distincte par produit, idem caisse.
  const ITEM_IMG = {
    'cayenne':                    'sandwich-cayenne.png',
    'supreme':                    'sandwich-supreme.png',
    'mega':                       'sandwich-mega.png',
    'terminator':                 'sandwich-terminator.png',
    'galette-normale':            'galette.png',
    'galette-cayenne':            'galette.png',
    'chicken-burger':             'chicken_burger.png',
    'cheese-burger':              'cheese-burger.png',
    'double-cheese':              'double-cheese.png',
    'fish-burger':                'fish-burger.png',
    'big-burger':                 'big-burger.png',
    'grill-burger':               'grill-burger.png',
    'tacos-m':                    'tacos-cayenne.png',
    'tacos-l':                    'tacos-cayenne.png',
    'bol-frites':                 'bol-frites.png',
    'bol-riz':                    'bol-riz.png',
    'petite-frites':              'frites.png',
    'grande-frites':              'frites.png',
    'glace':                      'ben-jerrys.png',
    'tarte-daim':                 'tarte.png',
    'tiramisu':                   'tiramisu.png',
    'coca':                       'coca.png',
    'coca-zero':                  'coca-zero.png',
    'fanta':                      'fanta-orange.png',
    'sprite':                     'sprite.png',
    'oasis':                      'oasis.png',
    'orangina':                   'tropico.png',
    'eau-plate':                  'eau.png',
    'capri-sun':                  'capri-sun.png',
    // [GOAL-SYNC 2026-07-08] +7 boissons canoniques — mêmes assets que les thumbs borne
    // (quirks canoniques assumés : Fuze Tea→lipton-framboise, Hawaï→fanta-fraise, Perrier→eau).
    'coca-cherry':                'coca-cherry.png',
    'tropico':                    'tropico.png',
    'ice-tea-peche':              'lipton-peche.png',
    'fanta-citron':               'fanta-citron.png',
    'fuze-tea':                   'lipton-framboise.png',
    'hawai':                      'fanta-fraise.png',
    'perrier':                    'eau.png',
    'menu-enfant-nuggets':        'nuggets.png',
    'menu-enfant-burger':         'chicken_burger.png',
  };

  const HERO_IMG = {
    'cayenne':                    'signature/cayenne-hero.png',
    'mega':                       'signature/cayenne-hero.png',
    'terminator':                 'signature/cayenne-hero.png',
    'galette-cayenne':            'signature/cayenne-hero.png',
    'tacos-m':                    'signature/tacos-hero.png',
    'tacos-l':                    'signature/tacos-hero.png',
  };

  function imgFor(slug) { return ASSET_BASE + (ITEM_IMG[slug] || 'item-default.svg'); }
  function heroFor(slug) { return HERO_IMG[slug] ? (ASSET_BASE + HERO_IMG[slug]) : imgFor(slug); }

  // -------------------------------------------------------------------------
  // SHARED POOLS — Le Cayenne canon (OwnerMenuUpdate20260623Seeder.php)
  // -------------------------------------------------------------------------

  // 7 viandes au choix (seeder MEATS) — [IMG-SYNC-CAISSE 2026-06-27] image DISTINCTE par viande,
  //   alignée sur les thumbs backend (attr1/2) que l'owner a mis à jour. Avant : 4 images
  //   génériques réutilisées (viande-marine/crispy/tandoori/curry) ≠ caisse.
  const MEATS = [
    { id: 'm-mexicanos',     name: 'Mexicanos',     price: 0, emoji: '🌶️', image: ASSET_BASE + 'viande-mexicanos.png' },
    { id: 'm-cordon-bleu',   name: 'Cordon Bleu',   price: 0, emoji: '🍗', image: ASSET_BASE + 'viande-cordon-bleu.png' },
    { id: 'm-viande-hachee', name: 'Viande Hachée', price: 0, emoji: '🥩', image: ASSET_BASE + 'viande-hachee.png' },
    { id: 'm-nuggets',       name: 'Nuggets',       price: 0, emoji: '🍗', image: ASSET_BASE + 'viande-nuggets.png' },
    { id: 'm-tenders',       name: 'Tenders',       price: 0, emoji: '🍗', image: ASSET_BASE + 'viande-tenders.png' },
    { id: 'm-fricadelle',    name: 'Fricadelle',    price: 0, emoji: '🌭', image: ASSET_BASE + 'viande-fricadelle.png' },
    { id: 'm-poulet-marine', name: 'Poulet mariné', price: 0, emoji: '🍗', image: ASSET_BASE + 'viande-poulet.png' },
  ];

  // 12 sauces incluses (seeder SAUCES) — [GOAL-SYNC 2026-07-08] 1 sauce max (canonique
  //   min1/max1 sur sandwich/tacos/burger) ; l'ancienne cascade +0,50€/sauce est SUPPRIMÉE.
  const SAUCES = [
    { id: 's-mayo',      name: 'Mayonnaise',       price: 0, image: ASSET_BASE + 'sauce-mayonnaise.png' },
    { id: 's-ketchup',   name: 'Ketchup',          price: 0, image: ASSET_BASE + 'sauce-ketchup.png' },
    { id: 's-blanche',   name: 'Blanche',          price: 0, image: ASSET_BASE + 'sauce-blanche.png' },
    { id: 's-hannibal',  name: 'Hannibal',         price: 0, is_spicy: true, image: ASSET_BASE + 'sauce-hannibal.png' },
    { id: 's-samurai',   name: 'Samouraï',         price: 0, is_spicy: true, image: ASSET_BASE + 'sauce-samurai.png' },
    { id: 's-algerien',  name: 'Algérienne',       price: 0, image: ASSET_BASE + 'sauce-algerienne.png' },
    { id: 's-andalouse', name: 'Andalouse',        price: 0, image: ASSET_BASE + 'sauce-andalouse.png' },
    { id: 's-curry',     name: 'Curry',            price: 0, image: ASSET_BASE + 'sauce-curry.png' },
    { id: 's-barbecue',  name: 'Barbecue',         price: 0, image: ASSET_BASE + 'sauce-barbecue.png' },
    { id: 's-harissa',   name: 'Harissa',          price: 0, is_spicy: true, image: ASSET_BASE + 'sauce-harissa.png' },
    { id: 's-fromagere', name: 'Fromagère maison', price: 0, image: ASSET_BASE + 'sauce-fromagere-maison.png' },
    { id: 's-spicy',     name: 'Spicy maison',     price: 0, is_spicy: true, image: ASSET_BASE + 'sauce-spicy-maison.png' },
  ];

  // 4 crudités — [GOAL-SYNC 2026-07-08] +Oignons cuits (canonique, extra 0€, opt-in
  //   non pré-sélectionné ; thumb borne = placeholder item-default.svg, reproduit ici).
  const CRUDITES = [
    { id: 'c-salade',        name: 'Salade',        default: true, image: ASSET_BASE + 'salade.png' },
    { id: 'c-tomate',        name: 'Tomate',        default: true, image: ASSET_BASE + 'tomate.png' },
    { id: 'c-oignon',        name: 'Oignon',        default: true, image: ASSET_BASE + 'oignon.png' },
    { id: 'c-oignons-cuits', name: 'Oignons cuits', price: 0,      image: ASSET_BASE + 'item-default.svg' },
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
    // [GOAL-SYNC 2026-07-08] Boursin ABSENT des extras canoniques Galette → galette_excluded.
    { id: 'sup-boursin',        name: 'Boursin',        price: 0.90, image: ASSET_BASE + 'boursin.png',           allergens: ['lactose'], galette_excluded: true },
    { id: 'sup-oeuf',           name: 'Œuf',            price: 0.90, image: ASSET_BASE + 'oeuf.png',              allergens: ['oeuf'] },
    { id: 'sup-legumes-sautes', name: 'Légumes sautés', price: 0.90, image: ASSET_BASE + 'legumes-sautes.png',    allergens: [] },
    // [GOAL-SYNC 2026-07-08] Supplément canonique réservé aux Galettes (galette_only) —
    //   1,00€, thumb borne = bol-frites-gratine (asset existant réutilisé).
    { id: 'sup-boule-gratinee', name: 'Boule gratinée', price: 1.00, image: ASSET_BASE + 'bol-frites-gratine.png', allergens: ['lactose'], galette_only: true },
  ];

  // Suppléments spécifiques aux bols (seeder SUPP_GROUP_BOL : les 9 suppléments + gratiné +2€ sur riz).
  // [WEB-SYNC-CAISSE 2026-06-26 F1] complété 5→9 (Raclette/Emmental/Boursin/Œuf/Légumes sautés
  //   manquaient). Gratiné réservé au Bol Riz (filtré dans le wizard, pas dans la donnée).
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

  // [WEB-SYNC-CAISSE 2026-06-26 L4] Choix du pain (seeder ATTR_PAIN : Pain / Galette, gratuit).
  //   Exposé sur les Sandwichs (cat 1) — "pain ou galette au choix".
  const PAINS = [
    { id: 'pain-classique', name: 'Pain',    price: 0, is_default: true, image: ASSET_BASE + 'sandwich-classique.png' },
    { id: 'pain-galette',   name: 'Galette',  price: 0,                   image: ASSET_BASE + 'galette.png' },
  ];

  // [WEB-SYNC-CAISSE 2026-06-26 L2] Viande supplémentaire (seeder : extra +2,50€) — sandwichs,
  //   galettes, burgers, tacos, bols. Modélisée comme add chiffré (extraMeatIds dans priceFor).
  const EXTRA_MEAT_PRICE = 2.50;

  // Formule menu (seeder : Option Menu frites + boisson).
  // [GOAL-SYNC-HEAL 2026-07-08] SSOT = l'addon backend `menu_component` (PricingService),
  //   3 rôles pricés distinctement (prouvé e2e borne, orders 5580-5590) :
  //   role menu_full = +2,50 € · role menu_frites = +1,50 € · role menu_boisson = +1,00 €.
  //   Mirrors f-frites/f-boisson corrigés 2,00 → 1,50 / 1,00 (étaient FAUX).
  const FORMULES = [
    { id: 'f-menu',    name: 'Menu (Frites + Boisson)', price: 2.50, has_drink: true, has_fries: true },
    { id: 'f-frites',  name: 'Ajouter Frites',           price: 1.50, has_fries: true },
    { id: 'f-boisson', name: 'Ajouter Boisson',          price: 1.50, has_drink: true },
  ];

  const FRITES_STYLES = [
    { id: null,                name: 'Nature',                   price: 0,    is_default: true, emoji: '🍟', image: ASSET_BASE + 'frites.png' },
    { id: 'fs-cheddar',        name: 'Cheddar fondu',            price: 1.00, emoji: '🧀',                  image: ASSET_BASE + 'frites-cheddar.png' },
    { id: 'fs-cheddar-oignon', name: 'Cheddar + Oignons frits',  price: 2.00, emoji: '🧅',                  image: ASSET_BASE + 'frites-cheddar-oignons.png' },
  ];

  const BOL_BASES = [
    { id: 'bb-frites', name: 'Frites', price: 0, image: ASSET_BASE + 'frites.png' },
    { id: 'bb-riz',    name: 'Riz',    price: 0, image: ASSET_BASE + 'bol-riz.png' },
  ];

  // [WEB-WIREUP 2026-06-26] Sauces du BOL = attribut backend « Sauce bol » (attr 8) qui n'a
  // QUE 2 options (≠ les 12 sauces sandwich). Noms alignés exactement sur le backend pour que
  // la résolution par nom matche (sinon le choix client s'effondrait sur la 1ère via min_select).
  const BOL_SAUCES = [
    { id: 'bsauce-fromagere', name: 'Sauce fromagère maison', price: 0, image: ASSET_BASE + 'sauce-fromagere-maison.png' },
    { id: 'bsauce-spicy',     name: 'Sauce spicy',            price: 0, is_spicy: true, image: ASSET_BASE + 'sauce-spicy-maison.png' },
  ];

  const FORMULE_DRINKS = [
    { id: 'd-coca',      name: 'Coca-Cola 33cl',      emoji: '🥤', image: ASSET_BASE + 'coca.png' },
    { id: 'd-coca-zero', name: 'Coca-Cola Zero 33cl', emoji: '🥤', image: ASSET_BASE + 'coca-zero.png' },
    { id: 'd-fanta',     name: 'Fanta Orange 33cl',   emoji: '🍊', image: ASSET_BASE + 'fanta-orange.png' },
    { id: 'd-sprite',    name: 'Sprite 33cl',         emoji: '🍋', image: ASSET_BASE + 'sprite.png' },
    { id: 'd-oasis',     name: 'Oasis Tropical 33cl', emoji: '🌴', image: ASSET_BASE + 'oasis.png' },
    { id: 'd-orangina',  name: 'Orangina 33cl',       emoji: '🍊', image: ASSET_BASE + 'tropico.png' },
    { id: 'd-eau',       name: 'Eau Plate 50cl',      emoji: '💧', image: ASSET_BASE + 'eau.png' },
    { id: 'd-capri',     name: 'Capri-Sun',           emoji: '🧃', image: ASSET_BASE + 'capri-sun.png' },
    // [GOAL-SYNC 2026-07-08] +7 saveurs canoniques (le sélecteur formule borne = catégorie 10, 15 saveurs).
    { id: 'd-coca-cherry',   name: 'Coca Cherry 33cl',    emoji: '🍒', image: ASSET_BASE + 'coca-cherry.png' },
    { id: 'd-tropico',       name: 'Tropico 33cl',        emoji: '🍊', image: ASSET_BASE + 'tropico.png' },
    { id: 'd-ice-tea-peche', name: 'Ice Tea Pêche 33cl',  emoji: '🍑', image: ASSET_BASE + 'lipton-peche.png' },
    { id: 'd-fanta-citron',  name: 'Fanta Citron 33cl',   emoji: '🍋', image: ASSET_BASE + 'fanta-citron.png' },
    { id: 'd-fuze-tea',      name: 'Fuze Tea 33cl',       emoji: '🍵', image: ASSET_BASE + 'lipton-framboise.png' },
    { id: 'd-hawai',         name: 'Hawaï 33cl',          emoji: '🍍', image: ASSET_BASE + 'fanta-fraise.png' },
    { id: 'd-perrier',       name: 'Perrier 33cl',        emoji: '🫧', image: ASSET_BASE + 'eau.png' },
  ];

  // -------------------------------------------------------------------------
  // CATEGORIES (9 visibles — [MENU-CANON 2026-06-26])
  //   cat 3 "Sandwich Classique" + cat 8 "Suppléments" RETIRÉES (seeder : masquées).
  //   cat 1 renommée "Sandwichs" ; cat 6 renommée "Bols". Ids backend préservés.
  // -------------------------------------------------------------------------
  const CATEGORIES = [
    { id: 1,  slug: 'sandwichs',    name: 'Sandwichs',   icon: '🥖', sort: 1,  wizard_template: 'sandwich', has_menu: true,  description: 'Cayenne, Suprême, Méga, Terminator — pain ou galette au choix',  image: ASSET_BASE + 'cat-sandwich-cayenne.png' },
    { id: 2,  slug: 'galette',      name: 'Galette',     icon: '🌯', sort: 2,  wizard_template: 'sandwich', has_menu: true,  description: 'Galette traditionnelle ou Cayenne',              image: ASSET_BASE + 'cat-galette.png' },
    { id: 4,  slug: 'burgers',      name: 'Burgers',     icon: '🍔', sort: 3,  wizard_template: 'burger',   has_menu: true,  description: 'Chicken, Cheese, Double Cheese, Fish, Big, Grill', image: ASSET_BASE + 'cat-burgers.png' },
    { id: 5,  slug: 'tacos',        name: 'Tacos',       icon: '🌮', sort: 4,  wizard_template: 'tacos',    has_menu: true,  description: 'Tacos M (1 viande) ou Tacos L (2 viandes)',       image: ASSET_BASE + 'cat-tacos.png' },
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
      case 1: case 2: case 4: case 5: return ['gluten'];
      case 6: return []; case 7: return [];
      case 9: return ['gluten', 'lactose'];
      case 10: return []; case 11: return ['gluten'];
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
      emoji: opts.emoji || '',
      time: opts.time !== undefined ? opts.time : 8,
      tags: opts.tags || [],
      is_featured: !!opts.is_featured,
      is_new: !!opts.is_new,
      is_spicy: !!opts.is_spicy,
      // [GOAL-SYNC 2026-07-08] défaut false (canonique is_halal:false partout — allégation réglementaire)
      is_halal: opts.is_halal === true,
      is_vegetarian: !!opts.is_vegetarian,
      viandes: opts.viandes ?? 0,
      viande_count: opts.viandes ?? 0,
      has_sauce: opts.has_sauce !== false,
      has_crudites: !!opts.has_crudites,
      has_supplements: opts.has_supplements !== false,
      has_menu_addon: !!opts.has_menu_addon,
      has_frites_style: !!opts.has_frites_style,
      has_bol_wizard: !!opts.has_bol_wizard,
      // [WEB-SYNC-CAISSE 2026-06-26] choix pain (sandwichs), viande suppl (+2,50), gratiné (bol riz)
      has_pain_choice: !!opts.has_pain_choice,
      has_extra_meat: !!opts.has_extra_meat,
      has_gratine: !!opts.has_gratine,
      sauce_locked: opts.sauce_locked || null,
      // [GOAL-SYNC 2026-07-08] sauce pré-sélectionnée mais MODIFIABLE (ex-Cayenne sauce_locked)
      sauce_default: opts.sauce_default || null,
      bol_meat_fixed: opts.bol_meat_fixed || null,
      bol_sauce_default: opts.bol_sauce_default || null,
      wizard_template: opts.wizard_template || null,
      allergens: defaultAllergensFor(category_id, opts),
      // Web-specific badges (auto-derived from tags + is_featured)
      badge: opts.badge || (opts.tags && opts.tags.includes('SIGNATURE') ? 'SIGNATURE' : opts.tags && opts.tags.includes('NEW') ? 'NOUVEAU' : opts.is_featured ? 'TOP' : null),
    };
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
      template: 'frites', version: 1, is_published: true,
      steps: [
        { step_key: 'frites_style', label: 'Style de frites', source_type: 'item_attribute', position: 1,
          min_select: 1, max_select: 1, allow_repeat: false, addon_role: null, default_choice_id: null,
          choices: FRITES_STYLES.map(fs => ({ id: fs.id, name: fs.name, price: fs.price, image: fs.image,
            emoji: fs.emoji, is_default: !!fs.is_default })) },
      ],
    };
  }

  function priceForDrinkAddon(formuleDrinkId) {
    // [GOAL-SYNC 2026-07-08] d-capri 1,90→1,50 (FIX P0 : prix canonique Capri-Sun) ;
    //   +7 nouvelles saveurs @1,90.
    const drinkPriceMap = {
      'd-coca': 1.90, 'd-coca-zero': 1.90, 'd-fanta': 1.90, 'd-sprite': 1.90,
      'd-oasis': 1.90, 'd-orangina': 1.90, 'd-eau': 1.00, 'd-capri': 1.50,
      'd-coca-cherry': 1.90, 'd-tropico': 1.90, 'd-ice-tea-peche': 1.90,
      'd-fanta-citron': 1.90, 'd-fuze-tea': 1.90, 'd-hawai': 1.90, 'd-perrier': 1.90,
    };
    return drinkPriceMap[formuleDrinkId] !== undefined ? drinkPriceMap[formuleDrinkId] : 1.90;
  }

  // -------------------------------------------------------------------------
  // ITEMS (38 produits — [MENU-CANON 2026-06-26] 31 + [GOAL-SYNC 2026-07-08] 7 boissons)
  // -------------------------------------------------------------------------
  // SANDWICHS (cat 1) — seeder : Cayenne 7,40 / Suprême 7,00 / Méga 8,00 / Terminator 9,00
  const SANDWICHS = [
    // [GOAL-SYNC 2026-07-08] Cayenne : sauce AU CHOIX (12) avec « Sauce fromagère maison »
    //   pré-sélectionnée (ex-sauce_locked retiré — la borne offre le choix) ; is_spicy:false (canonique).
    mkItem(101, 'cayenne', 1, 'Cayenne', 7.40,
      'Poulet mariné · Cheddar · Jambon · Oignons rouges · Tomates · Salade · Sauce fromagère maison',
      { viandes: 0, has_crudites: true, has_menu_addon: true, has_sauce: true, sauce_default: 'Sauce fromagère maison',
        has_pain_choice: true, has_extra_meat: true,
        is_featured: true, tags: ['SIGNATURE'], emoji: '🌶️', is_spicy: false, time: 10, badge: 'SIGNATURE' }),
    mkItem(102, 'supreme', 1, 'Suprême', 7.00,
      'Steak haché · Cordon bleu · Cheddar · Oignons rouges · Tomates · Salade · Sauce au choix',
      { viandes: 0, has_crudites: true, has_menu_addon: true, has_sauce: true,
        has_pain_choice: true, has_extra_meat: true, time: 10, emoji: '🥖' }),
    mkItem(103, 'mega', 1, 'Méga', 8.00,
      '2 viandes au choix · Cheddar · Œuf · Oignons rouges · Tomates · Salade · Sauce au choix',
      { viandes: 2, has_crudites: true, has_menu_addon: true, has_sauce: true,
        has_pain_choice: true, has_extra_meat: true,
        is_featured: true, tags: ['XL'], time: 12, emoji: '🥖', badge: 'XL' }),
    mkItem(104, 'terminator', 1, 'Terminator', 9.00,
      '2 viandes au choix · 2 cheddars · Œuf · Jambon de dinde · Oignons rouges · Tomates · Salade · Sauce au choix',
      { viandes: 2, has_crudites: true, has_menu_addon: true, has_sauce: true,
        has_pain_choice: true, has_extra_meat: true,
        is_featured: true, tags: ['XL'], time: 12, emoji: '🥖', badge: 'XL' }),
  ];

  // GALETTE (cat 2) — conservée (gate owner) ; 7 viandes + 12 sauces (pools partagés).
  const GALETTE = [
    mkItem(201, 'galette-normale', 2, 'Galette Normale', 6.50,
      'Galette traditionnelle · 1 viande au choix · Sauce au choix · Crudités · Suppléments optionnels',
      { viandes: 1, has_crudites: true, has_menu_addon: true, has_sauce: true, has_extra_meat: true, time: 8, emoji: '🌯' }),
    mkItem(202, 'galette-cayenne', 2, 'Galette Cayenne', 7.00,
      'Galette signature · 1 viande au choix · Sauce au choix · Crudités · Suppléments optionnels',
      { viandes: 1, has_crudites: true, has_menu_addon: true, has_sauce: true, has_extra_meat: true,
        // [GOAL-SYNC 2026-07-08] is_spicy:false — aligné canonique borne (fixture faisant loi)
        is_featured: true, tags: ['SIGNATURE'], emoji: '🌶️', is_spicy: false, time: 8, badge: 'SIGNATURE' }),
  ];

  // BURGERS (cat 4) — seeder : 6 burgers, compositions fixes (PAS de choix viande).
  const BURGERS = [
    mkItem(401, 'chicken-burger', 4, 'Chicken Burger', 4.90,
      'Salade · Tomate · Oignon · Sauce',
      { viandes: 0, has_crudites: true, has_menu_addon: true, has_sauce: true, has_extra_meat: true,emoji: '🍔', time: 10 }),
    mkItem(402, 'cheese-burger', 4, 'Cheese Burger', 6.00,
      'Steak · Cheddar · Salade · Tomate · Oignon · Sauce',
      { viandes: 0, has_crudites: true, has_menu_addon: true, has_sauce: true, has_extra_meat: true,emoji: '🍔', time: 10 }),
    mkItem(403, 'double-cheese', 4, 'Double Cheese', 7.00,
      '2 steaks · 2 cheddars · Salade · Tomate · Oignon · Sauce',
      { viandes: 0, has_crudites: true, has_menu_addon: true, has_sauce: true, has_extra_meat: true,is_featured: true, emoji: '🍔', time: 11, badge: 'TOP' }),
    mkItem(404, 'fish-burger', 4, 'Fish Burger', 6.00,
      'Poisson pané · Cheddar · Salade · Tomate · Oignon · Sauce',
      { viandes: 0, has_crudites: true, has_menu_addon: true, has_sauce: true, has_extra_meat: true,emoji: '🐟', time: 10 }),
    mkItem(405, 'big-burger', 4, 'Big Burger', 9.00,
      '3 steaks · 3 cheddars · 2 jambons de dinde · Salade · Tomate · Oignon · Sauce',
      { viandes: 0, has_crudites: true, has_menu_addon: true, has_sauce: true, has_extra_meat: true,is_featured: true, tags: ['XL'], emoji: '🍔', time: 13, badge: 'XL' }),
    mkItem(406, 'grill-burger', 4, 'Grill Burger', 8.00,
      '2 steaks · 2 cheddars · Jambon de dinde · Salade · Tomate · Oignon · Sauce',
      { viandes: 0, has_crudites: true, has_menu_addon: true, has_sauce: true, has_extra_meat: true,emoji: '🍔', time: 12 }),
  ];

  // TACOS (cat 5) — seeder : Tacos M 6,90 (1 viande) / Tacos L 7,90 (2 viandes).
  //   [GOAL-SYNC 2026-07-08] AVEC crudités : revert backend 05e5cacd0 (2026-07-07) répercuté —
  //   la borne offre les 4 crudités gratuites sur Tacos M/L (fixture items 26/97, group 'crudite' ×4).
  const TACOS = [
    mkItem(501, 'tacos-m', 5, 'Tacos M', 6.90,
      'Galette de blé · 1 viande au choix · Frites maison · Sauce',
      { viandes: 1, has_crudites: true, has_menu_addon: true, has_sauce: true, has_extra_meat: true,
        is_featured: true, tags: ['SIGNATURE'], emoji: '🌮', time: 10, badge: 'SIGNATURE' }),
    mkItem(502, 'tacos-l', 5, 'Tacos L', 7.90,
      'Galette de blé · 2 viandes au choix · Frites maison · Sauce',
      { viandes: 2, has_crudites: true, has_menu_addon: true, has_sauce: true, has_extra_meat: true,
        is_featured: true, tags: ['TOP'], emoji: '🌮', time: 12, badge: 'TOP' }),
  ];

  // BOLS (cat 6) — seeder : 2 produits @ 7,90, viande au choix (parmi 7), sauce, suppléments.
  //   Bol Riz : option gratiné. Viande rendue via l'étape VIANDES du template 'tacos'.
  const BOLS = [
    mkItem(601, 'bol-frites', 6, 'Bol Frites', 7.90,
      'Frites maison · 1 viande au choix · Sauce · Suppléments optionnels',
      { viandes: 1, has_crudites: false, has_menu_addon: false, has_sauce: true, has_supplements: true,
        has_extra_meat: true, emoji: '🥣', time: 10 }),
    mkItem(602, 'bol-riz', 6, 'Bol Riz', 7.90,
      'Riz · 1 viande au choix · Sauce · Suppléments optionnels · Option gratiné',
      { viandes: 1, has_crudites: false, has_menu_addon: false, has_sauce: true, has_supplements: true,
        has_extra_meat: true, has_gratine: true, emoji: '🥣', time: 10 }),
  ];

  // FRITES (cat 7) — Petite/Grande, style au choix.
  const FRITES = [
    mkItem(701, 'petite-frites', 7, 'Petite Frites', 2.50,
      'Portion petite · Style au choix (Nature / Cheddar fondu +1€ / Cheddar+Oignons +2€)',
      { has_sauce: false, has_crudites: false, has_supplements: false, has_menu_addon: false, has_frites_style: true, time: 4, emoji: '🍟', is_vegetarian: true }),
    mkItem(702, 'grande-frites', 7, 'Grande Frites', 4.00,
      'Portion grande · Style au choix (Nature / Cheddar fondu +1€ / Cheddar+Oignons +2€)',
      { has_sauce: false, has_crudites: false, has_supplements: false, has_menu_addon: false, has_frites_style: true, time: 5, emoji: '🍟', is_vegetarian: true }),
  ];

  // DESSERTS (cat 9) — seeder : 3,50€. [GOAL-SYNC 2026-07-08] is_vegetarian:true ×3 (canonique).
  const DESSERTS = [
    mkItem(901, 'glace',      9, 'Glace',      3.50, 'Glace artisanale', { has_sauce: false, has_crudites: false, has_supplements: false, has_menu_addon: false, time: 0, emoji: '🍦', allergens: ['lactose'], is_vegetarian: true }),
    mkItem(902, 'tarte-daim', 9, 'Tarte Daim', 3.50, 'Tarte au Daim',    { has_sauce: false, has_crudites: false, has_supplements: false, has_menu_addon: false, time: 0, emoji: '🍰', allergens: ['gluten', 'lactose'], is_vegetarian: true }),
    mkItem(903, 'tiramisu',   9, 'Tiramisu',   3.50, 'Tiramisu maison',  { has_sauce: false, has_crudites: false, has_supplements: false, has_menu_addon: false, time: 0, emoji: '🍰', allergens: ['gluten', 'lactose', 'oeuf'], is_vegetarian: true }),
  ];

  // BOISSONS (cat 10) — seeder : canettes 1,90€ ; Eau 1,00€ (Capri-Sun inchangé).
  const DRINKS = [
    mkItem(1001, 'coca',        10, 'Coca-Cola 33cl',      1.90, 'Coca-Cola original',   { has_sauce: false, has_crudites: false, has_supplements: false, has_menu_addon: false, time: 0, emoji: '🥤' }),
    mkItem(1002, 'coca-zero',   10, 'Coca-Cola Zero 33cl', 1.90, 'Coca-Cola sans sucre', { has_sauce: false, has_crudites: false, has_supplements: false, has_menu_addon: false, time: 0, emoji: '🥤' }),
    mkItem(1003, 'fanta',       10, 'Fanta Orange 33cl',   1.90, 'Fanta Orange',         { has_sauce: false, has_crudites: false, has_supplements: false, has_menu_addon: false, time: 0, emoji: '🍊' }),
    mkItem(1004, 'sprite',      10, 'Sprite 33cl',         1.90, 'Sprite',               { has_sauce: false, has_crudites: false, has_supplements: false, has_menu_addon: false, time: 0, emoji: '🍋' }),
    mkItem(1005, 'oasis',       10, 'Oasis Tropical 33cl', 1.90, 'Oasis Tropical',       { has_sauce: false, has_crudites: false, has_supplements: false, has_menu_addon: false, time: 0, emoji: '🌴' }),
    mkItem(1006, 'orangina',    10, 'Orangina 33cl',       1.90, 'Orangina',             { has_sauce: false, has_crudites: false, has_supplements: false, has_menu_addon: false, time: 0, emoji: '🍊' }),
    mkItem(1007, 'eau-plate',   10, 'Eau Plate 50cl',      1.00, 'Eau minérale',         { has_sauce: false, has_crudites: false, has_supplements: false, has_menu_addon: false, time: 0, emoji: '💧' }),
    mkItem(1008, 'capri-sun',   10, 'Capri-Sun',           1.50, 'Capri-Sun 20cl',       { has_sauce: false, has_crudites: false, has_supplements: false, has_menu_addon: false, time: 0, emoji: '🧃' }),
    // [GOAL-SYNC 2026-07-08] +7 boissons canoniques (fixture ids 119-125, toutes 1,90€,
    //   descriptions canoniques exactes — résolution API par nom).
    mkItem(1009, 'coca-cherry',   10, 'Coca Cherry 33cl',    1.90, 'Coca-Cola Cherry',     { has_sauce: false, has_crudites: false, has_supplements: false, has_menu_addon: false, time: 0, emoji: '🍒' }),
    mkItem(1010, 'tropico',       10, 'Tropico 33cl',        1.90, 'Tropico',              { has_sauce: false, has_crudites: false, has_supplements: false, has_menu_addon: false, time: 0, emoji: '🍊' }),
    mkItem(1011, 'ice-tea-peche', 10, 'Ice Tea Pêche 33cl',  1.90, 'Ice Tea saveur pêche', { has_sauce: false, has_crudites: false, has_supplements: false, has_menu_addon: false, time: 0, emoji: '🍑' }),
    mkItem(1012, 'fanta-citron',  10, 'Fanta Citron 33cl',   1.90, 'Fanta Citron',         { has_sauce: false, has_crudites: false, has_supplements: false, has_menu_addon: false, time: 0, emoji: '🍋' }),
    mkItem(1013, 'fuze-tea',      10, 'Fuze Tea 33cl',       1.90, 'Fuze Tea',             { has_sauce: false, has_crudites: false, has_supplements: false, has_menu_addon: false, time: 0, emoji: '🍵' }),
    mkItem(1014, 'hawai',         10, 'Hawaï 33cl',          1.90, 'Hawaï',                { has_sauce: false, has_crudites: false, has_supplements: false, has_menu_addon: false, time: 0, emoji: '🍍' }),
    mkItem(1015, 'perrier',       10, 'Perrier 33cl',        1.90, 'Perrier (eau gazeuse)',{ has_sauce: false, has_crudites: false, has_supplements: false, has_menu_addon: false, time: 0, emoji: '🫧' }),
  ];

  // MENU ENFANT (cat 11) — seeder : 2 SKU @ 4,90€ (Nuggets / Burger), frites + Capri-Sun inclus.
  const MENU_ENFANT = [
    mkItem(1101, 'menu-enfant-nuggets', 11, 'Menu Enfant Nuggets', 4.90,
      '6 nuggets · Frites · Capri-Sun',
      { has_sauce: false, has_crudites: false, has_supplements: false, has_menu_addon: false, time: 8, emoji: '🧒', tags: ['ENFANT'] }),
    // [GOAL-SYNC 2026-07-08] renommé « Menu Enfant Burger » → nom canonique EXACT
    //   « Menu Enfant Chicken Burger » (la résolution API se fait PAR NOM).
    mkItem(1102, 'menu-enfant-burger', 11, 'Menu Enfant Chicken Burger', 4.90,
      'Burger · Frites · Capri-Sun',
      { has_sauce: false, has_crudites: false, has_supplements: false, has_menu_addon: false, time: 8, emoji: '🧒', tags: ['ENFANT'] }),
  ];

  const ITEMS = [
    ...SANDWICHS, ...GALETTE, ...BURGERS, ...TACOS,
    ...BOLS, ...FRITES, ...DESSERTS, ...DRINKS, ...MENU_ENFANT,
  ];

  // -------------------------------------------------------------------------
  // PRICE CALCULATOR (mirror mobile)
  // -------------------------------------------------------------------------
  function priceFor(item, opts) {
    opts = opts || {};
    let total = item.price;
    // [GOAL-SYNC 2026-07-08] tarification multi-sauces +0,50€ SUPPRIMÉE sur les templates
    //   sandwich/tacos/burger : canonique = 1 sauce max (min1/max1), le wizard applique max 1.
    //   La cascade fritesSauceIds (bols/frites) plus bas reste inchangée.
    (opts.supplementIds || []).forEach(id => { const s = SUPPLEMENTS.find(x => x.id === id); if (s) total += s.price; });
    (opts.bolSupplementIds || []).forEach(id => { const s = SUPPLEMENTS_BOLS.find(x => x.id === id); if (s) total += s.price; });
    // [WEB-SYNC-CAISSE 2026-06-26 L2] viande supplémentaire +2,50€ chacune
    if (Array.isArray(opts.extraMeatIds)) total += opts.extraMeatIds.length * EXTRA_MEAT_PRICE;
    if (opts.bolDrinkId) total += priceForDrinkAddon(opts.bolDrinkId);
    if (opts.formuleId) { const f = FORMULES.find(x => x.id === opts.formuleId); if (f) total += f.price; }
    if (opts.fritesStyleId) { const fs = FRITES_STYLES.find(x => x.id === opts.fritesStyleId); if (fs) total += fs.price; }
    if (Array.isArray(opts.fritesSauceIds) && opts.fritesSauceIds.length > 1) total += (opts.fritesSauceIds.length - 1) * 0.50;
    return total * (opts.qty || 1);
  }

  function defaultCruditeIds() { return CRUDITES.filter(c => c.default).map(c => c.id); }

  // -------------------------------------------------------------------------
  // PEPPER CLUB (loyalty) — owner-gate D1+D2 defaults applied
  // -------------------------------------------------------------------------
  const PEPPER_CLUB = {
    earn_ratio: 1,   // D1 default: 1€ = 1pt (web canonical, mobile cycle may differ)
    welcome_bonus: 25,
    tiers: [
      { id: 'novice',  name: 'Novice',  threshold: 0,    perks: ['Programme gratuit', '+25 pts inscription'] },
      { id: 'pepper',  name: 'Pepper',  threshold: 500,  perks: ['Sauce maison offerte', '1 dessert/mois'] },
      { id: 'master',  name: 'Master',  threshold: 1500, perks: ['Frites gratuites', '10% remise permanente'] },
      { id: 'legende', name: 'Légende', threshold: 5000, perks: ['Terminator XL -50%', 'Invitations VIP', 'Plat dédié signature'] },
    ],
  };

  // -------------------------------------------------------------------------
  // EXPORT — window.LC.menu (mirror mobile)
  // -------------------------------------------------------------------------
  window.LC = window.LC || {};
  window.LC.menu = {
    brand: BRAND,
    categories: CATEGORIES,
    items: ITEMS,
    meats: MEATS,
    sauces: SAUCES,
    crudites: CRUDITES,
    supplements: SUPPLEMENTS,
    supplementsBols: SUPPLEMENTS_BOLS,
    bolSauces: BOL_SAUCES,
    pains: PAINS,
    extraMeatPrice: EXTRA_MEAT_PRICE,
    bolBases: BOL_BASES,
    formules: FORMULES,
    fritesStyles: FRITES_STYLES,
    formuleDrinks: FORMULE_DRINKS,
    pepperClub: PEPPER_CLUB,
    findItem(idOrSlug) { return ITEMS.find(i => i.id === idOrSlug || i.slug === idOrSlug); },
    findCategory(idOrSlug) { return CATEGORIES.find(c => c.id === idOrSlug || c.slug === idOrSlug); },
    itemsForCategory(categoryId) { return ITEMS.filter(i => i.category_id === categoryId); },
    priceFor, priceForDrinkAddon, defaultCruditeIds,
    buildFritesComposerProfile, imgFor, heroFor,
  };

  // Backwards-compat globals for existing web/screens.jsx code paths
  // W_CATS shape: { id (slug-string), label, icon } for menu chip filters
  window.W_CATS = [
    { id: 'all', label: 'Tout', icon: '✦' },
    ...CATEGORIES.map(c => ({ id: c.slug, label: c.name, icon: c.icon, sort: c.sort, backendId: c.id, wizard_template: c.wizard_template, has_menu: c.has_menu, image: c.image })),
  ];
  // W_ITEMS shape: legacy expects { id, cat, name, emoji, price, time, desc, badge, wizard, tags }
  window.W_ITEMS = ITEMS.map(it => {
    const cat = CATEGORIES.find(c => c.id === it.category_id);
    return {
      ...it,
      cat: cat ? cat.slug : 'other',
      desc: it.description,
      wizard: !!(it.viandes || it.has_sauce || it.has_crudites || it.has_supplements || it.has_menu_addon || it.has_frites_style || it.has_bol_wizard),
    };
  });
  window.W_DIET = [
    { id: 'spicy',  label: '🌶 Épicé' },
    // [E2E-AUDIT 2026-07-04] chip 'Nouveau' retiré : aucun produit n'est taggé NEW → le filtre renvoyait
    // toujours 0 résultat (dead filter). À réintroduire quand un item portera un badge/tag « Nouveau ».
    { id: 'top',    label: '⭐ Top' },
    { id: 'veggie', label: '🥬 Veggie' },
  ];
})();
