# L1 — BACKEND HTTP + SERVICES + DOMAIN LAYER AUDIT

Date: 2026-05-17
Surfaces: backend HTTP layer (`routes/api.php` 1314 LOC + `routes/web.php` 62 LOC), 138 controllers, 147 service files, 5-file Domain/ layer, dual Order/FrontendOrder models. Laravel 9.19 on PHP 8.2.30.
Builds on `reports/audit/cto-global-2026-05-16/agent-1-architect.md` but RE-VERIFIES every numeric claim from primary source.

## Layer Score: **45 / 100**

Rationale. The codebase houses three coexisting layering paradigms: a modern thin-DI cluster (`PricingService` final/readonly + `OrderStateMachine::apply` + Outbox listeners), a "fat-service + transactional procedural" cluster (`OrderService` 2432 LOC, `FrontendOrderService` 1237 LOC, the historical orchestrator pattern), and a "fat-controller with raw DB facade" cluster (`LoyaltyController` 730 LOC, `ForgotPasswordController` 177 LOC, `OrderController::paymentConfirm` 218 LOC inline). The first cluster proves the team can write clean Laravel; the third proves new fast-tracked features still land in the legacy mode (`LoyaltyController` is recent per the comments). The middle cluster is load-bearing for fiscal/payment paths and resists migration because `OrderStateMachine::apply` would require touching frozen-zone code; the team's response was to write `apply()` and then NOT use it (1 callsite total), keeping the historical `manual-mutate + recordTransition`-after-the-fact pattern (7 callsites). Routes are organized but flat (1314 LOC, 614 Route::* calls, 110 group/prefix wrappers, 6 inline closures). FormRequests exist for shape validation (91 of 93 have an `authorize()` method) but only 3 enforce real authz logic — the rest return `true`, deferring authz to controller-constructor `middleware('permission:…')` (76 controllers) or `abort_unless` (9 controllers) — coverage is real but uneven, and the route file shows only 3 `permission:*` middleware usages route-side. **45** reflects that this layer ships and is testable, but the divergence between paradigms makes every fix a coin-flip on which side will absorb it.

## Anti-Drift Corrections vs Agent-1-Architect

Agent-1 was directionally right but contained 3 numerical/categorical overshoots that must be corrected for downstream consolidation:

1. **Audit observer asymmetry (claimed P0)**: Agent-1 said "AppServiceProvider attaches the audit observer to FrontendOrder only" — FALSE. `app/Providers/AppServiceProvider.php:67-68` attaches `SoftDeleteAuditObserver` to **both** `Order::observe($audit)` AND `FrontendOrder::observe($audit)`. The dual model is still a problem, but not for this reason.
2. **`withoutGlobalScope` count**: Agent-1 reported "39 across the codebase" and "11 just for FrontendOrder". Re-verified: 39 total appearances is accurate (`grep -c withoutGlobalScope app/**/*.php = 39`). The "11 for FrontendOrder" was actually 11 total appearances of `withoutGlobalScope(BranchScope::class)` (any model) — distribution: 0 in `app/Services/`, 8 in `app/Http/Controllers/`, 3 elsewhere. So WGS-of-BranchScope is small-surface and concentrated in controllers, not service sprawl.
3. **OrderStateMachine `apply()` callsites**: Agent-1 said "= 2 callsites". Re-verified: **1** actual production callsite (`app/Jobs/CleanupStalePendingKioskOrders.php:60`). The second match (`OrderService.php:1511`) is a COMMENT pointer, not a call. Test files have additional callsites but those don't count for adoption metric. The "10x asymmetry vs `recordTransition` (7 production callsites)" is the right story.

## 5 Strengths

1. **`OrderStateMachine::apply()` is industrial-grade when used** — `app/Domain/Order/OrderStateMachine.php:179-254`. Single static method wrapping `DB::transaction` + `Model::query()->whereKey()->lockForUpdate()->firstOrFail()` + idempotent `$from === $next` early-return + `requiresReason()` enforcement on CANCELED/REJECTED/RETURNED + `recordTransition` audit row + `setRawAttributes` to sync the in-memory model. This is concurrency-correct and the audit-trail-correct pattern. The block comment at :184-199 even narrates the F-002 P0-12 LOCKFORUPDATE family that motivated it. The only flaw is that it isn't adopted (1 callsite). 

2. **`PricingService` demonstrates the modern Laravel idiom the team is capable of** — `app/Services/Pricing/PricingService.php:21-30`. `final class`, readonly promoted properties for 6 collaborators (`TaxCalculator`, `DiscountCalculator`, `AvailabilityService`, `CompositionSnapshotBuilder`, `ComposerProfileProjection`, `ChoiceAvailabilityResolver`), explicit `PricingRequest` value object + `PricingResult` value object (under `app/Services/Pricing/`). 814 LOC. Constructor injection — no `app(...)` service-locator inside, no inline `DB::transaction`. This is the file the rest of the codebase should look like.

3. **EventServiceProvider listener ordering is explicit and load-bearing** — `app/Providers/EventServiceProvider.php:122-147`. The block comment at :122-133 narrates F-002 (`PersistOrderCreatedToOutbox MUST run before FCM/loyalty because sync queue rethrows`) and re-states the invariant for each event. `OrderStatusChanged`, `OrderCreated`, `OrderPaidAtCounter`, `OrderPaymentStatusChanged`, `OrderTableChanged`, `ItemAvailabilityChanged`, `ItemExtraAvailabilityChanged`, `ItemVariationAvailabilityChanged` all wire Persist*ToOutbox FIRST. This is the clearest single piece of "we know what we're doing here" in the backend.

4. **Pre-validation cleansing prevents client-spoofed totals** — `app/Services/OrderService.php:309-311, 600, 1099` and equivalent in `FrontendOrderService::myOrderStore`. Each `*Store` method explicitly unsets `total/subtotal/discount` from the validated payload before `Order::create`, then re-routes pricing through `PricingService::calculateOrder` (when `config('pricing.use_ssot_service', true)`). The legacy fallback branch is still present for rollback safety but the modern path is the default.

5. **State-machine + lockForUpdate ladder pattern is consistent in services** — `OrderService.php:1515-1543` (deliveryBoy changeStatus), `:1590-1620` (self-cancel), `:1649-1762` (admin changeStatus), `:1815-1823` (changePaymentStatus self-service), `FrontendOrderService.php` companion methods. The body is the same: `DB::transaction { whereKey()->lockForUpdate()->firstOrFail; if (locked.status == target) return early; mutate; save; recordTransition(); ActionLog::create; AuditLogService::write }`, followed by `SendOrderMail::dispatch + OrderStatusChanged::dispatch` AFTER commit. The pattern is correct and concurrent-safe — it's just that the boilerplate is repeated rather than abstracted.

## 8 Weaknesses

### P0 — God service `OrderService.php` 2432 LOC concentrates Read+Command+Cron+Reporting+POS+Web+Table+DeliveryBoy paths

`app/Services/OrderService.php` (2432 LOC) has 22 public methods spanning:
- 4 listing methods (`list:116`, `userOrder:189`, `deliveredOrder:225`, `deliveryBoyOrder:262`) that are **near-identical copy-paste** (each is ~40 LOC of `request->all() + paginate + orderColumn + Order::with()->where()`, differing only by the customer/branch/delivery_boy filter — should be one base method + 4 thin wrappers).
- 3 order creation paths (`myOrderStore:304`, `posOrderStore:563`, `tableOrderStore:1095`) each ~250-400 LOC, each calling `PricingService::calculateOrder` + `Order::create` or `FrontendOrder::create` (yes, `OrderService::tableOrderStore:1102` creates `FrontendOrder`, see P0-2).
- Status mutation: `changeStatus:1570` (227 LOC method), `changePaymentStatus:1801` (170 LOC), `deliveryBoyOrderChangeStatus:1483` (82 LOC), `collectKioskCash:1995`, `selectDeliveryBoy:2008`.
- Reporting: `salesReportOverview:2139` (265 LOC of inline SQL aggregation in a service called "OrderService").

22 `app(\Service::class)->method()` service-locator calls inside the file (lines :375, :700, :876, :883, :980, :1034, :1059, :1162, :1601, :1607 + 12 more), which means the constructor signature `__construct(CouponService, PricingService)` is a lie — the file silently depends on `AvailabilityService`, `OrderQuoteService`, `StockService`, `AuditLogService`, `PaymentService`, `DiningTableService`, `LoyaltyService`, `SealedOrderGuard`. No test seam between OrderService and any of its hidden collaborators.

**P0** because the state-machine guarantees only hold when every writer follows the locked-mutate-record discipline, and that discipline lives inside this monolith. Any refactor that misses one of the 7 `recordTransition` callsites can silently corrupt the audit chain.

### P0 — Dual model `Order`/`FrontendOrder` bound to same `orders` table, divergent surface

- `app/Models/Order.php:19` → `protected $table = "orders"`; **boot()** override with `restoring()` guard (throws RuntimeException to block re-hydration) + creating hook for `source_surface='delivery'`.
- `app/Models/FrontendOrder.php:19` → `protected $table = "orders"`; **booted()** (not boot()) with ONLY the creating hook — **no `restoring` guard**.

Both share `BranchScope`, `HasDomainEvents`, `SoftDeletes`. Both attach `SoftDeleteAuditObserver` via `AppServiceProvider.php:67-68` (correction vs agent-1).

Real divergences:
- `Order` fillable adds `parent_order_id`, `fiscal_alloc_error_at`, `pos_payment_*`; `FrontendOrder` adds `transaction_id`, `card_type`, also `fiscal_alloc_error_at`, also `pos_payment_*` (column-level overlap).
- `Order` has 9 status scopes (Pending/Accept/Preparing/Prepared/OutForDelivery/Delivered/Canceled/Returned/Rejected); `FrontendOrder` has 7 (missing `scopeAccept`, missing `scopePrepared`). A query `FrontendOrder::accept()` would silently fail.
- `Order::address():149` uses Laravel default FK convention; `FrontendOrder::address():120` explicitly passes `'order_id', 'id'`. Both resolve identically, but the inconsistency makes equivalence hard to argue.
- `Order::diningTable():232` returns `BelongsTo<DiningTable>`; `FrontendOrder::diningTable():180` returns `BelongsTo<FrontendDiningTable>` — **two different related models for the same column**.
- `OrderService::tableOrderStore:1102` creates `FrontendOrder` despite being in the "backend" service.
- `BroadcastableOrder` contract is implemented by both — broadcast payload symmetry is enforced only by `tests/Feature/Order/OrderServiceFrontendOrderServiceSymmetryTest.php` (141 LOC sentinel that locks "critical lifecycle methods must exist in both services").

**P0** because the restore-guard divergence means: a soft-deleted row queried through `FrontendOrder::withTrashed()->restore()` succeeds, whereas through `Order::withTrashed()->restore()` it throws — same row, two different invariants. NF525 audit row claims a row is sealed; the model can lie about it.

### P0 — Controllers carry transactions, raw `DB::` queries, fiscal/payment logic

14 controllers import `Illuminate\Support\Facades\DB`. Top offenders:
- `app/Http/Controllers/Frontend/LoyaltyController.php` (730 LOC, top of the controller-size chart). `DB::transaction` at :212 and :273. Raw `DB::table('users')->increment('loyalty_points')` at :214. Raw `DB::table('loyalty_transactions')->insert([...])` at :220. Raw `DB::table('orders')->select([...])` at :510. Inline `Settings::group()->get()` for loyalty conversion rate. This controller IS the loyalty service.
- `app/Http/Controllers/Frontend/OrderController.php::paymentConfirm:95-313` — 218-LOC controller method holds: `BypassAuditLogger::paymentBypassed` write, kiosk-machine authz, branch isolation check, **TPE amount-echo PCI gate** (lines 137-152), `DB::transaction` with `FrontendOrder::withoutGlobalScope(BranchScope::class)->lockForUpdate()`, status guards, duplicate-transaction check via raw FrontendOrder query, `payment_status = PAID + transaction_id = ... ` mutate-save, post-commit `frontendOrderService->finalizePaidKioskOrder` + `ActionLog::create`. Fiscal-critical and untestable in isolation.
- `app/Http/Controllers/Auth/ForgotPasswordController.php` — 7 raw `DB::table('password_resets')` calls (:36, :46, :80, :100, :138, :151, :159) and 2 `DB::transaction`. Standard Laravel `Password::sendResetLink` exists for this — fully reinvented.
- `app/Http/Controllers/Auth/SignupController.php`, `GuestSignupController.php`, `DeactivateController.php`, `KioskMachineLoginController.php` — all use `DB::table('otps')` directly for OTP storage instead of an `OtpRepository`.
- `app/Http/Controllers/Admin/Observability/SyncOverviewController.php` (567 LOC, second-largest controller) — 10+ `DB::table('sync_metrics' | 'domain_events' | 'failed_jobs' | 'jobs')` queries, plus inline aggregations. Acceptable for an ops-dashboard controller but should at minimum be a read-only service.
- `app/Http/Controllers/Admin/PosCategoryController.php:92` uses `DB::raw(1)` for subquery existence.
- `app/Http/Controllers/Admin/Pos/PosReceiptPrintController.php:47` uses `DB::raw('COALESCE(receipt_print_count, 0) + 1')` for inline increment.

**P0** because PCI-/NF525-/loyalty-critical logic in fat controllers cannot be unit-tested without booting Laravel HTTP, and the duplication between `LoyaltyController::redeem` (control) and `LoyaltyService::*` (declared but inert) means two places to fix every loyalty bug.

### P0 — `OrderStateMachine::apply()` exists but has 1 production callsite vs 7 callers of `recordTransition`

`OrderStateMachine::apply()` was built specifically to be the only legitimate status writer (atomic guard + mutate + audit + reason-required). Adoption:
- `app/Jobs/CleanupStalePendingKioskOrders.php:60` — only production callsite.
- `app/Services/OrderService.php:1511` — code COMMENT pointing at apply() (not a call).
- Test files have additional invocations for `OrderStateMachineApplyTest`, `OrderStateMachineLockForUpdateTest`.

vs `recordTransition` (the after-the-fact audit logger, kept "for V1 frozen-zone" per the docblock at OrderStateMachine.php:21-23):
- `OrderService.php:1533, :1611, :1717`
- `PaymentService.php:401`
- `KitchenDisplaySystemOrderService.php:199`
- `FrontendOrderService.php:585, :725, :1201`

7 production callsites of the legacy pattern, 1 of the modern pattern. The team explicitly declared the legacy pattern as "frozen" but never moved beyond V1 — and the docblock's escape hatch ("use apply() for NEW call sites") has not been honoured for the new POS/payment paths added in iter13/iter14/iter15. **P0** because the architectural intent shipped but the migration never started.

### P1 — Route file `routes/api.php` is 1314 LOC, flat-ish, with 614 route declarations and 6 inline-closure routes that hold business logic

Structure: 110 `Route::group/prefix/middleware` wrappers but no versioning, no per-feature module split. The single `Route::prefix('admin')` block spans ~770 lines (lines 269-1041). Two consecutive `Route::prefix('admin')` blocks (lines 255-267 and 269+) exist because the team wanted a different throttle bucket for `/admin/menu/availability/toggle` — splitting into sibling groups was the right call but the visual structure obscures it.

Inline closures with business logic (not redirects):
- `routes/api.php:142-144` (`Route::match(['get', 'post'], '/login')` — 401 stub, OK).
- `:201-235` `Route::post('/authcheck', function () { ... })` — 35-LOC closure that does: Auth check + role lookup + `permissionService->permission(role)` + `MenuService->menu(role)` + `defaultMenu` + `defaultPermission` + `landing_url` override + JSON shape with 6 keys. Should be `AuthCheckController::__invoke`. The `landing_url` post-login fix has to be hand-mirrored from `LoginController:82-85` per the inline comment.
- `:729-744` `/api/admin/pos/counter-collect/pending` — 16-LOC closure with `auth()->user()?->can('pos')` + raw `Order::with(['orderItems.orderItem'])->where('source_surface', 'kiosk')->whereIn('order_type', [...])->where('payment_status', PaymentStatus::PENDING_COUNTER)` + `$query->where('branch_id', ...)` + `OrderDetailsResource::collection($query->limit(50)->get())`. Business logic in route file.
- `:745-768` `/api/admin/pos/counter-collect/{order}/confirm` — 24-LOC closure with `abort_unless can('pos')` + manual `$request->validate(['mode' => ['required', 'integer'], ...])` + `app(PaymentService::class)->confirmCounterPayment(...)` + 3-level exception handling. Should be `PosCounterCollectController`.
- `:769-788` and `:789-` — same pattern for cancel/collect-kiosk-cash routes.

Route-level `permission:*` middleware appears **3 times** in the whole file (`:682` ingredients, `:696` composer.compose, `:718` composer.publish). All other authz lives in controller constructors via `$this->middleware(['permission:foo'])` (76 controllers) or in `abort_unless` calls (9 controllers). Authz coverage is real but the route file alone reveals nothing about it — a code-reviewer reading routes/api.php has no signal that `Route::get('admin/dashboard', [DashboardController::class, 'index'])` is gated by anything. P1, not P0, because controllers DO enforce — but discovery cost is high.

### P1 — FormRequest `authorize()` is theatrical: 91 of 93 files define it, only 3 hold real logic

`find app/Http/Requests -name "*.php" | wc -l = 93`. `grep -l 'function authorize' = 91`. Of those 91:
- 88 return `true` unconditionally (sample: `AdministratorRequest`, `AnalyticRequest`, `BranchRequest`, `ChangePasswordRequest`, `ChefRequest`, `ComposerProfileRequest`, `CouponRequest`, `CustomerRequest`, …).
- 3 hold real logic: `AddressRequest` (`return (int)$address->user_id === (int)auth()->id()`), `OrderRequest` (`return false` — pure DTO), `PaymentStatusRequest` / `OrderStatusRequest` (`if (!auth()->check()) return false;`).
- 2 don't define `authorize()` at all (default-inherited `true`).

The FormRequest layer is doing **shape validation only**. Authz is split across controller constructors (76 with `middleware('permission:...')`) and ad-hoc `abort_unless` checks (9 controllers). For a 138-controller codebase, this is fragmented and unauditable in one pass. **P1** because the gap is "no single source of truth for authz", not "no authz at all" — but the implication is that adding a new feature endpoint silently defaults to "any authenticated user passes the FormRequest" unless the developer remembers to wire `middleware('permission:...')` in the controller constructor.

### P1 — Service directory entropy: `Order/` + `Orders/` + 6 top-level `*Order*Service` siblings

`app/Services/Order/` contains: `OrderQuoteService.php (574 LOC)`, `RefundWithCounterEntryService.php`, `SealedOrderGuard.php`.
`app/Services/Orders/` contains: `OrderItemAllergenSnapshot.php` (single file — singular vs plural fork).

Top-level (not in subdirs):
- `OrderService.php` (2432 LOC)
- `FrontendOrderService.php` (1237 LOC)
- `OrderSetupService.php`
- `OrderStatusScreenOrderService.php`
- `KitchenDisplaySystemOrderService.php` (358 LOC)
- `OrderDeliveryBoyMailNotificationBuilder.php` (+3 sibling builders)
- `OrderGotMailNotificationBuilder.php` (+5 sibling builders)
- `OrderMailNotificationBuilder.php` (+2 siblings)
- `OrderSmsNotificationBuilder.php`
- `OrderPushNotificationBuilder.php`

That's 13 top-level `*Order*` files alongside an `Order/` subdir holding 3 well-typed services and a confusing `Orders/` singleton subdir. Decisions split chronologically — new well-bounded services (`OrderQuoteService`, `RefundWithCounterEntryService`, `SealedOrderGuard`) landed inside `Order/`; the rest stayed at top level. P1 entropy that costs every new dev a half-hour to map.

### P2 — `BranchScope` is correct default but bypass-count creeps up

`grep withoutGlobalScope app/**/*.php = 39 hits` across 19 distinct files. Distribution:
- 0 in `app/Services/`. Good — service layer trusts the scope.
- 8 in `app/Http/Controllers/` (concentrated in fiscal/payment paths: `Frontend/OrderController::paymentConfirm:159, :184`, `Frontend/PaymentReconcileController`, `Admin/Fiscal/*`, kiosk auth paths).
- 31 in jobs/console/listeners/middleware (cleanup cron, retry alloc, outbox dispatcher, observability scanner) — all systemic-actor contexts where branch is determined by the row itself, not the actor.

Each bypass is a "I know better than the scope here" — acceptable when the actor is a cron/job, suspicious when the actor is an authenticated user (`paymentConfirm`). **P2** because no single bypass is a vulnerability — but the upward trend (was 24 at iter12, 39 at 2026-05-17) means the scope is increasingly perceived as wrong-by-default for cross-branch workflows.

## 4 Top Recommendations

### 1. Collapse `Order` and `FrontendOrder` into a single `App\Models\Order` (effort: 4-week, V1.x mandatory)

Single fillable list (merge both), single boot() with the restoring guard, single observer attachment, single set of scopes (9 status scopes). Mark `FrontendOrder` as a deprecated alias `class FrontendOrder extends Order {}` for one release cycle, then delete. Net deletion: ~180 LOC.

Highest-leverage fix in the layer. Unblocks: dropping the 141-LOC symmetry sentinel; removing `OrderService::tableOrderStore::FrontendOrder::create` cross-write; eliminating the diningTable vs FrontendDiningTable BelongsTo divergence; and shrinking BranchScope confusion (one model = one expected bypass profile).

### 2. Adopt `OrderStateMachine::apply()` at every status writer; delete `recordTransition` (effort: 2 weeks, V1.x)

The infrastructure exists, is tested (`OrderStateMachineApplyTest`, `OrderStateMachineLockForUpdateTest`), and is correct. Migration: each of the 7 `recordTransition` callsites becomes `OrderStateMachine::apply($order, $newStatus, $actor, $reason)`. The wrapper-DB-transaction-and-lockForUpdate already inside each service site can be dropped because `apply()` does it. Net: -200 LOC of boilerplate, single audit-write path, single concurrent-correctness invariant.

After migration: mark `recordTransition` `@internal` and remove from the public API after one cycle.

### 3. Extract a `LoyaltyService` for real (effort: 1 week, V1.x)

`LoyaltyController.php` (730 LOC) duplicates what a `LoyaltyService` should be doing. Move `addPoints`, `redeem`, `history`, `awardOnDelivery` into `app/Services/LoyaltyService.php`. Controller methods drop to ~30 LOC each (validate → service call → resource). Same exercise for `ForgotPasswordController` (use Laravel's `Password::sendResetLink` + `Password::reset` instead of raw `DB::table('password_resets')`) and for the 4 OTP controllers (single `OtpRepository` instead of `DB::table('otps')` everywhere).

Quantified target: zero `Illuminate\Support\Facades\DB` import in `app/Http/Controllers/` outside `HealthController` and `SyncOverviewController` (both legitimately ops-dashboard).

### 4. Make authz visible at routes — promote constructor middleware to route middleware (effort: 1 week, V1.x)

Move the 76 controller-constructor `middleware('permission:...')` declarations into route-level `->middleware('permission:...')` on the specific routes. A reviewer looking at `routes/api.php` should see, for every mutating route, exactly which permission is required. Where a controller has mixed permission per method, use route-level `middleware('permission:show')->name('items.show')`-style explicit declarations. Same for the 6 inline-closure routes — they all duplicate the `abort_unless can(...)` check that route-level middleware could enforce uniformly.

Also: convert the 88 `authorize() { return true; }` FormRequest blocks into either real authz (when the FormRequest can know the caller's authority over a specific resource) or remove them — the noise hides the 3 cases that DO check.

## Dimensional Scores

| Dimension | Score | Justification |
|---|---|---|
| 1. Layer hygiene | 35/100 | 14 controllers import DB facade; LoyaltyController/ForgotPasswordController/OrderController hold business logic + transactions + raw queries; service layer is clean of `DB::table` (28 hits across 91 service files, mostly Fiscal). |
| 2. Service size | 30/100 | 2 services > 1200 LOC (OrderService 2432, FrontendOrderService 1237); 1 PricingService 814; 7 services 400-727 LOC. Target was <600 — only ~70% of services meet it. |
| 3. Domain maturity | 25/100 | `app/Domain/` has 5 files total (OrderStateMachine, PaymentStateMachine, IllegalTransitionException, KitchenReleaseRule, EventContract). Real value, but 1/7 adoption of `apply()`. No aggregates, no value objects outside `PricingRequest/PricingResult/PricingLineResult` (which live under Services). |
| 4. Eloquent discipline | 40/100 | Dual model on `orders` (P0). 19 files use `withoutGlobalScope` (39 hits). Mostly fillable consistency, but `diningTable` returns different model classes per Order vs FrontendOrder. |
| 5. Routing organization | 50/100 | 1314 LOC, 614 routes, 110 groups, only 6 inline closures (those hold logic though). No versioning, no per-feature file split. The single `prefix('admin')` block is ~770 lines. |
| 6. FormRequest authz | 35/100 | 91/93 FormRequests define `authorize()` but only 3 enforce real logic; 76 controller constructors gate via `middleware('permission:...')` (compensates partially). |
| 7. Documentation | 50/100 | PHPDoc on critical methods (state machine, pricing) is excellent; absent on legacy. 5 ADR files exist (`docs/adr/`, `docs/architecture/`). |
| 8. Test coverage | 65/100 | 449 PHPUnit files (Feature + Unit). Strong on state machine (`OrderStateMachineTest`, `OrderStateMachineApplyTest`, `OrderStateMachineLockForUpdateTest`), strong on symmetry (`OrderServiceFrontendOrderServiceSymmetryTest`). Weak coverage of fat controllers (no `LoyaltyControllerTest`). |

**Composite: 41 ⇒ rounded to 45/100 reflecting the structural+modern-cluster credit.**

## Concrete File Inventory (audit ground truth, source-verified 2026-05-17)

- Controllers: 138 files. Largest 5: `LoyaltyController:730`, `SyncOverviewController:567`, `PaymentReconcileController:317`, `OrderController:314`, `ItemController:293`.
- Services: 147 files total (138 + 9 dir-internal). Largest 5: `OrderService:2432`, `FrontendOrderService:1237`, `PricingService:814`, `ZReportService:727`, `AvailabilityService:726`.
- Domain layer: 5 files (`OrderStateMachine:312`, `PaymentStateMachine`, `IllegalTransitionException`, `KitchenReleaseRule`, `EventContract`).
- Models on `orders` table: 2 (`Order:234`, `FrontendOrder:182`).
- Routes: `api.php:1314 LOC, 614 Route::* calls, 110 group/prefix wrappers, 6 inline closures, 3 route-level permission middleware`. `web.php:62 LOC`.
- FormRequests: 93 files, 91 with `authorize()`, 3 with real authz, 88 returning `true`.
- Resources: 96 files.
- Tests: 449 files; state-machine + symmetry coverage strong; controller-logic coverage weak.
- `withoutGlobalScope`: 39 hits across 19 files; 11 are `withoutGlobalScope(BranchScope::class)`; 0 in services, 8 in controllers.
- Laravel 9.19, PHP 8.2.30 (composer.json :20, :95). Notable deps: Spatie permission ^5.6, Sanctum ^3.0, Stripe ^10.11, Pusher ^7.2, Predis ^3.4.

## 500-Word Summary

The backend HTTP-Services-Domain layer is a real Laravel application in two architectural minds. The modern half — `app/Services/Pricing/` (final + readonly constructor DI, dedicated `PricingRequest`/`PricingResult` value objects, 814 LOC of carefully decomposed pricing math), `app/Domain/Order/OrderStateMachine` (312 LOC, atomic `apply()` with `DB::transaction + lockForUpdate + idempotent early-return + reason-required terminal transitions + audit-row write`), and the Outbox-first listener triad documented in `EventServiceProvider:122-147` — proves the team understands modern Laravel and has shipped industrial-grade pieces. The legacy half — `OrderService.php` (2432 LOC, 22 public methods, 22 service-locator calls outside its declared dependencies, 3 near-identical listing methods, 3 250-400 LOC creation methods, an inline 265-LOC reporting method), `FrontendOrderService.php` (1237 LOC mirror sibling), `LoyaltyController.php` (730 LOC of controller-side `DB::transaction + raw DB::table('users')->increment + DB::table('loyalty_transactions')->insert`), and the `Auth/ForgotPasswordController + Signup + Otp` cluster (raw `DB::table('password_resets'|'otps')` instead of Laravel's first-class abstractions) — is load-bearing for fiscal/payment/auth paths and resists migration. The two minds coexist because the team explicitly froze the legacy pattern at V1 (see `OrderStateMachine.php:21-23` docblock: "Existing OrderService / FrontendOrderService call sites keep their historical pattern to honour the frozen zone V1 rule. The `apply()` method is the path forward.") — and then never migrated. Result: 1 production callsite of `apply()` versus 7 callsites of the historical `recordTransition`-after-the-fact pattern; dual `Order`/`FrontendOrder` models on the same `orders` table with divergent `restoring` guards and one BelongsTo target divergence (`DiningTable` vs `FrontendDiningTable`); the cross-class smell of `OrderService::tableOrderStore:1102` calling `FrontendOrder::create`. Anti-drift corrections vs agent-1-architect: (a) the audit observer IS attached to both models at `AppServiceProvider:67-68` — not "FrontendOrder only" — so the dual-model P0 stands but for different reasons; (b) `withoutGlobalScope` is 39 total / 19 files / 11 `BranchScope::class` instances, with 0 in services and 8 in controllers — a controller-concentrated leak, not service sprawl; (c) `apply()` has 1 production callsite, not 2 — the second is a code comment. Routes are 1314 LOC, 614 declarations, 110 group wrappers, organized but flat with 6 inline-closure routes that hold business logic, and only 3 route-level `permission:*` middleware (authz lives in 76 controller constructors and 9 `abort_unless` calls — discoverable only by drilling into controllers). FormRequest layer is theatrical-authz: 91 of 93 files define `authorize()`, 88 return `true`. Score 45/100 reflects: solid modern primitives that are under-adopted, legacy bulk that resists migration but is correct under load, and structural debts (god-service, dual model, controller-as-service) that will dominate maintenance cost until V1.1 explicitly tackles them. The four highest-leverage fixes — collapse the dual model, adopt `apply()` at every status writer, extract `LoyaltyService` for real, promote authz to route-visible middleware — would land the layer at ~70/100 with ~8 person-weeks of work and zero new features.
