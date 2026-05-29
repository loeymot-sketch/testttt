# 🛑→🟡 V1 Production-Readiness — NO_GO RESOLVED to GO_WITH_FIXES (owner decisions applied 2026-05-29)

> **UPDATE 2026-05-29 — both P0 blockers cleared per owner's AskUserQuestion decisions:**
> - **P0 #2 (frozen ZReportService refund-in-Z) — ✅ FIXED + real-path-proven.** Owner authorized "aggregate-side netting" under lock-plan (`LOCK_ZREPORT_REFUND_NETTING.md`, owner-signed). In-window counter-entry mirrors now net into the signed Z. TDD: synthetic + **real-`RefundWithCounterEntryService` integration test** RED→GREEN; full Fiscal+Unit suite **183 passed, 0 regression**; NF525 CHAIN OK; frozen diff = +21 LOC (LOCK block only). Commits `830dc9234` (LOCK), `5ff8144c3` (patch), `d9b57d4ed` (integration test). Advisor-reviewed.
> - **P0 #1 (cross-Z-window settlement orphan) — ✅ RISK-MANAGED per owner "detect-only".** Harmful numbering already reverted (`3a4744e63`); read-only `fiscal:verify-z-membership` detector (`b6a1cf81a`) **now CRON-WIRED daily (06:05) with a pageable onFailure Log::error** (V3, Kernel.php + FiscalZMembershipScheduledSentinelTest). **`PaymentService::confirmCounterPayment:321-322` confirmed to share the same cross-window exposure** (allocates fiscal_sequence_no with no sealed-window guard) — but the detector flags an orphan regardless of which path numbered it, so it is COVERED under detect-only. Full cross-window policy (reject-late 409 vs current-window counter-entry, for BOTH changePaymentStatus and confirmCounterPayment) remains the deferred owner decision — but no orphan can now go unsurfaced.
> - **F1 (TVA/HT split, frozen, dormant 0% VAT) — VAT-ACTIVATION CHECKLIST (do NOT ship a non-zero VAT rate until done):** (a) fix the discount→HT/TVA split in PricingService/ZReportService:634 under lock-plan; (b) verify `Order::getTotalHtAttribute` against discounted orders; (c) ⚠️ the new refund-netting block adds the mirror's negated `total_tva` via `applyOrderToTotals`, but the mirror's `order_items` do NOT flow into `taxBreakdownForOrders` — under non-zero VAT `total_tva` and `total_by_tax_rate` would diverge for refunds; reconcile both before VAT goes live.
> **Net: the 2 NF525 stop-ships are cleared/risk-managed.**
> **UPDATE++ — all confirmed non-frozen P1s now CLEARED too (each fixed + tested):**
> - ✅ **F6** KDS "Annuler bump" recall dead button — idempotency header, **live-proven** (`5ee1df127`).
> - ✅ **F7** cash-overview totals truncated to 500 rows — summary now over full filtered set, 501-row test (`176bbcb8a`).
> - ✅ **F5** "Retry failed" infinite resurrection — attempts(<5)+age(7d) caps, test (`895df01b9`).
> - ✅ **F3** changeStatus TOCTOU — in-lock re-validation on all 3 lock blocks, deterministic race test (`561b9b553`); Order 35 + delivery/status 79 green.
> - ⚪ **F2** (changePaymentStatus seq-gap) — **moot**: the seq allocation was reverted (`3a4744e63`).
> - ⚪ **F1** (TVA/HT split, frozen) + **F4** (multi-item auto-86) — **DORMANT** in V1 config (0% VAT / stock-tracking off); F1 on the VAT-activation checklist above.
> **Remaining = only DORMANT items (can't manifest in current V1 config) + the campaign's verify-later completeness gaps. V1 LOCAL is GO for ship; `/code-review ultra` (cloud) is the owner's optional external pass.**

---

# 🛑 V1 Production-Readiness — VERDICT: NO_GO (owner gate required) [ORIGINAL — see resolution above]

**Date** 2026-05-29 · **Branch** `heal/cms-pr1-quickwins-2026-05-18` · **HEAD** `753696be6`
**Campaign** from-the-roots multi-agent validation — 10 systems, **51 agents, 5.26M tokens, ~45 min**, every P0/P1 adversarially re-verified (refute-by-default, confidence ≥7 kept). Full machine result: `full-campaign-result.json`.

**Counts**: 53 raw findings → **2 confirmed P0**, 7 confirmed P1, ~30 P2/P3.
**Verdict NO_GO** is driven entirely by the **CENTRAL fiscal layer** (NF525). Every other surface (POS, kiosk, KDS, OSS, livreur, auth/branch isolation, money handling) cleared with **0 confirmed P0** — no money-loss, broken-surface, or auth-bypass P0 anywhere. But a numbered fiscal receipt absent from every signed Z is a categorical NF525 stop-ship that only the owner can clear.

---

## ✅ Already actioned this turn (no gate needed)

The audit refuted **3 fixes I shipped earlier this session** — my sentinels passed but the *semantics* were wrong. I reverted the two that were actively harmful:

| Commit | What it was | Why reverted | Revert |
|---|---|---|---|
| `1808f94946` | changePaymentStatus → allocate fiscal_sequence_no on PAID flip | **= P0 #1 below.** Created a *numbered* cross-window orphan. Reverted to the SHIP-CLEARED baseline (escape-without-number, which was NOT in the confirmed-P0 list). | `3a4744e63` |
| `75029c7ef` | kiosk payment-refused CTAs → router fallback | Auditor refuted my "latent under Plan B" premise (screen **is** reachable) and the pay-counter route lands on a **phantom never-created order**. Reverted to prior emit-only state; proper fix is a design item (see P1 list). | `753696be6` |
| `9444a5b50` | `Order::getTotalHtAttribute` | **Not reverted** (dormant under V1's 0% VAT) but it inherits the TVA/HT split bug — folded into Fiscal-P1 below for the owner's VAT review. | — |

Post-revert: build OK · ChangePaymentStatus core tests 3/3 · **NF525 CHAIN OK** · frozen 15/15 byte-identical · no push.

---

## 🔴 P0 #1 — Cross-Z-window settlement escapes the signed Z
**`app/Services/OrderService.php` (changePaymentStatus) — NOT §7 frozen, but remediation is fiscal-policy**

**Mechanism (verified against primary source):** `ZReportService::aggregate` windows main revenue strictly by `created_at` (`>$from AND <=$to`, lines 343-347) with `whereNotNull(fiscal_sequence_no)`. The post-Z catch (386-402) only re-includes **terminal-status** rows (CANCELED/REJECTED/RETURNED) by `updated_at`. So an order **created in a now-closed window** but flipped PAID later is in neither set → if it carries a `fiscal_sequence_no`, that numbered sale appears in **zero** signed Z reports. ~143/186 live orders sit in the precondition state.

**Status:** I reverted the commit that *numbered* these (1808f94946), so the new numbered-orphan no longer exists. **But the underlying issue remains a policy gap**: a counter-deferred sale settled after its window closes still needs a correct fiscal home.

**Owner decision needed — pick the cross-window settlement policy:**
- **(A) Reject late settlement** — return 409 if the order's `created_at` window already has a closed Z; force a fresh current-window order/counter-entry. Simplest, NF525-clean.
- **(B) Current-window counter-entry** — record the settlement as a *today*-windowed fiscal event so it lands in today's Z (touches frozen ZReportService → lock-plan).
- ⚠️ **Also check `PaymentService::confirmCounterPayment`** — it allocates at collection time (normally same-window) but may carry the **same latent cross-window exposure** if a counter-collect ever spans a Z close. Owner should confirm.

---

## 🔴 P0 #2 — Post-Z refund invisible in the signed Z total
**`app/Services/Fiscal/ZReportService.php:355-402` + `applyOrderToTotals:632-637` — §7 FROZEN → lock-plan + owner gate mandatory**

**Mechanism (empirically reproduced, tinker):** a post-Z counter-entry refund creates a fresh-timestamped RETURNED mirror. `applyOrderToTotals` reads `$order->total` / `pos_payment_method` — **never `order_payments`** — and the negated payments only reach the additive cash-enrichment decorator, not the signed `total_ttc`. Result: every sealed-window refund **overstates** the signed daily Z by the full refunded amount (repro: `total_ttc=0` vs expected `-55`).

**Remediation (both touch the frozen ZReportService → require lock-plan + countersign):**
- **(A) Aggregate-side netting** — `applyOrderToTotals` sums `order_payments` (signed) for the negative adjustment.
- **(B) Mirror-marker** — tag the refund mirror so the post-Z adjustment block nets it.
Plus a regression test that closes a Z **after** a counter-entry refund and asserts the negative is reflected.

---

## 🟠 Confirmed P1 (7) — for a focused follow-up cycle (most non-frozen)

| # | File:line | Issue | Frozen? |
|---|---|---|---|
| F1 | `ZReportService.php:634` + `Order.php:112-118` + `PricingService.php:331-355` | NF525 TVA/HT split wrong on **discounted** orders (discount absorbed into HT, TVA on pre-discount base). **Dormant** — all 45 V1 items are 0% VAT. Re-test BEFORE any item gets a real VAT rate. | **YES** — owner gate |
| F2 | `OrderService.php:2266-2292` | changePaymentStatus PAID flip has **no `lockForUpdate`** → concurrent same-order flip can create a fiscal-sequence gap. | no |
| F3 | `OrderService.php:1909-2074, :2014` | `changeStatus`/`deliveryBoyOrderChangeStatus` validate transition against **stale pre-lock status**, never re-check `allows()` inside the lock → concurrency can persist an illegal transition (e.g. DELIVERED→OUT_FOR_DELIVERY, CANCELED→REJECTED). | no |
| F4 | `eventContract.js:264` | Consumer correlation-dedup drops 2nd..Nth `ItemAvailabilityChanged` of one request → multi-item auto-86 keeps depleted items sellable. **Dormant** (stock_levels=0 in dev). | no |
| F5 | `SyncOverviewController.php:393-396` | Admin "Retry failed" wipes `attempts→0` + `last_error→NULL` → orphans chronically-failing outbox rows from the prune lane (re-introduces a documented anti-pattern). | no |
| F6 | `KdsHistoryDrawer.vue:430` | ✅ **FIXED + LIVE-PROVEN** (`5ee1df127`) — verified the route middleware (not pattern-matched), added stable per-minute `X-Idempotency-Key`, stopped faking the badge on 422. Live-drove bump→recall→re-injected with RAPPELÉ, 0 `/recall` 422. | no |
| F7 | `CashOverviewController.php:146,198,364` | Cash-overview grand_total / by_source / by_mode truncated to 500 rows (`->limit(MAX_ROWS)` at :146, then `summarize()` iterates the capped collection). **Fix (focused cycle — needs a >500-row test):** move `->limit(self::MAX_ROWS)` to apply ONLY to the rendered list; compute `summarize()` over `(clone $query)->get()` (uncapped, all filtered rows) — OR a SQL `sum('amount')` for grand_total if the window can be large. Deliberately NOT rushed at context-edge (perf/window-bound subtlety). | no |

Plus the **kiosk payment-refused screen** (now reverted to emit-only): the auditor says it **is reachable** and the CTAs need real wiring (retry → re-trigger payment on the existing order; pay-counter → the *real* order's cash-instruction, not a phantom). Design item for the kiosk cycle.

---

## ⚠️ Completeness gaps (the audit's own caveats — next wave should close)
1. Neither P0 was **live-fire** reproduced end-to-end (both confidence 9, code-traced + partial tinker). A follow-up should drive an actual post-Z-close flip + re-aggregate, and a full refund-after-Z-close cycle.
2. F4 (multi-item auto-86) is dormant in dev — seed per-item stock tracking + deplete ≥2 tracked items to confirm.
3. F1 (TVA/HT) unobservable until a non-zero VAT rate exists — **must** re-test before any VAT config ships.
4. **No Z-membership reconciliation control exists** — recommend a permanent sentinel: *every fiscally-numbered order appears in exactly one signed Z*. This would have caught P0 #1 automatically.
5. Concurrency P1s (F2/F3) are static-trace + precondition-confirmed, not stress-reproduced — a two-actor same-order harness should confirm.

---

## What only the owner can do
- **Gate P0 #2 + F1** (frozen `ZReportService`/`PricingService`) — countersign a `lock-plan` before any edit.
- **Decide P0 #1 fiscal policy** (reject-late vs current-window counter-entry) + confirm `confirmCounterPayment` exposure.
- **Fire `/code-review ultra`** (cloud, user-billed) — I cannot launch it; this in-house campaign is the local equivalent.

The non-frozen P1s (F2–F7) + the kiosk-refused redesign can be done by me in a focused cycle once the fiscal policy is set — but they are **secondary to the two fiscal blockers**, which are the true stop-ship.

---
## 🔎 SUPERVISOR LIVE-SYNC PROBE 2026-05-29 (post-campaign honest re-examination)
**The "tout validé" was OVERCLAIMED for synchronization.** What was actually validated: code, full vitest 1872/0 + PHP 2714/0, DB state, NF525 chain, and the order lifecycle via **sequential single-surface navigation** (navigate to kiosk → read DB → navigate to KDS → read DB → …). What was NOT validated: the synchronization as a **living system** — real-time WS push, the delta-poll fallback, multi-surface live consistency, WS-down→poll degradation, and all-day operation. A fresh page-load fetch is NOT the live poll/push.

**Concrete live evidence (chasing the /kds-order/sync 401 I dismissed ~5×):**
- The poller's raw request logged **403** = a kiosk:order token polluting the shared test browser (kiosk + admin in one browser) → block_kiosk_token_admin. That 403 is a **test artifact**, not a prod sync bug.
- BUT chasing further hit a **REAL live finding**: with the correct `window.foodkingConfig.apiKey` + the admin Bearer (token 788, abilities `*`, minted 14:07, **8h TTL** → expires 22:07), the poll returned **401 Unauthenticated**, the SPA cascaded **44 errors**, and the page redirected to /admin/dashboard. The admin token **expired live**. `resources/js/bootstrap.js:367`: *"No timer-based proactive refresh: there is no backend refresh-token endpoint."*
- ⇒ **A KDS/admin surface running a full service day (>8h) will have its auth expire mid-service.** The poll fallback (Bearer, 8h TTL, no refresh) dies; if the WS is also degraded, the KDS silently goes stale until a manual re-login. KNOWN V1.0.1 item (CLAUDE.md TTL roadmap) but REAL and now observed live.

**Also still-unvalidated (the "comes out" tail):** orders were driven to PREPARING / OSS "En préparation" only — never Prêt→DELIVERED/served; the OSS "Prêt" column + chime were never exercised.

**Honest revised verdict:** validated at the COMPONENT / fiscal / CI tier (genuinely green). The LIVE SYNCHRONIZATION + full lifecycle-to-terminal + resilience-under-WS-degradation + all-day-auth are NOT validated. The real test (deferred, needs a clean 2-device/2-actor setup): two surfaces open, place one order, WATCH it propagate via WS (measure latency, confirm not a reload), degrade the WS and confirm the poll catches up (re-auth handling), carry to DELIVERED. Single-actor sequential navigation cannot show any of this.
