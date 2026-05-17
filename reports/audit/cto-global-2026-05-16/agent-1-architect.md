# FoodKing — CTO Architecture Audit (Agent 1: Architect)

Date: 2026-05-16
Surfaces in scope: POS / Kiosk / KDS / OSS / Admin / Mobile
Codebase signal: 834 PHP files in `app/`, 380 Vue components, 144 service files, 1314-line `routes/api.php`, 113 Vuex modules.

## Domain Score: **48 / 100**

Rationale: The codebase has *real* architectural artifacts (`OrderStateMachine`, an Outbox triad, a constructor-DI `PricingService`, an explicit listener ordering for the SSOT contract) that prove the team understands the patterns it is reaching for. But adoption is shallow, the older God-Service/Fat-Controller substrate it sits on top of is still load-bearing, and the frontend has *no* architectural layering at all (380 components, 113 Vuex modules, components bypassing the store and calling `axios` directly). The result is two architectures in the same repo — a modern one for new code, a legacy one for everything that already shipped — with no migration glide-path. 48 reflects that this is not a fundamentally broken system, but it is one where every new feature can land on either side of the divide depending on which Claude session wrote it, and where the cumulative debt of 73k PHP LOC + 380 components + dual-model duplication will dominate maintenance cost for v1.x.

## 5 Strengths

1. **OrderStateMachine is real, not theatrical** — `app/Domain/Order/OrderStateMachine.php:179-254` — `apply()` wraps `DB::transaction` + `whereKey($id)->lockForUpdate()` + idempotent early-return (`$from === $next`) + reason-required terminal transitions + audit row via `recordTransition`. Cleanest piece of domain code in the repo.

2. **PricingService demonstrates constructor DI + collaborator decomposition** — `app/Services/Pricing/PricingService.php:21-30` — `final class`, readonly promoted-property DI, four named collaborators. One of the few services under 1000 LOC despite being touched by 4 surfaces.

3. **Outbox triad is coherent and load-bearing** — `app/Models/DomainEvent.php` + `app/Traits/HasDomainEvents.php` + `app/Jobs/DispatchDomainEventsJob.php`. Listener `PersistOrderCreatedToOutbox.php:22` uses `sha1(event_type|aggregate_id)` for idempotency; `:57-79` uses `DB::afterCommit` + try/catch.

4. **Listener ordering is explicit, documented, and defends an invariant** — `app/Providers/EventServiceProvider.php:122-147` — Persist*ToOutbox is registered FIRST with a 13-line comment explaining the F-002 root cause (FCM ShouldQueue throwing prevented downstream listeners under `QUEUE_CONNECTION=sync`).

5. **Cross-surface sync uses a real graceful-degradation contract** — `resources/js/services/OssSyncService.js:3,260-331` + `PosSyncService.js:15-19` — Pusher live + polling fallback with explicit DISCONNECTED→polling cadence from config and CONNECTED→stop. `eventContract.js` (386 LOC) centralizes channel names + `onEvents` subscription. The realtime substrate is the most well-thought-out layer in the codebase.

## 7 Weaknesses (P0 / P1 / P2)

### P0 — Two Eloquent models bound to the SAME `orders` table

- `app/Models/Order.php:19` → `protected $table = "orders"` with `parent_order_id`, `fiscal_alloc_error_at` in fillable.
- `app/Models/FrontendOrder.php:19` → `protected $table = "orders"` with `transaction_id`, `card_type`, `source_surface` in fillable.

Both add `BranchScope`, both `HasDomainEvents`, both `SoftDeletes`. `Order` uses `boot()`, `FrontendOrder` uses `booted()`. `AppServiceProvider.php:68` attaches the audit observer to `FrontendOrder` only. `OrderService.php:1102` *creates* a `FrontendOrder::create(...)` despite living in the "backend" service. This is not a polymorphism, not a CQRS read model, not a STI — it is a copy-paste duplication that lets two writers produce the same row with different fillable contracts, different observer wiring, and different scopes. **P0 because** of the live v1 fiscal/NF525 requirement: divergent observer attachment on a fiscal aggregate is a hard blocker.

### P0 — Controllers carry business logic, transactions, and direct DB calls

- `app/Http/Controllers/Frontend/LoyaltyController.php` 730 LOC, 22 Eloquent/DB calls, `DB::transaction` at :212 and :273, raw `DB::table('users')->increment` at :214, `DB::table('loyalty_transactions')->insert` at :220.
- `app/Http/Controllers/Frontend/OrderController.php:95-313` (`paymentConfirm`) holds `lockForUpdate`, fiscal sequence finalization, `ActionLog::create` writes, `BypassAuditLogger` calls, raw amount-echo PCI gate.
- `app/Http/Controllers/Auth/ForgotPasswordController.php` uses `DB::table('password_resets')` directly.
- 14 controllers import `Illuminate\Support\Facades\DB`.

**P0 because** every fiscal/payment/loyalty fix has to be reproduced in two places and there is no test seam between HTTP and domain.

### P0 — God service: `OrderService` 2432 LOC with copy-pasted query loops

- `app/Services/OrderService.php:116, 189, 225, 262` — `list()`, `userOrder()`, `deliveredOrder()`, `deliveryBoyOrder()` are four near-identical methods.
- Direct `$locked->status = $newStatus` mutations at :1530, :1609, :1714, :1820, :1907 — bypassing the `OrderStateMachine::apply()` that was built for exactly this case.

**P0 because** the state-machine guarantees only hold when `apply()` is the only writer.

### P1 — State machine half-adopted (`apply()` callsites = 2)

`OrderStateMachine::apply` is used at `app/Jobs/CleanupStalePendingKioskOrders.php:60` and referenced in a comment in `OrderService.php:1511`. That's it. `OrderStateMachine::recordTransition` is called 6× from the *old* pattern (caller writes status manually, then asks state machine to *log* it).

### P1 — Frontend has zero architectural layering

- `resources/js/components/admin/pos/PosComponent.vue` is **3769 LOC**. It bypasses the 113 Vuex modules and calls axios directly: `:1900`, `:1914`, `:2325`, `:2349`, `:2365`, `:3040`, `:3331`, `:3344`. 8 direct backend calls in a "presentational" component.
- `resources/js/components/frontend/kiosk/KioskWizardComponent.vue` is **3094 LOC**, `KitchenDisplaySystemComponent.vue` is **2545 LOC**.
- Composition API adoption is **10 files** vs **308 Options API files**.
- No `api/` client folder, no per-feature service, no Pinia. The Vuex store is a 113-module flat list.

### P1 — Directory entropy + version drift in service layout

- `app/Services/Order/` and `app/Services/Orders/` both exist (singular vs plural).
- Top-level `OrderService.php`, `FrontendOrderService.php`, `OrderSetupService.php`, `OrderStatusScreenOrderService.php`, `KitchenDisplaySystemOrderService.php` coexist with the `Order/` subdir.
- `resources/js/components/admin/pos/v5/` subdir suggests a v5 that started but never replaced `PosComponent.vue` (still 3769 LOC).
- Config: `caisse_v1_rollout.php`, `catalog_v15.php` — versioned config files. 34 config files for ~73k LOC.

### P2 — `BranchScope` is too eager; `withoutGlobalScope` used 39× across the codebase

The scope exists for multi-tenant safety but is the wrong default for fiscal/audit reads, kiosk cleanup jobs, payment reconciliation, and cross-branch admin views. `withoutGlobalScope(BranchScope::class)` appears in 11 places just for `FrontendOrder`. Every override is a soft assertion that the scope was wrong here.

## 3 Top Recommendations

### 1. Collapse the `Order` / `FrontendOrder` duality into one model (4-week effort, v1.x mandatory)

Single `App\Models\Order` with all fillable fields, ONE observer attachment site, ONE boot hook. Replace `FrontendOrder` references with `Order` + (where needed) read-only scopes. Net deletion: ~180 LOC. Highest leverage refactor in the codebase.

### 2. Standardize the controller→service→domain layer; make `OrderStateMachine::apply` the only status writer

- Phase A: extract `LoyaltyController` → `LoyaltyService`. Same for `OrderController::paymentConfirm`. Target: zero `DB::` import in `app/Http/Controllers/`.
- Phase B: replace the 5 direct `$order->status = …` mutations in `OrderService` with `OrderStateMachine::apply()`.
- Phase C: split `OrderService.php` into `OrderQueryService` + `OrderCommandService` + existing `Order/OrderQuoteService`. Target: no service file > 600 LOC.

### 3. Introduce a frontend API client layer + start Composition API + Pinia migration on POS first

- Create `resources/js/api/` with one file per Laravel controller.
- Pick **POS first** as the migration testbed: rewrite `PosComponent.vue` (3769 LOC) into a `<script setup>` Composition tree backed by Pinia stores `posCart`, `posCustomer`, `posPayment`, `posFloorplan`.
- Ban new Options API components, ban new direct-axios calls in components.

## Comparison Sketch: FoodKing vs Ideal Laravel Restaurant POS SaaS

| Layer | Ideal | FoodKing |
|---|---|---|
| Controllers | HTTP-only: validate → dispatch → resource | LoyaltyController = 730 LOC of domain; OrderController.paymentConfirm = 313 LOC |
| Services | Thin Application Services calling Domain Services | OrderService 2432 LOC; FrontendOrderService 1237 LOC; near-identical query blocks |
| Domain | Aggregates + state machines + value objects | Real but sparse: 5 files in `app/Domain/`; OrderStateMachine::apply adopted in 2 callsites |
| Persistence | 1 model per table; repositories for cross-cutting reads | 2 models on `orders`; 39 `withoutGlobalScope` overrides; raw `DB::table` in controllers |
| Frontend state | Pinia, one store per feature, with API client layer | Vuex with 113 flat modules; components calling axios directly |
| Frontend components | <script setup> Composition, small components | 10 Composition vs 308 Options; PosComponent 3769 LOC, KioskWizardComponent 3094 LOC |
| Cross-surface sync | Outbox + broadcast + polling fallback | Genuinely solid here — Persist*ToOutbox triad + Pusher/polling state machine |
| Feature flags | Single registry + provider | 34 config files including versioned `caisse_v1_rollout.php`, `catalog_v15.php` |

FoodKing is best where it built new (Pricing, OrderStateMachine, Outbox, sync services) and worst where it inherited the original CRUD scaffold and never amortized the migration cost (controllers, OrderService, models, the 380-component Options API frontend). The v1.x architectural mandate is not "build more" — it is to *finish* what the modern half started, and to stop letting new code regress to the legacy patterns.
