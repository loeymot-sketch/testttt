# GOAL 2.0 — Phase 1 (safe non-frozen heals) — completion

**Branch:** `heal/massive-2dot0-2026-06-14` · HEAD after Phase 1 = `24cb3b4f0` · base `2477ff9fc`.
**Plan:** `plans/GOAL_2DOT0_HARDENING_CLOUD_CUTOVER_2026-06-14.md` Phase 1. Owner: "Run Phase 1 now".
**Gate posture:** every item NON-frozen, no owner gate. Full PHPUnit **3317/0**, frozen 0.

## HEALED
| ID | Item | Commit | Evidence |
|----|------|--------|----------|
| **UNI-03** | env→config migration — closes the **latent-LIVE P0** (`config:cache` already in the OVH deploy → `env('CURRENCY')` rendered null money on prod). NEW config/currency.php + config/localization.php; AppLibrary money/date, OrderItemResource, DEMO×6, APP_NAME/URL → config(). | `cf174a5d8` | Under `config:cache`: `config('currency.code')='EUR'` + '50,00 €' while raw `env('CURRENCY')=NULL`. NoRequestTimeEnv sentinel. |
| **RCH-01/02/03** | Outbox-guard class completion — `GuardsOutboxPersistence` on ALL **14** `Persist*ToOutbox` (was 3) + ratchet sentinel (count=14). | `621e840f6` | Empirical: `PersistLoyaltyBalanceChangedToOutbox` exists (plan's "stale" claim wrong) → guarded. Real throw test (drops domain_events). |
| **SEC-APIKEY-TIMING** | ApiKeyMiddleware `===` → `hash_equals` (constant-time) + null/empty-config guard. | `24cb3b4f0` | Source sentinel; ForgotPassword sentinel exercises the valid-key path green. |
| **NF-4** | Wire read-only `fiscal:verify-z-membership` into deploy fiscal smoke ([10b/12], WARN-first per G-ZMEMBER-THRESHOLD). | `24cb3b4f0` | deploy.sh `bash -n` OK; scheduler+pager already exist (Kernel.php:91). |

## REFUTED (empirical > inference — verify-before-heal avoided 2 NO-OP heals)
- **RCH-04 (cleanup-notif wrong column → spam):** REFUTED. `CleanupStalePendingKioskOrders:59` selects `source_surface='kiosk'`, which `FrontendOrderService:547` DOES set for kiosk orders; the query is a narrow conjunction (order_type + status + payment + `fiscal_sequence_no IS NULL` + TTL) and sends exactly one cancellation notice per genuinely-stale order. No wrong-column bug, no spam.
- **RCH-05 (stock double-credit on cancel-then-refund):** REFUTED. StockService's release READS `order_items.released_qty` (`StockService:381`) to compute `remainingQty`; AvailabilityService WRITES it (`AvailabilityService:772`). The shared ledger — not the reason-keyed movement key — is the cross-reason guard. The existing `StockReleaseTest::test_order_canceled_then_full_refund_second_delivery_is_no_op` proves `released_qty=5` (not 10) after both events → the 2nd release (any reason) is a no-op → `on_hand` credited once. The `PaymentService:207` "idempotent" comment is correct for BOTH stock and availability.

## DEFERRED (documented reasons)
- **NF-2 (kiosk `finalizePaidKioskOrder` flag-or-allocate):** forward exposure not clearly reachable — in the forward callers (`OrderController:272`, `PaymentReconcileController:257`) the false-return only hits NON-realized-paid kiosk orders (kiosk-cash = PENDING_COUNTER, no seq needed yet); the realized-paid non-kiosk case is the CRON, already covered by CR-P1a generic salvage (`RetryFiscalAllocCommand:108`). The method is a sensitive fiscal path the plan flagged owner-flag → deferred (low value × non-trivial risk).
- **FormRequest return-true ratchet:** already tight — sentinel baseline `RETURN_TRUE_BASELINE=66` == actual count; no slack without a risky authz refactor. No action.
- **LoginController dummy-hash:** minor (~35ms timing), deferred to a focused security pass.

## Remaining (Phase 2+, gated)
- **Phase 2 NF-1** (frozen ZReportService late-salvage) — LOCK drafted `plans/LOCK_FISC-EXH-01_ZREPORT_LATE_SALVAGE_2026-06-14.md`, owner APPROVED, awaits signature + the NF-1-prereq column.
- **Phase 2 NF-3 / Phase 4 DEP-4** legacy backfill (owner data-mutation gate).
- **Phase 4** OVH cutover (DEP-0…DEP-6). **Phase 5** design/UX (DUX-1…7).

## Tally
Campaign **26 heals**; Phase 1 = 3 heals + NF-4 wiring + 2 empirical refutations + 3 documented deferrals. Pushed to PR `heal/massive-2dot0-2026-06-14`.
