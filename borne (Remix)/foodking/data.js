/* ==========================================================================
   FoodKing Kiosk — Données de démo (alignées sur le brief DESIGN_BRIEF_KIOSK_2026)
   ========================================================================== */

// ---- Catégories (ordre du brief §9) --------------------------------------
const CATEGORIES = [
  { id: "tacos",     name: "Tacos",        emoji: "🌯", count: 4 },
  { id: "burgers",   name: "Burgers",      emoji: "🍔", count: 5 },
  { id: "sandwichs", name: "Sandwichs",    emoji: "🥖", count: 4 },
  { id: "assiettes", name: "Assiettes",    emoji: "🍛", count: 3 },
  { id: "snacking",  name: "Snacking",     emoji: "🍗", count: 4 },
  { id: "salades",   name: "Salades",      emoji: "🥗", count: 2 },
  { id: "omelettes", name: "Omelettes",    emoji: "🍳", count: 2 },
  { id: "boissons",  name: "Boissons",     emoji: "🥤", count: 6 },
  { id: "desserts",  name: "Desserts",     emoji: "🍰", count: 4 },
  { id: "enfant",    name: "Menu Enfant",  emoji: "🧒", count: 2 },
];

// ---- Produits (avec templates pour router le wizard) ---------------------
// template ∈ tacos | sandwich | burger | assiette | snacking | omelette | salade | simple
const PRODUCTS = {
  tacos: [
    { id: "tacos-m",  name: "Tacos M",   desc: "1 viande au choix, sauce, salade",       price: 6.90, emoji: "🌯", template: "tacos", size: "M",   meats: 1, badge: null },
    { id: "tacos-l",  name: "Tacos L",   desc: "2 viandes au choix, sauce, salade",      price: 8.90, emoji: "🌯", template: "tacos", size: "L",   meats: 2, badge: "Populaire" },
    { id: "tacos-xl", name: "Tacos XL",  desc: "3 viandes, double fromage fondu",        price: 10.90, emoji: "🌯", template: "tacos", size: "XL",  meats: 3, badge: null },
    { id: "tacos-xxl",name: "Tacos XXL", desc: "4 viandes, pour les gros appétits",      price: 12.90, emoji: "🌯", template: "tacos", size: "XXL", meats: 4, badge: "XXL" },
  ],
  burgers: [
    { id: "b-cheese",  name: "Cheese Burger",  desc: "Steak haché, cheddar fondu, cornichons", price: 6.50, emoji: "🍔", template: "burger", meats: 1 },
    { id: "b-bbq",     name: "BBQ Bacon",      desc: "Double steak, bacon, sauce BBQ",         price: 8.90, emoji: "🍔", template: "burger", meats: 2, badge: "Populaire" },
    { id: "b-crispy",  name: "Crispy Chicken", desc: "Poulet pané croustillant, mayo curry",   price: 7.50, emoji: "🍔", template: "burger", meats: 1 },
    { id: "b-big",     name: "Big King",       desc: "Triple viande, triple cheddar",          price: 10.90, emoji: "🍔", template: "burger", meats: 3, badge: "Nouveau" },
    { id: "b-veggie",  name: "Veggie Burger",  desc: "Galette de légumes, fromage, salade",    price: 6.90, emoji: "🍔", template: "burger", meats: 1 },
  ],
  sandwichs: [
    { id: "s-poulet",  name: "Sandwich Poulet",    desc: "Pain baguette, émincé de poulet",  price: 5.90, emoji: "🥖", template: "sandwich", meats: 1 },
    { id: "s-kebab",   name: "Sandwich Kebab",     desc: "Veau grillé à la broche",          price: 6.50, emoji: "🥙", template: "sandwich", meats: 1, badge: "Populaire" },
    { id: "s-mixte",   name: "Sandwich Mixte",     desc: "Poulet + viande, crudités",        price: 7.50, emoji: "🥪", template: "sandwich", meats: 2 },
    { id: "s-panini",  name: "Panini Jambon-Fromage", desc: "Grillé au four",                price: 5.50, emoji: "🫓", template: "sandwich", meats: 1, unavailable: true },
  ],
  assiettes: [
    { id: "a-kebab",  name: "Assiette Kebab",      desc: "Viande, frites, salade, sauce",    price: 11.90, emoji: "🍛", template: "assiette", meats: 1 },
    { id: "a-mixed",  name: "Assiette Mixed Grill",desc: "Poulet + kefta + merguez",         price: 13.90, emoji: "🍖", template: "assiette", meats: 3, badge: "Nouveau" },
    { id: "a-poulet", name: "Assiette Poulet",     desc: "Émincé de poulet, riz, légumes",   price: 11.50, emoji: "🍗", template: "assiette", meats: 1 },
  ],
  snacking: [
    { id: "n-tenders",  name: "Tenders × 4",   desc: "Lanières de poulet pané",       price: 5.50, emoji: "🍗", template: "snacking" },
    { id: "n-nuggets",  name: "Nuggets × 6",   desc: "Bouchées de poulet panées",     price: 5.90, emoji: "🍗", template: "snacking" },
    { id: "n-wings",    name: "Wings × 10",    desc: "Ailes de poulet épicées",       price: 7.90, emoji: "🍗", template: "snacking", badge: "Épicé" },
    { id: "n-mozza",    name: "Mozza Sticks × 6", desc: "Bâtonnets de mozzarella",    price: 5.50, emoji: "🧀", template: "snacking" },
  ],
  salades: [
    { id: "sa-cesar",   name: "Salade César",   desc: "Poulet grillé, parmesan, croûtons", price: 8.90, emoji: "🥗", template: "salade" },
    { id: "sa-chevre",  name: "Salade Chèvre",  desc: "Chèvre chaud, noix, miel",          price: 8.50, emoji: "🥗", template: "salade" },
  ],
  omelettes: [
    { id: "o-cheese",   name: "Omelette Cheese", desc: "3 œufs, cheddar fondant",     price: 7.50, emoji: "🍳", template: "omelette" },
    { id: "o-campagne", name: "Omelette Campagne", desc: "Lardons, oignons, pommes de terre", price: 8.50, emoji: "🍳", template: "omelette" },
  ],
  boissons: [
    { id: "bo-coca",      name: "Coca-Cola 33cl",     desc: "", price: 2.50, emoji: "🥤", template: "simple" },
    { id: "bo-cocazero",  name: "Coca Zero 33cl",     desc: "", price: 2.50, emoji: "🥤", template: "simple" },
    { id: "bo-fanta",     name: "Fanta Orange 33cl",  desc: "", price: 2.50, emoji: "🥤", template: "simple" },
    { id: "bo-oasis",     name: "Oasis 33cl",         desc: "", price: 2.50, emoji: "🧃", template: "simple" },
    { id: "bo-icetea",    name: "Ice Tea 33cl",       desc: "", price: 2.50, emoji: "🧋", template: "simple" },
    { id: "bo-eau",       name: "Eau Cristaline 50cl",desc: "", price: 1.50, emoji: "💧", template: "simple" },
  ],
  desserts: [
    { id: "d-cookie",    name: "Cookie",       desc: "Pépites de chocolat",    price: 2.50, emoji: "🍪", template: "simple" },
    { id: "d-brownie",   name: "Brownie",      desc: "Fondant au chocolat",    price: 3.50, emoji: "🍫", template: "simple" },
    { id: "d-tiramisu",  name: "Tiramisu",     desc: "Recette italienne",      price: 3.90, emoji: "🍰", template: "simple" },
    { id: "d-donut",     name: "Donut Glaçage",desc: "Glaçage chocolat",       price: 2.90, emoji: "🍩", template: "simple" },
  ],
  enfant: [
    { id: "e-happy",   name: "Menu Happy Kids",  desc: "Nuggets + frites + compote + jouet", price: 6.50, emoji: "🧒", template: "simple", badge: "Jouet" },
    { id: "e-mini",    name: "Mini Burger Menu", desc: "Mini burger + frites + jus",         price: 5.90, emoji: "🍔", template: "simple" },
  ],
};

// ---- Wizard: options par étape -------------------------------------------

const SIZES = [
  { id: "M",   name: "Taille M",   desc: "1 viande",  delta: 0,    emoji: "🌯" },
  { id: "L",   name: "Taille L",   desc: "2 viandes", delta: 2.00, emoji: "🌯" },
  { id: "XL",  name: "Taille XL",  desc: "3 viandes", delta: 4.00, emoji: "🌯" },
  { id: "XXL", name: "Taille XXL", desc: "4 viandes", delta: 6.00, emoji: "🌯" },
];

const PAINS = [
  { id: "galette",   name: "Galette",    desc: "Pain tortilla",   emoji: "🫓" },
  { id: "baguette",  name: "Baguette",   desc: "Pain français",   emoji: "🥖" },
  { id: "buns",      name: "Pain Burger",desc: "Pain brioché",    emoji: "🍞" },
  { id: "faluche",   name: "Faluche",    desc: "Pain du Nord",    emoji: "🥐" },
];

const VIANDES = [
  { id: "poulet",     name: "Poulet",       emoji: "🍗" },
  { id: "boeuf",      name: "Bœuf",         emoji: "🥩" },
  { id: "cordon",     name: "Cordon Bleu",  emoji: "🍖" },
  { id: "escalope",   name: "Escalope",     emoji: "🥩" },
  { id: "merguez",    name: "Merguez",      emoji: "🌭" },
  { id: "nuggets",    name: "Nuggets",      emoji: "🍗" },
  { id: "tenders",    name: "Tenders",      emoji: "🍗" },
  { id: "kefta",      name: "Kefta",        emoji: "🥩" },
];

// 17 sauces — aligné sur pos-wizard.js ALL_SAUCES (réel backend Grill House)
const SAUCES = [
  { id: "ketchup",    name: "Ketchup" },
  { id: "mayo",       name: "Mayonnaise" },
  { id: "algerienne", name: "Algérienne" },
  { id: "curry",      name: "Curry" },
  { id: "andalouse",  name: "Andalouse" },
  { id: "burger",     name: "Burger" },
  { id: "samourai",   name: "Samouraï" },
  { id: "bbq",        name: "BBQ" },
  { id: "cocktail",   name: "Cocktail" },
  { id: "americaine", name: "Américaine" },
  { id: "hannibal",   name: "Hannibal" },
  { id: "harissa",    name: "Harissa" },
  { id: "blanche",    name: "Blanche" },
  { id: "poivre",     name: "Poivre" },
  { id: "biggy",      name: "Biggy" },
  { id: "fromagere",  name: "Fromagère" },
  { id: "tartare",    name: "Tartare" },
];
// Aligné BUSINESS_RULES : 1ère gratuite, suivantes +0.50 €
const SAUCE_EXTRA_PRICE = 0.50;

const GARNITURES = [
  { id: "salade",    name: "Salade",    emoji: "🥬" },
  { id: "tomate",    name: "Tomate",    emoji: "🍅" },
  { id: "oignon",    name: "Oignon",    emoji: "🧅" },
  { id: "cornichon", name: "Cornichons",emoji: "🥒" },
  { id: "olive",     name: "Olives",    emoji: "🫒" },
  { id: "mais",      name: "Maïs",      emoji: "🌽" },
];

// Suppléments — prix alignés sur GrillHouseMenuSeeder.php
// NB: les suppléments "viande" (Poulet, Kebab, Viande hachée) sont gérés séparément
// via une popup — voir SUPPLEMENT_MEAT_OPTIONS ci-dessous
const SUPPLEMENTS = [
  { id: "cheddar",     name: "Cheddar",         price: 1.00, emoji: "🧀" },
  { id: "jambon",      name: "Jambon",          price: 1.00, emoji: "🥓" },
  { id: "oeuf",        name: "Œuf",             price: 1.00, emoji: "🥚" },
  { id: "raclette",    name: "Raclette",        price: 1.00, emoji: "🧀" },
  { id: "boursin",     name: "Boursin",         price: 1.00, emoji: "🧀" },
  { id: "chevre",      name: "Chèvre",          price: 1.00, emoji: "🧀" },
];

// Suppléments de type "Viande" — ouvrent une popup de sélection
const SUPPLEMENT_MEAT_OPTIONS = [
  { id: "poulet",       name: "Poulet",        price: 2.00, emoji: "🍗" },
  { id: "kebab",        name: "Kebab",         price: 2.00, emoji: "🥩" },
  { id: "viandehachee", name: "Viande hachée", price: 2.00, emoji: "🥩" },
];

// Menu (formule)
// Deltas alignés sur WIZARD_LOGIC_DOCUMENTATION §Étape 5
const MENU_CHOICES = [
  { id: "complet",  name: "En Menu",       desc: "Frites + Boisson",   emoji: "🍔🍟🥤", delta: 3.00 },
  { id: "frites",   name: "Frites seules", desc: "Accompagnement",     emoji: "🍟",     delta: 1.50 },
  { id: "boisson",  name: "Boisson seule", desc: "Seulement la boisson", emoji: "🥤",   delta: 1.50 },
  { id: "aucun",    name: "Aucun menu",    desc: "Produit seul",       emoji: "❌",    delta: 0 },
];

const FRITES_UPGRADE = [
  { id: "standard", name: "Frites standard",      desc: "Inclus",           delta: 0,    emoji: "🍟" },
  { id: "cheddar",  name: "Frites + Cheddar",     desc: "Sauce cheddar",    delta: 1.00, emoji: "🍟", badge: "+1 €" },
  { id: "crispy",   name: "Cheddar + Oignons",    desc: "Cheddar & crispy", delta: 2.00, emoji: "🍟", badge: "+2 €" },
];

const BOISSONS_MENU = [
  { id: "coca",      name: "Coca-Cola",     emoji: "🥤" },
  { id: "cocazero",  name: "Coca Zero",     emoji: "🥤" },
  { id: "fanta",     name: "Fanta Orange",  emoji: "🥤" },
  { id: "oasis",     name: "Oasis",         emoji: "🧃" },
  { id: "icetea",    name: "Ice Tea",       emoji: "🧋" },
  { id: "sprite",    name: "Sprite",        emoji: "🥤" },
  { id: "eau",       name: "Eau Plate",     emoji: "💧" },
  { id: "eauGaz",    name: "Eau Gazeuse",   emoji: "💧" },
];

// Produits d'upsell (affichés juste avant paiement)
const UPSELL_ITEMS = [
  { id: "up-frites", name: "Frites",    price: 2.80, emoji: "🍟" },
  { id: "up-coca",   name: "Coca 33cl", price: 2.50, emoji: "🥤" },
  { id: "up-cookie", name: "Cookie",    price: 2.50, emoji: "🍪" },
  { id: "up-wings",  name: "Wings × 6", price: 5.50, emoji: "🍗" },
  { id: "up-brownie",name: "Brownie",   price: 3.50, emoji: "🍫" },
  { id: "up-icetea", name: "Ice Tea",   price: 2.50, emoji: "🧋" },
];

// ---- Templates: ordre des étapes par type de produit ---------------------
// D'après le brief §7
const TEMPLATE_STEPS = {
  tacos:    ["size", "meat", "sauce", "garniture", "supplement", "menu", "recap"],
  sandwich: ["pain", "meat", "sauce", "garniture", "supplement", "menu", "recap"],
  burger:   ["meat", "sauce", "garniture", "supplement", "menu", "recap"],
  assiette: ["meat", "sauce", "garniture", "supplement", "recap"],
  snacking: ["sauce", "supplement", "recap"],
  omelette: ["garniture", "supplement", "recap"],
  salade:   ["garniture", "sauce", "supplement", "recap"],
  simple:   ["recap"],
};

// Libellés courts des étapes (pour le progress bar)
const STEP_LABELS = {
  size:       "Taille",
  pain:       "Pain",
  meat:       "Viande",
  sauce:      "Sauce",
  garniture:  "Crudités",
  supplement: "Suppl.",
  menu:       "Menu",
  friteUp:    "Frites",
  boisson:    "Boisson",
  sauceFrite: "Sauce frite",
  recap:      "Récap",
};

// Expose everything to window (Babel scope)
Object.assign(window, {
  CATEGORIES, PRODUCTS,
  SIZES, PAINS, VIANDES, SAUCES, GARNITURES, SUPPLEMENTS, SUPPLEMENT_MEAT_OPTIONS,
  MENU_CHOICES, FRITES_UPGRADE, BOISSONS_MENU, UPSELL_ITEMS,
  TEMPLATE_STEPS, STEP_LABELS, SAUCE_EXTRA_PRICE,
});
