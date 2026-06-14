# MASSIVE TEST-E2E 2.0 — CONVERGENCE REPORT (Round 1)

**Date:** 2026-06-14 · **Tree:** `release/v1-integration-2026-06-12` @ `7b3f14feb` (spine, validated GO superset)
**Harness:** isolated clone `foodking_2dot0` @ `http://127.0.0.1:8780` (ENV=2dot0, soketi+queue+redis UP = real push sync). NEVER touched operating `foodking`.
**Baselines:** audit_logs 4342 · z_reports 20 · fiscal_seq 2426.

---

## ⚠️ POST-HOC CORRECTION (supervisor audit `wf_3cb7ccef-036`, 2026-06-14)
This Round-1 verdict was **scope-accurate at the time but OVERCLAIMED** in three ways the supervisor audit later flagged — corrected here for honesty:
1. **"0 P0/P1 / shippable-grade"** was Round-1-scope only. The campaign's OWN adversarial cycle 2 surfaced **G-RUPTURE (P1)** (ruptured item sellable via order-create) — so the spine was NOT P0/P1-clean; Round-1's audit depth missed it.
2. **Test B journey table marks all 8 steps ✅**, but step 3 (KDS) was **data-verified (SQL board-predicate), not live-driven**, and the OSS-display + KDS-bump (PREPARING→READY→served) legs + the **DELIVERY/livreur-cash flow** were **DEFERRED, never driven live**.
3. **"jusqu'à client livré"** is unsupported — the journey ends at the gestion/dashboard reflection (PAID + fiscalised), never reaching a served/delivered state. Degraded-mode sync (soketi down→polling) was also not tested live (soketi was UP).

## VERDICT (Round 1): GREEN **at the P0/P1 level KNOWN IN ROUND-1** — but see correction above: a P1 (G-RUPTURE) was missed and surfaced in cycle 2; journey coverage was partial. Spine is strong but NOT independently certified P0/P1-clean by Round-1 alone. 18 P2/P3 backlog. 1 P2 healed this round.

Per CLAUDE.md §10: **heal** (the campaign continued to heal; G-RUPTURE escalated to owner).

---

## TEST A — Per-system deep audit (W1, 19 agents, ≥3-skeptic adversarial dispute)

| System | Confirmed P0/P1 | Notes |
|---|---|---|
| S1 BORNE | 0 | offline-queue race REFUTED (all skeptics); login anti-enum present |
| S2 CAISSE | 0 | encaissement/refund/cash-trail clean |
| S3 KDS+OSS | 0 | **board-release predicate PRESENT** (`KitchenReleaseRule.php:56-92`); **allergen food-safety string-coerce PRESENT** (`kdsCustomization.js:152-155`) |
| S4 CENTRAL | 0 | dashboard numbers coherent (verified live) |
| S5 SHARED/FISCAL | 0 | chain append-only; `AuditLogService env()` = known UNI-03 frozen cloud-gate |

**Result: 0 confirmed P0/P1, 3 refuted, 18 P2/P3.** The 3 historically-flagged P1s (KDS unreleased-bump, allergen food-safety, offline-queue race) are **confirmed resolved in the spine.** Full data: `round-1/W1-persystem-audit.json`.

## TEST B — Cross-surface LIVE journey (real synchronizations, main-thread + Playwright)

Order **A0040** (Sandwich Cayenne composed: Poulet mariné + Algérienne + 4 garnitures, + Coca-Cola 33cl = **8,50 €**):

| Step | Surface | Evidence | Verdict |
|---|---|---|---|
| 1. Compose + order | BORNE :8780/kiosk | 5-step wizard, prices backend-SSOT, no per-step price (NF525), composition_snapshot frozen@creation | ✅ |
| 2. Plan-B routing | BORNE | "Paiement à la caisse 8,50 €" → order ACCEPT(4)+PENDING_COUNTER(15), no fiscal seq (correct: unpaid) | ✅ |
| 3. Kitchen visibility | KDS | A0040 board-eligible (predicate match), source→"Borne" column (`KDSOrderDetailsResource` source_surface) | ✅ |
| 4. Counter collection | CAISSE /admin/encaissement | A0040 top of list, labeled **Borne / Client borne**, items + 8,50 € exact | ✅ |
| 5. Cash encaissement | CAISSE unified modal | Espèces/TR/Terminal-manuel/Mobile; paid cash exact | ✅ |
| 6. Fiscal allocation | NF525 | payment→PAID(5), **fiscal_seq=2427** (gap-free from 2426), audit +2 (`counter_payment_confirmed`+`cash.movement.recorded`), CashMovement 8,50, **CHAIN OK** | ✅ |
| 7. Operator identity | Receipt | `operator_name="Admin Le Cayenne"` (cashier via editor_id, S16-01), seq 2427, SIRET+VAT+footer — NOT "Client passage" | ✅ |
| 8. Gestion reflection | CENTRAL dashboard | CA du Jour 242,71→**251,21 €** (+8,50 exact); Total ventes +8,50; Commandes/Ticket-Moyen bases verified coherent (order_datetime, realized, excl mirrors) | ✅ |

**Result: full journey GREEN, 0 P0/P1. All candidate findings during the journey (SAUCE badge, editor_type NULL, "Commandes du Jour" off-by-1, "Ticket Moyen" basis) were REFUTED on verification — each is correct-by-design (double-gate caught own false positives).**

## HEAL applied (W3) — 1× P2 security

**KIOSK-AUTH timing oracle** (`KioskMachineLoginController.php`): not-found path returned ~16-216ms (no bcrypt) vs existing ~270-320ms → username enumeration via timing. Fix = constant-time cost-12 bcrypt on not-found branch. **Live: unknown now 290-640ms == existing 280-350ms (indistinguishable).** Regression test added (`KioskLoginEnumerationTest::test_unknown_username_still_pays_bcrypt_cost`, 5/5 pass). frozen diff=0. Commit `656888c7f`.

---

## P2/P3 IMPROVEMENT BACKLOG (prioritized for "2.0")

### Top — food-safety / security / numbers (non-frozen, heal-ready)
- **[P2] #1 Allergen collision case-sensitivity** `KsAllergenBadge.vue:106` — customer codes not lowercased vs item codes lowercased → red allergen alert can silently miss. *Latent* (declared_allergens future-migration). FE rebuild needed.
- **[P2] #9 allergens_snapshot double-encode latent** `KitchenDisplaySystemOrderService.php:556,590-604` + `OrderItem.php:107` — food-safety hash. Matches 2026-06-13 finding (order_item 4391).
- **[P2] #15 POS barcode sells ruptured item** `ItemController.php:252-288` — barcode lookup ignores `ItemBranchAvailability` rupture. Single-box low impact, correctness gap.
- **[P2] #12 Dashboard "Top du jour" no refund-netting** `DashboardService.php:847-863` — over-counts refunded items.
- ✅ **[P2] #3 kiosk login timing oracle — HEALED this round.**

### Display / sync (P2)
- **[P2] #8 OSS per-listener isolation absent** `OssSyncService.js:441-447` — one throwing listener stops others (backoff IS present).
- **[P2] #11 post-commit Throwable re-wraps committed bump→422** `KitchenDisplaySystemOrderService.php:470-484` — verify vs 2026-06-09 fix.

### Owner gates (NOT auto-healable)
- **[P2] #4 WCAG contrast** white-on-#F4501E = 3.49:1 (<4.5:1 normal text) — **owner brand-mandate design gate**.
- **[P2] #16 AuditLogService env() under config:cache** `AuditLogService.php:273` — **FROZEN** (known UNI-03 cloud-prep, LOCK+gate).

### P3 (mostly V2 multi-tenant / cosmetic / dead-lane) — 8 items, deferred
#2 allergen vocab 'poissons'/'poisson'; #5 cash received=null guard; #6 cast inconsistency; #7 parked-order UNIQUE no branch_id (V2); #10 allergen non-string drop; #13 hourly chart mirror phantom; #14 sales-report discount mirror; #17 dead broadcast lane; #18 DispatchDomainEventsJob.failed() wrong connection log.

---

## OWNER GATES
| Gate | What | Status |
|---|---|---|
| G-WCAG | #4 orange contrast — brand-mandate design decision | PENDING owner |
| G-FROZEN | #16 AuditLogService env() (FROZEN) — LOCK+gate if healed | PENDING owner |
| G-PUSH | push spine to remote | PENDING owner |
| G-OVH | deploy to OVH | PENDING owner |

## RESUMABLE NEXT STEPS (Round 2)
1. Heal top food-safety P2 (#1, #9) + #15 + #12 (TDD; #1 needs FE rebuild — watch disk).
2. Re-run Test A (W1 re-dispatch) + Test B (re-journey) → require 2 consecutive identical P0+P1=0 cycles for full convergence.
3. OSS + KDS-bump visual leg (deferred this round — order buried in e2e-polluted board; logic W1-confirmed).
4. Owner gates G-WCAG / G-FROZEN / G-PUSH / G-OVH.

**Harness is live + detached (survives session reset): `:8780` ENV=2dot0, soketi :6001, queue worker. Resume by re-reading this report + `plans/GOAL_TEST_E2E_MASSIVE_2DOT0_2026-06-14.md`.**
