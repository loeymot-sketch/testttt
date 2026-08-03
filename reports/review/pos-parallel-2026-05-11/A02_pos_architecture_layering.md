# A02 — POS Architecture & Layering — 2026-05-11

> Sub-agent : **A02** of 20 (parallel adversarial POS audit).
> HEAD : `a220b9bd8` — branch `feature/mobile-app-le-cayenne-2026-05-10`.
> Scope (strict) : `PosController`, `PosOrderController`, `PosCategoryController`, `Admin/Pos/*` controllers, `PosParkedOrderService`, `Pos/WalkInCustomerResolver`, `Admin/Pos/FloorplanTransferRequest`, `Http/Requests/PosOrderRequest`. READ-ONLY, file:line verified.
> Frozen zones (referenced only, never modified) : `OrderService.php`, `PricingService.php`, `PaymentService.php`, `OrderStateMachine.php`, `FrontendOrderService.php`.

---

## §1 Findings

### P0 — none in this slice

No P0 issues identified in the architecture/layering surfaces. Order creation funnels through `OrderService::posOrderStore` (frozen) ; pricing, fiscal allocation, state machine remain encapsulated. The defects below are layering/coherence issues, not correctness-fatal violations.

### P1 — layering violations & permission coherence

**P1-A02-001 — `PosOrderController::reorderItems` reimplements snapshot deserialization in the controller.**
- File : `app/Http/Controllers/Admin/PosOrderController.php`
- Lines : `178-284`
- Defect : `reorderVariations()` (209-239), `reorderExtras()` (241-269), `decodedOrderItemArray()` (271-284) are ~75 lines of business deserialization (parsing `composition_snapshot`, fallback to `item_variations`/`item_extras`, line shape construction). This is service-tier logic embedded in a thin HTTP controller. Other controllers in scope correctly delegate to a service (`ParkedOrderController` → `PosParkedOrderService`, `FloorplanController` → `DiningTableService`, `PosController` → `OrderService`).
- Impact : duplicate-logic risk (`OrderResource`/`OrderDetailsResource` already shape similar payloads), no service-level unit test target, can drift from `OrderService::posOrderStore` snapshot writer. Controller cyclomatic complexity ≈ 8 per private (multiple null-coalescing branches × snapshot/legacy/empty paths) — well within the warn threshold but the wrong layer.
- Suggested remedy : extract to `App\Services\Order\PosReorderProjection` (or similar). No frozen-zone touch.

**P1-A02-002 — `PosController::quote` inlines validation rules, bypassing FormRequest discipline.**
- File : `app/Http/Controllers/Admin/PosController.php`
- Lines : `75-116` (esp. `77-92` `$request->validate([...])`)
- Defect : `store()` (42-54) properly uses `PosOrderRequest`; the cohabiting `quote()` action validates inline with 12 fields including `quote_token`, `quote_signature`, `consume`, `items` + custom `ValidJsonOrder` rule. Same shape as the order create, but no `PosQuoteRequest` class — so rules are duplicated and drift-prone. Also there is no explicit `permission:` middleware on `quote` (the constructor binds `permission:pos` only to `store`, line 39), and no `abort_unless` inside — quote is effectively reachable by any authenticated user with a Sanctum/web session.
- Impact : inconsistent authz between paired endpoints ; pricing reconnaissance possible via `/admin/pos/quote` (cf. A03 Pricing SSOT, A14 RBAC). Should be cross-validated with A14.
- Suggested remedy : create `PosQuoteRequest extends FormRequest` with `authorize()` returning `$this->user()?->can('pos') ?? false` and bind in route.

**P1-A02-003 — `PosCategoryController::index` is a 110-line god-method mixing controller + repository + projection.**
- File : `app/Http/Controllers/Admin/PosCategoryController.php`
- Lines : `36-150` (single method)
- Defect : the action builds a multi-layered Eloquent query against `ItemCategory` (line 55), encodes a SQLite-vs-MySQL JSON-channel toggle inline (73-78, 84-88), reads `DefaultAccessService` (line 48), assembles a synthetic "all items" pseudo-row (114-120), and finally hands off to `PosMenuProjection` (139-143). 4× nested `where(function …)` closures. Cyclomatic complexity ≈ 18-20 (close to the >20 threshold A02 watches for). Only one direct `ItemCategory::` call (line 55), but the controller IS the repository here.
- Impact : impossible to unit-test without HTTP layer ; SQLite/MySQL JSON branching duplicated with `Item` siblings ; the `posRuntimeBranchId` gate (47) is repeated almost verbatim in other catalog controllers (KdsItemController, KioskItemController) — drift risk.
- Suggested remedy : extract `PosCategoryQuery` / `PosCategoryRepository` service ; controller drops to <20 lines.

**P1-A02-004 — `PosReceiptPrintController` extends raw `Controller` (not `AdminController`) and writes to Eloquent directly.**
- File : `app/Http/Controllers/Admin/Pos/PosReceiptPrintController.php`
- Lines : `29` (`extends Controller`), `43-58` (direct `Order::query()->...->update()` + `firstOrFail()`).
- Defect : every other in-scope controller extends `AdminController` for consistency (gets `authorizeBranchScope` helpers). This one extends `Controller` and reimplements `branch_id` defense at line 45-47 + 56-58. Also writes to `orders.receipt_print_count` directly via Eloquent — no service abstraction. There is no `permission:pos` middleware on the controller (registered route at api.php:797 inherits the outer `auth:sanctum` group only).
- Impact : authz hole if route group middleware is relaxed in the future (cross-validate with A14). Layering inconsistency makes audit harder. Tight coupling to `orders` table column name — refactor-fragile.
- Suggested remedy : extract `PosReceiptService::recordPrint($orderId, $branchId, $userId): array` ; controller becomes 10 lines + middleware. Make controller extend `AdminController`.

**P1-A02-005 — `PosOrderController::reorderItems` lacks any explicit branch-scope guard.**
- File : `app/Http/Controllers/Admin/PosOrderController.php`
- Lines : `178-207`
- Defect : route-model-binding (`Order $order`) plus the `BranchScope` global scope normally suffices, BUT the `show()` action (104-115) explicitly uses `withoutGlobalScope(BranchScope::class)` (line 108) — meaning the team has hit cross-branch concerns here before. The `reorderItems()` action has no such defense and no explicit `branch_id` check ; an Admin operator on branch A who can guess an order id from branch B will get the full cart structure including pricing. Permission `pos-orders` is constructor-bound (line 28-36) but pos-orders is granted to many non-Admin operators.
- Impact : cross-branch read of past order contents possible (low severity — no mutation, no fiscal value, but information disclosure). Likely cross-validated with A13 (branch isolation).
- Suggested remedy : add `abort_if($order->branch_id !== $request->user()->branch_id && ! $request->user()->hasRole('Admin'), 403)` or use `OrderService::orderDetails` (frozen-zone service that already gates).

### P2 — anti-patterns & maintainability

**P2-A02-006 — Inconsistent return-type union in nearly every PosOrderController action.**
- File : `app/Http/Controllers/Admin/PosOrderController.php`
- Lines : `93-96`, `104-106`, `117-119`, `132-134`, `142-145`, `153-156`, `164` (and PosController 42 / 75)
- Defect : actions return a 5-element union type like `\Illuminate\Http\Response|OrderDetailsResource|\Illuminate\Contracts\Foundation\Application|\Illuminate\Contracts\Routing\ResponseFactory`. This is the auto-IDE-generated grab-bag of every type `response()` may yield. Real returns are limited to `JsonResponse` or `OrderDetailsResource`. The verbose union obscures intent and tooling can't infer correctness.
- Impact : reviewer fatigue ; tools like Larastan can't enforce stricter contract.
- Suggested remedy : normalize to `\Illuminate\Http\JsonResponse|OrderDetailsResource` (and `BinaryFileResponse` for export). Pure cleanup.

**P2-A02-007 — `PosController` constructor binds middleware only on `store`, leaving `walkInCustomer` and `quote` self-guarded.**
- File : `app/Http/Controllers/Admin/PosController.php`
- Lines : `39` (`->only('store')`), `62` (in-action `abort_unless` for walkIn), `quote()` (no gate at all).
- Defect : Mixed authz style. `walkInCustomer` uses `abort_unless($request->user()?->can('pos'), 403)` ; `store` uses middleware ; `quote` uses neither. Pick one — middleware is preferable for grep-ability and route-level enforcement.

**P2-A02-008 — `try/catch (Exception)` blanket masking on 13 of 13 controller actions.**
- Files : both `PosController` (44-53, 94-115) and `PosOrderController` (97-101, 107-114, 120-129, etc.)
- Defect : the universal pattern `catch (Exception $e) { return response(['status' => false, 'message' => $e->getMessage()], 422); }` exposes raw exception messages to clients (`$e->getMessage()`) — that includes DB constraint names, file paths from `firstOrFail`, internal class names, etc. PII leak surface and information disclosure. Also normalizes ALL failures to 422 regardless of cause (404s, 500s, 503s all become 422 unprocessable). The `PosOrderController::destroy` carve-out at line 123-126 acknowledges this exact problem ("Do NOT mask 403/404 as 422") but only fixes one action — the rest still mask.
- Impact : forensics hard (real status codes hidden), info disclosure to client. Cross-validate with A14 (RBAC) and A15 (webhook events) for similar patterns.
- Suggested remedy : let HttpException bubble (already done for `destroy`), log the rest server-side, return generic message — pattern already proven in `PosOrderController::refundWithCounterEntry` (74-90).

**P2-A02-009 — `PosCategoryController::index` constructor middleware uses `canAny([…])` but the action uses a re-derived `posRuntimeBranchId` gate.**
- File : `app/Http/Controllers/Admin/PosCategoryController.php`
- Lines : `17-22` (middleware) vs `45-53` (action gate).
- Defect : same permission check expressed two different ways. The middleware allows `items_show` OR `pos` ; the action then re-checks `$user->can('pos') && ! $user->can('items_show') && ! $user->hasRole('Branch Manager')` to decide whether to apply branch-scoped filtering. Permission policy split across 2 places ⇒ drift risk.

### P3 — minor

**P3-A02-010 — `WalkInCustomerResolver` couples password hashing into a resolver.**
- File : `app/Services/Pos/WalkInCustomerResolver.php`
- Lines : `16-27`
- Defect : the resolver uses `firstOrCreate` and seeds `password => Hash::make('123456')` on the canonical walk-in row. The hashing happens every time the row needs to be looked-up by a fresh deploy, but only because the resolver is also the seeder. Pre-baked constant `123456` is a weak well-known password (compounded by a real account in the `users` table). Even though no human logs in as that user, the row is reachable via the auth stack and Spatie role assignments.
- Impact : low (no human login expected, but `php artisan tinker` reset would be trivial). Worth seeding a 32-char random hash instead.

**P3-A02-011 — `PosParkedOrderService::isDuplicateIdempotencyException` does substring sniffing on DB driver error text.**
- File : `app/Services/PosParkedOrderService.php`
- Lines : `232-239`
- Defect : detects unique constraint violations by scanning the lowercased exception message for `'pos_parked_user_idem_uniq'`, `'unique constraint'`, `'duplicate'`. Fragile across MySQL/Postgres/SQLite locales and string i18n. Cleaner approach : check `$exception->getCode() === '23000'` (SQLSTATE integrity).
- Impact : low ; broken localization or driver upgrade could silently make the dedup path throw instead of replay.

**P3-A02-012 — `PosOrderController::show` bypasses `BranchScope` via `withoutGlobalScope` then delegates to `OrderService::show($order, false)`.**
- File : `app/Http/Controllers/Admin/PosOrderController.php`
- Lines : `107-109`
- Defect : intentional (comment-less) bypass. Once `BranchScope` is removed, the order id can be any branch's order. `OrderService::show` is frozen-zone — A02 won't propose change to it — but A02 flags the pattern for cross-validation with A13. If the service re-applies branch guard internally we're fine ; if not, this is a P1.

---

## §2 Cross-validation watch list

These items must be triangulated with sibling sub-agents :

- **P1-A02-002 (PosController::quote authz gap)** → cross-check with **A14 (RBAC / FormRequest authz)** and **A03 (Pricing SSOT reconnaissance via quote endpoint)**.
- **P1-A02-004 (PosReceiptPrintController missing permission middleware, direct Eloquent)** → cross-check with **A14** and **A07/A08 (Fiscal audit chain — receipt reprint is NF525-relevant per Article 286-I-3 bis CGI)**.
- **P1-A02-005 (reorderItems no branch guard)** → cross-check with **A13 (Branch Isolation)**.
- **P2-A02-008 (universal exception masking)** → cross-check with **A14 (RBAC)** : if 403s are being silently rewritten to 422 in any action other than `destroy`, that is a real auth-bypass-style obscuration.
- **P3-A02-012 (PosOrderController::show withoutGlobalScope)** → MUST be cross-validated with **A13** to confirm `OrderService::show` re-applies branch isolation internally. If not, escalate to P0/P1.
- **P2-A02-009 (twin permission gate in PosCategoryController)** → cross-check with **A14**.

---

## §3 Proposed E2E coverage (Playwright)

Five scenarios that exercise the controller surfaces in scope without touching frozen services :

1. **`pos-quote-unauthenticated-401`** — Hit `POST /api/v1/admin/pos/quote` with no Sanctum token. Assert 401. Then hit with a `kds-only` permission user (no `pos`). Assert 403 (currently expected 200 — confirms P1-A02-002).
2. **`pos-walk-in-customer-resolves-and-binds`** — Login as POS operator (branch A), `GET /admin/pos/walk-in-customer` → assert 200 + `id` integer. Then `POST /admin/pos` with `customer_id: 0` → assert backend silently substitutes walk-in id (validates `normalizePosRuntimePayload`). Then assert `Order.customer_id === walkInId`.
3. **`pos-reorder-cross-branch-leak`** — Seed two branches A/B, each with one paid order containing custom variations/extras. Login as POS-operator-branch-A. `GET /admin/pos-order/reorder-items/{branchB-order-id}` → expected 403 ; observed 200 with full cart payload (confirms P1-A02-005, ties to A13).
4. **`pos-receipt-reprint-audit-chain`** — Create order (id N), `POST /admin/pos/orders/N/print-receipt` 3×. Assert response 1 → `is_duplicata=false`, responses 2/3 → `is_duplicata=true`. Then query `audit_logs WHERE resource_id=N` → expect 3 rows (1×`pos.receipt.print`, 2×`pos.receipt.reprint`) with chained `prev_hash`. Validates W9.B / A07 too.
5. **`pos-cash-drawer-session-cross-branch-403`** — Operator on branch A opens session, captures session id. Operator on branch B does `GET /admin/pos/cash-drawer/sessions/{idA}/movements` → assert 403 (validates `assertSessionVisibleToUser` line 199-213). Then admin global (branch_id=0) does the same → assert 200 (validates the line-208 bypass).
6. *(bonus)* **`pos-parked-order-recall-prunes-unavailable`** — Park order containing a variation, soft-delete the variation, recall the parked order → assert response `warnings.unavailable_variations` contains the dropped row (validates `PosParkedOrderService::pruneUnavailableParkedVariations` 113-193). Documents intent.

All scenarios run against the admin Vue surface or via direct API (no need to touch the frozen Vanilla wizard).

---

## §4 Verdict

**A02 verdict : HEAL.** No P0 in the architecture/layering slice. **5 P1 layering violations** — none fatal — but `PosReceiptPrintController` missing the AdminController parent + the explicit `permission:pos` middleware (P1-A02-004) and `quote` endpoint missing authz (P1-A02-002) are real cross-validation candidates with A14. `reorderItems` (P1-A02-001) is a 75-line service that found its way into a controller — refactor at low risk. Universal `catch (Exception)` masking (P2-A02-008) is annoying but already has a fix template inside the same file (`destroy` 123-126).

Frozen zones honored : `OrderService.php`, `PricingService.php`, `PaymentService.php`, `OrderStateMachine.php`, `FrontendOrderService.php` were referenced (e.g., `posOrderStore` line 563, `show` line 1351, `collectKioskCash` line 1954 — read for dependency mapping only). No findings propose modification to those files.

Dependency direction summary :
- ✅ `PosController` → `OrderService` (frozen) + `OrderQuoteService` + `WalkInCustomerResolver` + `DeliveryFeeService` — clean.
- ✅ `PosOrderController` → `OrderService` (frozen) — clean except `reorderItems` (P1-A02-001).
- ⚠️ `PosCategoryController` → `ItemCategory` + `DefaultAccessService` + `PosMenuProjection` directly (P1-A02-003).
- ✅ `Pos/CashDrawerController` → `EscPosPrinterService` — clean (thin).
- ✅ `Pos/CashDrawerSessionController` → `CashDrawerService` — clean.
- ✅ `Pos/FloorplanController` → `DiningTableService` — clean.
- ✅ `Pos/ParkedOrderController` → `PosParkedOrderService` — clean.
- ⚠️ `Pos/PosReceiptPrintController` → `AuditLogService` (frozen) + direct `Order::query()->update()` (P1-A02-004).
- ✅ `Pos/CustomerNfcLookupController` → `User` Eloquent (acceptable here — pure lookup, no business rule).

---

## §5 BRAIN drift note

This audit slice does NOT contradict `PROJECT_BRAIN.md` §6 DECISIONS LOG or §7 VERIFICATION CHECKLIST. The 2026-05-09 audit (`reports/review/pos-ultra-audit-2026-05-09/99_VERDICT.md`) flagged 13 P0 across the FULL POS surface — A02's narrower architecture-layering slice surfaces P1/P2 only. No P0 escalation, no frozen-zone gate request, no DECISIONS-LOG amendment proposed.

One pre-existing fact to re-confirm with A14 : `pos-orders` permission is held by non-Admin operators ; if the cross-branch `reorderItems` route returns data without an explicit `branch_id` check, the BRAIN §9 "Branch Isolation" invariant is partially weakened on the `pos-order/reorder-items/*` route. Pending A13 confirmation.

Report length : ~1 450 words (under the 1 500 budget).
