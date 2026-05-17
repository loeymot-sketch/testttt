# RED-team Audit A3 — Outbox + Events + KDS V2

**Branch**: `v1-0-1-hardening-2026-05-17` @ HEAD `1235e3e1a`
**Auditor**: A3 (round 1)
**Date**: 2026-05-18
**Scope**: 10 axes — Refund event wiring, Settings fanout, Branch token revoke, outbox/webhook pruning, listener parity, KDS V2 claims, Stripe/SenangPay refund webhooks, OSS wakeLock.

---

## TL;DR

- **Implemented claims (7/10) — VERIFIED GREEN**: RefundCreated wire (5F), SettingsUpdated fanout (5G R9), BranchStatusChanged revoke (5G R10), PruneOutboxCommand (5E), PruneWebhookEventsCommand (5E), all 11 outbox listeners retain `wasRecentlyCreated`, OSS wakeLock (5G R4).
- **Mis-attributed claims (3/10) — NOT IMPLEMENTED, deferred V2 backlog (per commit body)**: KDS V2 bumped state cross-station sync, Kitchen printer auto-fallback when KDS muet, Stripe/SenangPay refund webhook handlers.
- **Findings**: 0 P0, 2 P1, 4 P2, 3 P3.

The Wave 5F commit message itself lists the 3 missing items as "V2 backlog". The audit task brief misread these as completed. Code review of `app/`, `routes/`, and `tests/` confirms zero implementation.

---

## Axis 1 — RefundCreated dispatch (5F P0-#8) — VERIFIED GREEN

**Claim**: Two production call sites now dispatch.

- `app/Services/Order/RefundWithCounterEntryService.php:229` — `RefundCreated::dispatch($parent)` inside `connection->transaction()` closure. Comment block lines 224-228 explains: passes PARENT (positive qty) so listener iterates `$order->orderItems` for release ledger; `DispatchableAfterCommit` defers until commit. **Correct.**
- `app/Services/PaymentService.php:134` — `RefundCreated::dispatch($order)` inside `if ($transaction)` block of `cashBack()` (line 85-138). Idempotent-by-construction: second cashBack call early-returns at line 78-80 before reaching dispatch. **Correct.**

**Consumers** (`app/Providers/EventServiceProvider.php:165-168`):
- `App\Listeners\ReleaseStockOnRefundCreated`
- `App\Listeners\ReleaseAvailabilityOnRefundCreated`

Both exist (`app/Listeners/Release{Stock,Availability}OnRefundCreated.php`). RefundCreated has NO outbox/broadcast listener — POS/Kiosk receive no live broadcast. Acceptable in V1 (admin-only refund flow), but worth flagging.

**Test** `tests/Feature/RefundCreatedDispatchTest.php` (116 LOC, 2 tests):
- `test_cashback_dispatches_refund_created_event_once` — Event::fake + create paid transaction + call cashBack → asserts 1 dispatch with matching order id.
- `test_cashback_idempotent_call_does_not_re_dispatch_event` — two consecutive cashBack calls → still 1 dispatch (second hits existing-cashback early-return at PaymentService.php:78-80).

**Coverage gap (P3)**: No test for `RefundWithCounterEntryService:229` — only PaymentService::cashBack covered.

### Findings A3-1
- **P3-A3-1-a**: `RefundWithCounterEntryService::execute` dispatch site has no Event::fake sentinel. The frozen `tests/Feature/RefundCounterEntryTest.php` could carry an extra assertion at marginal cost. Doc-accept or add sentinel.
- **P2-A3-1-b**: RefundCreated is **NOT persisted to outbox** — POS/Kiosk listening on `private-branch.{id}` will not receive a `RefundCreated` broadcast. If frontend kart/availability ever depends on it (currently inferred from `OrderStatusChanged` / `CatalogChanged`), would silently regress. Document as V2 backlog OR add `PersistRefundCreatedToOutbox` mirror.

---

## Axis 2 — SettingsUpdated fanout (5G R9) — VERIFIED GREEN

**Event** `app/Events/SettingsUpdated.php` (33 LOC):
- 2-arg ctor: `array $changedKeys`, `?int $branchId` (null = global fan-out).
- `DispatchableAfterCommit` trait → safe inside service transactions.

**Listener** `app/Listeners/PersistSettingsUpdatedToOutbox.php` (119 LOC):
- Lines 29-35: `branchId !== null` → single row; else `Branch::whereIn('status', [ACTIVE, 1])->pluck('id')` (mirror Catalog/Coupon parity with literal 1 fallback). **Sound.**
- Lines 42-44: `sort($sortedKeys)` BEFORE fingerprint → order-stable idempotency. Test `test_idempotency_key_is_stable_regardless_of_changed_keys_order` validates.
- Lines 50-55: idempotency_key = sha1(SETTINGS_UPDATED|branchId|fingerprint|correlationId). Matches `PersistCatalogChangedToOutbox.php` / `PersistCouponChangedToOutbox.php` shape.
- Lines 57-77: `firstOrCreate` keyed on `idempotency_key` (DB UNIQUE `uniq_domain_events_idempotency_key` from migration `2026_05_09_180000_add_idempotency_key_to_domain_events.php`).
- Lines 75-77: `wasRecentlyCreated` guard before appending to dispatch list. **Parity confirmed.**
- Lines 84-99: `DB::afterCommit(...)` wraps `DispatchDomainEventsJob::dispatch($domainEventId)`. Best-effort with `Log::warning` fallback. **Correct.**

**Controllers (5/5 wired)**:
- `app/Http/Controllers/Admin/CurrencyController.php:38, 50, 62` — store/update/destroy.
- `app/Http/Controllers/Admin/OrderSetupController.php:37`.
- `app/Http/Controllers/Admin/CompanyController.php:36`.
- `app/Http/Controllers/Admin/TaxController.php:39, 51, 63` — store/update/destroy.
- `app/Http/Controllers/Admin/SiteController.php:40`.

All gated by `middleware(['permission:settings'])` on mutating actions (verified at CurrencyController.php:21 — pattern shared).

**Test** `tests/Feature/Settings/SettingsUpdatedBroadcastTest.php` (121 LOC, 5 tests):
- dispatch shape, active-branch fan-out (excludes INACTIVE), targeted single-branch mode, replay idempotency (X-Correlation-ID stable), key-order stability.

### Findings A3-2
- **P2-A3-2-a**: Dispatch is OUTSIDE the service tx (`CurrencyController.php:38` — `$this->currencyService->store($request)` returns first, then dispatch). If service uses internal `DB::transaction()`, the event still goes through `DispatchableAfterCommit::dispatch()` with transactionLevel=0 at that point → fires immediately (no afterCommit wrap). Functionally OK since service has already committed, but inconsistent with the AfterCommit invariant doc (`DispatchableAfterCommit.php:13-16`). Doc-clarify.
- **P3-A3-2-b**: Lines 50-55 fingerprint omits payload deltas (e.g. updated currency code). POS/Kiosk receiving `SettingsUpdated{changed_keys:['currency']}` must REFETCH `/api/setting` — heavy on 50-branch deploys × 5 currency edits/day. Document as design choice (broadcast-tail-refetch) OR carry minimal diff.

---

## Axis 3 — BranchStatusChanged token revoke (5G R10) — VERIFIED GREEN

**Event** `app/Events/BranchStatusChanged.php` (33 LOC) — `branchId, oldStatus, newStatus` triple. Advisor note 1 quoted in docblock: oldStatus prevents re-fire on save-without-transition.

**Listener** `app/Listeners/RevokeTokensOnBranchDeactivated.php` (69 LOC):
- Line 27-29: `if oldStatus === newStatus → return` (no-transition guard).
- Line 31-33: `if newStatus !== INACTIVE → return` (only INACTIVE triggers revoke — reactivation is no-op).
- Line 52-55: `PersonalAccessToken::query()->where('tokenable_type', User::class)->whereIn('tokenable_id', $userIds)->delete()`. **Scope-strict on `User::class`.** KioskMachine tokens untouched (verified by test #5 below).
- Lines 56-66: structured Log::warning with security channel fallback.

**Dispatcher** `app/Http/Controllers/Admin/BranchController.php:71`:
```
$oldStatus = (int) $branch->status;
$updated   = $this->branchService->update($request, $branch);
$newStatus = (int) $updated->status;
if ($oldStatus !== $newStatus) {
    BranchStatusChanged::dispatch((int) $updated->id, $oldStatus, $newStatus);
}
```
Comment lines 62-66 explains the `tap(...)->update()` mutation order trap. **Correct.**

**Test** `tests/Feature/Branch/BranchDeactivationTokenRevokeTest.php` (173 LOC, 5 tests):
1. ACTIVE→INACTIVE revokes 2/2 tokens.
2. INACTIVE→INACTIVE no-op (re-save).
3. INACTIVE→ACTIVE no revoke (reactivation safe).
4. Other-branch users untouched.
5. KioskMachine tokenable_type untouched — inserted directly via `DB::table('personal_access_tokens')->insert(['tokenable_type' => 'App\\Models\\KioskMachine', ...])` to bypass mass-assign guard. Asserts kiosk token survives and User token gets purged.

### Findings A3-3
- **P3-A3-3-a**: No test for **reactivation immediately following deactivation** (deactivate then reactivate within same minute). The listener has nothing to "restore" since tokens were deleted, but staff will need to re-login post-reactivation. Document this UX cost. Currently invisible to the admin UI — Wave 5G adds no toast/alert.
- **P3-A3-3-b**: User belonging to MULTIPLE branches? FoodKing `users.branch_id` is single-tenant (column type `int`). Edge: admin user with `branch_id=0` is filtered out by `where('branch_id', $event->branchId)` since 0 != target_id. Safe; admin sessions persist after a branch goes INACTIVE. Acceptable.

---

## Axis 4 — PruneOutboxCommand (5E P0-#4) — VERIFIED GREEN

**File** `app/Console/Commands/PruneOutboxCommand.php` (104 LOC).

- Signature: `foodking:outbox:prune {--older-than-days=90} {--batch=1000} {--dry-run}`.
- Safe-set UNION:
  - (A) `dispatched_at NOT NULL AND dispatched_at < cutoff` — pure history.
  - (B) `attempts >= 6 AND created_at < cutoff` — terminal failure past retry-failed lane.
- Predicate closure shared between count + delete → single source of truth.
- Chunked: `LIMIT $batch` loop, predicate-based (cursor-stable, not ID-range).
- NF525 docblock lines 25-27: emphasizes domain_events is operational outbox; audit_logs + z_reports never touched.

**Race concern** (axis 4 brief): pruning hits while ProcessOutboxJob retries an old row.
- `app/Jobs/DispatchDomainEventsJob.php:65-78`: claim phase under `lockForUpdate()`; if row pruned before job picks it up → `! $domainEvent` returns silently (line 70-73).
- If job picks first and locks row, prune query blocks on the row lock then deletes after job commits dispatched_at. **Safe.**

**Kernel schedule** `app/Console/Kernel.php:100-106`:
- `dailyAt('04:00')`, `name('outbox-prune')`, `withoutOverlapping`, `onOneServer`, `runInBackground`. Off-peak after 02:00 fiscal archive. **Production-grade.**

### Findings A3-4
- **P2-A3-4-a**: 90d retention covers Pusher/Soketi restart window (~6.4min worst-case retry per backoff `[1,5,15,60,300]` × tries=6). But cross-region disaster recovery may need a 6h-7d freeze where prune should be paused. No `--respect-disaster-flag` or `app.maintenance` check. Add `if (app()->isDownForMaintenance()) return self::SUCCESS;` for safety OR document the trade-off.
- **P3-A3-4-b**: clause (B) keeps `attempts >= 6 AND created_at < 90d` only. A row created 91d ago with attempts=5 (never reached terminal) is **never pruned** — would only grow if the row sits in retry-fail purgatory. Staleness monitor `foodking:outbox:monitor` should page humans for `attempts >= 5 AND created_at > 24h`. Cross-check that the monitor exists.

---

## Axis 5 — PruneWebhookEventsCommand (5E sibling) — VERIFIED GREEN with caveat

**File** `app/Console/Commands/PruneWebhookEventsCommand.php` (102 LOC).

- Signature: `foodking:webhook:prune {--older-than-days=90} {--batch=1000} {--dry-run}`.
- Safe-set: `status IN (processed, duplicate) AND received_at < cutoff`.
- DO NOT prune: `pending` (handler retrying), `failed` (DLQ retry lane owns).
- NF525 invariant doc: `webhook_events` is operational; fiscal payment evidence on `order_payments` + `audit_logs` (6y).
- Idempotent + chunked + cursor-stable.

**Schedule** `app/Console/Kernel.php:113-119`: `dailyAt('04:15')` — staggered +15min vs outbox prune for distinct lock windows.

### Findings A3-5
- **P1-A3-5-a — GDPR-vs-PCI tension UNADDRESSED**: 90d for webhook_events conflicts with PCI DSS 10.5.3 (audit trail history) and Visa/Mastercard chargeback windows (up to 120-180 days for disputes). Stripe disputes are typically `~75 days`; SenangPay typically `30 days`. **A 90d cutoff is borderline.** Recommend lifting to 180d explicitly OR adding a config knob with PCI-safe default `--older-than-days=180`. Currently the only documented justification is "90d window exceeds any provider replay horizon" (line 26), which conflates replay with dispute. NF525 archives the FISCAL payment via `order_payments`, but the webhook payload (signature, retries, dispute hooks) is forensic evidence the merchant needs to defend disputes.
- **P3-A3-5-b**: `failed` status stays forever per the docblock. The DLQ retry-failed command (`foodking:webhook:retry-failed`) should have a separate hard-archive lane (e.g. 365d) — otherwise `webhook_events` grows from `failed` rows. Verify command exists and has retention.

---

## Axis 6 — Outbox listener parity (`wasRecentlyCreated`) — VERIFIED GREEN

11/11 outbox persistence listeners use `firstOrCreate` + `wasRecentlyCreated` guard:

| Listener | wasRecentlyCreated | firstOrCreate |
|---|---|---|
| PersistCatalogChangedToOutbox | 1 | 3 |
| PersistCouponChangedToOutbox | 1 | 2 |
| PersistItemAvailabilityChangedToOutbox | 1 | 3 |
| PersistItemExtraAvailabilityChangedToOutbox | 1 | 2 |
| PersistItemVariationAvailabilityChangedToOutbox | 1 | 2 |
| PersistOrderCreatedToOutbox | 2 | 2 |
| PersistOrderPaidAtCounterToOutbox | 2 | 2 |
| PersistOrderPaymentStatusChangedToOutbox | 1 | 2 |
| PersistOrderStatusChangedToOutbox | 2 | 2 |
| PersistOrderTableChangedToOutbox | 1 | 2 |
| PersistSettingsUpdatedToOutbox | 1 | 1 |

DB-level UNIQUE constraint `uniq_domain_events_idempotency_key` (migration `2026_05_09_180000_add_idempotency_key_to_domain_events.php`) provides race-safe dedupe even if listener fires concurrently in two queue workers. **No regression from Wave 5D-5I.**

### Findings A3-6
- None. Parity intact.

---

## Axis 7 — KDS V2 bumped-state cross-station sync — NOT IMPLEMENTED

**Claim**: Wave 5F backend sync of bumped state.

**Evidence**: Wave 5F commit body (`git show --stat 55edb83ba` → P1 section line "KDS bumped state cross-station backend sync (**V2**)"). Listed as V2 BACKLOG, not implemented.

- `grep -rn "bumped\|bump_state\|item_bumped" app --include="*.php"` → 0 hits in KDS services.
- `app/Services/KitchenDisplaySystemOrderService.php` and `app/Services/KdsSyncService.php` have NO bump-state column writes/reads.
- `app/Services/KdsSyncService.php:130-145`: `computeOrderVersion` still TODO-noted for `status_changed_at` column (line 132-136). Bumped state is per-line-item, not per-order; nothing in the codebase models it.
- `resources/js/components/admin/kitchenDisplaySystem/KdsV2Grid.vue`: FE-only component, no API call to a backend bump endpoint.

### Findings A3-7
- **P2-A3-7-a — CLAIM MIS-ATTRIBUTED**: Audit task brief states "KDS V2 bumped state cross-station sync (mentioned Wave 5F commit body) — claim: backend sync of bumped state". Commit body explicitly says **(V2)** = deferred. Two cashiers at two KDS stations who tap a line-item bump do **NOT** sync — each station has local state only. **Real but non-critical for V1 single-station deploys (Le Cayenne).** Track as V1.0.2 backlog.

---

## Axis 8 — Kitchen printer auto-fallback when KDS muet — NOT IMPLEMENTED

**Claim**: Wave 5F/5H added auto-fallback to printer when KDS unresponsive.

**Evidence**:
- Commit body of `55edb83ba` lists: "Kitchen printer auto-fallback when KDS muet (V2)" — explicitly V2 backlog.
- `grep -rn "kds_unresponsive\|kdsUnresponsive\|fallback_to_printer\|printerFallback" app resources` → 0 hits.
- No heartbeat detection, no `kds_last_heartbeat_at` column, no `auto_fallback_printer_at` flag.

### Findings A3-8
- **P2-A3-8-a — CLAIM MIS-ATTRIBUTED**: Same as A3-7. Brief reads as implemented; commit body classifies as V2 backlog. **No auto-fallback exists.** If a KDS goes offline mid-rush, tickets are simply not printed/displayed — kitchen must manually trigger reprint. Document V1 operational mitigation (manual reprint UX in POS).
- **P3-A3-8-b — Speculative**: If implemented later, false-positive risk on network blip → printer storm. Threshold should be 30-60s heartbeat absence with hysteresis (e.g. require 3 consecutive missed beats) + per-branch rate-limit on auto-print (max 1 ticket / 5s).

---

## Axis 9 — Stripe/SenangPay refund webhook handlers — NOT IMPLEMENTED

**Claim**: Wave 5H added refund webhook handlers.

**Evidence**:
- Wave 5F commit body (5F predates 5H but covers same scope) line: "Stripe/SenangPay refund webhook handlers do NOT exist (V2 backlog)".
- `grep -rn "refund\|Refund" app/Http/Controllers --include="*.php" | grep -iE "stripe|senangpay|webhook"` → 0 hits.
- `grep -rln "charge.refunded\|refund.created\|StripeRefund\|SenangPayRefund" app` → 0 hits.
- `app/Http/PaymentGateways/Gateways/Stripe.php` and `Senangpay.php` have NO `refund`-handling logic.

### Findings A3-9
- **P1-A3-9-a — CLAIM MIS-ATTRIBUTED + REAL GAP**: Customer-initiated refund via gateway dashboard (Stripe Dashboard, SenangPay portal) → **no callback into FoodKing**. `Order` stays `PAID` in FoodKing while gateway shows refunded. Z-report breaks (cash on FoodKing != gateway settlement). For V1 single-resto Le Cayenne the refund flow is admin-initiated via `RefundWithCounterEntryService` (handles fiscal mirror), so the **gateway-initiated** flow is the gap. Document operational SOP: refunds MUST be issued in FoodKing admin, NEVER in gateway dashboard. Add a runbook entry under `docs/`.
- **P2-A3-9-b**: When V2 implements webhook handlers, `firstOrCreate` on `webhook_events(provider, webhook_id)` UNIQUE remains the idempotency anchor. Double-refund prevention: ensure handler checks `Order::payment_status == PAID` before calling RefundWithCounterEntryService.

---

## Axis 10 — OSS wakeLock TV walls (5G R4) — VERIFIED GREEN

**File** `resources/js/components/admin/orderStatusScreen/PreparingAndReadyComponent.vue`:
- Lines 92-94: `_wakeLockSentinel: null`, `_onVisibilityChange: null` data slots.
- Lines 118-123: `mounted()` → `_acquireWakeLock()` + visibilitychange listener.
- Lines 142-147: `beforeUnmount()` → `_releaseWakeLock()` + listener removal.
- Lines 171-181: `async _acquireWakeLock()`:
  - Line 172-173: `if (flag === false) return` — feature flag `window.foodkingConfig.ossWakeLockEnabled`.
  - Line 174: `if (!('wakeLock' in navigator) || typeof navigator.wakeLock?.request !== 'function') return` — Safari <16.4 fallback.
  - Line 175: idempotent (sentinel already held).
  - Line 177-179: `await navigator.wakeLock.request('screen')`, attach `release` event for auto-clear on system release.
  - Line 180: try/catch → graceful degrade. **Correct.**

**Test** `tests/js/ossWakeLockOnMount.spec.js` (102 LOC, 5 tests):
1. Static source assertions (sentinel var, async _acquireWakeLock, _releaseWakeLock, navigator.wakeLock.request('screen')).
2. Feature flag honored (`if (flag === false) return`).
3. visibilitychange listener wires `_acquireWakeLock`.
4. Logic mock test: requestSpy called once with 'screen', idempotent on second call.
5. Graceful degrade when flag false (no spy call).
6. Graceful degrade when wakeLock API missing (Safari <16.4).

(Task brief says "6/6" — counts above sum to 6 including static + logic.)

**Browser support**: Chrome 84+, Edge 84+, Opera 70+, Firefox 126+ (May 2024), Safari iOS 16.4+. **Older browsers gracefully no-op (test 6 sentinel).**

### Findings A3-10
- **P2-A3-10-a**: System-initiated release (battery-saver, lid-close) fires sentinel's `release` event (line 179) → `_wakeLockSentinel = null`. **But re-acquire only happens on `visibilitychange`** — if user keeps tab visible while system kills wakeLock (e.g. low-battery laptop), no re-acquire until visibility flip. Add: also re-acquire on `release` event itself OR periodic 60s heartbeat.
- **P3-A3-10-b**: KDS (`KitchenDisplaySystemComponent.vue`) does not have wakeLock. TV walls on a single KDS also burn out. Document as parallel V1.0.2 backlog.

---

## Round 1 Findings Summary (A3)

| ID | Sev | Title | Disposition |
|---|---|---|---|
| A3-1-a | P3 | RefundWithCounterEntryService dispatch sentinel gap | Add to RefundCounterEntryTest |
| A3-1-b | P2 | RefundCreated has no outbox listener (no broadcast) | V1.0.2 backlog or doc-accept |
| A3-2-a | P2 | SettingsUpdated dispatch outside service tx — inconsistent w/ AfterCommit intent | Doc-clarify |
| A3-2-b | P3 | Settings broadcast no payload diff → POS refetches /api/setting | Design choice — document |
| A3-3-a | P3 | No test for fast deactivate→reactivate UX | Doc operational SOP |
| A3-3-b | P3 | Admin (branch_id=0) bypass on revoke — correct, undocumented | Doc |
| A3-4-a | P2 | PruneOutboxCommand has no maintenance-mode bypass | Add `app()->isDownForMaintenance()` check |
| A3-4-b | P3 | Rows attempts<6 AND age>90d never pruned | Verify monitor pages humans |
| **A3-5-a** | **P1** | **webhook_events 90d retention conflicts w/ PCI dispute window 75-180d** | **Bump to 180d default OR config** |
| A3-5-b | P3 | `failed` status rows never archived | Verify retry-failed lane has retention |
| A3-6 | — | All 11 outbox listeners pass wasRecentlyCreated parity | None |
| A3-7-a | P2 | KDS V2 bumped-state cross-station sync — NOT IMPLEMENTED (claim misattributed) | V1.0.2 backlog |
| A3-8-a | P2 | Kitchen printer auto-fallback — NOT IMPLEMENTED (claim misattributed) | V1.0.2 backlog |
| **A3-9-a** | **P1** | **Stripe/SenangPay refund webhook handlers — NOT IMPLEMENTED. Gateway-side refund drift = Z-report mismatch** | **Runbook + V1.0.2 backlog** |
| A3-9-b | P2 | V2 webhook handler future-design note | V1.0.2 spec |
| A3-10-a | P2 | OSS wakeLock no re-acquire on `release` event itself | Add release-listener + reacquire OR 60s ping |
| A3-10-b | P3 | KDS lacks parallel wakeLock | V1.0.2 backlog |

**Score**: 0 P0 (no merge-blocker on axis A3), 2 P1, 7 P2, 6 P3. **Axis A3 is GREEN for V1 ship.** P1 items are operational/runbook risks, not code defects.

---

## Cross-axis observations

1. **3/10 claims in the audit brief are misattributed.** Wave 5F commit body explicitly tags KDS bump-sync, printer auto-fallback, Stripe/SenangPay refund webhooks as **(V2)** = deferred. Auditor must read commit body, not just trust brief.
2. **Outbox + listener parity is the cleanest sub-system in V1.0.1.** 11/11 listeners follow identical idempotency contract; DB UNIQUE + `wasRecentlyCreated` + `DispatchableAfterCommit` are the canonical triple.
3. **Webhook retention 90d is the only borderline operational decision.** Recommend bumping to 180d default.
