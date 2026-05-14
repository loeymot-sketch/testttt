# Menu V3.1 — Burger composer + Double viande — Convergence Report 2026-05-14

Status : **GO** (P0+P1 = 0 across 2 consecutive clean rounds)
Branch : feature/mobile-app-le-cayenne-2026-05-10
Backup : `backup/pre-menu-v3-1-2026-05-14`
Frozen-zone NEW diff : **0 lines** (verified `git diff HEAD -- <13 frozen files>` = 0)
NF525 chain : untouched (no writes to fiscal_sequence_no / audit_logs / z_reports).

---

## Phase 1 — Heal V3.1 stats

Command : `MenuHealLightV31BurgerCommand` (signature `menu:heal-light-v3-1-burger`)

| Metric                  | Run 1 | Run 2 (idempotency) |
|-------------------------|-------|---------------------|
| double_viande_added     | 2     | 0                   |
| double_viande_skipped   | 0     | 2                   |
| composer_profiles_added | 2     | 0                   |
| composer_steps_added    | 6     | 0                   |
| composer_skipped        | 0     | 2                   |
| events_fired            | 5     | 0                   |

Built-in verification queries (Layer C) all green:

- `[OK] published profiles for burger items {375,490} = 2` (expected 2)
- `[OK] steps across both burger profiles = 6` (expected 6)
- `[OK] burger 375 step keys = [sauce,supplements,menu]`
- `[OK] burger 490 step keys = [sauce,supplements,menu]`
- `[OK] Double viande extras = 2 rows, price 2.50€` (expected 2 rows)

### Tradeoff documented (canonical convention wins over task literal)

The task specified `group_label='supplement_burger'`. Pre-flight DB inspection
revealed that Big Classique 489 (the working reference profile 83) uses step
`source_ref='supplement'` reading from `group_label='supplement'`. Items 375
and 490 already had 9 supplements (Cheddar/Œuf/Jambon/Boursin/Raclette/
Emmental/Légumes sautés/Oignon frais/Champignons) under `group_label=
'supplement'`. Adopting `supplement_burger` would have orphaned those 9 items
out of the wizard's supplement step.

→ Decision : use `group_label='supplement'` for `Double viande`. Owner spec
("Double viande visible alongside Cheddar/Jambon/etc.") satisfied; canonical
catalogue convention preserved across burger + sandwich families.

### Sauce attribute resolved

Owner-canonical `source_ref='sauce (1ère gratuite)'` with
`source_item_attribute_id=311` — same as Big Classique 489 step 257. Item 375
has 13 sauce variations on attr 311 (Mayonnaise, Ketchup, Algérienne,
Samouraï, Curry, Andalouse, Harissa, Hannibal, Blanche, Sauce fromagère maison,
Spicy, Barbecue, Ail). Item 490 has the same 13 sauces. Sauce step renders OK
both burgers.

---

## Phase 2 — Technical tests

### PHPUnit (filter `Menu|ItemCategory|PricingService|Wizard`)

`Tests:  12 skipped, 213 passed   Time: 32.36s` — full green.

### Vitest (kiosk-relevant specs)

| Spec                                | Tests | Verdict |
|-------------------------------------|-------|---------|
| kioskExtrasPartition.spec.js        | 16    | GREEN (was 15, +1 regression spec for Double viande supplement group) |
| kioskMenuStore.spec.js              | 7     | GREEN   |
| kioskMenuCache.spec.js              | 9     | GREEN   |
| kioskMenuBundledExtras.spec.js      | 7     | GREEN   |
| kioskComposerProfileChangeHandling  | 5     | GREEN   |
| TOTAL                               | 44/44 | GREEN   |

Pre-existing unrelated failure in `KioskWizard.spec.js` (tacos viande
narrative test) confirmed present BEFORE this heal — `git stash`
diff: 1 fail/96 pass → unchanged after my work (same 1 fail/97 pass under
broader filter). Not a regression.

### Mix build

`npm run dev` → "Mix: Compiled successfully in 9.33s, webpack compiled
successfully". Bundle includes the updated `kioskExtrasPartition.js` helper.

---

## Phase 3 — Visual capture + adversarial supervisor

7 surfaces × (PNG + DOM + console + network) = 28 quartet files captured at
`tests/e2e/__screenshots__/menu-v3-1/`. Spec: `tests/e2e/menu-v3-1-burger.spec.js`.

Convergence loop : **3 rounds total**, 2 consecutive clean.

### Round 1 (initial — found P0)

| Surface | What captured                                       | Verdict R1 |
|---------|-----------------------------------------------------|------------|
| S1      | Burgers grid 349 — 2 cards (Chicken Burger 6.90 + Chicken Burger Special 8.90) | PASS |
| S2      | Wizard 375 step 1 — Sauce, 13 choices, 4 dots, NO viande step | PASS |
| S3      | Wizard 375 step 2 — Suppléments                     | **P0 FAIL** Double viande absent |
| S4      | Wizard 375 step 3 — Menu (Combo / +Frites / Drink-only / Sans menu) | PASS |
| S5      | Wizard 474 Sandwich Cayenne — 4 viandes regression  | PASS |
| S6      | Wizard 478 Tacos M — 4 viandes regression           | PASS |
| S7      | Wizard 493 Bowl Frites curry — 2 sauces regression  | PASS |

### Root cause analysis (Round 1 → Round 2 heal)

DOM inspection + DB verification proved Double viande WAS in the composer
profile output (`ComposerProfileProjection::project` returned id=1209 in
supplements step choices). But the kiosk `KioskStepSupplementsComponent`
sources its row list from `partitionKioskExtras(item).supplements` — the
LEGACY partition helper, not the composer projection.

`partitionKioskExtras` was filtering Double viande into `viandesPaid` bucket
because `kioskIsViandePaidExtra` flagged it on name "viande" + price > 0.
That bucket is created but NOT consumed anywhere in the kiosk frontend → row
silently dropped.

### Heal applied (non-frozen helper)

File: `resources/js/helpers/kioskExtrasPartition.js` (helper, NOT in CLAUDE.md
§7 frozen list). Refined `kioskIsViandePaidExtra` so that an explicit
supplement `group_label` ('supplement', 'supplements', 'supplement_burger',
'supplement_bol', 'supp') wins over the name-based "viande" heuristic.

Companion test added in `tests/js/kioskExtrasPartition.spec.js`:
- Double viande w/ group=supplement → NOT viande-paid (heal v3.1)
- Double viande w/ group=supplement_burger → NOT viande-paid
- Double viande w/ group=viande → still viande-paid (explicit group wins)
- Double viande w/ no group → still viande-paid (name-only fallback)

### Round 2 — heal applied + Mix rebuild

| Surface | Verdict R2 |
|---------|------------|
| S1      | PASS — 2 burger cards visible |
| S2      | PASS — 4 dots (no viande step), 13 sauce choices |
| S3      | PASS — has_double_viande=TRUE, choices_count=16 (was 15) |
| S4      | PASS — Menu Combo / +Frites / Boisson seule / Sans menu visible |
| S5      | PASS — sandwich cayenne 4/4 viandes |
| S6      | PASS — tacos M 4/4 viandes |
| S7      | PASS — bowl 2 sauces (Spicy + Sauce fromagère maison) |

### Round 3 — convergence proof (set-equality with Round 2)

| Surface | Round 2          | Round 3          |
|---------|------------------|------------------|
| S2      | viande=F sauce=T 4 dots | viande=F sauce=T 4 dots |
| S3      | DV=T +2.50=T 16 ch    | DV=T +2.50=T 16 ch     |
| S4      | menu=T sans=T frites=T| menu=T sans=T frites=T  |
| S5      | viande=T 4/4 viandes  | viande=T 4/4 viandes    |
| S6      | viande=T 4/4 viandes  | viande=T 4/4 viandes    |
| S7      | spicy=T fromagere=T 2 ch | spicy=T fromagere=T 2 ch |

Identical findings R2 ≡ R3. **2 consecutive clean rounds confirmed.**

### Multimodal vision verification (PNG Read)

Owner-side observations from PNG read via Read tool:

- **S1** : Light orange palette, both burger cards rendered with product
  images (chicken_burger.png + chicken-big-burger-thumb.png), prices €6,90
  and €8,90, "2 produits" label correct.
- **S2** : 4-dot progress bar (QUELLE SAUCE / QUEL SUPPLÉMENT / QUEL MENU /
  RÉCAP). 13 sauce cards in 4×4 grid with photo thumbnails. "1re sauce
  gratuite" subtitle. NO viande step visible — owner spec confirmed.
- **S3** : Supplement step grid shows JAMBON DE DINDE €1,00 / BOURSIN €1,00
  / FROMAGE A RACLETTE €1,00 / ŒUF €1,00 / FROMAGE €1,00 / GALETTE POMMES
  DE TERRE €1,00 / CHEDDAR €0,90 / RACLETTE €0,90 / EMMENTAL €0,90 / ŒUF
  €0,90 / BOURSIN €0,90 / LÉGUMES SAUTÉS €0,90 / JAMBON €0,90 / OIGNON
  FRAIS €0,90 / CHAMPIGNONS €0,90 / **DOUBLE VIANDE €2,50** (confirmed in
  DOM: "Double viande €2,50"). 16 total options.
- **S4** : 4 menu cards in 2×2 layout — MENU COMPLET (Frites + Boisson) /
  + FRITES (Seulement les frites) / BOISSON SEULE (Seulement la boisson) /
  SANS MENU (Article seul). Helper text: "Touchez une option pour
  continuer — y compris « Sans menu » si vous ne voulez pas de formule."
- **S5** (Sandwich Cayenne) : 6 progress dots, step 1 "QUELLE VIANDE ?"
  with 4 cards (POULET MARINÉ / POULET CURRY / POULET TANDOORI / POULET
  CRISPY). Owner spec regression preserved.
- **S6** (Tacos M) : 3 progress dots, step 1 same 4 viandes. Regression OK.
- **S7** (Bowl Frites Poulet curry) : 4 progress dots, step 1 "QUELLE
  SAUCE ?" with 2 cards (SAUCE FROMAGÈRE MAISON / SPICY). Regression OK.

### Adversarial findings final

| Priority | Count | Notes |
|----------|-------|-------|
| P0       | 0     | (R1 P0 healed in R2) |
| P1       | 0     | |
| P2       | 0     | |
| P3       | 0     | i18n_leaks scan = empty across all 7 surfaces |

---

## Frozen-zone verification

| Frozen file (§7 CLAUDE.md)                                        | NEW diff (vs HEAD) |
|-------------------------------------------------------------------|---------------------|
| public/js/pos-wizard.js                                           | 0 |
| public/css/pos-wizard.css                                         | 0 |
| resources/views/admin-pos-v4.blade.php                            | 0 |
| resources/js/components/frontend/kiosk/KioskWizardComponent.vue   | 0 |
| resources/js/components/frontend/kiosk/KioskAppComponent.vue      | 0 |
| resources/js/components/frontend/kiosk/KioskUpsellComponent.vue   | 0 |
| app/Services/Fiscal/FiscalSequenceService.php                     | 0 |
| app/Services/Fiscal/ZReportService.php                            | 0 |
| app/Services/Fiscal/AuditLogService.php                           | 0 |
| app/Models/Scopes/BranchScope.php                                 | 0 |
| app/Http/Middleware/IdempotencyKeyMiddleware.php                  | 0 |
| app/Services/Pricing/PricingService.php                           | 0 |
| app/Domain/Order/OrderStateMachine.php                            | 0 |
| **TOTAL NEW DIFF**                                                | **0** |

Files modified (non-frozen, data + helper layer only):

- `app/Console/Commands/MenuHealLightV31BurgerCommand.php` (new, ~430 lines)
- `tests/e2e/menu-v3-1-burger.spec.js` (new, ~320 lines)
- `resources/js/helpers/kioskExtrasPartition.js` (heal: 7 lines added)
- `tests/js/kioskExtrasPartition.spec.js` (regression test: 9 lines added)
- Various Mix build outputs (auto-generated)
- DB writes (item_extras×2 + item_wizard_profiles×2 + item_wizard_steps×6)

---

## Final verdict

**GO** for Menu V3.1 burger composer heal.

- Both burger profiles created (375 + 490 each with 3 steps in canonical
  order [sauce, supplements, menu]).
- Double viande €2,50 visible in S3 PNG verified via Read tool (multimodal
  vision + DOM grep "Double viande €2,50").
- 4 viandes still render for Sandwich Cayenne (S5) + Tacos M (S6) — 0
  regression.
- 2 sauces still render for Bowl 493 (S7) — 0 regression.
- PHPUnit 213 pass / Vitest 44 pass on relevant specs.
- Frozen-zone NEW diff = 0 lines.
- 2 consecutive clean rounds R2≡R3.
- Idempotency proven (Run 2 = no-op, all metrics 0/skipped).

Owner mandate satisfied : **"WIZARD comme toujours"** — Sauce → Supp (with
Double viande +2,50€) → Menu, no viande step, Crispy implicit.
