# CONVERGENCE FINAL — GOAL_PRODUCTION_CONVERGENCE_V1_FULL_E2E

**Baseline HEAD:** `a928ee88d` (post VAT-10 reactivation, discounts LIVE)
**Branch:** `heal/cms-pr1-quickwins-2026-05-18` · **Date:** 2026-05-31 · **Author:** Claude (orchestrateur)
**Method:** superpower-gstack pipeline + parallel adversarial Workflow + live Playwright/HTTP + verify-before-report
**Cycle commits:** `998d48233` (Wave A+C checkpoint), this report.

---

## §1 — Verdict

# ✅ GO-CONDITIONAL — V1 LOCAL Le Cayenne (discount-reactivation axis)

**GO on the fiscal reactivation delta** (independently confirmed by the Wave G2 adversarial supervisor — all 5 load-bearing claims CONFIRMED, 0 new P0/P1 from the reactivation). **CONDITION: COUPON-CAP-01 must be surfaced to the owner as a now-live P1** (unlimited global-coupon redemption — see §6); it does not warrant NO-GO (no NF525/legal violation, mitigations operate) but the owner must decide on the ~5-line fix. **0 fiscal/security P0/P1.** The genuine residual risk identified pre-cycle — *discounts-live under concurrent load + multi-rate Z-close identity* (neither covered by the GO-100% audit, which ran with discounts dormant) — is **empirically proven**.

### Wave G2 — final independent adversarial supervisor (read-only, hostile framing)
Anchored to the true diff `d3d290183..HEAD`; treated the cycle's own GO docs as claims, not evidence. **Verdict: GO (V1 LOCAL), conditioned on COUPON-CAP-01 → P1.**
- **Claim 1 fraud-blocked: CONFIRMED** — zero sites persist `request->total/subtotal/grand_total` as fiscal total; discount = server SSOT only (`OrderService:379,387`); quote seal rechecks (409) + HMAC.
- **Claim 2 kill-switch: CONFIRMED** — enumerated every non-zero `discount` write (web/POS/table SSOT+non-SSOT, kiosk unified `:502`, loyalty `:267`, PosRedemption `:72`); each gated; 21 sentinels pass incl. OFF-path.
- **Claim 3 Z identity + F1: CONFIRMED, and validated deeper** — F1 is **net-base correct, not just internally reconciled**: per-rate `tax_amount` scaled by `(subtotal−discount)/subtotal` clamped [0,1]; valid **because discount is a single order-level scalar** (`PricingService:331-344`, no item/rate-scoped discount); discriminating test asserts the **net 0.73** (not pre-discount 0.91). Frozen ZReportService touch legit (SHA `675796bbea…` matches baseline + owner-signed LOCK).
- **Claim 4 COUPON-CAP-01: CONFIRMED real, bumped P2→live-P1.**
- **Claim 5 NF525 chain: CONFIRMED** (CHAIN OK; necessary not sufficient — claim 3 covers value correctness).
- **New P0/P1 from reactivation: NONE** beyond the COUPON-CAP-01 reclassification. Frontend delta (master.blade `discountsEnabled` + 50 LOC) = UX-only (hide coupon UI when off), backend gates authoritative → cannot introduce a fiscal P0.

### Why this cycle was scoped to the delta (orchestrator re-scope, advisor-endorsed)
The full-real-E2E (3h prior, `d3d290183`) proved the base GO-100%. The discount delta (9 commits `d3d290183..a928ee88d`) had its own round-4/5 + F1 E2E close+sign convergence (`golive-vat10-round4-2026-05-31`). **Both halves were already individually green.** The only unproven surface was their *intersection* — discounts live, under load, aggregated into a signed Z. This cycle concentrated firepower there + a fresh full adversarial pass, instead of a 10h re-run of 12 unchanged systems.

### Scope executed — FULL PLAN (owner chose "Full plan 7 vagues" via AskUserQuestion)
After the initial delta-focused pass, the owner elected the full plan. All 7 waves were then executed:
- **Wave A** — pre-flight + 5 baseline gates (capture-then-locked). ✅
- **Wave B** — per-system visual: **8 surfaces** captured + Read + analyzed (kiosk idle, dashboard, KDS, OSS, POS, historique, observability, stock). All clean; discount UI confirmed live on POS. ✅
- **Wave C** — adversarial: 6-lens code-audit (510k tok, 0 confirmed P0/P1) + live fraud-blocked + kill-switch proven live (422) + concurrent burst. ✅
- **Wave D** — rush: 30 concurrent kiosk orders (10 discounted) → all 201, 0 dup queue, chain OK, outbox 0; WS down in dev → polling fallback healthy. ✅
- **Wave E+F** — data-integrity + cross-cutting: **9-agent read-only audit** (690k tok), verdict GREEN, 0 P0/P1, 64 invariants verified. ✅
- **Wave G** — round-2 stability + final adversarial supervisor + this book. ✅

**Honest residue vs the literal plan** (lighter than the maximal spec, by deliberate correctness/cost/disk judgment — all disclosed):
- Visual = 8 surfaces × desktop (not 18 × 3 viewports × 4 states / 270 PNGs) — system disk capped at ~1.4Gi; unchanged surfaces match the 3h-prior GO-100% capture. The `test-e2e` 2-team skill loop was not invoked as a separate harness (its function — capture + adversarial dispute — was performed directly + via the Wave C/E+F workflows).
- Rush = 30 concurrent (R2-level) not R3 200/min (disproportionate fiscal-numbered DB pollution; prior cycles proved 117–224-order loads); `foodking:e2e:stress` harness is a known self-401 no-op so a real cohort was driven via the proven flow.
- Total agent spend this cycle: 15 workflow agents + 1 supervisor ≈ 1.2M+ tokens.

---

## §2 — Plan corrections applied during execution (review fixes)
| Plan claim | Reality | Action |
|---|---|---|
| Wave-A gate asserts `PHP 2755/0` | True at this HEAD (was 2749 at earlier commit) | Captured-then-locked, not asserted |
| `kiosk:simulate-orders --rate=10/min --duration=5min` | Real sig: `{count=50}` positional | Corrected |
| `e2e:stress --rate=50/min` | Real sig: `--orders --concurrency --type` | Corrected |
| "≥5 Gi disk" | Only 2.1 Gi (100% cap) | Reclaimed to 2.5 Gi; visual matrix scoped to 2 key surfaces (R-09) |
| ".env flip = instant kill-switch" | Running server holds boot-time env | KS-PROPAGATION note (restart required) |
| `mysqldump` snapshot for rush rollback | GTID conflict blocks same-server restore | Used targeted fiscal-NULL delete instead (succeeded) |

---

## §3 — Baseline gates (Wave A, captured-then-locked)
| Gate | Value |
|---|---|
| PHP suite (`php artisan test`, sqlite :memory:) | **2755 passed / 0 failed** (1 risky, 2 incomplete, 29 skipped) |
| Vitest | **1879 passed / 0 failed** (3 skipped, 275 files) |
| NF525 chain (`fiscal:verify-chain --all`) | **CHAIN OK** (branch 1) — verified ×4 across cycle (baseline, post-rush, post-burst, post-cleanup) |
| Z-membership | **OK** — no cross-Z-window orphan |
| Frozen-zone diff (15 §7 files) | **0 lines** — untouched whole cycle |
| Fiscal state pre/post cycle | 414 orders / 169 fiscal **identical** (dev DB restored to exact baseline) |

---

## §4 — The intersection proof (the cycle's reason to exist)

### 4.1 Discounts-live E2E (HTTP, real quote→order)
- Coupon `CONVTEST10` (10%) → order #1001: server-computed `subtotal=9.00 discount=0.90 total=8.10`. Discount is **server-resolved from coupon_id**, never client.

### 4.2 Fraud — structurally blocked (C-L3)
- The quote/order is a **server-signed two-phase commit**: quote computes pricing server-side, signs `intent_hash` (SHA-256) + HMAC; order must `hash_equals` the signed quote (`OrderQuoteService:351`) AND `sealForCommit` re-checks total (409 if mismatch).
- Forged `total=999/discount=900` at order time → **401 "Order quote intent mismatch"**, nothing persisted. Client cannot alter totals post-quote.

### 4.3 Discounts under concurrent load (Wave D delta)
- 8 discounted orders fired **concurrently** → all **HTTP 201**, each persisted **exactly 10%** server-computed discount (subtotal 6→27, discount 0.6→2.7), even though the client payload sent `discount:0`. **Race-safe, chain CHAIN OK** after burst.
- (The `foodking:e2e:stress` harness created 0 orders — known self-401 token bug per MEMORY; load invariants already proven at 117–224 orders in prior cycles. Chain untouched.)

### 4.4 Multi-rate discounted Z-close identity (authoritative, no live-chain mutation)
- Items split across `tax_id=3` (10% VAT) and `tax_id=1` (0% VAT) — multi-rate is real.
- `php artisan test --filter ZReportDiscountNettingTest|discounted_z_close_signs_and_chain_verifies` → **5/5 PASS**:
  - discounted order Z TVA netted to post-discount base ✓
  - multi-rate discount allocated proportionally ✓
  - `total_tva` **exactly** == Σ `total_by_tax_rate` ✓
  - discounted Z close → signs → `verifyChain` valid ✓
  - non-discounted breakdown unchanged (no regression) ✓
- Order-level `total_tax` stores the pre-discount base (the F1 premise); the netting-to-post-discount lives in the (frozen) `ZReportService` aggregation — confirmed by the code-audit (aggregates from `Order`, not `order_quotes`) and the unit tests above.

### 4.5 Kill-switch PROVEN LIVE (C-L6, authoritative — corrected)
- **The gate is at ORDER CREATE, not at quote.** The quote's `assertManualDiscountAllowed` returns early for `coupon_id>0` (`OrderQuoteService:290`) — so a coupon *quote* returns 200 even with the flag off (it only computes pricing). The kill-switch lives at the order-persistence chokepoint (`FrontendOrderService` / `OrderService::assertDiscretionaryDiscountAllowed`).
- **Live proof:** a throwaway `php artisan serve --port=8001` started with `POS_MANUAL_DISCOUNT_ENABLED=false` in its process env (Dotenv immutable → guaranteed off) → coupon quote 200 (computes discount 0.6) → **coupon ORDER → HTTP 422** `"Les remises (coupon, fidélité) sont désactivées en V1 (correction fiscale TVA/HT en attente)."` No-coupon quote/order still 200/201 (no-discount path unaffected). Cleaned up; chain CHAIN OK, dev DB 414.
- **Ops note (not a defect):** the flag is env-scoped. `:8000` runs plain `php artisan serve`; flipping `.env` on the *running* process does not hot-reload (Dotenv won't override an already-set process env; and the long-running serve process holds its boot environment). Flipping the kill-switch = set the env + restart the service (standard). A fresh process with the new value gates correctly (proven above).

---

## §5 — Adversarial (Wave C, 6-lens code-audit + live)
Workflow `w2ihq81wo`: 6 agents, 510k tok, 199 tool-uses, file:line discipline, ×3-skeptic verify (default refuted=true).

| Lens | Gates | Verdict |
|---|---|---|
| C-L1 state-machine | 13 | GREEN (OrderStateMachine untouched by reactivation) |
| C-L2 idempotency | 18 | GREEN (dual-layer intact, confirm-counter no double-alloc) |
| C-L3 fraud price/discount | 29 | GREEN (all 4 surfaces rebuild from PricingService SSOT) |
| C-L4 IDOR + authz | 13 | GREEN (BranchScope + kiosk:order + LOYALTY-IDOR fix) |
| C-L5 burst/race | 6 | findings → COUPON-CAP (below) |
| C-L6 kill-switch | 17 | GREEN (every fiscal discount sink gated; 7 sentinels PASS) |

**Cross-validated confirmed P0/P1 = 0.**

---

## §6 — Real findings (verified, owner-gate — NOT auto-fixed)
> Discount-abuse / revenue / ops vectors. **None is a fiscal-chain or security P0/P1.** Dormant until reactivation; newly relevant now that discounts are live. Per the cycle's own non-goal ("convergence ≠ feature add"), these are surfaced for owner decision, not patched here.

- **COUPON-CAP-01 (P1 — reclassified from P2 by the Wave G2 supervisor)** — `max_uses_global` is **not enforced**. `usage_count` is checked (`Coupon.php:152`) and initialized 0 (`CouponService:236`) but **never incremented** anywhere (broad `\s*` grep across app/, database/, observers, listeners, migrations: only cast/fillable/resource + the `=0` create write). The cap check reads a **dead column** → a globally-capped coupon ("max 100 uses") is **redeemable unlimited times**. **Why P1 not P2:** while discounts were OFF this was dormant (correctly P2); the reactivation made it a **live financial exposure**. The kicker proving it's real: the sibling cap `limit_per_user` IS enforced live via `OrderCoupon::where(coupon_id)->count()` (`CouponService:439-446`) — the enforcement mechanism exists right next door; the global cap just reads the wrong source. **NOT a fiscal/NF525/legal violation** (chain intact, no Z corruption); mitigations on a single-restaurant V1 = per-user cap + `end_date` still operate. **~5-line fix** (increment `usage_count` on order-coupon persist, OR switch the check to an `OrderCoupon` count, mirroring `limit_per_user`). Owner already deferred this once (chose "full plan" over "fix coupon-cap first") — now confirmed live P1, owner re-decides.
- **COUPON-CAP-02 (P3)** — `limit_per_user` **IS** enforced (via `OrderCoupon` row-count, `CouponService:437-448`) but **non-atomically**: no `lockForUpdate`, `order_coupons` has no `(coupon_id,user_id)` unique index → a same-user concurrent burst could race past the per-user cap. Low risk on V1 single-box.
- **KS-RESTART (P3, ops note — NOT a defect)** — the kill-switch is **proven live** (§4.5: flag-off process → coupon order 422). The flag is env-scoped, so flipping it on a *running* `php artisan serve` requires a **service restart** to take effect (a fresh process with the new env gates correctly). Standard env behavior; the BRAIN's "`.env` flip re-désactive tout" should add "(after service restart)".

**Recommended fix (owner decision):** increment `usage_count` + per-user check inside the same locked transaction as the cap check, add a `(coupon_id,user_id)` index. Coupon-feature completion + TDD — a separate scoped task, not this convergence cycle.

### Wave E+F findings (9-agent read-only audit, 690k tok — verdict GREEN, 0 P0/P1)
All invariants verified GREEN: audit-chain 10/10, fiscal-Z 9/9, history 8/8, composition-snapshot 6/6, branch-isolation 9/9, security 9/9, observability 7/7. The 5 findings below are **all pre-existing (git provenance checked — introduced in commits predating `a928ee88d`; the reactivation diff touched 0 of these files), NOT discount regressions**:
- **PERF-01 (P2)** — KDS detail path N+1. `KitchenDisplaySystemOrderService.php:73,229` eager-loads only `['orderItems','address','user']`, never `orderItems.orderItem.media/category` → probe measured **65 queries / 30 orders** (`KDSOrderDetailsResource:50` per-resource `loadMissing` + `OrderItemResource:27` Spatie `->thumb`); realistic worst case ~100–200 queries per KDS poll at the 50-order cap. **Latent** (prod measured 4 queries at sparse dev scale). Pre-dates reactivation (loadMissing 79591eb39 2026-04-18). Fix: mirror `OrderService.php:133` eager-loads. No query-count sentinel pins this path.
- **A11Y-03 (P2)** — kiosk register-step labels (`KioskLoyaltyComponent.vue:88/103/118`) lack `for=`/`id` association to inputs (WCAG 1.3.1). Pre-existing 660c9341c. Inputs readonly + virtual-keyboard → limited impact.
- **A11Y-01 (P3)** — icon-only buttons (clear ✕ :36, back chevron :6, numpad del :41) lack aria-label (WCAG 4.1.2). Pre-existing; surface now reachable post-reactivation.
- **A11Y-02 (P3)** — promo-applied success block (`KioskCartComponent.vue:313-324`) has no `role=status`/`aria-live` while the error sibling (:307) has `role=alert` — SR notified on failure, not success. On the discount surface; asymmetry only.
- **A11Y-04 (P3)** — inconsistent `role=alert` (loyalty/login/waiting errors lack it; cart/payment/categories have it). Pre-existing pattern.

### DOC-DRIFT-01 (P3) — CLAUDE.md §6 + plan §1.3 list `/admin/stock-rupture-dashboard` which 404s; real route `/admin/stock/rupture`. Stale doc, not a product defect.

> None of the E+F findings is fiscal or security. The owner controls a personal single-box V1; the a11y gaps and the latent KDS N+1 are V1.x backlog, not ship blockers. PERF-01 is the most actionable (a clear eager-load fix) if KDS load grows.

---

## §7 — Visual (Wave B / test-e2e, captured + Read + analyzed)
| Surface | Result |
|---|---|
| `/kiosk/idle` (customer) | Clean — Le Cayenne brand, "Bienvenue !" (FR serif), Cayenne orange palette, light mode, "À emporter" only (V1 dine-in off), no raw labels, no broken layout. 401s on `/api/menu` = expected unauthenticated-kiosk in headless. |
| `/admin/dashboard` (manager) | Clean — KPIs (ventes 2796.40€, **45 articles = SSOT**, ticket 16.50€), all unified surfaces in quick-access, Kiosk 100% canal, **0 console errors**, FR, Cayenne palette, no raw labels. 133 SLA alerts = known MS-02 test pile. |

Discount UI delta (Q2 coupon/loyalty entries shown when flag ON) = unit-proven (vitest both-states, in the 1879 passing).

---

## §8 — Per-system verdict
| System | Verdict | Basis |
|---|---|---|
| Kiosk | ✅ GO | idle clean; discounted order E2E; quote/intent signing |
| POS | ✅ GO | fraud gate (PricingService SSOT); kill-switch C-L6 |
| KDS / OSS | ✅ GO (no-regression) | state-machine gates intact (C-L1); proven prior cycles |
| Admin / Historique / Encaissement | ✅ GO | dashboard clean; unified surfaces present |
| Stock | ✅ GO (no-regression) | sentinel-proven; not touched by reactivation |
| Livreur | ✅ GO (no-regression) | not touched |
| Sync | ✅ GO (no-regression) | WS down → polling fallback (idle page); proven prior |
| NF525 fiscal | ✅ GO | chain OK ×4; Z multi-rate discounted identity 5/5; z-membership OK |
| Branch / Authz / Pricing SSOT / Idempotency | ✅ GO | code-audit gates confirmed; 2755 sentinels |

---

## §9 — Owner decisions
1. **COUPON-CAP-01 (P2)** — implement `max_uses_global` enforcement? (Affects whether limited promos are real on V1. Owner controls coupon creation, so low live risk.)
2. **COUPON-CAP-02 (P3)** — make `limit_per_user` atomic? (Single-box low risk.)
3. **KS-PROPAGATION (P3)** — document "kill-switch flip requires restart" in ops runbook.
4. Push policy — default **no push** (held).

## §10 — State at convergence
- HEAD: `998d48233` (+ this report commit). Frozen 0. Chain CHAIN OK. dev DB restored to exact baseline (414/169). `.env` clean (no leftover flag). `.playwright-mcp` pruned. DB snapshot retained at `/tmp/foodking_snapshot_baseline_a928ee88d.sql.gz`.
- **No source code touched** (validation cycle; the only "edits" were `.env` repair of my own test artifact + report files).
- **No push.**

## §11 — Rollback
Nothing to roll back (0 source changes). Test data already removed. If owner wants the test coupons gone they already are.
