# K18 — Order creation + Payment + Idempotency + Fiscal

> Branch `feature/mobile-app-le-cayenne-2026-05-10` — actual HEAD at audit run
> = **`6a33a9763`** (prompt cited `245e8ab57`, drift recorded). Mode read-only,
> citations file:line vérifiées sur le HEAD ci-dessus.

## Files audited
- `app/Http/Controllers/Frontend/OrderController.php` (315 lines) — main K18 controller
  (prompt cited path `FrontendOrderController.php` ; that name is **NOT** present
  at `app/Http/Controllers/Frontend/` — the file is `OrderController` aliased as
  `FrontendOrderController` in `routes/api.php` use-import block; **path drift recorded**).
- `app/Http/Controllers/Frontend/PaymentReconcileController.php` (318 lines) — F-008 batch reconcile
- `app/Http/Middleware/IdempotencyKeyMiddleware.php` (244 lines) — F-VERIFY-09-02 dual-layer
- `app/Services/Fiscal/FiscalSequenceService.php` (105 lines) — FROZEN, audit OK
- `app/Services/Pricing/PricingService.php` (head ~100 lines) — FROZEN, audit OK
- `app/Domain/Order/OrderStateMachine.php` (313 lines) — FROZEN, audit OK
- `app/Services/FrontendOrderService.php` (1215 lines) — entry `myOrderStore` :131, `finalizePaidKioskOrder` :1044
- `app/Services/Cash/CashDrawerService.php` (verified head 150 lines) — P0-09 fix
- `app/Models/Order.php` (234 lines), `app/Models/OrderItem.php` (94 lines) — P0-01/02 context
- `app/Http/PaymentGateways/Gateways/Senangpay.php` (48 lines) — P0-11 fix
- `app/Http/PaymentGateways/Routes/senangpay.php` (19 lines) — `/payment/senangpay-webhook/`
- `config/idempotency.php` (37 lines) — P0-05 flag
- `app/Http/Requests/OrderRequest.php` (head 80 lines) — P0-08 ability gate
- `routes/api.php:789-799` (collect-kiosk-cash), `:1102-1128` (frontend/order + reconcile)
- `app/Console/Commands/RetryFiscalAllocCommand.php` (head 90 lines)
- Migrations audited :
  - `2026_05_09_120000_create_webhook_events_table.php`
  - `2026_05_09_160000_add_z_reports_delete_trigger_immutability.php`
  - `2026_05_09_200000_add_fiscal_alloc_error_at_to_orders.php`
  - `2026_05_10_010000_secure_fiscal_audit_trail_immutability.php`
  - `2026_05_10_020000_add_unique_partial_cash_drawer_open.php`
  - `2026_04_18_140003_scope_idempotency_key_to_branch.php`
- Tests cross-checked : `tests/Feature/Fiscal/ZReportDeleteTriggerMysqlOnlyTest.php`,
  `tests/Support/MysqlOnly.php`.

## Mandatory P0 re-verify table — HEAD `6a33a9763`

| P0 | Description | Status @HEAD | Evidence (file:line + quoted code + commit) |
|---|---|---|---|
| **P0-01** | `Order` model has SoftDeletes trait → NF525 risk (soft-deleted fiscal-emitted orders excluded from Z aggregation) | **MITIGATED (Option A — withTrashed in ZReportService)** — soft delete trait still present, but aggregation re-includes soft-deleted rows | `app/Models/Order.php:11` `use Illuminate\Database\Eloquent\SoftDeletes;` + `:17` `use SoftDeletes;` (trait IS used). Mitigation: `app/Services/Fiscal/ZReportService.php:338` `->withTrashed() // [P0-FIX-1/2] include soft-deleted post-allocation orders for NF525 fiscal continuity`. Restore is blocked at `Order.php:108-116` `static::restoring(function (self $order) { throw new RuntimeException('Order::restore() is disabled — …'); });`. Test inversion `ZReportAggregateFilterTest::test_soft_deleted_post_allocation_orders_are_counted`. Fix commit: **`a37f58e4a heal(P0-fiscal): iter15 G0-A withTrashed + z_reports trigger MysqlOnly + cascadeOnDelete→restrictOnDelete`**. **Residual P1**: removing SoftDeletes entirely is the cleaner long-term option (V1.0.1 sprint candidate). |
| **P0-02** | `OrderItem` model has SoftDeletes → fiscal child-row gap | **MITIGATED — same Option A path** | `app/Models/OrderItem.php:8` `use Illuminate\Database\Eloquent\SoftDeletes;` + `:13` `use SoftDeletes;`. Inherited mitigation through `Order`'s `withTrashed()` aggregation + `OrderItem.php:91` `->withTrashed()` already on the `belongsTo(Item::class)` relation (`return $this->belongsTo(Item::class, 'item_id', 'id')->withTrashed();`). Same commit `a37f58e4a`. Residual P1 — see P0-01. |
| **P0-03** | `z_reports` DELETE trigger MySQL-only, SQLite tests had 0 coverage | **FIXED** | Trigger: `database/migrations/2026_05_09_160000_add_z_reports_delete_trigger_immutability.php:50-58` `CREATE TRIGGER z_reports_no_delete BEFORE DELETE ON z_reports … SIGNAL SQLSTATE '45000'`. Driver gate `:44` `if ($driver !== 'mysql' && $driver !== 'mariadb') { return; }`. **Coverage added**: `tests/Feature/Fiscal/ZReportDeleteTriggerMysqlOnlyTest.php:41-43` `class ZReportDeleteTriggerMysqlOnlyTest extends TestCase { use MysqlOnly; use RefreshDatabase;`. setUp gate `:53` `$this->requiresMysqlDriver();` skips cleanly on SQLite, exercises trigger on MySQL CI matrix (3 tests). Helper trait `tests/Support/MysqlOnly.php:36`. Fix commit: **`a37f58e4a`**. |
| **P0-04** | `cash_movements` + `order_payments` FK `cascadeOnDelete` (should be `restrictOnDelete`) | **FIXED** | `database/migrations/2026_05_10_010000_secure_fiscal_audit_trail_immutability.php:76-77` `->references('id')->on('cash_drawer_sessions')->restrictOnDelete();` for cash_movements; `:91-94` `->foreign('order_id')->references('id')->on('orders')->restrictOnDelete();` for order_payments. Both old cascade FKs dropped first via `dropForeignIfExists()` `:66, :86`. DELETE triggers on `cash_movements`, `cash_drawer_sessions`, `order_payments` installed `:108-141`. Fix commit: **`a37f58e4a`**. |
| **P0-05** | `IDEMPOTENCY_MIDDLEWARE_ENABLED` default flag (dormant in fresh deploys?) | **CONFIRMED STILL OPEN — DEFAULT FALSE** | `config/idempotency.php:20` `'enabled' => env('IDEMPOTENCY_MIDDLEWARE_ENABLED', false),`. Middleware respects flag at `app/Http/Middleware/IdempotencyKeyMiddleware.php:41-43` `if (! config('idempotency.enabled', false)) { return $next($request); }`. Operational gap: a fresh prod `.env` without `IDEMPOTENCY_MIDDLEWARE_ENABLED=true` leaves dual-layer protection at single-layer (the app-level `Cache::lock(frontend_order_idempotency_…)` at `FrontendOrderService.php:156-160` + DB UNIQUE `(branch_id, idempotency_key)` from `2026_04_18_140003_scope_idempotency_key_to_branch.php`). **Required action**: flip to `'enabled' => env('IDEMPOTENCY_MIDDLEWARE_ENABLED', true)` for V1, or add a deploy gate in CI. |
| **P0-09** | `CashDrawerService::openSession` no `Cache::lock` + UNIQUE partial | **FIXED** | `app/Services/Cash/CashDrawerService.php:35-93` `openSession()`. Triple defense documented `:41-57`: layer-1 `Cache::lock("cash_drawer_open_b{$branchId}_u{$userId}", 5)->block(3, …)` `:58-61`; layer-2 `DB::transaction()` + `lockForUpdate()` on existing-session probe `:62-68`; layer-3 UNIQUE partial via `database/migrations/2026_05_10_020000_add_unique_partial_cash_drawer_open.php` — SQLite/pgsql native partial index (`:43-45`), MySQL generated `open_user_lock` column + `UNIQUE INDEX uk_branch_user_open (branch_id, open_user_lock)` `:62-69`. LockTimeoutException → HTTP 409 `:83-91`. Fix commit referenced as `iter15-P0-09` (search `git log --grep="iter15-P0-09"`). |
| **P0-11** | SenangPay Gateway class missing → `/senangpay-webhook/` 500 | **FIXED (stub 501)** | `app/Http/PaymentGateways/Gateways/Senangpay.php:31` `class Senangpay { public function webhook(Request $request): JsonResponse { … return response()->json(['error' => 'not_implemented', …], 501); } }`. Route `app/Http/PaymentGateways/Routes/senangpay.php:18` `Route::match(['get','post'], '/senangpay-webhook/', [Senangpay::class, 'webhook'])`. Webhook-events idempotency infrastructure ready : `database/migrations/2026_05_09_120000_create_webhook_events_table.php:83` `$table->unique(['provider', 'webhook_id'], 'uk_webhook_provider_id');`. Fix commit `iter15-P0-11 owner G0-B Option C`. |
| **P0-12** | `OrderStateMachine::apply` :185 no lockForUpdate upstream | **FIXED** | `app/Domain/Order/OrderStateMachine.php:185-253` `apply()`. Comment `:185-200` explicit `[iter15 P0-12 LOCKFORUPDATE 2026-05-10]`. Implementation `:208-253` : `DB::transaction(function () … { $locked = $modelClass::query()->whereKey($orderKey)->lockForUpdate()->firstOrFail(); $from = (int) $locked->status; if ($from === $next) { $order->setRawAttributes(…); return; } // idempotent early-return … });`. Audit row written inside same transaction via `self::recordTransition(…)` `:245-252`. Mirrors POS pattern at `OrderService.php:1515`. Fix commit (in commits log under P0-12): correlate via `git log --grep="P0-12"`. |

## Findings

### P0 (block pre-merge V1)

- **K18-P0-01: `IDEMPOTENCY_MIDDLEWARE_ENABLED` default `false` — dual-layer guard dormant in fresh prod deploy**
  - File: `config/idempotency.php:20`
  - Issue: middleware ships disabled by default. Without the env override, F-VERIFY-09-02 protections (`Idempotency-Replayed` header, 409 on payload-diff replay, 425 on in-flight twin, dead-letter audit) are 100% inactive. The app-level lock + DB UNIQUE backstops survive but `findExistingFrontendOrderForIdempotencyRecovery()` only returns the existing order **inside the same branch_id namespace** — and the middleware-level conflict-detection (header `Idempotency-Key-Conflict` 409) is the only place that surfaces the **"key reused with different payload"** scenario.
  - Evidence: `'enabled' => env('IDEMPOTENCY_MIDDLEWARE_ENABLED', false),` + middleware early-return `IdempotencyKeyMiddleware.php:41` `if (! config('idempotency.enabled', false)) { return $next($request); }`.
  - Suggested fix: flip default → `true`. If a staged rollout is required, add a deploy-gate test in CI that ASSERTS the production env file has the flag set. Document in `docs/PROJECT_CONTINUITY_AND_VISION.md` deployment section.

### P1 (V1.0.1 sprint)

- **K18-P1-01: SoftDeletes traits on Order + OrderItem — fragile NF525 mitigation via `withTrashed()`**
  - File: `app/Models/Order.php:17` + `app/Models/OrderItem.php:13`
  - Issue: NF525 fiscal continuity currently relies on **every** aggregator manually adding `->withTrashed()` (ZReportService.php:338 does so ; future writers may forget). A future regression that misses one `withTrashed()` silently drops fiscal-emitted rows from a Z aggregation.
  - Evidence: trait usage above. Mitigation is service-level not model-level → defense-in-depth violation.
  - Suggested fix: V1.0.1 — remove `SoftDeletes` from `Order` + `OrderItem`. Replace soft-delete with append-only `OrderArchive` mirror. Coordinate with `OrderService::destroy()` (currently hard-deletes children — see `Order.php:90-116` static::restoring guard). Alternative: keep trait but make `static::deleting` impossible for orders with `fiscal_sequence_no != null` (DB trigger + Eloquent guard).

- **K18-P1-02: `paymentConfirm` catches `Throwable` from `finalizePaidKioskOrder` post-commit side-effects — 200 even on partial fiscal-alloc failure**
  - File: `app/Http/Controllers/Frontend/OrderController.php:265-282`
  - Issue: comment block `:241-264` documents the trade-off intentionally — the controller returns 200 even when fiscal allocation throws after payment commit. The retry path is `RetryFiscalAllocCommand` (`foodking:fiscal:retry-alloc`). However the kiosk-facing 200 carries no marker (no `fiscal_alloc_pending: true` field in the response) so the kiosk UI sees "payment confirmed" with no signal that fiscal seq is degraded. NF525 says the chain must surface this state.
  - Evidence: `OrderController.php:267-281` try-catch on `finalizePaidKioskOrder` swallows; return at `:307` ships `['status' => true, 'message' => 'Paiement confirmé', 'data' => ['order_id' => …]]` regardless.
  - Suggested fix: include `fiscal_sequence_no` (or `fiscal_alloc_pending` boolean) in the success payload so ops dashboards + kiosk toast can flag the degraded state.

- **K18-P1-03: `dispatchNewOrderSignals` dispatched outside the order-create DB transaction (best-effort)**
  - File: `app/Services/FrontendOrderService.php:1044-1196` `finalizePaidKioskOrder`
  - Issue: `DB::transaction(function () { … allocate fiscal_sequence_no, save status=ACCEPT … })` then dispatches signals **after** commit at `:1190` `$this->dispatchNewOrderSignals($frontendOrder);`. If `dispatchNewOrderSignals` throws (queue down, broadcast failure), the order is already ACCEPT + fiscal-sealed but KDS/OSS receive no event. The retry path covers the fiscal-alloc failure case but **not** the post-fiscal signal-dispatch failure case.
  - Evidence: lines cited.
  - Suggested fix: add Outbox row write inside the same transaction (already pattern in EventServiceProvider — `PersistOrderCreatedToOutbox` runs first in listener chain per controller comment `:280-282`). Ensure the Outbox row write happens inside the same DB::transaction.

### P2 (backlog)

- **K18-P2-01: composition_snapshot frozen invariant — confirmed Y but write sites scattered**
  - File: write sites `app/Services/FrontendOrderService.php:441`, `app/Services/OrderService.php:455, 810, 1241`, `app/Services/Pricing/PricingService.php:291`, `app/Services/Order/RefundWithCounterEntryService.php:135` (refund mirror — copies, not rewrites).
  - Issue: 5 distinct write call-sites. None overwrite an existing row (writes happen once at OrderItem creation). Refund counter-entry **copies** existing snapshot to a new mirror row → invariant preserved.
  - Evidence: `grep -n "composition_snapshot" app/Services/**` shows only `INSERT`-time writes ; `OrderItemResource.php:31-36` reads snapshot only, never writes. KDSOrderItemsResource reads only.
  - Suggested fix: doc only — add inline ASSERT in test suite that no production write site UPDATEs an existing OrderItem.composition_snapshot field.

- **K18-P2-02: `PaymentReconcileController::reconcile` accepts up to 50 entries per call but no global cap on cumulative orders/min**
  - File: `app/Http/Controllers/Frontend/PaymentReconcileController.php:58` `'entries' => ['required', 'array', 'min:1', 'max:50']`
  - Issue: throttle 5/min × 50 entries = 250 reconcile attempts/min/token. Each loops `finalizePaidKioskOrder` which holds row locks. A buggy kiosk could DoS the fiscal alloc path.
  - Evidence: route `routes/api.php:1126` `Route::prefix('payment')->name('payment.')->middleware(['auth:sanctum', 'throttle:5,1'])->group(…)`.
  - Suggested fix: tighten throttle to `throttle:2,1` and/or `max:25` per batch.

- **K18-P2-03: `finalizePaidKioskOrder` returns early without flagging when `payment_status !== PAID` (silent log-only)**
  - File: `app/Services/FrontendOrderService.php:1085-1092`
  - Issue: a misuse from a non-controller caller logs `'finalizePaidKioskOrder called without confirmed payment'` and exits. No metric, no surface to ops dashboard.
  - Suggested fix: bump log level to `error` and add a counter increment (or convert to `throw new RuntimeException` since this is a defensive invariant — current callers always pre-check).

### P3 (nice-to-have)

- **K18-P3-01**: `OrderRequest::authorize()` returns `true` when `currentAccessToken()` is null (session/guard auth) — documented but a tolerated soft-gap. Production tokens always have tokenCan check, but a future session-auth path would inherit the soft-gap. Consider tightening to explicit allowlist of guard-auth callers.
- **K18-P3-02**: `PaymentReconcileController.php:88` `$hasKioskAbility = $token ? $authenticatedUser->tokenCan('kiosk:order') : app()->runningUnitTests();` — session-auth tolerance for fixtures lives in production code path. Move to a test-only mock or trait.

## NF525 invariant verification (Y/N + evidence)

| Invariant | Y/N | Evidence |
|---|---|---|
| `composition_snapshot` written ONCE at create, never overwritten | **Y** | All 5 write sites are INSERT-time (`FrontendOrderService:441`, `OrderService:455/810/1241`, `PricingService:291`). Refund mirror copies (`RefundWithCounterEntryService:135`). |
| `fiscal_sequence_no` monotonic per branch, gap-free, Cache::lock 5s + DB FOR UPDATE | **Y** | `FiscalSequenceService.php:65-103` — `Cache::lock("fiscal_seq_b{$branchId}", 5)->block(3)` + `Order::withoutGlobalScopes()->where('branch_id', $branchId)->lockForUpdate()->max('fiscal_sequence_no')` inside `DB::transaction`. SQLite uses BEGIN IMMEDIATE semantics. |
| `audit_logs` HMAC chain-signed, DB trigger BEFORE DELETE | **Y (frozen, out of K18 scope but verified)** | Migration `2026_04_22_000002` cited in P0-FIX-3 comment. |
| `z_reports` DELETE forbidden via MySQL trigger | **Y** | Migration `2026_05_09_160000` + test `ZReportDeleteTriggerMysqlOnlyTest`. |
| `fiscal_alloc_error_at` retry path covered | **Y** | Migration `2026_05_09_200000` adds column; `RetryFiscalAllocCommand.php:41-90` cron `foodking:fiscal:retry-alloc` reads `WHERE payment_status=PAID AND fiscal_sequence_no IS NULL AND fiscal_alloc_error_at IS NOT NULL`. Clearing handled by finalize at `FrontendOrderService:1120-1122`. |
| Idempotency 2xx-only replay; 409 on payload diff | **Y (when middleware enabled)** | `IdempotencyKeyMiddleware.php:145-151` complete-only-on-2xx ; `:88-93` 409 + `Idempotency-Key-Conflict: true` on payloadHash mismatch. **GATED on P0-05 flag**. |
| `webhook_events` UNIQUE (provider, webhook_id) | **Y** | `2026_05_09_120000:83` `$table->unique(['provider', 'webhook_id'], 'uk_webhook_provider_id');`. |
| Order soft-delete preserved in Z totals | **Y (via withTrashed)** | `ZReportService.php:338` + `Order.php:108-116` restore blocked. Fragile (see P1-01). |

## Idempotency dual-layer verification

- **HTTP middleware layer** (`IdempotencyKeyMiddleware.php:39-157`) — gated on `config('idempotency.enabled')` flag (P0-05 OPEN). Provides cross-payload conflict detection (409), in-flight twin race (425), 2xx-only complete cache (TTL 86400s default), scoped key `idempotency:v1:{branch}:{user}:sha256(key)`. Branch resolution `:182-219` handles kiosk via KioskMachine pivot.
- **App-level cache layer** (`FrontendOrderService.php:152-170`) — `Cache::lock('frontend_order_idempotency_' . sha1($lockBranchId . '|' . $idempotencyKey), 10)->block(5)` + `findExistingFrontendOrderForIdempotencyRecovery($idempotencyKey, $lockBranchId)` returns the existing order with `loyaltyApplied` recomputed `:166-168`.
- **DB layer** — UNIQUE `(branch_id, idempotency_key)` from migration `2026_04_18_140003_scope_idempotency_key_to_branch.php:34-36`.
- Status: middleware layer is **dormant** without the env flag — see K18-P0-01.

## Existing E2E coverage (relevant to K18)

- `tests/Feature/Fiscal/ZReportDeleteTriggerMysqlOnlyTest.php` — 3 tests, MySQL-only.
- `tests/Feature/Fiscal/FiscalAllocOrphanRetryTest.php` — retry cron path.
- `tests/Feature/Webhooks/WebhookEventIdempotencyTest.php` — webhook_events UNIQUE.
- `tests/Feature/Webhooks/SenangPayStubResponseTest.php` — 501 stub returned.
- `tests/Feature/Frontend/OrderRouteAbilityTest.php` (referenced) — kiosk:order ability gate.
- `tests/Feature/Sentinels/F008PaymentReconcileAbilitySentinelTest.php` — F-008 ability.

## Proposed new E2E tests

- **T-K18-01: idempotency middleware enabled E2E roundtrip on `/api/frontend/order`**
  - Steps: enable `IDEMPOTENCY_MIDDLEWARE_ENABLED=true` via config helper ; POST `/api/frontend/order` with `X-Idempotency-Key: foo` + payload A → 201 ; replay → 200 + `Idempotency-Replayed: true` header ; POST with same key + payload B → 409 + `Idempotency-Key-Conflict: true`.
  - Assertions: single Order row in DB ; second-response body bytes-identical to first ; 409 contains `IDEMPOTENCY_KEY_CONFLICT`.

- **T-K18-02: P0-05 default-flag sentinel test**
  - Steps: assert `config('idempotency.enabled') === true` in production-like env loader test ; assert middleware is registered on `/api/frontend/order` route via `Route::getRoutes()`.
  - Assertions: gate fails the build if flag default flips to false.

- **T-K18-03: fiscal_alloc_error_at retry round-trip**
  - Steps: kiosk-paid card order ; mock `FiscalSequenceService` to throw once ; verify order persists `payment_status=PAID + fiscal_sequence_no=NULL + fiscal_alloc_error_at=<now>` ; run `foodking:fiscal:retry-alloc` ; verify `fiscal_sequence_no` allocated + `fiscal_alloc_error_at=NULL` + `status=ACCEPT`.

- **T-K18-04: `composition_snapshot` immutability E2E**
  - Steps: create order with addon ; assert `composition_snapshot` JSON populated ; refund-with-counter-entry order ; verify mirror row's `composition_snapshot` == original's (not regenerated) ; verify legacy `OrderItem::find` cannot UPDATE the column (DB-level reject if a constraint is added in V1.0.1).

- **T-K18-05: PaymentReconcileController per-tx idempotency**
  - Steps: POST `/api/frontend/payment/reconcile-pending` with same `transaction_id` twice ; verify single `pending_payment_confirmations` UNIQUE row + `status='resolved'` ; verify second response returns `status='already_paid'` not `'reconciled'`.

## Risks & open questions

- **Owner gate**: P0-05 default-flag flip needs go-live decision. Recommendation: ship V1 with `IDEMPOTENCY_MIDDLEWARE_ENABLED=true` default + canary test.
- **P1-02 disclosure**: surfacing `fiscal_alloc_pending` in the kiosk-facing payment-confirm response requires KioskPaymentComponent UI work — coordinate with K10 audit.
- **K18 ↔ K16 link**: ability check `tokenCan('kiosk:order')` for `/payment-confirm` is enforced via `PaymentConfirmRequest::authorize()` (`app/Http/Requests/Frontend/PaymentConfirmRequest.php:20`) ; ditto `/reconcile-pending` `:88` ; ditto `/order POST` via `OrderRequest::authorize()` `:65`. K16's P0-08 fix path is the source of truth — K18 paths are downstream consumers. No additional K18 P0 here.
- **Path drift**: prompt's `FrontendOrderController.php` is actually `Frontend\OrderController.php` aliased on import — confirm with K18 prompt-author whether to rename for clarity.
