// Données menu pour la borne — Grill House (fast-food burger)
// Couleurs de placeholder mappées par catégorie pour produire des "cards produit"
// crédibles sans vraies images.

const CATEGORIES = [
  { id: 'menus',      name: 'Menus',        emoji: '🍔', tagline: 'Best-sellers',      hue: 28 },
  { id: 'burgers',    name: 'Burgers',      emoji: '🥩', tagline: 'Viande fraîche',    hue: 14 },
  { id: 'chicken',    name: 'Poulet',       emoji: '🍗', tagline: 'Tenders & wings',   hue: 42 },
  { id: 'veggie',     name: 'Veggie',       emoji: '🥬', tagline: '100% végétal',      hue: 120 },
  { id: 'sides',      name: 'Accompagn.',   emoji: '🍟', tagline: 'Frites & co',        hue: 48 },
  { id: 'sauces',     name: 'Sauces',       emoji: '🥫', tagline: 'Maison',             hue: 8  },
  { id: 'desserts',   name: 'Desserts',     emoji: '🍰', tagline: 'Sucré',              hue: 340 },
  { id: 'drinks',     name: 'Boissons',     emoji: '🥤', tagline: 'Fraîches',           hue: 200 },
];

const ITEMS = {
  menus: [
    { id: 'm-classic',  name: 'Menu Classic',    price: 12.90, desc: 'Cheeseburger · Frites · Boisson', tag: 'POPULAIRE', hue: 24 },
    { id: 'm-double',   name: 'Menu Double XL',  price: 15.90, desc: 'Double Beef · Frites XL · Boisson', tag: 'NOUVEAU',  hue: 14 },
    { id: 'm-bbq',      name: 'Menu BBQ Smoky',  price: 14.50, desc: 'BBQ Bacon · Potatoes · Boisson',  hue: 32 },
    { id: 'm-chick',    name: 'Menu Chicken',    price: 13.50, desc: 'Chicken Crispy · Frites · Boisson', hue: 46 },
    { id: 'm-veggie',   name: 'Menu Garden',     price: 13.90, desc: 'Veggie Burger · Frites · Boisson',  hue: 118 },
    { id: 'm-kids',     name: 'Menu Kids',       price: 8.90,  desc: 'Mini burger · Frites · Jus',       hue: 50 },
  ],
  burgers: [
    { id: 'b-cheese',   name: 'Cheeseburger',    price: 7.90,  desc: 'Bœuf 150g · Cheddar · Salade',      hue: 22 },
    { id: 'b-double',   name: 'Double Beef',     price: 10.50, desc: '2x150g · Cheddar · Oignons',         hue: 14, tag: 'XL' },
    { id: 'b-bbq',      name: 'BBQ Bacon',       price: 9.90,  desc: 'Bacon · Sauce BBQ · Cheddar',         hue: 28 },
    { id: 'b-smoky',    name: 'Smoky Stack',     price: 11.50, desc: 'Bacon · Oignons frits · BBQ',         hue: 18 },
    { id: 'b-spicy',    name: 'Spicy Fire',      price: 10.50, desc: 'Jalapeños · Sauce feu',               hue: 6,  tag: '🌶' },
    { id: 'b-mush',     name: 'Truffle Mush',    price: 12.50, desc: 'Champignons · Truffe · Emmental',    hue: 32 },
  ],
  chicken: [
    { id: 'c-crispy',   name: 'Chicken Crispy',  price: 8.50,  desc: 'Poulet pané · Sauce ranch',          hue: 42 },
    { id: 'c-tenders',  name: 'Tenders x5',      price: 7.90,  desc: '5 tenders · Sauce au choix',         hue: 44 },
    { id: 'c-wings',    name: 'Hot Wings x6',    price: 8.90,  desc: '6 wings épicés',                     hue: 12 },
    { id: 'c-nugg',     name: 'Nuggets x9',      price: 6.90,  desc: '9 nuggets dorés',                    hue: 46 },
  ],
  veggie: [
    { id: 'v-garden',   name: 'Garden Burger',   price: 9.50,  desc: 'Steak légumes · Avocat',              hue: 118 },
    { id: 'v-falafel',  name: 'Falafel Wrap',    price: 8.90,  desc: 'Falafel · Tzatziki · Crudités',       hue: 90  },
    { id: 'v-halloumi', name: 'Halloumi Stack',  price: 10.50, desc: 'Halloumi grillé · Pesto',             hue: 60  },
  ],
  sides: [
    { id: 's-fries',    name: 'Frites',          price: 3.50,  desc: 'Moyennes · Sel fin',                  hue: 48 },
    { id: 's-fries-xl', name: 'Frites XL',       price: 4.50,  desc: 'Grandes · Sel fin',                    hue: 48 },
    { id: 's-potato',   name: 'Potatoes',        price: 4.20,  desc: 'Pommes de terre épicées',              hue: 28 },
    { id: 's-onion',    name: 'Onion Rings',     price: 4.50,  desc: '8 oignons frits',                      hue: 34 },
    { id: 's-salad',    name: 'Salade César',    price: 5.90,  desc: 'Poulet · Parmesan',                    hue: 90 },
  ],
  sauces: [
    { id: 'sa-ketchup', name: 'Ketchup',         price: 0.50, hue: 8 },
    { id: 'sa-mayo',    name: 'Mayo',            price: 0.50, hue: 50 },
    { id: 'sa-bbq',     name: 'BBQ',             price: 0.60, hue: 28 },
    { id: 'sa-ranch',   name: 'Ranch',           price: 0.60, hue: 55 },
    { id: 'sa-spicy',   name: 'Sauce Feu',       price: 0.70, hue: 4  },
    { id: 'sa-curry',   name: 'Curry doux',      price: 0.60, hue: 42 },
  ],
  desserts: [
    { id: 'd-brownie',  name: 'Brownie',         price: 3.90, desc: 'Chaud · Pépites',                      hue: 22 },
    { id: 'd-cookie',   name: 'Cookie',          price: 2.90, desc: 'Pépites chocolat',                     hue: 36 },
    { id: 'd-sundae',   name: 'Sundae Caramel',  price: 3.50, desc: 'Glace · Caramel beurre salé',         hue: 40 },
    { id: 'd-donut',    name: 'Donut Rose',      price: 2.90, desc: 'Glaçage framboise',                   hue: 340 },
    { id: 'd-tiram',    name: 'Tiramisu',        price: 4.50, desc: 'Maison',                               hue: 30 },
    { id: 'd-cheese',   name: 'Cheesecake',      price: 4.90, desc: 'Fruits rouges',                        hue: 350 },
  ],
  drinks: [
    { id: 'dr-coke',    name: 'Coca-Cola',       price: 2.90, desc: '33cl',                                 hue: 4 },
    { id: 'dr-coke-z',  name: 'Coca Zero',       price: 2.90, desc: '33cl',                                 hue: 220 },
    { id: 'dr-sprite',  name: 'Sprite',          price: 2.90, desc: '33cl',                                 hue: 120 },
    { id: 'dr-ice',     name: 'Ice Tea Pêche',   price: 2.90, desc: '33cl',                                 hue: 36 },
    { id: 'dr-water',   name: 'Eau plate',       price: 1.90, desc: '50cl',                                 hue: 200 },
    { id: 'dr-beer',    name: 'Bière 1664',      price: 4.50, desc: '25cl · 5°',                            hue: 42 },
  ],
};

// Options de wizard (suppléments) pour un burger type
const BURGER_WIZARD_STEPS = [
  {
    id: 'size',
    title: 'Choisissez votre taille',
    required: true,
    single: true,
    options: [
      { id: 'reg', name: 'Simple',  price: 0,    desc: '1 steak 150g' },
      { id: 'dbl', name: 'Double',  price: 2.50, desc: '2 steaks 150g', recommended: true },
      { id: 'tri', name: 'Triple',  price: 4.50, desc: '3 steaks 150g' },
    ],
  },
  {
    id: 'cuisson',
    title: 'Cuisson du steak',
    required: true,
    single: true,
    options: [
      { id: 'saign',  name: 'Saignant',    price: 0, desc: 'Rosé à cœur' },
      { id: 'apoint', name: 'À point',     price: 0, desc: 'Rosé chaud',  recommended: true },
      { id: 'bien',   name: 'Bien cuit',   price: 0, desc: 'Aucune rosé' },
    ],
  },
  {
    id: 'extras',
    title: 'Suppléments',
    required: false,
    single: false,
    options: [
      { id: 'bacon',    name: 'Bacon',          price: 1.50 },
      { id: 'cheese',   name: 'Cheddar extra',  price: 1.00 },
      { id: 'avocado',  name: 'Avocat',         price: 1.80 },
      { id: 'egg',      name: 'Œuf',            price: 1.20 },
      { id: 'onion',    name: 'Oignons frits',  price: 0.80 },
      { id: 'jalap',    name: 'Jalapeños',      price: 0.80 },
      { id: 'mush',     name: 'Champignons',    price: 1.20 },
      { id: 'truff',    name: 'Truffe',         price: 2.50 },
    ],
  },
  {
    id: 'sauce',
    title: 'Sauce incluse',
    required: true,
    single: true,
    options: [
      { id: 'ketchup', name: 'Ketchup',    price: 0 },
      { id: 'mayo',    name: 'Mayo',       price: 0 },
      { id: 'bbq',     name: 'BBQ',        price: 0, recommended: true },
      { id: 'ranch',   name: 'Ranch',      price: 0 },
      { id: 'spicy',   name: 'Sauce Feu',  price: 0 },
    ],
  },
];

// Helpers
const fmt = (n) => n.toFixed(2).replace('.', ',') + ' €';

window.KIOSK_DATA = { CATEGORIES, ITEMS, BURGER_WIZARD_STEPS, fmt };
