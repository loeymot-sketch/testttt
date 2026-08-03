// W6R2 adversarial harness — load REAL web data/menu.js + api.js in a node stub,
// reproduce EXACTLY what resolveLine sends, then place REAL orders on :8766.
import fs from 'node:fs';
import path from 'node:path';

const WEB = '/Users/1millnonstop/Downloads/web';
const TOKEN = '6625|U2sYzBULk802OTteFA6IkmYtWA6Z5OSKYcF8Jvz3fac5b35e';

// ---- browser stubs ----
const store = { 'lecayenne.authToken': TOKEN, 'lecayenne.authPhone': '0697222388' };
global.localStorage = {
  getItem: (k) => (k in store ? store[k] : null),
  setItem: (k, v) => { store[k] = String(v); },
  removeItem: (k) => { delete store[k]; },
};
global.document = { querySelector: () => null }; // → metaContent fallbacks (base :8766, fixture apiKey)
global.window = {};
if (!global.crypto) global.crypto = await import('node:crypto').then(m => m.webcrypto);

// ---- load real files ----
function loadIntoGlobal(file) {
  const code = fs.readFileSync(path.join(WEB, file), 'utf8');
  // eslint-disable-next-line no-eval
  (0, eval)(code); // IIFE attaches to global.window
}
loadIntoGlobal('data/menu.js');
loadIntoGlobal('api.js');

const api = global.window.LC.api;
const menu = global.window.LC.menu;

function euros(v) { return v == null ? '?' : Number(v).toFixed(2); }

async function run() {
  await api.buildItemIndex();

  const targets = [
    { webId: 402, label: 'Cheese Burger', base: 6.00 },
    { webId: 101, label: 'Cayenne', base: 7.40 },
  ];
  const results = [];

  for (const t of targets) {
    const item = menu.findItem(t.webId);
    if (!item) { console.log('NO ITEM', t.webId); continue; }
    // resolve the real backend id + print the resolved line for menu=full
    const previewLine = { ...item, qty: 1, state: { sauce: ['s-mayo'], menu: 'full' } };
    const resolvedFull = await api.resolveLine(previewLine);
    console.log(`\n=== ${t.label} (web ${t.webId}) -> backend item_id ${resolvedFull.item_id} ===`);
    console.log('resolveLine(menu=full) payload:', JSON.stringify(resolvedFull));

    const scenarios = [
      { menu: 'none',    expectDelta: 0.00 },
      { menu: 'full',    expectDelta: 2.50 },
      { menu: 'frites',  expectDelta: 1.50 },
      { menu: 'boisson', expectDelta: 1.00 },
    ];
    for (const s of scenarios) {
      const line = { ...item, qty: 1, state: { sauce: ['s-mayo'], menu: s.menu } };
      let order, err = null;
      try {
        order = await api.placeOrder({ cart: [line], orderType: 10, paymentMethod: 1 });
      } catch (e) {
        err = e;
      }
      if (err) {
        console.log(`  [${t.label}/${s.menu}] ERROR`, JSON.stringify(err));
        results.push({ item: t.label, menu: s.menu, ok: false, err });
        continue;
      }
      const total = Number(order.total_amount ?? order.grand_total ?? order.total ?? order.amount);
      const id = order.id ?? order.order_id ?? '?';
      console.log(`  [${t.label}/${s.menu}] order id=${id} total=${euros(total)} (keys: ${Object.keys(order).join(',')})`);
      results.push({ item: t.label, menu: s.menu, ok: true, id, total, expectDelta: s.expectDelta, base: t.base });
    }
  }

  // ---- verdict ----
  console.log('\n===== VERDICT =====');
  for (const t of targets) {
    const baseRow = results.find(r => r.item === t.label && r.menu === 'none' && r.ok);
    const baseTotal = baseRow ? baseRow.total : t.base;
    for (const r of results.filter(x => x.item === t.label)) {
      if (!r.ok) { console.log(`FAIL ${r.item}/${r.menu}: ${r.err.status||''} ${r.err.message||JSON.stringify(r.err)}`); continue; }
      const delta = r.total - baseTotal;
      const expTotal = baseTotal + r.expectDelta;
      const pass = Math.abs(delta - r.expectDelta) < 0.005;
      console.log(`${pass ? 'PASS' : 'FAIL'} ${r.item}/${r.menu}: id=${r.id} total=${euros(r.total)} delta=${euros(delta)} expectDelta=${euros(r.expectDelta)} expectTotal=${euros(expTotal)}`);
    }
  }
}

run().catch(e => { console.error('FATAL', e); process.exit(1); });
