// Node stub to exercise REAL mobile/api/client.js — confirm formula fix (menuChoice full/frites/boisson)
const fs = require('fs');
const vm = require('vm');
const path = require('path');

const FIX = JSON.parse(fs.readFileSync('/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/reports/goal-web-app-sync/e2e-fixtures.json','utf8'));
const TOKEN = FIX.clients[0].token;
const BASE = FIX.base;
const APIKEY = FIX.apiKey;

// ---- browser stubs ----
const store = {};
const localStorage = {
  getItem: k => (k in store ? store[k] : null),
  setItem: (k,v) => { store[k] = String(v); },
  removeItem: k => { delete store[k]; },
};
const window = {};
window.localStorage = localStorage;
window.crypto = require('crypto').webcrypto;
window.LC = window.LC || {};
window.LC.config = { apiBase: BASE, apiKey: APIKEY, branchId: 1, onlineCardEnabled: false };
// storage stub returning the real client token
window.LC.storage = {
  getToken: () => TOKEN,
  getAuth: () => ({ token: TOKEN }),
  setAuth: () => {},
  clearAuth: () => {},
};

const sandbox = { window, localStorage, crypto: window.crypto, fetch, console, setTimeout, URL, TextEncoder, TextDecoder };
sandbox.global = sandbox;
vm.createContext(sandbox);

function load(rel){
  const code = fs.readFileSync(path.join('/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/mobile', rel),'utf8');
  vm.runInContext(code, sandbox, { filename: rel });
}

// load menu (defines window.LC.menu) then client (defines window.LC.mobileApi)
load('data/menu.js');
load('api/client.js');

const api = window.LC.mobileApi;

(async () => {
  console.log('=== buildItemIndex ===');
  const idx = await api.buildItemIndex();
  console.log('items indexed:', idx.rows.length, '| big burger id via byName:', idx.byName['big burger']);

  const BURGER_ID = idx.byName['big burger'];
  const det = await api.itemDetails(BURGER_ID);
  console.log('Big Burger base price:', det.price);

  const choices = ['full','frites','boisson'];
  const expectedDelta = { full: 2.50, frites: 1.50, boisson: 1.00 };
  const base = parseFloat(det.price);

  for (const ch of choices) {
    console.log('\n===== menuChoice =', ch, '=====');
    const line = { name: 'Big Burger', menuChoice: ch, qty: 1 };
    let resolved;
    try {
      resolved = await api.resolveLine(line);
      console.log('resolveLine ->', JSON.stringify(resolved));
    } catch(e){ console.log('resolveLine ERROR', JSON.stringify(e)); continue; }

    try {
      const r = await api.placeOrder({ cart: [line] });
      const total = parseFloat(r.total_amount ?? r.total ?? r.grand_total ?? (r.order && (r.order.total_amount||r.order.total)));
      const expected = base + expectedDelta[ch];
      console.log('ORDER id:', r.id || (r.order&&r.order.id), '| total:', total, '| expected base+delta:', expected,
        '| MATCH:', Math.abs(total-expected) < 0.001 ? 'YES' : 'NO ***');
      console.log('  raw order keys:', Object.keys(r));
    } catch(e){
      console.log('placeOrder ERROR status', e.status, JSON.stringify(e.message || e), e.body?JSON.stringify(e.body):'');
    }
  }
})().catch(e=>{ console.error('FATAL', e); process.exit(1); });
