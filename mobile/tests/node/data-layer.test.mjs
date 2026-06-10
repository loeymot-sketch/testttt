// Technical-layer (T) assertions for the GOAL's data-rooted findings.
//
// Run: node mobile/tests/node/data-layer.test.mjs   (from repo root /app)
//
// These encode the DESIRED post-fix invariants. Against current code several are
// RED (proving the bug, TDD-first per GOAL §0.4). As each task lands, its block
// flips GREEN. Gated assertions (G1/G2/G3/G4) are reported as PENDING, never guessed.

import assert from 'node:assert';
import { loadLC } from './harness.mjs';

const LC = loadLC();
const menu = LC.menu;
const orders = LC.orders;
const loyalty = LC.loyalty;

let pass = 0, fail = 0, pending = 0;
const results = [];
function check(id, desc, fn) {
  try { fn(); pass++; results.push(`  PASS  ${id}  ${desc}`); }
  catch (e) { fail++; results.push(`  FAIL  ${id}  ${desc}\n          → ${e.message}`); }
}
function pend(id, desc, note) { pending++; results.push(`  PEND  ${id}  ${desc}\n          → ${note}`); }

// ── M4 / T-4.1 — Order↔menu price parity (F7) — canonical value GATED on G3 ──
// We can still assert the STRUCTURAL invariant: every order line resolves to a
// real menu item, and line_total == menu unit price * qty (+ documented extras).
// The Tacos L (502) value itself is pending G3, so that single line is reported.
check('T-4.1a', 'every orders.js item_id exists in menu.js SSOT', () => {
  const all = [...orders.active, ...orders.history];
  for (const o of all) for (const it of (o.items || [])) {
    assert.ok(menu.findItem(it.item_id), `item_id ${it.item_id} (order ${o.id}) missing from menu`);
  }
});
// [G3=8.90 SSOT] Parity sentinel for the ACTIVE order: every line reconciles to
// menu price*qty + the surcharges documented in extras_summary ("+X,XX €").
check('T-4.1b', 'active order C-1234 reconciles to menu SSOT (price*qty + documented extras)', () => {
  const o = orders.findById('C-1234');
  const surcharge = (s) => { let t = 0, m; const re = /\+\s*([\d.,]+)\s*€/g; while ((m = re.exec(s || ''))) t += parseFloat(m[1].replace(',', '.')); return t; };
  for (const it of o.items) {
    const expected = Math.round((menu.findItem(it.item_id).price * it.qty + surcharge(it.extras_summary)) * 100) / 100;
    assert.strictEqual(it.line_total, expected, `${it.name}: line ${it.line_total} ≠ SSOT ${expected}`);
  }
  const sum = Math.round(o.items.reduce((s, i) => s + i.line_total, 0) * 100) / 100;
  assert.strictEqual(o.total, sum, `order total ${o.total} ≠ Σ lines ${sum}`);
});

// ── M4 / T-4.3 — Featured signature reference integrity (F8) ──
// Data sanity for the fix target: a real signature tacos must exist for the
// featured card to point at. (Consumer wiring is proven in source-assert.test.mjs.)
check('T-4.3', "a real signature tacos slug resolves ('big-tacos-2-viandes' fix target)", () => {
  const featured = menu.findItem('big-tacos-2-viandes');
  assert.ok(featured, "fix-target slug 'big-tacos-2-viandes' must resolve to a real item");
  assert.strictEqual(featured.category_id, 5, 'fix target must be a Tacos (category 5)');
  assert.ok(!menu.findItem('tacos-xxl'), "'tacos-xxl' is fictional — must not be reintroduced");
});

// ── M4 / T-4.2 — Catalog slug integrity for the cart upsell (F5) ──
check('T-4.2', "accompaniment items are reachable via canonical slugs (frites/boissons/desserts)", () => {
  const slugs = new Set(LC.menu.items.map((i) => (menu.findCategory(i.category_id) || {}).slug));
  for (const s of ['frites', 'boissons', 'desserts']) assert.ok(slugs.has(s), `no category slug '${s}'`);
  // Document the bug: the upsell filters on 'sides'/'drinks' which are NOT slugs.
  assert.ok(!slugs.has('sides') && !slugs.has('drinks'),
    "'sides'/'drinks' are not canonical slugs — the upsell filter can never match them");
});

// ── M3 / T-3.1 — Bowl-supplement allergen data (F4) — values GATED on G4 ──
check('T-3.1', 'every SUPPLEMENTS_BOLS item carries an allergens array (FIC 1169/2011)', () => {
  for (const s of menu.supplementsBols) {
    assert.ok(Array.isArray(s.allergens), `bol supplement '${s.name}' has no allergens field`);
  }
});
check('T-3.1b', 'Boule gratinée (cheese) discloses lactose [unambiguous; G4 = full-set sign-off]', () => {
  const bg = menu.supplementsBols.find((s) => s.id === 'sb-boule-gratinee');
  assert.ok(bg && bg.allergens.includes('lactose'), 'Boule gratinée must disclose lactose');
});

// ── M3 / T-3.2 — Formule-drink id↔slug mapping precondition (F11) ──
check('T-3.2', 'formule-drink ids differ from item slugs → a drinkSlugMap is required', () => {
  // Root cause: selections.drinkId is 'd-coca' but the catalog item slug is 'coca'.
  assert.ok(!menu.findItem('d-coca'), "direct lookup of 'd-coca' must fail (proves slug map needed)");
  assert.ok(menu.findItem('coca'), "catalog item slug 'coca' must exist (slug-map target)");
});

// ── M2 / T-2.2 — Progress copy accuracy (F9) ──
check('T-2.2', "progressToNext(347) drives 'remaining/target' — exposes the hardcoded '153 pts → burger gratuit'", () => {
  const p = loyalty.progressToNext(347);
  assert.strictEqual(p.remaining, 153, `expected 153 remaining, got ${p.remaining}`);
  // The hardcoded cart banner says the 153 leads to "burger gratuit" (1000-pt reward 5),
  // but progressToNext points at the 500-pt reward. The fix must use p.target.name.
  assert.notStrictEqual(p.target.name, 'Burger gratuit (au choix)',
    "153 pts targets the 500-pt reward, NOT the burger — hardcoded banner copy is wrong");
});

// ── M2 / T-2.1 — Earn-rate single source of truth (F2) — owner-canonical 1 pt/€ (GATE-LOYALTY-1) ──
check('T-2.1', 'earn rate canonical (1 pt/€ owner): config + active estimate agree', () => {
  assert.strictEqual(loyalty.config.earn_ratio, 1, 'owner-canonical 1 pt/€ (GATE-LOYALTY-1 2026-06-09)');
  const o = orders.findById('C-1234');
  assert.strictEqual(o.points_earned_estimate, Math.round(o.total * loyalty.config.earn_ratio),
    `active estimate ${o.points_earned_estimate} ≠ total×rate ${Math.round(o.total * loyalty.config.earn_ratio)}`);
});

// ── M1 / T-1.3 — Order-id collision resistance (F10) ──
check('T-1.3', 'seeded order-id space exists for the collision guard to check against', () => {
  const ids = new Set([...orders.active, ...orders.history].map((o) => o.id));
  assert.ok(ids.has('C-1234'), 'expected seeded active order C-1234 (collision target)');
  // Post-fix: index.html must reject/regenerate any id already in this set.
});

// ── M1 / T-1.1 — Promo semantics (F1, G1=real -10%) ──
// Promo charge is a flow/JSX concern, not data-layer; verified in source-assert.test.mjs
// (T-1.1: promo lifted to App + applied in snapshotOrder/total). Full charged==displayed
// proof belongs to the deferred Playwright spec.

// ── report ──
console.log('\n=== Le Cayenne — data-layer technical gate ===\n');
console.log(results.join('\n'));
console.log(`\n  ${pass} pass · ${fail} fail · ${pending} pending(gate)\n`);
process.exit(fail > 0 ? 1 : 0);
