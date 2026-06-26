# RED-team verification — CARTE V2 "Viande 1/2" → "Choix" (slot label loss + inter-surface divergence)

**Verdict: CONFIRMED P3** (finding self-classified P3 — correct). NON-frozen heal valid.

## [P3] resources/js/helpers/kdsCustomization.js:24-41,277-295 — CARTE V2 buckets "Viande 1/2" into generic "Choix"

### repro (re-executed, all green)
- DB (foodking_e2e, `deleted_at IS NULL`): order_items id=4933/4924/4911/4878 carry
  `attribute_name="Viande 1"/"Viande 2"` in `composition_snapshot.lines`.
  Worst case id=4875 = TWO IDENTICAL meats: `Viande 1=Mexicanos`, `Viande 2=Mexicanos`.
- Board default = V2 card: `useV2Layout()` returns true by default
  (KitchenDisplaySystemComponent.vue:1268/1300/1303). KdsV2Grid renders `KdsOrderCard`,
  KdsOrderCard.vue:390 `renderItemLines` → `renderItem(item).lines`.
- `renderItem` calls `classifyGroup(v.variation_name, v.attribute_name)` which concatenates
  `"Cordon Bleu Viande 1"`; NO `GROUP_PATTERN` (l.24-31) matches "Viande" → 'other'. Both meats
  fall into the same `other` bucket → single line `{group:'other', label:'Cordon Bleu, Fricadelle'}`.
- KdsOrderLine.vue:29-34 + groupLabel(l.96-103) renders `kds_group_other`="Choix"
  (resources/js/languages/fr.json:811). Chef reads: **"Choix : Cordon Bleu, Fricadelle"**.

### evidence (Vitest probe on REAL snapshots, PASS)
- CARTE V2 (distinct meats, id=4933): `[{group:'other',label:'Cordon Bleu, Fricadelle'},{group:'sauce',label:'Samouraï'},{supplement:'+ Cheddar'}]`
- CARTE V2 (identical meats, id=4875): `[{group:'other',label:'Mexicanos, Mexicanos'},{group:'sauce',label:'Mayonnaise'}]` — **NOT fused, both listed, count preserved.**
- items-board divergent path `kdsVariationLine`: `['Viande 1: Cordon Bleu','Viande 2: Fricadelle','Sauce (1ère Gratuite): Samouraï']` — DIVERGENCE proven.
- Existing test kdsCustomization.spec.js:72 covers ONLY legacy `variation_name`=GROUP (Pain/Crudités/Sauce); the snapshot `attribute_name`="Viande N" shape through `renderItem`/`classifyGroup` is UNCOVERED.

### why P3 (not P0/P1) — adversarial severity test
Brief P0/P1 triggers = compo illisible / inversée / **2-viandes-fusionnées** / allergène-masqué.
- Distinct meats → BOTH shown distinctly. Identical meats → BOTH shown (not collapsed). NO fusion.
- Compo legible (2 meats + sauce + Cheddar +0,90 all present), not inverted, allergen path untouched.
  Chef knows exactly what to prepare; for a Tacos/Sandwich the slot ordinal carries no prep meaning.
- Only loss = the generic slot LABEL + inter-surface label divergence = cosmetic/fidelity → **P3**.

### lentille: cuisinier (fidelity degraded, NOT a failed order)

### reco (NON-frozen — kdsCustomization.js editable; source-of-compo untouched, display-only)
Option A (preferred, kills divergence): in the snapshot shape, use the real `attribute_name` as the
group instead of re-classifying by keyword → aligns CARTE V2 with `kdsVariationLine`/items-board.
Option B: add `{ key:'meat', re:/\bviande\b/i }` to GROUP_PATTERNS + i18n `kds_group_meat`="Viande"
(no `meat` key exists today — verified). TDD: create `tests/js/kdsTwoMeatsDistinctRender.spec.js`
asserting the snapshot Viande1/Viande2 shape (line-72 test does not cover it).
