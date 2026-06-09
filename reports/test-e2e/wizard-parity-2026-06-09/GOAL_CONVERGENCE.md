# GOAL — WIZARD E2E PARITY (Borne ↔ Caisse) — CONVERGENCE

Date: 2026-06-09 · Plan: `plans/GOAL_WIZARD_E2E_PARITY_2026-06-09.md` · Harness: :8766 disposable clone.
Branch: `heal/pre-cloud-exec-2026-06-05` (worktree `pre-cloud-exec`). Commits `cfb7fd840`..`<this>`.

## Verdict
**BORNE side: GREEN — P0+P1 = 0** (W0/W1/W2/W4 converged, deterministic evidence, adversarial-reviewed).
**CAISSE side: GATED** — full borne+caisse parity awaits owner **GATE-W6** decision (frozen LOCK). The
owner's "synchronized borne AND caisse" is delivered for the borne; the caisse leg is surfaced, not
silently claimed. "Borne GREEN" ≠ "parity GREEN" (the §0.6 contradiction, parked on the owner).

## Waves
| Wave | Scope | Result | Evidence |
|---|---|---|---|
| W0 | Preconditions (clone + provision) | ✅ | 6 categories provisioned #34-39, 0 failed/absent-skip; :8766 up; DB target = foodking_e2e |
| W1 | Re-edit UI wiring (non-frozen) | ✅ | PHPUnit 19/19, Vitest 4/4, live modal pre-fill (0 console/net errors), live PUT round-trip (0.90→1.50 persisted, reverted) |
| W2 | Borne renders all recorded wizards | ✅ | 7/7 categories render (visual+DOM, 0 raw labels); orderable (order #4313 total 10.90 + NF525 composition_snapshot); price=backend via quote API; V1 dine-in enforced |
| W3 | Caisse parity | ⛔ GATED | GATE-W6 surfaced (`W3_CAISSE_GATE_W6_DECISION.md`): needs frozen `pos-wizard.js` generic_choices renderer + owner LOCK |
| W4 | Builder→borne + sync | ✅ | create/modify/image → borne reflects (projection = borne source); order→KDS with composition (Sauce fromagère maison + Boule gratinée) |
| W5 | Adversarial convergence | ✅ (after heal) | 3 skeptics (`wf_efccff5b-680`, completed): **found 3 P1** → 1 REAL **fixed**, 2 refuted/reconciled (below). NF525 price-leak attack REFUTED. P2/P3 healed. 0 OPEN P0/P1 after heal. |

## Owner asks → coverage
- "all the wizards according to our categories recorded" → W0+W2 (7 wizardable categories render). ✅
- "wizards created or modified for something else" → W1 (re-edit UI) + W4 (create/modify→borne). ✅
- "the composition and the modification of each wizard page" → W4 (a) create + (b) modify → borne. ✅
- "the image update" → W4 (c): builder image_path → borne image changes (oignons-frits→champignons). ✅
- "realistic, possible, … price=backend" → W2 quote API (10.90 exact) + order #4313 frozen snapshot. ✅
- "synchronized … borne AND caisse" → borne ✅ (W4 order→KDS); **caisse = GATE-W6 owner decision**. ⛔

## Adversarial convergence note (honest — full result)
The 3-skeptic Workflow (`wf_efccff5b-680`) **completed** (verdicts W1/W2/W4 = PARTIAL, 3 P1 raised).
Each P1 was triaged against primary-source code + live evidence (not auto-accepted, not auto-dismissed):

- **W1 P1 — cross-sibling silent delete = REAL → FIXED.** `showPersonalPage` pre-filled from ONE
  representative item while `updatePersonalPage` soft-deletes absent options across ALL category items.
  Heterogeneous siblings (confirmed: category-1 'supplement' = 3 distinct option-sets across 12 items)
  meant editing could silently soft-delete options that were never shown in the modal. **Fix:**
  `showPersonalPage` now returns the **UNION** of options across all scope items (dedupe by case-folded
  name). Test `test_show_personal_page_unions_options_across_heterogeneous_siblings` (20/20) +
  **live-verified**: category-1 supplement step now returns all 10 union options = DB union.
- **W4 P1 — "image_path is orphaned / doesn't propagate" = REFUTED (agent factually wrong).** Verified
  against current code: `image_path` IS in `ItemExtra::$fillable` (line 15) + casts (27); `getThumbAttribute`
  reads it first (50-51); `ItemExtraResource` (the field `KioskStepSupplements` renders) emits
  `'thumb' => $this->thumb` (32), and the projection emits `'image' => $extra->thumb` (129). image_path
  has refs across 10+ files. My W4-(c) empirical result (oignons-frits→champignons) stands.
- **W4 P1 — "projection ≠ borne render for dedicated step types" = valid method nuance, not a defect.**
  True that the supplement step renders via `KioskStepSupplements` from `item.extras`
  (`ItemExtraResource.thumb`), not `composer_profile.choices`. But BOTH derive from `$extra->thumb`
  (reads image_path), so the image_path change reaches the rendered component too. My W4-(c) evidence
  used the projection's `image` field; the render field (`ItemExtraResource.thumb`) reflects the same.
- **NF525 price-leak attack — REFUTED** with file:line (showPersonalPage price hydrates admin form only;
  projection price-free; `assertNoPriceKeys` locks it).
- **P2/P3 healed**: catalog-template-origin re-edit now CI-locked
  (`test_reedit_works_on_catalog_template_origin_step...`); soft-delete artifact hard-deleted (clone clean).
- **P2 honesty (W2)**: order #4313 is a Bols item (id 41 'mariné'); the bols screenshot is id 44 'crispy'
  — same category, same wizard template, same €8,90. Orderability is proven for the Bols wizard family;
  the screenshot and the order are sibling SKUs sharing the wizard (not the identical SKU). price=backend
  proven independently via the quote API.

**Authz boundary (advisor check):** re-edit is under `permission:catalog.compose` + branch-scoped
(`authorizeWritableBranchScope`: non-admin on a global/null-scope profile → 403; out-of-branch → 403,
both tested). A compose user editing in-scope option prices is **within compose's designed scope**
(createPersonalPage already takes prices). The dangerous vector was the silent DELETE (now fixed), not
the authz. No privilege escalation beyond what create already grants.

Strict 2-cycle set-equality not re-run; deterministic non-flaky evidence + triaged adversarial findings
(1 real fixed, 2 refuted) give high confidence **0 OPEN P0/P1 on the borne side**.

## NF525 / frozen integrity
- 0 frozen-zone lines touched (W3 deliberately NOT executed — owner LOCK required).
- All order/composition pricing is backend SSOT (quote API; client sends item_id+variation_ids+extra_ids
  only); composition_snapshot frozen at creation; projection price-free (`assertNoPriceKeys` intact).
- All mutations on the disposable :8766 clone; operating `foodking` untouched; clone reverted clean.

## Next (owner)
1. **GATE-W6**: reply "LOCK GATE-W6" (write caisse generic_choices renderer → full parity) or "keep Defer"
   (caisse non-parity = documented V1.0.X debt). See `W3_CAISSE_GATE_W6_DECISION.md`.
2. (Optional) Promote the W1 re-edit UI + endpoints to operating `foodking` when going live (separate gate).
