# Wave C — Adversarial (code-audit + live discount probes)

**HEAD `a928ee88d`** · focus = the discount-reactivation delta (discounts now LIVE) + re-confirm prior-proven invariants.

## C.1 — Parallel read-only code-audit (Workflow `w2ihq81wo`, 6 agents, 510k tok, 199 tool-uses)
6 lenses, file:line discipline, ×3-skeptic verify (default refuted=true).

| Lens | Gates confirmed | Findings | Verdict |
|---|---|---|---|
| C-L1 state-machine | 13 | 0 | GREEN — no regression; OrderStateMachine untouched by reactivation (last edit 619b49bc1) |
| C-L2 idempotency | 18 | 0 | GREEN — X-Idem dual-layer intact; confirm-counter no double-alloc |
| C-L3 fraud price/discount | 29 | 2 (unconfirmed) | GREEN — all 4 order surfaces unset client total/subtotal/discount, rebuild from `PricingService::calculateOrder` SSOT; persisted `order.discount` = server-resolved |
| C-L4 IDOR + authz | 13 | 4 (unconfirmed) | GREEN — BranchScope + kiosk:order + LOYALTY-IDOR fix present |
| C-L5 burst/race | 6 | 2 + **3 missing gates** | see COUPON-CAP below |
| C-L6 kill-switch | 17 | 0 | GREEN — `.env=false` gates every discount/coupon/loyalty fiscal sink; filter_var false/0/garbage→gate fires; 7 sentinels PASS; ZReportService aggregates from Order not order_quotes |

**Cross-validated confirmed P0/P1 = 0.** (×3 skeptics refuted all candidates.)

## C.2 — Live HTTP probes against `:8000` (main loop)
| Probe | Result |
|---|---|
| **Discounts-live E2E** (quote+order with `coupon_id=1`) | ✅ Order #1001 created: server-computed `subtotal=9.00`, `discount=0.90` (10%), `total=8.10`, queue A0016. Discount is SERVER-resolved, not client. |
| **Fraud — forged `total=999/discount=900`** | ✅ **Structurally blocked.** Quote/order is a server-signed two-phase commit: `intent_hash` (SHA-256 over server pricing) + HMAC signature; order must `hash_equals` the signed quote (`OrderQuoteService:351`) AND `sealForCommit` rechecks total (409 if mismatch). Forged order → **401 "Order quote intent mismatch"**, nothing persisted. |
| **Kill-switch gate** (C-L6 live) | ⚠️ Gate PROVEN at config/unit level (tinker confirms `config=false` when `.env=false`; 7 sentinels pass). **Live caveat (KS-PROPAGATION, P3 ops):** the running `:8000` server holds boot-time env → a `.env` flip does NOT hot-reload; flipping the kill-switch requires a **server restart** (or `config:cache` redeploy) to propagate. Standard for env changes, but the "`.env` flip = instant kill-switch" framing should note the restart. |

## C.3 — REAL FINDINGS (verified by main loop, not auto-fixed — owner-gate)
> Discount-abuse / revenue vectors, **NOT fiscal-chain or security P0/P1**. Dormant until reactivation; newly relevant now that discounts are live.

- **COUPON-CAP-01 (P2)** — `max_uses_global` is **not enforced**. `usage_count` is checked (`Coupon.php:152`) and initialized 0 (`CouponService:236`) but **never incremented anywhere** (broad grep: no `increment(`, no observer/listener). Empirical: `CONVTEST10` shows `usage_count=0` after redeeming order #1001. A globally-capped coupon ("max 100 uses") is effectively unlimited.
- **COUPON-CAP-02 (P3)** — `limit_per_user` **IS** enforced (via `OrderCoupon::where(user_id,coupon_id)->count()`, `CouponService:437-448`) — refines the workflow's "no enforcement" claim — but **non-atomically**: no `lockForUpdate`, and `order_coupons` has no unique index (only PRIMARY on id). A concurrent same-user burst could race past the per-user limit. Low risk on V1 single-box.

**Recommendation:** surface to owner. Fix = increment `usage_count` (and the per-user check) inside the same locked transaction as the cap check + add a `(coupon_id,user_id)` index. This is coupon-feature completion (needs owner decision on whether global caps matter for V1 + TDD), **not** a convergence heal — do not scope-creep it into this cycle.

## C.4 — Re-confirmed (proven prior cycles, still green)
State-machine 422s, idempotency single-apply, burst race-safety on fiscal/bump/confirm, BranchScope isolation, LOYALTY-IDOR — all confirmed present at file:line by the code-audit, no regression from reactivation.
