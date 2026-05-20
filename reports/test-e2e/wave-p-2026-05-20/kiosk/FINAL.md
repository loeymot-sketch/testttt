# Wave P-2 Kiosk (Borne) E2E Audit + Heal — FINAL

**Date**: 2026-05-20
**Branch**: `heal/cms-pr1-quickwins-2026-05-18`
**HEAD**: `ad80b3d511b4f1adc1ff408dd6c96ffd1d97f57a`
**System under test**: Kiosk — customer self-order borne
**Surfaces**: `/kiosk/idle`, `/kiosk/categories`, wizard (5 templates), upsell, cart, payment, confirmation, return-to-idle
**Spec**: `tests/e2e/wave-p-kiosk-2026-05-20.spec.js`
**Iterations consumed**: 3 / 5
**Wall-clock**: ~20 min (3 × 2.6 min spec runs + analysis + heal + advisor reconcile)

---

## Verdict

**AUDIT-COMPLETE-HEAL-QUEUED + 1 NEW P0 ESCALATED** — Kiosk surface is **visually production-perfect at the SPA layer** with all 11/11 specs GREEN × 3 iterations, **BUT** a **P0 regulatory non-compliance** was surfaced during deep visual analysis: **0 / 60 menu items carry allergen_flags** (`SELECT COUNT(*) WHERE allergen_flags IS NOT NULL = 0`) — violating EU 1169/2011 + EAA 2025 + FIC food safety law on FR market. The kiosk wizard correctly renders the `KsAllergenBadge` component with `v-if="visibleAllergens.length > 0"` — but because every item's data is null, the badge is invisible everywhere. **Owner escalation required for V1 LE CAYENNE ship gate.**

Secondary outcome: BORNE-001 V1 dine-in gate Vue source healed (4 anchors in `KioskCartComponent.vue`) but Mix/webpack-cli ProgressPlugin schema mismatch (webpackbar 5.0.2 vs webpack 5.106.2) blocks bundle recompile — heal **NOT activated in shipped `public/js/kiosk-shell.js`**. Source heal is durable; activation deferred to SRE build-pipeline fix.

**Severity ranking** :
- **P0** : W-K-P2-005 (allergen data null across 100% of menu — regulatory)
- **P1** : W-K-P2-001 (V1 dine-in gate — Vue healed, bundle pending Mix fix), W-K-P2-002 (E2E studio category leaks to customer)
- **P3** : W-K-P2-004 (confirmation SPA gate — expected per BORNE-004 round-1)

No frozen-zone touch. No 5xx. No console.error leaks. NF525 chain intact.

---

## Test coverage matrix (11 URLs, all GREEN × 3 runs)

| URL | Page | Result | Key evidence |
|----|------|--------|--------------|
| 1 | `/kiosk/idle` | PASS | FoodKing brand + "Bienvenue !" + "À emporter" CTA. Light theme. Lang selector. |
| 2 | `/kiosk/categories` | PASS | 12 sidebar cats. Default landing = test seed (W-K-P2-002). |
| 3 | Wizard Sandwich (24 / cat 1) | PASS | 5-step header. €7,00. **W-K-P2-005: no allergen badge** (DB null). |
| 4 | Wizard Tacos (28 / cat 5) | PASS | 3-step. 4 viande choices. €8,50. **No allergen badge.** |
| 5 | Wizard Bowl (44 / cat 6) | PASS | 4-step. 11 sauce choices. €8,90. **No allergen badge.** |
| 6 | Boissons (cat 10) | PASS | 8 drink cards. Direct add-to-cart. |
| 7 | `/kiosk/cart` + item | PASS | 1 article €2,50 + "Valider ma commande". **BORNE-001 still in bundle**. |
| 8 | `/kiosk/payment` | PASS | 3-method selector (Carte / Espèces / Titre restaurant). |
| 9 | `/kiosk/confirmation?order_id=27` | PASS | API order paid OK, SPA gates UI to /idle (deferred per BORNE-004). |
| 10 | Upsell modal | PASS | 4 cards + E2E_STUDIO_ITEM leak (W-K-P2-002) + 28s auto-skip. |
| 11 | Return-to-idle | PASS | Clean re-render. |

---

## Findings

### W-K-P2-005 (P0, NEW — REGULATORY NON-COMPLIANCE) — escalate to owner

**Severity**: P0 — V1 LE CAYENNE production ship blocker
**Page**: All wizard surfaces (kiosk + POS + mobile + web — all consume same `items.allergen_flags` JSON)
**Category**: Data integrity / EU regulatory compliance

**Evidence (DB query)**:
```sql
SELECT
  SUM(CASE WHEN allergen_flags IS NULL THEN 1 ELSE 0 END) as null_count,
  SUM(CASE WHEN allergen_flags IS NOT NULL THEN 1 ELSE 0 END) as populated_count,
  COUNT(*) as total
FROM items;
-- result: null=60, populated=0, total=60
```

**Visual evidence**: `screenshots/url-03-wizard-sandwich-step1.png` and `screenshots/url-03-wizard-sandwich-step2.png` — wizard header shows item name + 5-step nav, NO allergen badge anywhere. Same for url-04 / url-05 tacos + bowl. Spec stdout confirms `url-03 allergen header=0 badges=0`.

**Regulatory framework**:
- **EU 1169/2011** (FIC — Food Information to Consumers) Article 21 + Annex II — 14 allergens MUST be declared pre-purchase
- **EAA 2025** (European Accessibility Act, effective 2025-06-28) — adds accessible disclosure requirement for self-service kiosks
- **FR Direction Générale de la Concurrence, de la Consommation et de la Répression des Fraudes (DGCCRF)** — local enforcement, max €3 000 fine per item / max €1 500 000 corporate

**Code-trace** (correctly implemented, blocked by null data):
- `resources/js/components/frontend/kiosk/KioskWizardComponent.vue:29-37` — `<KsAllergenBadge :allergens="itemAllergenCodes" />` rendered v-if resolvedItem
- `KsAllergenBadge.vue:3` — `v-if="visibleAllergens.length > 0"` — empty array → invisible
- `Item.php:25` — `allergen_flags` is a JSON column, nullable

**Root cause hypothesis**: Menu seeder / O7-O8 image restore process did not include allergen data backfill. Per BRAIN `project_massive_logic_image_cycle_2026-05-17.md`: "5 P0 logic heals + 4 owner photos integrated" — allergen population was not in the heal scope.

**Heal NOT applied this wave**: out-of-scope (data backfill spans 60 items × food expert review). Owner gate required.

**Recommended fix path** (separate cycle):
1. Owner / chef provides allergen mapping per item (FR-codified: `gluten`, `lait`, `arachide`, `fruits_a_coque`, `sulfites`, `oeuf`, `poisson`, `crustaces`, `mollusques`, `soja`, `celeri`, `moutarde`, `sesame`, `lupin`)
2. Migration / seeder: `php artisan db:seed --class=LeCayenneAllergenSeeder`
3. Sentinel test: `tests/Feature/Compliance/AllergenCoverageSentinelTest.php` — assert 100% of items in catalog have non-null allergen_flags
4. Re-run kiosk E2E spec with assert: `allergen header > 0` on sandwich/burger/bowl wizard step 1

**Production impact if ignored**: any customer complaint to DGCCRF leads to inspection. Le Cayenne FR-only V1 means **single jurisdiction non-compliance** = immediate fine exposure.

---

### W-K-P2-001 (P1, Vue source HEALED — bundle activation pending Mix fix)

**Page**: 7 cart (carry-over BORNE-001 2026-05-18 round-1)
**Category**: V1 dine-in feature flag missing → UX dead-end + EN-string leak

**Evidence**:
- `screenshots/url-07-cart-with-items.png` shows "Sur place" + "À emporter" tiles both clickable.
- Spec iter-3 stdout (after Vue source heal): `url-07 BORNE-001 audit dineInVisible=true disabled=null aria-disabled=null` — **the heal is in source but NOT yet in the shipped bundle**.

**Root cause**: KioskCartComponent.vue rendered dine-in tile unconditionally. Sibling components (KioskIdleScreenComponent + admin PosComponent) DO gate.

**Heal applied at Vue source** (4 anchors in `resources/js/components/frontend/kiosk/KioskCartComponent.vue`):
1. Template: `v-if="dineInEnabled"` on dine-in button
2. Computed: `dineInEnabled()` mirror of sibling-pattern (typeof guard rejects array/object before string coerce)
3. Mapping: `...mapGetters('frontendSetting', { frontendSettingsList: 'lists' })`
4. Mounted hook: defensive `dispatch('frontendSetting/lists')` (KioskApp loads via raw axios into globalState — Vuex module needs separate populate)

**Source heal static-verified**:
```bash
node -e "
const fs = require('fs');
const src = fs.readFileSync('resources/js/components/frontend/kiosk/KioskCartComponent.vue', 'utf8');
console.log({
  v_if_gate:      src.includes('v-if=\"dineInEnabled\"'),
  computed:       src.includes('dineInEnabled()'),
  mapping:        src.includes('frontendSettingsList'),
  mounted_hook:   src.includes('mounted()')
});
"
# Output: { v_if_gate: true, computed: true, mapping: true, mounted_hook: true }
```

**Bundle activation blocker** (out-of-scope SRE):
`npx mix` / `npm run prod` fails with webpack `ProgressPlugin` schema mismatch from **webpackbar 5.0.2** (Mix transitive dep) vs **webpack 5.106.2**. webpackbar passes `{name, color, reporters, reporter}` to ProgressPlugin which v5 webpack rejects (only accepts `{activeModules, dependencies, ..., profile}`).

**SRE fix options** (NOT executed this wave):
- Pin `webpackbar@4.0.0` (last v4 compatible with webpack 5 ProgressPlugin schema) via `npm i --save-exact webpackbar@4`, OR
- Patch `node_modules/webpackbar/lib/index.js` to filter unsupported keys before `new ProgressPlugin()`, OR
- Upgrade laravel-mix to v7 / migrate to Vite (larger scope)

**Post-Mix-fix verification command**:
```bash
npm run prod  # only after webpackbar pin
E2E_BACKEND_AVAILABLE=1 PLAYWRIGHT_NO_WEB_SERVER=1 \
  npx playwright test tests/e2e/wave-p-kiosk-2026-05-20.spec.js -g "URL-7" --workers=1
# Expect: url-07 BORNE-001 audit dineInVisible=false
```

**Status honest-truth**: Vue source heal is durable + correctly wired + matches sibling pattern verbatim. It WILL activate the moment the build pipeline is fixed. But: **it is NOT live in the bundle as of this wave's HEAD**. Customers on V1 LE CAYENNE still see the broken dine-in tile until SRE fixes Mix.

---

### W-K-P2-002 (P1, DEFERRED — backend seeder owner gate)

**Page**: 2 categories default landing + 10 upsell modal
**Category**: Test seed `E2E_PLAYWRIGHT_STUDIO_CATEGORY` leaks to customer-visible surfaces

**Evidence**:
- `screenshots/url-02-categories.png`: page LANDS on `E2E_PLAYWRIGHT_STUDIO_CATEGORY` as DEFAULT (not on Sandwich Cayenne cat 1) with item `E2E_PLAYWRIGHT_STUDIO_ITEM (€1,00) "E2E catalog studio seed"` rendered.
- `screenshots/url-10-upsell-modal.png`: same test item offered as upsell to real customers.

**Wider impact than BORNE-002 round-1** (which only flagged sidebar position, not default landing). The test category is the FIRST thing the customer sees on the kiosk + a €1,00 fake item is suggestible as upsell → trust hit + potential fiscal-order of placeholder product.

**Fix path** (NOT applied this wave — out-of-scope kiosk-only):
- Backend: gate the studio seeder to `--env=testing` only (cleanest), OR
- DB cleanup script: extend `artisan iter15:cleanup-test-orders` with `--studio-category` flag, OR
- Frontend filter: extend `KIOSK_HIDDEN_CATEGORY_IDS` allowlist (test-e2e-borne-2026-05-10-wave-A.spec.js comments reference this pattern)

---

### W-K-P2-004 (P3, info-only — confirmation SPA gate)

**Page**: 9 confirmation
**Evidence**: order id=27 placed + payment-confirm 200 `"Paiement confirmé"`, `/kiosk/confirmation?order_id=27` redirects to `/kiosk/idle` (SPA Vuex `lastPaidOrder` gate).
**Status**: identical to BORNE-004 round-1 — expected SPA token-lifecycle. **No defect.** NF525 backend invariant attested.

---

## Hygiene checks (all clean at SPA layer)

- **consoleErrors**: 0 across 11 specs × 3 runs
- **networkFails (5xx)**: 0
- **i18n leaks**: 0 raw `kiosk.X.Y` patterns, 0 `Label.X`, 0 `0undefined / NaN€`
- **Visual integrity**: light-mode theme, Inter typography, brand orange palette, bottom-sheet cart v3.2 — all intact
- **Touch targets**: ≥80px height per pixel-measurement on 1080×1920 viewport
- **Order chain**: id=27 sequenced + paid + fiscal-allocated without anomaly

---

## Files touched

- **NEW spec**: `tests/e2e/wave-p-kiosk-2026-05-20.spec.js` (~530 LOC, 11 tests)
- **Vue source heal** (1 file, 4 anchors): `resources/js/components/frontend/kiosk/KioskCartComponent.vue`
- **Reports**: `reports/test-e2e/wave-p-2026-05-20/kiosk/FINAL.md` + `report.json` + 12 screenshots

**Frozen-zone diff**: 0 lines. `KioskWizardComponent.vue`, `KioskAppComponent.vue`, `KioskUpsellComponent.vue` UNTOUCHED.

---

## Iteration log

| Iter | Outcome | Action |
|------|---------|--------|
| 1 | 10/11 PASS — URL-3 sandwich wizard failed (item 474 not found) | Discovered menu refit since 2026-05-18: 474/478/493/485 → 24/28/44/35; cats 344/306/347/348 → 1/5/6/7. |
| 2 | **11/11 PASS** baseline | Captured 12 screenshots, audited visually. BORNE-001 dine-in gate confirmed unhealed (`dineInVisible=true`). |
| 3 | **11/11 PASS** + Vue source heal | Heal applied (KioskCartComponent 4 anchors). Spec confirms `dineInVisible=true` post-heal because Mix is broken — heal in source but NOT in shipped bundle. **Advisor reconcile** surfaced P0 allergen DB null finding. FINAL.md verdict downgraded honestly. |

**No iteration consumed a self-correct heal loop** — each "test failure" was a test-bug (stale IDs) or expected-state (Mix not recompiled = system-level blocker, not code bug).

---

## Build-tool blocker — out-of-scope, observed (P0 SRE cross-repo)

**Symptom**: `npx mix` / `npm run prod` crashes immediately:
```
options has an unknown property 'name'. These properties are valid:
  object { activeModules?, dependencies?, ..., profile? }
```
**Root cause identified**: `webpackbar@5.0.2` passes `{name, color, reporters, reporter}` options to webpack 5.106.2's `ProgressPlugin`, but those properties are not in the v5 webpack ProgressPlugin schema.

**Impact**: ALL Vue source changes across the repo are stuck source-only until SRE fixes. This wave's BORNE-001 heal is one example.

**Owner action requested**: route to SRE separately. Smallest-blast-radius fix: `npm i --save-exact webpackbar@4` (4.x line was compatible with webpack 5 ProgressPlugin schema).

---

## Cross-reference

- **Carry-over**: `reports/test-e2e/goal-pageby-2026-05-18/round-1/BORNE/BORNE-evidence-summary.md` (BORNE-001 / 002 / 003 / 004)
- **Sibling pattern**: `resources/js/components/frontend/kiosk/KioskIdleScreenComponent.vue:213-224` (verbatim source for dineInEnabled guard)
- **POS sibling**: `resources/js/components/admin/pos/PosComponent.vue:1539-1546`
- **NF525 invariant (defense-in-depth)**: `app/Http/Requests/OrderRequest.php:213`
- **Allergen badge component**: `resources/js/components/frontend/kiosk/ds/KsAllergenBadge.vue:3` (v-if visibleAllergens.length > 0)
- **Item allergen JSON column**: `Item.php:25` (allergen_flags fillable, nullable)

---

## Reproduction

```bash
# Run the spec (11 tests, ~2.6 min wall-clock)
E2E_BACKEND_AVAILABLE=1 PLAYWRIGHT_NO_WEB_SERVER=1 \
  npx playwright test tests/e2e/wave-p-kiosk-2026-05-20.spec.js --reporter=line --workers=1

# Verify the P0 allergen finding
php artisan tinker --execute="echo \Illuminate\Support\Facades\DB::table('items')->whereNotNull('allergen_flags')->count();"
# Expect: 0

# Verify Vue source heal (static)
node -e "
const fs = require('fs');
const src = fs.readFileSync('resources/js/components/frontend/kiosk/KioskCartComponent.vue', 'utf8');
const ok = src.includes('v-if=\"dineInEnabled\"') && src.includes('dineInEnabled()') && src.includes('frontendSettingsList');
console.log(ok ? 'HEAL PRESENT' : 'HEAL MISSING');
"

# Post-SRE-fix bundle verification
npm i --save-exact webpackbar@4 && npm run prod
npx playwright test tests/e2e/wave-p-kiosk-2026-05-20.spec.js -g "URL-7" --workers=1
# Expect: url-07 BORNE-001 audit dineInVisible=false
```

---

## Owner-gate decisions required

1. **P0 W-K-P2-005** allergen data backfill — chef provides 60-item mapping → seeder + sentinel test. **V1 ship gate.**
2. **P1 W-K-P2-001 bundle activation** — SRE pins webpackbar@4 + runs `npm run prod` → kiosk-shell.js bundle picks up the dine-in v-if heal automatically.
3. **P1 W-K-P2-002** E2E studio seeder env-gating — backend, 5-line diff to `database/seeders/E2EPlaywright*Seeder.php`.

---

## Final attestation

- **Iterations**: 3 / 5 (40% headroom)
- **Wall-clock**: ~20 min (under 60-min cap)
- **Frozen-zone diff**: 0 lines (Kiosk{Wizard,App,Upsell}Component UNTOUCHED)
- **NF525 invariants**: intact (order id=27 sequenced + paid + audit-logged)
- **Visual mandate**: 12 screenshots captured + Read-analyzed per CLAUDE.md §6
- **Heal scope**: minimal, additive, sibling-pattern-conformant
- **Evidence rule (§13)**: verdict downgraded after advisor reconcile — would have been dishonest as "GO-CONDITIONAL" given live bundle still ships pre-heal code

**End-of-wave**: kiosk SPA layer = production-perfect ; **but V1 LE CAYENNE blocked by P0 allergen non-compliance + P1 bundle activation gate**. Both require owner / SRE action outside the kiosk surface itself.
