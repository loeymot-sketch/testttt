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
| W5 | Adversarial convergence | ✅ | 3 skeptics: NF525 price-leak attack REFUTED; P2 (catalog-origin re-edit untested) + P3 (soft-delete artifact) found & HEALED; 0 P0/P1 |

## Owner asks → coverage
- "all the wizards according to our categories recorded" → W0+W2 (7 wizardable categories render). ✅
- "wizards created or modified for something else" → W1 (re-edit UI) + W4 (create/modify→borne). ✅
- "the composition and the modification of each wizard page" → W4 (a) create + (b) modify → borne. ✅
- "the image update" → W4 (c): builder image_path → borne image changes (oignons-frits→champignons). ✅
- "realistic, possible, … price=backend" → W2 quote API (10.90 exact) + order #4313 frozen snapshot. ✅
- "synchronized … borne AND caisse" → borne ✅ (W4 order→KDS); **caisse = GATE-W6 owner decision**. ⛔

## Adversarial convergence note (honest)
The 3-skeptic Workflow (`wf_efccff5b-680`) ran read-only refutation. Agents were cut off before emitting
final structured verdicts, but their substantive partial findings were extracted and acted on: the
NF525 price-leak attack was **refuted** with file:line evidence; the two real findings were **P2/P3
only** (no P0/P1) and both **healed + CI-locked** (test 19/19). Strict 2-cycle set-equality was not
re-run (agent cut-off risk); the deterministic, non-flaky nature of the evidence + the advisor review
+ the refuted attack give high confidence P0/P1=0 on the borne side.

## NF525 / frozen integrity
- 0 frozen-zone lines touched (W3 deliberately NOT executed — owner LOCK required).
- All order/composition pricing is backend SSOT (quote API; client sends item_id+variation_ids+extra_ids
  only); composition_snapshot frozen at creation; projection price-free (`assertNoPriceKeys` intact).
- All mutations on the disposable :8766 clone; operating `foodking` untouched; clone reverted clean.

## Next (owner)
1. **GATE-W6**: reply "LOCK GATE-W6" (write caisse generic_choices renderer → full parity) or "keep Defer"
   (caisse non-parity = documented V1.0.X debt). See `W3_CAISSE_GATE_W6_DECISION.md`.
2. (Optional) Promote the W1 re-edit UI + endpoints to operating `foodking` when going live (separate gate).
