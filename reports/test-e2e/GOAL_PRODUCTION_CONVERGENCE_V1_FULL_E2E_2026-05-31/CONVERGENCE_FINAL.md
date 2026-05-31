# CONVERGENCE FINAL — GOAL_PRODUCTION_CONVERGENCE_V1_FULL_E2E

**Baseline HEAD:** `a928ee88d` (post VAT-10 reactivation, discounts LIVE)
**Branch:** `heal/cms-pr1-quickwins-2026-05-18` · **Date:** 2026-05-31 · **Author:** Claude (orchestrateur)
**Method:** superpower-gstack pipeline + parallel adversarial Workflow + live Playwright/HTTP + verify-before-report
**Cycle commits:** `998d48233` (Wave A+C checkpoint), this report.

---

## §1 — Verdict

# ✅ GO — V1 LOCAL Le Cayenne (discount-reactivation axis)

**0 cross-validated P0/P1.** All baseline gates GREEN. The genuine residual risk identified pre-cycle — *discounts-live under concurrent load + multi-rate Z-close identity* (neither covered by the GO-100% audit, which ran with discounts dormant) — is now **empirically proven**. Three real but **non-fiscal / non-security** findings surfaced for owner decision (coupon usage caps + kill-switch ops note); none blocks V1 ship.

### Why this cycle was scoped to the delta (orchestrator re-scope, advisor-endorsed)
The full-real-E2E (3h prior, `d3d290183`) proved the base GO-100%. The discount delta (9 commits `d3d290183..a928ee88d`) had its own round-4/5 + F1 E2E close+sign convergence (`golive-vat10-round4-2026-05-31`). **Both halves were already individually green.** The only unproven surface was their *intersection* — discounts live, under load, aggregated into a signed Z. This cycle concentrated firepower there + a fresh full adversarial pass, instead of a 10h re-run of 12 unchanged systems.

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

- **COUPON-CAP-01 (P2)** — `max_uses_global` is **not enforced**. `usage_count` is checked (`Coupon.php:152`) and initialized 0 (`CouponService:236`) but **never incremented** (broad grep: no `increment(`, no observer). Empirical: `CONVTEST10` showed `usage_count=0` after redemption. A globally-capped coupon ("max 100 uses") is effectively unlimited.
- **COUPON-CAP-02 (P3)** — `limit_per_user` **IS** enforced (via `OrderCoupon` row-count, `CouponService:437-448`) but **non-atomically**: no `lockForUpdate`, `order_coupons` has no `(coupon_id,user_id)` unique index → a same-user concurrent burst could race past the per-user cap. Low risk on V1 single-box.
- **KS-PROPAGATION (P3, ops)** — the kill-switch gate is complete (7 sentinels + code-audit), but a `.env` `POS_MANUAL_DISCOUNT_ENABLED=false` flip on the **running** server does NOT hot-reload (server holds boot-time env). Flipping the kill-switch requires a **service restart** (or `config:cache` redeploy). Standard for env, but the "instant kill-switch" framing should note this.

**Recommended fix (owner decision):** increment `usage_count` + per-user check inside the same locked transaction as the cap check, add a `(coupon_id,user_id)` index. Coupon-feature completion + TDD — a separate scoped task, not this convergence cycle.

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
