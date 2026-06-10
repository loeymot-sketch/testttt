// Source-pattern gate (proxy for the DOM-interface layer when a browser isn't
// installable). Asserts the buggy CONSUMER pattern is gone and the correct one
// present, for findings whose fix lives in JSX/HTML (not the data layer).
// The full VISUAL (V) capture is the Playwright specs under tests/mobile-e2e/.
//
// Run: node mobile/tests/node/source-assert.test.mjs   (from repo root /app)

import assert from 'node:assert';
import fs from 'node:fs';
import path from 'node:path';

const M = (f) => fs.readFileSync(path.resolve(process.cwd(), 'mobile', f), 'utf8');
const indexHtml = M('index.html');
const screensMain = M('screens-main.jsx');
const itemSteps = M('screens-item-steps.jsx');
const shared = M('shared.jsx');
const screensModals = M('screens-modals.jsx');

let pass = 0, fail = 0;
const results = [];
const check = (id, desc, fn) => {
  try { fn(); pass++; results.push(`  PASS  ${id}  ${desc}`); }
  catch (e) { fail++; results.push(`  FAIL  ${id}  ${desc}\n          → ${e.message}`); }
};
const has = (s, sub) => s.includes(sub);

// ── T-1.2 (F3) — ScreenCart go wrapper must forward the 2nd arg ──
check('T-1.2', 'ScreenCart go wrapper forwards ext (no arg-dropping single-param wrapper)', () => {
  assert.ok(!has(indexHtml, "go={(s)=> s==='confirm' ? go('pay') : go(s)}"),
    'single-param wrapper still drops go("item", id) — ext not forwarded');
});

// ── T-1.3 (F10) — order id must be collision-checked against existing ids ──
check('T-1.3', 'snapshotOrder guards order-id against existing LC.orders ids', () => {
  assert.ok(!/'C-' \+ Math\.floor\(1000 \+ Math\.random\(\) \* 9000\)\s*;?\s*\n?\s*const total/.test(indexHtml)
    || /findById|existingIds|while\s*\(|already|orders\.(active|history)/.test(indexHtml.slice(indexHtml.indexOf('snapshotOrder'), indexHtml.indexOf('snapshotOrder') + 600)),
    'order-id generation has no collision guard against seeded ids');
});

// ── T-2.2 (F9) — cart loyalty banner must be computed, not hardcoded ──
check('T-2.2', "cart banner does not hardcode '153 pts pour ton burger gratuit'", () => {
  assert.ok(!has(screensMain, '153 pts pour ton burger gratuit'),
    'hardcoded contradictory progress copy still present');
});

// ── T-3.2 (F11) — formule-drink allergen lookup must use a slug map ──
check('T-3.2', 'drinkId allergen path maps id→slug before catalog lookup', () => {
  const agg = itemSteps.slice(itemSteps.indexOf('aggregatedAllergens'), itemSteps.indexOf('aggregatedAllergens') + 1400);
  const drinkBlock = agg.slice(agg.indexOf('selections.drinkId'), agg.indexOf('selections.bolDrinkId'));
  assert.ok(!/find\(x => x\.slug === selections\.drinkId \|\| x\.id === selections\.drinkId\)/.test(drinkBlock),
    'drinkId path still does a raw slug/id match (no drinkSlugMap) — d-coca never resolves to coca');
});

// ── T-4.2 (F5) — cart upsell must use canonical slugs ──
check('T-4.2', "cart upsell filter does not use non-existent 'sides'/'drinks' slugs", () => {
  assert.ok(!has(screensMain, "i.cat === 'sides' || i.cat === 'drinks'"),
    "upsell still filters on 'sides'/'drinks' which match no canonical slug");
});

// ── T-4.3 (F8) — featured card must not reference the fictional slug ──
check('T-4.3', "Home featured card does not call findItem('tacos-xxl')", () => {
  assert.ok(!has(screensMain, "findItem('tacos-xxl')"),
    "featured card still references fictional slug 'tacos-xxl' (silently falls back to items[0])");
});

// ── T-5.1 (F6) — sauce step must render a selectable 'Sans sauce' option ──
check('T-5.1', "sauce step renders a 'Sans sauce' choice (not just toggle logic)", () => {
  const sauceFn = itemSteps.slice(itemSteps.indexOf('function ScreenStepSauce'), itemSteps.indexOf('function ScreenStepCrudites'));
  // The render must surface SANS_SAUCE, not only handle it in toggle().
  const renderPart = sauceFn.slice(sauceFn.indexOf('return ('));
  assert.ok(/SANS_SAUCE|s-sans|Sans sauce/i.test(renderPart),
    "'Sans sauce' is handled in toggle() but never rendered → unselectable");
});

// ── T-5.2 (F12) — image fallback emoji must be visible when shown ──
check('T-5.2', 'Slot fallbackEmoji is not rendered at opacity 0.001 (invisible)', () => {
  const slotFn = shared.slice(shared.indexOf('function Slot'), shared.indexOf('function Slot') + 1400);
  assert.ok(!/fallbackEmoji[\s\S]{0,400}opacity:\s*0\.001/.test(slotFn),
    'fallbackEmoji span still uses opacity:0.001 (invisible despite onError relying on it)');
});

// ── T-1.1 (F1, G1) — promo discount lifted to App and applied to the charge ──
check('T-1.1', 'promo discount lifted to App and reaches the charge (was display-only)', () => {
  assert.ok(has(indexHtml, 'const [promoCode, setPromoCode] = useState'), 'promoCode not lifted to App state');
  assert.ok(has(indexHtml, 'const discount = promoCode ? Math.round(subtotal * 10) / 100 : 0;'),
    'snapshotOrder/total does not apply the promo discount');
  assert.ok(has(indexHtml, 'promoCode={promoCode} setPromoCode={setPromoCode}'), 'ScreenCart not wired to App promo state');
  assert.ok(!has(screensMain, 'const [promoCode, setPromoCode] = uS(null)'),
    'ScreenCart still owns promo locally → discount never reaches the charge');
  // [RED-fix] billing subtotals must use price*qty, not the stale add-time lineTotal.
  assert.ok(!has(indexHtml, 'i.lineTotal'), 'index.html billing still references stale lineTotal');
  assert.ok(!/const subtotal = cart\.reduce\(\(s, i\) => s \+ \(i\.lineTotal/.test(screensMain),
    'ScreenCart subtotal still uses stale lineTotal (undercharge after qty change)');
});

// ── T-2.1 (F2 → SSOT 2026-06-10) — every points preview routes through loyalty.pointsFor()
// (config-driven, owner-canonical 1 pt/€) instead of any inline Math.round/ratio math.
check('T-2.1', 'point previews route through the pointsFor() SSOT helper', () => {
  assert.ok(has(screensMain, 'pointsFor(total)} pts gagnés sur cette commande'),
    'cart preview must use loyalty.pointsFor (SSOT)');
  assert.ok(!has(screensMain, '+{Math.round(total || 0)} pts gagnés'),
    'cart preview must not inline Math.round');
  assert.ok(has(indexHtml, 'pointsFor(lastOrder.total)'),
    'points-gain modal must use loyalty.pointsFor (SSOT)');
  assert.ok(has(screensModals, 'pointsFor(total)} pts crédités'),
    'order-detail credit must use loyalty.pointsFor (SSOT; data gate enforces carte==détail)');
});

console.log('\n=== Le Cayenne — consumer source-pattern gate ===\n');
console.log(results.join('\n'));
console.log(`\n  ${pass} pass · ${fail} fail\n`);
process.exit(fail > 0 ? 1 : 0);
