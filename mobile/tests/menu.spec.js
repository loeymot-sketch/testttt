// Standalone canon verifier for mobile/web data/menu.js.
// Stubs `window` + `console.warn`, loads the IIFE, asserts the Le Cayenne canon
// (SSOT = database/seeders/OwnerMenuUpdate20260623Seeder.php). Pure Node, no deps.
//
// Run:  node mobile/tests/menu.spec.js
//   (optionally pass [mobilePath] [webPath] to override the defaults below)
// Exit 0 = all canon checks pass on BOTH the mobile file and its web mirror.
'use strict';
const fs = require('fs');
const vm = require('vm');
const path = require('path');

// Default targets: mobile file (next to this test) + web standalone mirror.
const DEFAULT_MOBILE = path.resolve(__dirname, '../data/menu.js');
const DEFAULT_WEB = '/Users/1millnonstop/Downloads/web/data/menu.js';

function loadMenu(file) {
  const code = fs.readFileSync(file, 'utf8');
  const sandbox = { window: {}, console: { warn() {}, log() {}, error() {} } };
  sandbox.globalThis = sandbox;
  vm.createContext(sandbox);
  vm.runInContext(code, sandbox, { filename: file });
  return sandbox.window;
}

let failures = 0;
function check(cond, msg) {
  if (cond) { console.log('  ok  ' + msg); }
  else { console.log('  FAIL ' + msg); failures++; }
}

function verify(label, file) {
  console.log('\n=== ' + label + ' (' + file + ') ===');
  const w = loadMenu(file);
  const M = w.LC && w.LC.menu;
  check(!!M, 'window.LC.menu populated (IIFE ran without throwing)');
  if (!M) return;

  const items = M.items;
  const byName = n => items.find(i => i.name === n);
  const names = items.map(i => i.name);

  // --- pools ---
  check(M.meats.length === 7, '7 viandes (got ' + M.meats.length + ')');
  const meatNames = M.meats.map(m => m.name).sort();
  const expMeats = ['Cordon Bleu','Fricadelle','Mexicanos','Nuggets','Poulet mariné','Tenders','Viande Hachée'];
  check(JSON.stringify(meatNames) === JSON.stringify(expMeats), '7 viandes exactes = ' + JSON.stringify(expMeats));
  check(M.sauces.length === 12, '12 sauces (got ' + M.sauces.length + ')');
  check(M.crudites.length === 4, '4 crudités (got ' + M.crudites.length + ')');
  check(!M.crudites.find(c => c.name === 'Cornichon'), 'Cornichon supprimé des crudités');
  check(!!M.crudites.find(c => c.name === 'Oignons cuits'), 'Crudité "Oignons cuits" présente (sync 2026-07-08)');
  check(M.supplements.length === 10, '10 suppléments (got ' + M.supplements.length + ')');
  check(!!M.supplements.find(s => s.name === 'Oignons frits'), 'Supplément "Oignons frits" présent');
  check(!M.supplements.find(s => s.name === 'Oignon frais'), '"Oignon frais" renommé (absent)');
  // 9 suppléments à 0,90€ + "Boule gratinée" à 1,00€ (ajoutée sync 2026-07-08)
  check(M.supplements.filter(s => s.name !== 'Boule gratinée').every(s => s.price === 0.90), 'Suppléments (hors Boule gratinée) tous à 0,90€');
  check(!!M.supplements.find(s => s.name === 'Boule gratinée' && s.price === 1.00), 'Supplément "Boule gratinée" 1,00€');

  // --- [GOAL-SYNC 2026-07-08] nouveaux pools normatifs (CONTRACTS.md §5, mêmes noms web & mobile) ---
  check(Array.isArray(M.pains) && M.pains.length === 2, 'PAINS = 2 entrées (got ' + (M.pains && M.pains.length) + ')');
  check(!!(M.pains || []).find(p => p.id === 'pain-classique' && p.name === 'Pain'), 'PAINS: pain-classique "Pain"');
  check(!!(M.pains || []).find(p => p.id === 'pain-galette' && p.name === 'Galette'), 'PAINS: pain-galette "Galette"');
  check(M.extraMeatPrice === 2.50, 'EXTRA_MEAT_PRICE = 2,50€ (got ' + M.extraMeatPrice + ')');
  check(Array.isArray(M.bolSauces) && M.bolSauces.length === 2, 'BOL_SAUCES = 2 sauces bol (got ' + (M.bolSauces && M.bolSauces.length) + ')');
  check(!!(M.bolSauces || []).find(s => s.name === 'Sauce fromagère maison') && !!(M.bolSauces || []).find(s => s.name === 'Sauce spicy'), 'BOL_SAUCES = fromagère maison + spicy');
  check(Array.isArray(M.supplementsBols) && M.supplementsBols.length === 10, 'SUPPLEMENTS_BOLS = 10 (9×0,90€ + Gratiné, got ' + (M.supplementsBols && M.supplementsBols.length) + ')');
  check((M.supplementsBols || []).filter(s => s.id !== 'sb-gratine').every(s => s.price === 0.90), 'Suppléments bols (hors Gratiné) tous à 0,90€');
  const sbGratine = (M.supplementsBols || []).find(s => s.id === 'sb-gratine');
  check(!!sbGratine && sbGratine.price === 2.00 && sbGratine.riz_only === true, 'Option Gratiné 2,00€ riz_only (Bol Riz uniquement)');
  const boursin = M.supplements.find(s => s.id === 'sup-boursin');
  check(!!boursin && boursin.galette_excluded === true, 'Boursin galette_excluded (pas offert sur galettes)');
  const bouleG = M.supplements.find(s => s.id === 'sup-boule-gratinee');
  check(!!bouleG && bouleG.galette_only === true, 'Boule gratinée galette_only');
  // 4 sandwichs = choix du pain ; 16 items = viande supplémentaire possible
  const sandwichs = items.filter(i => i.category_id === 1);
  check(sandwichs.length === 4 && sandwichs.every(s => s.has_pain_choice === true), '4 sandwichs has_pain_choice');
  check(items.filter(i => i.has_extra_meat === true).length === 16, '16 items has_extra_meat (4 sandwichs + 2 galettes + 6 burgers + 2 tacos + 2 bols)');
  // Tacos M/L : crudités restaurées (REVERT 05e5cacd0)
  check(byName('Tacos M') && byName('Tacos M').has_crudites === true, 'Tacos M has_crudites (revert crudités)');
  check(byName('Tacos L') && byName('Tacos L').has_crudites === true, 'Tacos L has_crudites (revert crudités)');
  // Cayenne : sauce pré-sélectionnée fromagère, pas verrouillée ; is_spicy false partout au canon
  const cay = byName('Cayenne');
  check(!!cay && cay.sauce_default === 'Sauce fromagère maison' && !cay.sauce_locked, 'Cayenne sauce_default fromagère, non verrouillée');
  check(byName('Galette Cayenne') && byName('Galette Cayenne').is_spicy === false, 'Galette Cayenne is_spicy=false (canon)');
  check(items.every(i => i.is_halal === false), 'is_halal=false partout (allégation réglementaire, canon)');
  // Catégorie 6 (Bols) = wizard_template 'bol'
  check(M.findCategory(6) && M.findCategory(6).wizard_template === 'bol', 'cat 6 Bols wizard_template=bol');

  // --- formule ---
  const fMenu = M.formules.find(f => f.id === 'f-menu');
  check(fMenu && fMenu.price === 2.50, 'Formule menu +2,50€ (got ' + (fMenu && fMenu.price) + ')');

  // --- categories ---
  const catNames = M.categories.map(c => c.name);
  check(!!M.findCategory('sandwichs') && M.findCategory('sandwichs').name === 'Sandwichs', 'cat "Sandwichs" (renommée)');
  check(!catNames.includes('Sandwich Cayenne'), '"Sandwich Cayenne" renommée (absente)');
  check(!!M.findCategory('bols') && M.findCategory('bols').name === 'Bols', 'cat "Bols" (renommée)');
  check(!catNames.includes('Bols Gourmands'), '"Bols Gourmands" renommée (absente)');
  check(!catNames.includes('Sandwich Classique'), 'cat "Sandwich Classique" supprimée');
  check(!catNames.includes('Suppléments'), 'cat "Suppléments" vendable supprimée');
  check(!!M.findCategory('galette'), 'cat "Galette" conservée (gate owner)');

  // --- TACOS ---
  check(byName('Tacos M') && byName('Tacos M').price === 6.90, 'Tacos M 6,90€');
  check(byName('Tacos L') && byName('Tacos L').price === 7.90, 'Tacos L 7,90€ (PAS 8,90)');

  // --- SANDWICHS ---
  check(byName('Cayenne') && byName('Cayenne').price === 7.40, 'Cayenne 7,40€');
  check(byName('Suprême') && byName('Suprême').price === 7.00, 'Suprême 7,00€ (manquant ajouté)');
  check(byName('Méga') && byName('Méga').price === 8.00, 'Méga 8,00€ (manquant ajouté)');
  check(byName('Méga') && byName('Méga').viandes === 2, 'Méga = 2 viandes au choix');
  check(byName('Terminator') && byName('Terminator').price === 9.00, 'Terminator 9,00€ (manquant ajouté)');
  check(byName('Terminator') && byName('Terminator').viandes === 2, 'Terminator = 2 viandes au choix');

  // --- BURGERS (6) ---
  const burgers = items.filter(i => i.category_id === 4);
  check(burgers.length === 6, '6 burgers (got ' + burgers.length + ')');
  check(byName('Chicken Burger') && byName('Chicken Burger').price === 4.90, 'Chicken Burger 4,90€ (PAS 6,90)');
  check(byName('Cheese Burger') && byName('Cheese Burger').price === 6.00, 'Cheese Burger 6,00€');
  check(byName('Double Cheese') && byName('Double Cheese').price === 7.00, 'Double Cheese 7,00€');
  check(byName('Fish Burger') && byName('Fish Burger').price === 6.00, 'Fish Burger 6,00€');
  check(byName('Big Burger') && byName('Big Burger').price === 9.00, 'Big Burger 9,00€');
  check(byName('Grill Burger') && byName('Grill Burger').price === 8.00, 'Grill Burger 8,00€');

  // --- BOLS (2) ---
  const bols = items.filter(i => i.category_id === 6);
  check(bols.length === 2, '2 bols (got ' + bols.length + ')');
  check(byName('Bol Frites') && byName('Bol Frites').price === 7.90, 'Bol Frites 7,90€');
  check(byName('Bol Riz') && byName('Bol Riz').price === 7.90, 'Bol Riz 7,90€');
  check(bols.every(b => b.viandes === 1), 'Bols = viande au choix (viandes:1, PAS poulet-only fixe)');
  check(bols.every(b => !b.bol_meat_fixed), 'Bols sans viande fixe (bol_meat_fixed=null)');

  // --- DESSERTS ---
  check(items.filter(i => i.category_id === 9).every(d => d.price === 3.50), 'Desserts 3,50€');

  // --- BOISSONS ---
  const drinks = items.filter(i => i.category_id === 10);
  // Canettes 33cl = 1,90€ ; Eau 50cl = 1,00€ ; Capri-Sun 20cl = 1,50€
  // (canon — confirmé live kiosk + seeder 2026-06-26 ; Capri n'est PAS une canette 1,90).
  check(drinks.filter(d => d.slug !== 'eau-plate' && d.slug !== 'capri-sun').every(d => d.price === 1.90), 'Canettes 1,90€');
  check(byName('Eau Plate 50cl') && byName('Eau Plate 50cl').price === 1.00, 'Eau 1,00€ (inchangée)');
  check(byName('Capri-Sun') && byName('Capri-Sun').price === 1.50, 'Capri-Sun 1,50€ (20cl, ≠ canette)');
  // [GOAL-SYNC 2026-07-08] 15 boissons (8 historiques + 7 nouvelles ids 1009-1015 @ 1,90€)
  check(drinks.length === 15, '15 boissons (got ' + drinks.length + ')');
  [['Coca Cherry 33cl', 1009], ['Tropico 33cl', 1010], ['Ice Tea Pêche 33cl', 1011],
   ['Fanta Citron 33cl', 1012], ['Fuze Tea 33cl', 1013], ['Hawaï 33cl', 1014],
   ['Perrier 33cl', 1015]].forEach(([n, id]) => {
    const d = byName(n);
    check(!!d && d.id === id && d.price === 1.90, 'boisson "' + n + '" id ' + id + ' @ 1,90€');
  });
  check(M.formuleDrinks && M.formuleDrinks.length === 15, 'FORMULE_DRINKS = 15 saveurs (got ' + (M.formuleDrinks && M.formuleDrinks.length) + ')');
  check(M.priceForDrinkAddon('d-capri') === 1.50, 'priceForDrinkAddon capri = 1,50€ (FIX P0)');
  check(M.priceForDrinkAddon('d-coca-cherry') === 1.90, 'priceForDrinkAddon coca-cherry = 1,90€');

  // --- MENU ENFANT (2 SKU @ 4,90) ---
  const enfant = items.filter(i => i.category_id === 11);
  check(enfant.length === 2, '2 SKU menu enfant (got ' + enfant.length + ')');
  check(enfant.every(e => e.price === 4.90), 'Menu enfant 4,90€');
  check(!!byName('Menu Enfant Nuggets') && !!byName('Menu Enfant Chicken Burger'), 'Menu Enfant Nuggets + Chicken Burger');

  // --- PHANTOMS (must be absent) ---
  ['Big Cayenne','Big Chicken','Big Tacos','Big Classique','Sandwich Classique',
   'Bowl Frites Poulet mariné','Bowl Riz Poulet curry','Menu Nuggets',
   'Cheddar','Raclette','Emmental'].forEach(ph => {
    check(!names.includes(ph), 'fantôme absent: "' + ph + '"');
  });
  // No "Bowl" products at all
  check(!names.find(n => /^Bowl /.test(n)), 'aucun produit "Bowl …" résiduel');
  // No standalone supplement products (Cheddar/Raclette as sellable items)
  check(!items.find(i => i.category_id === 8), 'aucun item en cat 8 (Suppléments vendables)');

  // --- price calc smoke (formule applies +2,50) ---
  const tm = byName('Tacos M');
  check(M.priceFor(tm, { formuleId: 'f-menu' }) === 9.40, 'Tacos M + menu = 9,40€ (6,90+2,50)');

  // --- helpers resolve ---
  check(typeof M.findItem === 'function' && !!M.findItem('tacos-m'), 'findItem(slug) ok');
  check(typeof M.priceForDrinkAddon === 'function' && M.priceForDrinkAddon('d-coca') === 1.90, 'priceForDrinkAddon coca = 1,90€');

  // [GOAL-SYNC 2026-07-08] verrou volumétrie mirror : 38 items (42 canoniques − 6 SKU frites
  // consolidés en 2 wizards frites_style, divergence structurelle acceptée CONTRACTS.md §5).
  check(items.length === 38, '38 items mirror (got ' + items.length + ')');
  check(M.categories.length === 9, '9 catégories (got ' + M.categories.length + ')');

  console.log('  -> ' + items.length + ' produits, ' + M.categories.length + ' catégories');
}

verify('MOBILE', process.argv[2] || DEFAULT_MOBILE);
verify('WEB', process.argv[3] || DEFAULT_WEB);

console.log('\n================ RESULT ================');
if (failures === 0) { console.log('ALL CHECKS PASSED (0 failures)'); process.exit(0); }
else { console.log(failures + ' FAILURE(S)'); process.exit(1); }
