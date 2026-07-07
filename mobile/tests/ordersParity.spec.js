// Standalone parity verifier for mobile/data/orders.js against the Le Cayenne canon.
// The header of orders.js claims "invariants enforced by tests/ordersParity.spec.js" —
// this file is that enforcement (previously the claim was un-backed: no orders test existed).
//
// Loads menu.js + loyalty.js + orders.js in a shared `window` sandbox, then asserts, for
// every active + history order:
//   • each item_id resolves to a canonical menu.js item
//   • item.name === canon menu.js name for that item_id (kills "Sandwich Cayenne" phantoms)
//   • line_total === canon unit price × qty
//   • order.total === sum(line_total)
//   • points (points_earned | points_earned_estimate) === Math.round(total × earn_ratio)
//   • no fictional product name appears anywhere in the order data
//
// Run:  node mobile/tests/ordersParity.spec.js
//   (optionally pass [mobileDataDir] to override the default below)
// Exit 0 = all parity checks pass. Pure Node, no deps.
'use strict';
const fs = require('fs');
const vm = require('vm');
const path = require('path');

const DATA_DIR = process.argv[2] || path.resolve(__dirname, '../data');
const EPS = 0.005; // float tolerance for euro arithmetic

function loadDataLayer(dir) {
  const sandbox = { window: {}, console: { warn() {}, log() {}, error() {} } };
  sandbox.globalThis = sandbox;
  vm.createContext(sandbox);
  // Order matters: menu.js defines window.LC.menu; loyalty.js the config; orders.js the orders.
  ['menu.js', 'loyalty.js', 'orders.js'].forEach(f => {
    const file = path.join(dir, f);
    vm.runInContext(fs.readFileSync(file, 'utf8'), sandbox, { filename: file });
  });
  return sandbox.window;
}

let failures = 0;
function check(cond, msg) {
  if (cond) { console.log('  ok  ' + msg); }
  else { console.log('  FAIL ' + msg); failures++; }
}

// Fictional product names that must never resurface in order data (anti-fiction, CLAUDE.md §3bis).
const PHANTOM_RE = /Sandwich Cayenne|Box |Bowl |Nashville|Wrap |Smash|Cookie XL|Frites M\b|Assiette|Ojja|Omelette|Wings/i;

console.log('=== ORDERS PARITY (' + DATA_DIR + ') ===');
const w = loadDataLayer(DATA_DIR);
const M = w.LC && w.LC.menu;
const O = w.LC && w.LC.orders;
const L = w.LC && w.LC.loyalty;
check(!!M, 'window.LC.menu populated');
check(!!O, 'window.LC.orders populated');
check(!!L && !!L.config, 'window.LC.loyalty.config populated');

if (M && O && L) {
  const ratio = L.config.earn_ratio;
  check(ratio === 10, 'earn_ratio = 10 pt/€ (got ' + ratio + ')');

  const allOrders = [].concat(O.active || [], O.history || []);
  check(allOrders.length > 0, 'orders present (got ' + allOrders.length + ')');

  allOrders.forEach(o => {
    const tag = 'order ' + o.id;

    // items_summary must be phantom-free
    check(!PHANTOM_RE.test(o.items_summary || ''), tag + ' items_summary phantom-free ("' + (o.items_summary || '') + '")');

    let sum = 0;
    (o.items || []).forEach(it => {
      const canon = M.findItem(it.item_id);
      check(!!canon, tag + ' item_id ' + it.item_id + ' resolves to a canon item');
      if (canon) {
        check(canon.name === it.name,
          tag + ' item ' + it.item_id + ' name = canon "' + canon.name + '" (got "' + it.name + '")');
        const expLine = canon.price * (it.qty || 1);
        check(Math.abs((it.line_total || 0) - expLine) < EPS,
          tag + ' item "' + it.name + '" line_total = ' + expLine.toFixed(2) +
          ' (' + canon.price + ' × ' + it.qty + ', got ' + it.line_total + ')');
      }
      check(!PHANTOM_RE.test(it.name || ''), tag + ' item name phantom-free ("' + it.name + '")');
      sum += (it.line_total || 0);
    });

    check(Math.abs((o.total || 0) - sum) < EPS,
      tag + ' total = Σ line_total = ' + sum.toFixed(2) + ' (got ' + o.total + ')');

    const points = o.points_earned != null ? o.points_earned : o.points_earned_estimate;
    check(points != null, tag + ' has a points figure (points_earned | points_earned_estimate)');
    if (points != null) {
      const expPoints = Math.round((o.total || 0) * ratio);
      check(points === expPoints,
        tag + ' points = round(total × ' + ratio + ') = ' + expPoints + ' (got ' + points + ')');
    }
  });
}

console.log('\n================ RESULT ================');
if (failures === 0) { console.log('ALL PARITY CHECKS PASSED (0 failures)'); process.exit(0); }
else { console.log(failures + ' FAILURE(S)'); process.exit(1); }
