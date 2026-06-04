# V1 LE CAYENNE — SYNC DEEP AUDIT SYNTHESIS
**Date**: 2026-05-19 · **Mode**: read-only adversarial · **Branch**: `v1-0-1-hardening-2026-05-17` · **Working tree**: local (no cloud)

8 parallel RED agents · 1 cross-corroborated P0 · 7 single-agent P0 candidates · ~25 P1 · ~12 P2/P3 · ~160 hard questions

---

## A. EXECUTIVE VERDICT

| Zone | Quality | Verdict | P0 | P1 | Lead Finding |
|---|---|---|---|---|---|
| Z1 Stock cascade | 8.0 | yes-with-caveats | 0 | 1 | preventive cron OFF (env flag) |
| Z2 Order lifecycle | 7.5 | yes-with-caveat | 0 | 2 | `DispatchableAfterCommit` dead code on hot path |
| Z3 Sync reliability | 7.2 | yes-with-caveat | 2 | 4 | worker crash strands rows + retry resets attempts |
| Z4 Pricing SSOT | 7.0 | conditional (repro) | 1 | 1 | `role` injection bypasses menu-ratio guard |
| Z5 NF525 fiscal | 8.4 | GO | 0 | 5 | verifyChain unbounded walk in z_report open |
| Z6 BranchScope | 7.0 | GO-conditional | 1 | 2 | `PosLoyaltyController:41` cross-branch redeem |
| Z7 Idempotency | 8.0 | SHIPPABLE | 0 | 1 | scope key omits route path (collision risk) |
| Z8 Refund + loyalty | 6.0 | conditional V1 GO | 4 | 2 | KDS recall doesn't exist server-side |

**Weighted average**: 7.4/10 — V1 LOCAL **GO-CONDITIONAL** pending owner decision on 8 P0 items (1 hard-confirmed, 7 single-agent candidates).

**Owner's flagship Q answered**: "If I put product en rupture, does it sync to kiosk/POS/KDS?" — **YES, core cascade is GREEN** (BranchScope on 3 stock tables, composite UNIQUE, triple-defense decrement, commit-before-broadcast, checkout-time revalidation, channel auth hardened). One caveat: preventive cron OFF by default (`FK_CATALOG_AUTO_86_PREVENTIVE_CRON_ENABLED` env flag) — auto-86 is reactive-only at this moment. Enable cron in prod env file = full GREEN.

---

## B. CROSS-CORROBORATED P0 — HARD-CONFIRMED

### 🔴 P0-CROSS-1 — `PosLoyaltyController.php:41` cross-branch loyalty redeem
- **Flagged independently by**: Z6 P0-Z6-01 + Z8 P0-3 (different audit angles)
- **Code**: `Order::withoutGlobalScopes()->find($orderId)` with **zero** post-fetch branch check
- **Authz gate**: FormRequest `PosLoyaltyRedeemRequest:22-25` only checks Spatie permission (global, not branch-bound)
- **Exploit**: branch-5 cashier with `pos.redeem-loyalty` POSTs against branch-3 order ID → financial mutation + dirty audit trail across branches
- **Sibling pattern (correct)**: `PosOrderController::show:117-121` does `abort_unless` after `withoutGlobalScope`
- **Fix**: 3-line heal, no frozen-zone touch, mirror the sibling pattern

**This is the only finding I would block V1 on.** Heal is low-risk, low-blast-radius, and the cross-corroboration eliminates false-positive risk.

---

## C. SINGLE-AGENT P0 CANDIDATES (need owner triage)

| # | File:line | Zone | Risk | Owner Action |
|---|---|---|---|---|
| P0-Z3-B1 | `app/Jobs/DispatchDomainEventsJob.php:65-86,155-165` | Z3 | Worker crash between Phase 1 claim + Phase 3b release strands rows (zero recovery lanes match `whereNull('dispatched_at')`). Silent loss. | Heal or defer V1.0.2 |
| P0-Z3-B2 | `OutboxRetryFailedCommand.php:119-123` | Z3 | `attempts=0` reset per replay → row flaps indefinitely, prune lane (`attempts>=6`) never reclaims | Heal or defer |
| P0-Z4-01 | `app/Services/Pricing/PricingService.php:793-813` + `CompositionSnapshotBuilder.php:136-138` | Z4 | `role` payload string drives 60% price reduction; not bound to addon membership. **NEEDS OWNER REPRODUCTION**: POST any addon with `role:'menu_boisson'` → observe 60% reduction? | Reproduction test FIRST |
| P0-Z8-1 | `RefundWithCounterEntryService.php:73-78` + migration `2026_05_06_200000:25` | Z8 | Duplicate-mirror guard checks wrong field (`parent.status === RETURNED` but counter-entry never mutates parent.status — NF525 immutability). INDEX, not UNIQUE → double mirror = double Z negative | Heal (UNIQUE migration) |
| P0-Z8-2 | `LoyaltyService.php:62-71` | Z8 | `refundPoints` bare `LoyaltyTransaction::create` — DB UNIQUE `(user_id, order_id, type)` throws 23000 → generic 500 (no 409 ALREADY_REFUNDED surface) | Heal (try/catch UNIQUE) |
| P0-Z8-4 | grep `kds.recall\|KdsRecallEvent\|recallItem` in `app/` = EMPTY | Z8 | KDS "recall" cascade does NOT EXIST server-side. Pure client-side localStorage Vuex (`resources/js/store/modules/kds.js:66-85`, 60s grace) | **OWNER DECISION**: V1 Le Cayenne supervised, may accept client-only OR add server cascade |

**P0-Z4-01 has the strongest exploit narrative — if reproduced, it's V1 block. Owner please POST-test before any other action.**

---

## D. P1 CLUSTER — sync-foundation-adjacent

Group these as a foundation cluster (consider single heal sprint or V1.0.2 backlog):

1. **Z2 P1** — `DispatchableAfterCommit` is **dead code** on every primary order creation site (`OrderService.php:572,1075,1385` + `FrontendOrderService.php:597-606,1212-1226`). All sites dispatch OUTSIDE `DB::transaction` closure → `transactionLevel()>0` check at `DispatchableAfterCommit.php:33` falls through. Docstring at `OrderCreated.php:14-17` advertises rollback-safety that isn't engaged.
2. **Z2 P1** — Sibling listeners alongside `PersistOrderCreatedToOutbox` (`DecrementItemAvailabilityOnOrder`, `DecrementStockOnOrderCreated`, `SendFcmOnOrderCreated`) are NOT idempotent and not `ShouldQueue` → duplicate dispatch double-decrements stock.
3. **Z3 P1 B-3** — `OutboxBroadcastSwallowedEvent` dispatched but no listener registered. Alarm void.
4. **Z3 P1 B-4** — KDS REST `_refresh` setInterval (`admin-kds.js:1565-1577`) bypasses `KdsSyncService._versionMap` dedup → polling+broadcast double-render race.
5. **Z3 P1 B-6** — `config/broadcasting.php:33` `polling_fallback.interval_ms` config value has **0 readers**; contract drifts: config (30s) vs KDS client (60s) vs kiosk (15s).
6. **Z3 P1 B-7** — `ws:heartbeat` fed by `broadcast()` 200, NOT subscriber delivery → false-green observability.
7. **Z5 P1-A** — `AuditLogService::verifyChain()` walk under 4s `z_report_b{N}` cache lock inside `ZReportService::open()` → year-3+ scale cliff.
8. **Z5 P1-B** — UNIQUE `(branch_id, prev_hash)` doesn't defend genesis NULL row (NULL distinct per MySQL/SQLite) → chain-fork hazard if cache driver degrades.
9. **Z5 P1-C** — `fiscal_alloc_error_at = now()` saved INSIDE parent transaction (`FrontendOrderService.php:1174`); save throws → flag rolls back → **pre-iter14 orphan reproduced**. ⚠ This was supposed to be fixed in iter14 — worth investigating regression.
10. **Z5 P1-D** — `orders` table NOT in Ansible REVOKE DROP/ALTER list (only 7 sibling fiscal tables). Hard-DELETE on orders is NOT trigger-blocked.
11. **Z5 P1-E** — `FiscalSequenceService::next()` MAX missing `withTrashed()`; soft-deleted order shadows MAX → next alloc returns same N → UNIQUE 1062 visible to cashier.
12. **Z6 P1-Z6-03** — `withoutGlobalScopes()` (plural) used 17×; kills BOTH BranchScope AND SoftDeletingScope. V1 LOCAL impact low (single tenant) but code-quality drift.
13. **Z6 P1-Z6-02** — `FormRequestAuthzDriftSentinelTest` baseline 69 — chronic fleet-wide posture, V1.0.2 backlog.
14. **Z7 P1** — `IdempotencyKeyMiddleware:77-82` scope = `(branch_id, user_id, sha256(key))`; same key + same body across 2 distinct routes collides.
15. **Z8 P1-1** — Refund is FULL-only; `RefundCreated::dispatch($parent)` always sends empty `refundedItems`. Partial-refund plumbed in event signature, never invoked.
16. **Z8 P1-2** — `PaymentService::cashBack:90-152` has NO `DB::transaction` wrapper outside outer txn context.
17. **Z8 P2-2** — Sync listener chain: if `ReleaseStockOnRefundCreated` throws, `PersistOrderPaymentStatusChangedOnRefundCreated` (WG-1 heal) never runs → WG-1 hole re-opens silently.
18. **Z1 P1-1** — `stock:scan-rupture` preventive cron defaults OFF (`config/catalog_v15.php` + `Kernel.php:135` + `StockScanRupture.php:58-61`). Reactive-only auto-86 leaves desync window between last-unit-sold and next order. Owner can enable via env.

---

## E. GREEN INVARIANTS CONVERGED (cross-zone)

What was verified solid across all 8 zones (file:line in source reports):

- **NF525 chain integrity**: triple-defense (Cache::lock + lockForUpdate + UNIQUE), HMAC chains with sentinel/min-32-char guards, daily chain monitor + daily archive cron with `onOneServer`+`withoutOverlapping`, BEFORE DELETE/UPDATE triggers (MySQL SIGNAL + SQLite RAISE parity), sealed-Z guard predicate consistency, archive command acquires same z_report lock (TOCTOU closure), TRUNCATE REVOKE sentinel-locked.
- **BranchScope**: 21 models scoped (verified count: 20 via BranchScope + 1 via WizardProfileBranchScope), 4 sentinels active (BranchScopeCoverage / ClaudeMdBranchScopeCount / F010BranchScopeQueueContext / FormRequestAuthzDrift), admin branch_id=0 bypass correct, Order::restore() blocked, DefaultAccessService clamps non-admin branch_id writes.
- **Idempotency**: 27 routes wired in routes/api.php, all covered by 24 patterns in config, `IdempotencyRequiredRoutesCoverageTest` walks `Route::getRoutes()` asserting drift, prod boot guard at `AppServiceProvider:143-151`, WebhookEvent ledger (Stripe + Senangpay) UNIQUE `(provider, webhook_id)`.
- **Stock cascade**: triple-defense decrement (tx + lockForUpdate + idempotency_key), composite UNIQUE on 3 stock tables, checkout-time revalidation via `assertItemsOrderableForBranch` with lock, commit-before-broadcast (`DispatchableAfterCommit` engaged correctly here), channel auth hardened against kiosk-token wildcard + guest-Echo bypass (`routes/channels.php:41-62`), append-only `stock_movements` (model `updating` LogicException + DB trigger migration), idempotent compensating release via `released_qty` ledger.
- **Pricing**: PricingService is sole computation site (6 entry points all route through `calculateOrder`), composition_snapshot zero UPDATE callsites (PR03), refund mirror carries snapshot verbatim, reorder triggers fresh SSOT, Stripe round-before-cast PR07, HMAC Quote sealing forced for POS+Kiosk, SplitPayment validates against server total.
- **Order lifecycle**: PersistOrderCreatedToOutbox FIRST listener confirmed (EventServiceProvider:146-152), DispatchableAfterCommit semantics when engaged, DB-level UNIQUE on `idempotency_key`, Phase-1 atomic claim in `DispatchDomainEventsJob`, envelope contract validation pre-broadcast, kiosk-token un-spoofable discriminator, KDS 409 optimistic-lock conflict, TZ-aware Paris→UTC sync bounds.

---

## F. TOP 25 HARD QUESTIONS FOR OWNER (curated from ~160)

**Cross-zone P0 reproduction**:
1. **Z4 P0-01** — Can you POST `{id:<any non-menu addon>, role:'menu_boisson'}` and observe a 60% charge reduction? **This single test decides V1 ship.**
2. **Z6/Z8 cross-confirmed** — Approve 3-line heal on `PosLoyaltyController.php:41` to add post-fetch branch check (mirror PosOrderController:117-121)?

**Stock cascade**:
3. Enable `FK_CATALOG_AUTO_86_PREVENTIVE_CRON_ENABLED=true` in prod `.env` for full preventive auto-86?
4. Wire dead-letter listener for `StockDecrementFailedEvent`?

**Sync foundation**:
5. Z3 B-1 worker-crash recovery lane — heal V1 or defer V1.0.2?
6. Z3 B-2 attempts reset infinite-flap — heal V1 or defer?
7. Z2 P1 `DispatchableAfterCommit` dead code — accept comment-driven trust on hot path, or move dispatches inside transaction closure?
8. Z3 B-3 `OutboxBroadcastSwallowedEvent` — write the listener? (alarm void)
9. Z3 B-4 KDS REST `_refresh` bypass — single dedup source-of-truth?

**Fiscal NF525**:
10. Z5 P1-C — Investigate iter14 regression on `fiscal_alloc_error_at` rollback? (was supposed to be fixed)
11. Z5 P1-D — Add `orders` table to Ansible REVOKE list?
12. Z5 P1-E — Add `withTrashed()` to `FiscalSequenceService::next()` MAX query?
13. Z5 P1-A — Move `verifyChain()` out of z_report open lock (year-3+ scale concern)?

**Refund + loyalty**:
14. Z8 P0-4 KDS recall — accept localStorage-only for V1 Le Cayenne supervised, OR add server cascade?
15. Z8 P0-1 — Add UNIQUE migration on `parent_order_id` (currently INDEX only)?
16. Z8 P0-2 `refundPoints` — wrap in try/catch UNIQUE → return 409 ALREADY_REFUNDED?
17. Z8 P1-1 — Refund FULL-only acceptable for V1, or expose partial refund flow?
18. Z8 P1-2 — Wrap `PaymentService::cashBack` in `DB::transaction`?
19. Z8 P2-3 — No outbound refund webhook to Stripe/Senangpay (V1 cash-only) — accept or wire?

**BranchScope / multi-tenant**:
20. Z6 P1-Z6-03 — Cleanup 17 `withoutGlobalScopes()` (plural) call-sites in V1.0.2?
21. Z6 P2 — Customer model exemption per-endpoint enumeration sweep — V1.0.2?

**Idempotency**:
22. Z7 P1 — Add route path to scope key formula (`branch_id, user_id, route, sha256(key)`)?
23. Z7 P2 — Add 425 race-path E2E test?

**Sync structure (cross-cut)**:
24. Should we promote `PRICING_USE_SSOT` to AppServiceProvider refuse-to-boot invariant?
25. Single dedup source-of-truth across Echo + polling + REST (instead of 3 separate stores)?

---

## G. RECOMMENDED OWNER DECISION TREE

```
                            ┌─ V1 BLOCK ─────────────┐
                            │ • Z6/Z8 P0-CROSS-1     │  ← APPROVE 3-line heal
                            │   PosLoyaltyController │     mirror sibling pattern
                            │                        │
                            │ • Z4 P0-01 IF reproes  │  ← POST-test first
                            │   role injection       │     decide after observation
                            └────────────────────────┘
                                       │
                                       ▼
                        ┌─ V1 ACCEPTABLE w/ NOTE ─────┐
                        │ • Z1 P1: enable env cron    │  ← simple .env flag
                        │ • Z8 P0-4: client-side OK   │  ← V1 supervised single-resto
                        │ • Z8 P1-1: full-refund OK   │  ← V1 doesn't need partial
                        │ • Z2 P1 listener idempotency│  ← document, V1.0.2 fix
                        └─────────────────────────────┘
                                       │
                                       ▼
                        ┌─ V1.0.2 BACKLOG ────────────┐
                        │ • Z3 B-1/B-2 sync recovery  │
                        │ • Z3 B-3 swallow listener   │
                        │ • Z5 P1 fiscal scaling      │
                        │ • Z6 cleanup 17 sites       │
                        │ • Z7 scope key collision    │
                        │ • ~12 P2/P3 cosmetic        │
                        └─────────────────────────────┘
```

---

## H. ATTESTATIONS

- **Frozen-zone diff (CLAUDE.md §7)**: 0 lines this audit (read-only)
- **NF525 chain integrity**: not touched this audit (chain count + last_hash unchanged)
- **No code mutations**: only `reports/audit/v1-sync-deep-audit-2026-05-19/*.md` written
- **8 sub-agent reports**: file paths above; total ~14,000 words; all file:line citations Read this session
- **Working tree**: branch `v1-0-1-hardening-2026-05-17`, local-only, no cloud refs

---

## I. NEXT STEPS

**Owner-driven** (no auto-execution):
1. **First decision** — POST-test Z4 P0-01 role injection on local server. Result determines V1 block vs not.
2. **Approve** — Z6/Z8 cross-confirmed PosLoyaltyController:41 heal (3-line, low-risk).
3. **Triage** — Walk top 25 questions; mark each as: heal-now / V1.0.2 backlog / accept-with-doc.
4. **Continue manual test** — diagram + 8 surfaces still live at http://127.0.0.1:8000/

**Claude-side hold**: I'll wait for owner heal decisions before touching code. Per audit-only mandate.

---

**Source reports** (deep-dive each):
- `RED-Z1-stock-cascade.md` (Z1: 8/10)
- `RED-Z2-order-lifecycle.md` (Z2: 7.5/10)
- `RED-Z3-sync-reliability.md` (Z3: 7.2/10)
- `RED-Z4-pricing-ssot.md` (Z4: 7/10)
- `RED-Z5-nf525-fiscal.md` (Z5: 8.4/10)
- `RED-Z6-branchscope.md` (Z6: 7/10)
- `RED-Z7-idempotency.md` (Z7: 8/10)
- `RED-Z8-refund-loyalty.md` (Z8: 6/10)
