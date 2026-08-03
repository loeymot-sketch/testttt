// JETABLE — harness node e2e mobile via api/client.js (W6R1 adversaire)
'use strict';
const fs = require('fs');
const path = require('path');
const vm = require('vm');

const MOBILE = '/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/mobile';
const FIX = JSON.parse(fs.readFileSync('/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/reports/goal-web-app-sync/e2e-fixtures.json','utf8'));
const CAT = JSON.parse(fs.readFileSync('/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/reports/goal-web-app-sync/catalog-canonical.json','utf8'));

// ---- stub localStorage ----
const _ls = {};
global.localStorage = {
  getItem: k => (k in _ls ? _ls[k] : null),
  setItem: (k,v) => { _ls[k] = String(v); },
  removeItem: k => { delete _ls[k]; },
};
const window = {};
global.window = window;
global.crypto = require('crypto').webcrypto;
global.fetch = fetch;

window.LC = { config: { apiBase: FIX.base, apiKey: FIX.apiKey, branchId: 1, onlineCardEnabled: false } };

function load(rel){ vm.runInThisContext(fs.readFileSync(path.join(MOBILE, rel),'utf8'), {filename: rel}); }
load('api/storage.js');
load('data/menu.js');
load('api/client.js');

const api = window.LC.mobileApi;
const storage = window.LC.storage;
const lcMenu = window.LC.menu;
const client = FIX.clients[0];
storage.setAuth({ token: client.token, phone: client.phone, user_id: client.user_id });

const J = o => JSON.stringify(o);

// Reproduit la portion additive de buildLineItem qui alimente resolveLine
// (spread item + champs de selections). On construit directement le shape consommé.
function buildLine(slugOrId, sel){
  const item = lcMenu.findItem(slugOrId);
  const cruditeIds = sel.cruditeIds || lcMenu.defaultCruditeIds();
  const cruditeRemoved = lcMenu.crudites.filter(c => !cruditeIds.includes(c.id)).map(c => c.name);
  return Object.assign({}, item, {
    painId: sel.painId || null,
    meatIds: sel.meatIds || [],
    extraMeatIds: sel.extraMeatIds || [],
    sauceIds: sel.sauceIds || [],
    cruditeIds,
    cruditeRemoved,
    supplementIds: sel.supplementIds || [],
    bolSupplementIds: sel.bolSupplementIds || [],
    bolDrinkId: sel.bolDrinkId !== undefined ? sel.bolDrinkId : null,
    menuChoice: sel.menuChoice || 'none',
    drinkId: sel.drinkId || null,
    fritesStyleId: sel.fritesStyleId !== undefined ? sel.fritesStyleId : null,
    fritesSauceIds: sel.fritesSauceIds || [],
    instruction: sel.instruction || '',
    qty: sel.qty || 1,
  });
}

function catPrice(slug){
  const it = (CAT.items||CAT).find ? (CAT.items||CAT).find(x=>x.slug===slug) : null;
  return it ? it.price : null;
}

(async () => {
  console.log('=== isAuthed:', api.isAuthed(), '| user', client.user_id, client.phone);
  const idx = await api.buildItemIndex();
  console.log('=== buildItemIndex rows:', idx.rows.length, 'bySlug:', Object.keys(idx.bySlug).length);
  console.log('    mega->', idx.bySlug['mega'], '| bol-frites->', idx.bySlug['bol-frites'], '| coca->', idx.bySlug['coca']);

  // ---------- CASE 1 : Méga (2 viandes) composé ----------
  const megaLine = buildLine('mega', {
    meatIds: ['m-mexicanos','m-viande-hachee'],   // 2 viandes -> attr 1 & 2
    painId: 'pain-classique',
    sauceIds: ['s-samurai'],                       // -> attr 5
    cruditeIds: ['c-salade','c-oignon'],           // retire Tomate
    supplementIds: ['sup-cheddar'],                // +0.90
    instruction: 'bien cuit',
  });
  const megaResolved = await api.resolveLine(megaLine);
  console.log('\n--- CASE1 Méga resolveLine ->', J(megaResolved));

  // ---------- CASE 2 : Bol Frites + bolDrinkId (ligne séparée) ----------
  const bolLine = buildLine('bol-frites', {
    meatIds: ['m-tenders'],
    sauceIds: ['bs-spicy'],                         // -> attr 8 sauce bol
    bolSupplementIds: ['sb-cheddar'],
    bolDrinkId: 'd-coca',                           // ligne coca séparée
  });
  const bolItems = await api.resolveOrderItems([bolLine]);
  console.log('\n--- CASE2 Bol+drink resolveOrderItems ->', J(bolItems), '| lignes:', bolItems.length);

  // ---------- CASE 3 : Boisson standalone (Fanta) ----------
  const fantaLine = buildLine('fanta', {});
  const fantaResolved = await api.resolveLine(fantaLine);
  console.log('\n--- CASE3 Fanta resolveLine ->', J(fantaResolved));

  // ---------- PLACE ORDER (les 3 dans un panier) ----------
  console.log('\n=== placeOrder cart=[Méga, Bol(+coca), Fanta] ...');
  let order;
  try {
    order = await api.placeOrder({ cart: [megaLine, bolLine, fantaLine], orderType: 10 });
  } catch(e){
    console.log('!!! placeOrder ERROR:', J(e));
    process.exit(0);
  }
  const oid = order.id;
  console.log('=== ORDER created id=', oid, 'total=', order.total_amount || order.total, 'status=', order.order_status, 'payment_method=', order.payment_method, 'fiscal=', order.fiscal_sequence_no);
  // fetch back
  const full = await api.getOrder(oid);
  const lines = (full.order_items || full.items || full.details || []);
  console.log('=== server order_items count:', lines.length);
  lines.forEach((li,i)=>{
    console.log(`  L${i}: item_id=${li.item_id} name=${li.item_name||(li.item&&li.item.name)} qty=${li.quantity} price=${li.price||li.total_price||li.amount}`);
  });
  console.log('=== RAW getOrder keys:', Object.keys(full).join(','));
  console.log('=== FULL total_amount=', full.total_amount, 'grand_total=', full.grand_total, 'order_amount=', full.order_amount, 'pay_amount=', full.pay_amount);

  // ---------- prix attendus (catalog canonical) ----------
  console.log('\n=== catalog prices: mega=', catPrice('mega'), 'bol-frites=', catPrice('bol-frites'), 'coca=', catPrice('coca'), 'fanta=', catPrice('fanta'));
})().catch(e=>{ console.log('FATAL', e && (e.stack||e.message||J(e))); });
