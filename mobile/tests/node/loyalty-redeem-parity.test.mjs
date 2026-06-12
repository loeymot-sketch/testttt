// Loyalty redeem parity gate (B1-M, 2026-06-10) — locks canonical model A:
//   earn 1 pt/€ · redeem 100 pts = 1 € · minimum redeemable 100 pts.
//
// Asserts are aligned on the REAL implementation read in data/loyalty.js:
//   • conversion is LINEAR points/100 with NO floor — the authored catalog has
//     '−2,50 € sur ta commande' at 250 pts (reward id 3), so flooring 250→2 €
//     would CHANGE the barème. redeemValueEuros(250) === 2.50.
//   • the min-100 gate means sub-minimum amounts are worth 0 € (not redeemable).
//
// Run: node mobile/tests/node/loyalty-redeem-parity.test.mjs   (from repo root)

import assert from 'node:assert';
import fs from 'node:fs';
import path from 'node:path';
import { loadLC } from './harness.mjs';

const LC = loadLC();
const loyalty = LC.loyalty;
const CONFIG = loyalty.config;

let pass = 0, fail = 0;
const results = [];
function check(id, desc, fn) {
  try { fn(); pass++; results.push(`  PASS  ${id}  ${desc}`); }
  catch (e) { fail++; results.push(`  FAIL  ${id}  ${desc}\n          → ${e.message}`); }
}

// ── R-1 — canonical model A constants ──
check('R-1.1', 'config locks model A: earn 1 pt/€, redeem 100 pts = 1 €, min 100 pts', () => {
  assert.strictEqual(CONFIG.earn_ratio, 1, 'earn_ratio drifted from 1 pt/€');
  assert.strictEqual(CONFIG.redeem_ratio, 100, 'redeem_ratio drifted from 100 pts/€');
  assert.strictEqual(CONFIG.min_redeem_points, 100, 'min_redeem_points drifted from 100');
});

// ── R-2 — redeemValueEuros SSOT behaviour (aligned on the real implementation) ──
check('R-2.1', 'redeemValueEuros(100) = 1 €', () => {
  assert.strictEqual(loyalty.redeemValueEuros(100), 1);
});
check('R-2.2', 'redeemValueEuros(99) = 0 € (below the min-100 gate → not redeemable)', () => {
  assert.strictEqual(loyalty.redeemValueEuros(99), 0);
  assert.strictEqual(loyalty.redeemValueEuros(0), 0);
  assert.strictEqual(loyalty.redeemValueEuros(-50), 0);
  assert.strictEqual(loyalty.redeemValueEuros(undefined), 0);
});
check('R-2.3', 'redeemValueEuros(250) = 2.50 € — LINEAR, no floor (matches authored reward id 3)', () => {
  assert.strictEqual(loyalty.redeemValueEuros(250), 2.5);
  assert.strictEqual(loyalty.redeemValueEuros(500), 5);
  assert.strictEqual(loyalty.redeemValueEuros(1000), 10);
});
check('R-2.4', 'redeemValueEuros agrees with the raw converter pointsToDiscount above the min', () => {
  for (const p of [100, 150, 250, 500, 1000, 2000, 3000]) {
    assert.strictEqual(loyalty.redeemValueEuros(p), loyalty.pointsToDiscount(p),
      `divergence at ${p} pts`);
  }
});

// ── R-3 — reward catalog parity (the screens redeem via the catalog) ──
check('R-3.1', 'every discount reward amount == redeemValueEuros(points_cost) — barème unchanged', () => {
  const discounts = loyalty.rewards.filter((r) => r.type === 'discount');
  assert.ok(discounts.length >= 3, 'expected the 3 authored discount rewards');
  for (const r of discounts) {
    assert.strictEqual(r.payload.amount, loyalty.redeemValueEuros(r.points_cost),
      `reward #${r.id} '${r.name}': ${r.payload.amount} € ≠ ${r.points_cost} pts / 100`);
  }
});
check('R-3.2', 'no reward is redeemable below the 100-pt minimum', () => {
  for (const r of loyalty.rewards) {
    assert.ok(r.points_cost >= CONFIG.min_redeem_points,
      `reward #${r.id} '${r.name}' costs ${r.points_cost} < min ${CONFIG.min_redeem_points}`);
  }
});
check('R-3.3', 'loyalty tiers all sit at/above the min gate (loyalty screen tier rows)', () => {
  for (const t of CONFIG.tiers) {
    assert.ok(t >= CONFIG.min_redeem_points, `tier ${t} below min_redeem_points`);
  }
});

// ── R-4 — source-level: no stray redeem math outside the SSOT helpers ──
const MOBILE = path.resolve(process.cwd(), 'mobile');
const read = (f) => fs.readFileSync(path.join(MOBILE, f), 'utf8');
check('R-4.1', 'WizardRedeem debits exactly reward.points_cost (no local rate math)', () => {
  const src = read('components/WizardRedeem.jsx');
  assert.ok(src.includes('balanceBefore - reward.points_cost'), 'debit formula changed');
  assert.ok(!/redeem_ratio|\/\s*100\b/.test(src.replace(/\/\/[^\n]*/g, '')),
    'WizardRedeem must not re-derive €-value locally');
});
check('R-4.2', 'screens-main routes €-labels through the SSOT (no inline tier/redeem_ratio left)', () => {
  const src = read('screens-main.jsx');
  assert.ok(src.includes('window.LC.loyalty.redeemValueEuros(tier)'),
    'tier fallback label no longer routed through redeemValueEuros');
  assert.ok(!src.includes('tier / config.redeem_ratio'),
    'inline duplicate redeem math reintroduced in screens-main.jsx');
});

console.log('\nLoyalty redeem parity gate (B1-M)\n');
for (const r of results) console.log(r);
console.log(`\n  ${pass} pass · ${fail} fail\n`);
if (fail > 0) process.exit(1);
