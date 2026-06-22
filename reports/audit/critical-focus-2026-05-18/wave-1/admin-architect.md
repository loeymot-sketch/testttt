# Admin / Management Transverse — GStack Architect Audit (Wave 1)

**Branch**: `v1-0-1-hardening-2026-05-17` @ HEAD `068461ffc`
**Scope**: Catalogue + Stock + Settings + Users RBAC + Reports + Branch (read-only, V1 LOCAL Le Cayenne)
**Reference plan**: `plans/ULTRA_PLAN_V1_CRITICAL_FOCUS_2026-05-18.md` §2 Zone 7

---

## 1. Surface Inventory

`ls app/Http/Controllers/Admin/` returns **89 entries** (file count = 87 PHP + 2 sub-dirs `Fiscal/` + `Pos/` + `Observability/`).

### 1.1 Catalogue cluster
- `app/Http/Controllers/Admin/ItemController.php:31-36` — gates `items`, `items_create`, `items_edit`, `items_delete`, `items_show`
- `app/Http/Controllers/Admin/ItemCategoryController.php:27` — gate `permission:settings` (full CRUD)
- `app/Http/Controllers/Admin/ItemAttributeController.php:22` — gate `permission:settings` (index+show+store+update+destroy)
- `app/Http/Controllers/Admin/ItemAddonController.php:21-22` — `items_show` / `items_edit`
- `app/Http/Controllers/Admin/ItemExtraController.php:21-22` — `items_show` / `items_edit`
- `app/Http/Controllers/Admin/ItemVariationController.php:22-23` — `items_show` / `items_edit`
- `app/Http/Controllers/Admin/ItemPhotoController.php:18` — `items_edit` (store only)
- `app/Http/Controllers/Admin/IngredientController.php:12-18` — **NO controller-level middleware**. Gated at route level: `routes/api.php:682` (`permission:ingredients_manage`), `:696` (`permission:catalog.compose`), `:718` (`permission:catalog.publish`)

### 1.2 Stock cluster
- `app/Http/Controllers/Admin/AvailabilityController.php:21-26` — `items_edit` on mutating endpoints
- `app/Http/Controllers/Admin/StockRuptureDashboardController.php:23-24` — `items_show` (read), `items_create` (run)
- `app/Services/Stock/StockService.php:145,151,447` — emits `StockLevelChanged` + `ItemAvailabilityChanged` cascade
- Cascade wiring observed (the "86" figure in the brief is opaque — not verified as such): `EventServiceProvider.php:173-180` registers 3 listeners on `ItemAvailabilityChanged` (`BumpMenuSnapshotOnItemAvailabilityChanged`, `InvalidateKioskMenuCacheOnItemAvailabilityChanged`, `PersistItemAvailabilityChangedToOutbox`); `:227-230` registers `NotifyStockLowOnStockLevelChanged`. Dispatch fan-in covers 5 service sites (Item, ItemExtra, ItemAddon, ItemVariation, Stock).

### 1.3 Fiscal cluster (frozen services, controllers thin)
- `app/Http/Controllers/Admin/Fiscal/ZReportController.php:18-110` — gate via private `authorizeFiscal()` (`pos-manage-fiscal` Spatie permission). Routes: `routes/api.php:1046-1053` with `throttle:10,1` on `/open` + `/close`
- `app/Http/Controllers/Admin/Fiscal/XReportController.php` — `routes/api.php:1055`
- Service backbone (FROZEN): `app/Services/Fiscal/ZReportService.php`, `FiscalSequenceService.php`, `AuditLogService.php`

### 1.4 Settings cluster (24 controllers, R9 wired Wave 5G)
- 30 controllers carry `permission:settings` middleware (grep verified). Subset wired with `SettingsUpdated` event:
  - `app/Http/Controllers/Admin/CurrencyController.php:21,38,50,62` (3 dispatch sites)
  - `app/Http/Controllers/Admin/TaxController.php:39,51,63`
  - `app/Http/Controllers/Admin/CompanyController.php:36`
  - `app/Http/Controllers/Admin/SiteController.php:40`
  - `app/Http/Controllers/Admin/OrderSetupController.php:37`
- Sensitive RCE-class endpoints: `app/Http/Controllers/Admin/LanguageController.php:27` — Wave 5E gates `store, update, destroy, fileText, fileTextStore` behind `permission:settings`

### 1.5 Users / RBAC cluster
- `app/Http/Controllers/Admin/AdministratorController.php:31-35` — granular `administrators_*` gates
- `app/Http/Controllers/Admin/EmployeeController.php:28-32` — granular `employees_*` gates
- `app/Http/Controllers/Admin/RoleController.php:21-22` — `permission:settings` write, `permission:settings|employees` read
- `app/Http/Controllers/Admin/PermissionController.php:27` — `permission:settings` on update
- FormRequest authz layer: `app/Http/Requests/AdministratorRequest.php:16-27` (verb-aware `$user->can(...)` defense-in-depth, Wave 5H)

### 1.6 Branch + Reports + Hardware
- `app/Http/Controllers/Admin/BranchController.php:19-110` — `permission:settings` on `store/update/destroy/updateZone` + `BranchStatusChanged` dispatch at `:72` (update) and `:99` (destroy)
- `app/Http/Controllers/Admin/SalesReportController.php:37` — `permission:sales-report`
- `app/Http/Controllers/Admin/ItemsReportController.php:31` — `permission:items-report`
- `app/Http/Controllers/Admin/PrinterController.php:19-20` — `permission:pos` read, `permission:settings` write
- `app/Http/Controllers/Admin/KioskMachineController.php:22` — `permission:settings` on lifecycle
- `app/Http/Controllers/Admin/PaymentTerminalController.php` — `permission:settings` (cluster member)

### 1.7 Mount points (auth)
- `routes/api.php:255` and `:269` — admin prefix wrapped in `auth:sanctum` + `throttle:admin-mutation`. The `api` middleware group (`app/Http/Kernel.php:46-59`) injects `EnsureUserStatusActive` AFTER auth via priority list at `:80-92`.

---

## 2. Critical Invariants (verified)

| # | Invariant | Verified | Evidence |
|---|---|---|---|
| 1 | `permission:settings` on sensitive admin routes | YES | 30 controllers (see §1.4) |
| 2 | `LanguageController` RCE-class endpoints gated (Wave 5E) | YES | `LanguageController.php:27` |
| 3 | `permission:pos` on POS routes | YES | `PosController.php:51`; `Pos/CashDrawerController.php:22`; `Pos/ParkedOrderController.php:15`; `Pos/FloorplanController.php:16`; `Pos/CustomerNfcLookupController.php:16` |
| 4 | `SettingsUpdated` broadcasts on update (currency / tax / company / site / order-setup) | YES | 9 dispatch sites verified; listener `PersistSettingsUpdatedToOutbox` wired `EventServiceProvider.php:239-241` |
| 5 | `BranchStatusChanged` revokes tokens on deactivate | YES | `BranchController.php:67-73` (update transition guard) + `:94-99` (destroy as forced INACTIVE); listener `RevokeTokensOnBranchDeactivated.php:25-67` with strict `tokenable_type = User::class` filter at `:53` |
| 6 | `EnsureUserStatusActive` per-request middleware after `auth:sanctum` | YES | Kernel `api` group `:58`; priority array `:86`; logic `EnsureUserStatusActive.php:46-93` (live DB single-column read line `:70`, currentAccessToken delete + 401 lines `:76-89`) |
| 7 | bcrypt rounds 12 + auto-rehash on login | YES | `config/hashing.php:34-39` (`'rounds' => env('BCRYPT_ROUNDS', 12)`); rehash hook `LoginController.php:95-98` |
| 8 | "18 models scoped by `BranchScope`" (brief, inconsistent: SCOPE says 17, Critical Invariants says 18) | **PARTIAL — discriminating numbers** | At HEAD `068461ffc`: (a) **17 models** wrap the scope via `addGlobalScope(new BranchScope())` — verified by grep, list: Order, OrderItem, OrderPayment, FrontendOrder, KioskMachine, StockLevel, StockMovement, CashDrawerSession, CashMovement, PendingPaymentConfirmation, PushNotification, DiningTable, Printer, PosParkedOrder, OrderQuote, PaymentTerminal (Sprint 1C NEW), User; (b) **effective branch-filtered count = 16** because `BranchScope::apply` short-circuits on `User instanceof` at `BranchScope.php:21-23`; (c) brief's "18 expected" is unsourced — **internal inconsistency to reconcile against `plans/GOAL_PRODUCTION_READINESS_LECAYENNE_2026-05-18.md`** before declaring the invariant met. |
| 9 | Stock cascade fires `ItemAvailabilityChanged` (brief writes "86 cascade" — opaque) | YES (structure verified; "86" not verified) | Services: `ItemService.php:336,494`, `ItemExtraService.php:127`, `ItemAddonService.php:91`, `ItemVariationService.php:201`, `Stock/StockService.php:151`. Listener wiring: `EventServiceProvider.php:173-180` (3 listeners) + `:227-230` (1 listener on `StockLevelChanged`). |
| 10 | Z report PDF + audit chain extend (frozen service) | YES | `ZReportController.php:73-89` (pdf) calls `ZReportService::verifySignature`; FROZEN service per CLAUDE.md §7 |

---

## 3. Weak Spots

### W-1 — BranchScope coverage: three discriminating numbers (P1)
The brief contains an internal inconsistency: SCOPE says **17** (`Models BranchScope (17 post-GOAL)`), Critical Invariants says **18** (`18 models scoped (verify 17 + 1)`). Ground truth at HEAD:
- **17 models** wrap `addGlobalScope(new BranchScope())` (grep verified, list in row 8 above).
- **16 effective branch-filtered models** — `User` is in the 17 but `BranchScope::apply` short-circuits on `User instanceof` at `BranchScope.php:21-23` (required to avoid Sanctum recursion).
- The brief's **18** is unsourced.

Risk: if the brief's "18" reflects a real GOAL-plan deliverable not landed, one multi-tenant model is leaking cross-branch. Action: reconcile against `plans/GOAL_PRODUCTION_READINESS_LECAYENNE_2026-05-18.md` to identify which model (if any) is the missing 18th.

### W-2 — `EmployeeRequest::authorize()` returns `true` unconditionally (P1)
`app/Http/Requests/EmployeeRequest.php:16-19` — explicit `return true;`. Per task brief "AdministratorRequest authz refactored Wave 5H + EmployeeRequest skipped". Defense-in-depth gap remains: any future route mis-wire on `EmployeeController` (whose middleware tree is granular `:28-32`) bypasses verb-level capability check. Symmetric treatment with `AdministratorRequest.php:16-27` recommended.

### W-3 — `ItemAttributeRequest::authorize()` also `return true` (P2)
`app/Http/Requests/ItemAttributeRequest.php:15-18`. `ItemAttributeController.php:22` gates with single `permission:settings`. Lower risk than W-2 because the gate is wider and not granular, but the FormRequest still lies about authz.

### W-4 — `IngredientController` has no constructor middleware (P2)
`app/Http/Controllers/Admin/IngredientController.php:12-18` relies exclusively on route-level gates (`routes/api.php:682, 696, 718`). If a developer adds a new route outside the `prefix('ingredients')` group, it inherits zero capability gate. Constructor-level guard is the FoodKing convention (see all 30 cousins in §1.4).

### W-5 — `BranchScope` does not apply during CLI (P2)
`app/Models/Scopes/BranchScope.php:27` — `if ((!App::runningInConsole() || App::runningUnitTests()) && Auth::check())`. Artisan commands (cron, queues) MUST set the actor explicitly. Existing F-010 sentinel addresses queues; verify Reports CLI exports do not exfiltrate cross-branch.

### W-6 — Z report `pdf()` returns JSON, not a PDF binary (P3 / spec drift)
`ZReportController.php:73-89` returns JSON bundle (`z_report` + `verified` + `generated_at`) — comment at `:67-71` acknowledges "PDF rendering delegated to a later view layer". V1 LOCAL acceptable; NF525 archival expects a real PDF on the 6-year retention window. Carry to V1.0.2 backlog.

### W-7 — `BranchStatusChanged` non-transition guard ONLY in listener, not at controller (P3)
`BranchController.php:71` dispatches only on `$oldStatus !== $newStatus`. Good. The DESTROY path at `:99` dispatches `(branchId, oldStatus, INACTIVE)` unconditionally — if a branch is already INACTIVE on soft-delete the listener `RevokeTokensOnBranchDeactivated.php:27-29` short-circuits. Acceptable belt-and-suspenders; no action needed.

### W-8 — `EnsureUserStatusActive` reads `users.status` raw int via `\DB::table` (P3 / cleanliness)
`EnsureUserStatusActive.php:70` casts to `(int)` against `Status::ACTIVE`. Enum coercion happens correctly because `Status` is an int-backed enum (`use App\Enums\Status`). No bug; flagged because the bypass of Eloquent is intentional (see comment `:62-69`) — must remain.

---

## 4. Existing Test Coverage

### Settings + R9/R10 broadcast
- `tests/Feature/Settings/SettingsUpdatedBroadcastTest.php` — covers R9 dispatch
- `tests/Feature/Branch/BranchDeactivationTokenRevokeTest.php`, `BranchDestroyRevokesTokensTest.php` — covers R10 update + destroy paths

### Auth + bcrypt
- `tests/Feature/Auth/BcryptRoundsUpgradeTest.php` — covers `Hash::needsRehash` rehash hook

### BranchScope
- `tests/Feature/BranchScopeTest.php`, `tests/Feature/BranchIsolationTest.php`
- `tests/Feature/Menu/PosCategoryBranchScopeTest.php`
- `tests/Feature/Orders/IdempotencyBranchScopedTest.php`
- `tests/Feature/Sentinels/F010BranchScopeQueueContextSentinelTest.php` — queue context regression
- `tests/Feature/Dashboard/DashboardBranchScopeMatrixTest.php`
- `tests/Feature/Composer/ComposerTemplateBranchScopedTest.php`
- `tests/Feature/Branch/BranchFiscalIdentityTest.php`, `OrderBranchIsolationTest.php`, `OssAdminBranchPolicyTest.php`

### Fiscal (~25 tests under `tests/Feature/Fiscal/`)
- `ZReportControllerTest.php`, `ZReportCloseTest.php`, `XReportTest.php`
- `FiscalPermissionTest.php` (gate verification), `FiscalRateLimitTest.php` (`throttle:10,1`)
- `NF525ComplianceE2ETest.php`, `AuditLogHashChainTest.php`, `AuditLogImmutabilityTest.php`
- `Sentinels/FiscalSealedZSentinelTest.php`, `FiscalZBranchExactnessSentinelTest.php`

### Stock (~13 tests under `tests/Feature/Stock/`)
- `StockBranchIsolationTest.php`, `StockMovementsAppendOnlyTest.php`, `StockConcurrentDecrementTest.php`
- `StockRuptureAvailabilitySyncTest.php`, `StockAvailabilityAfterCommitTest.php`

### Catalogue / Items
- `tests/Feature/Admin/ItemRequestBarcodeKdsStationTest.php` (H5 Z5-P1-02)
- `tests/Feature/Requests/{ItemRequestTest,ItemCategoryRequestTest,ItemAttributeRequestTest}.php`
- `tests/Feature/Items/{ItemCreateContractTest,ItemPhotoUploadTest,ItemPhotoUploadAtomicityTest}.php`

---

## 5. Test Coverage GAPS

### G-1 — No test for `EnsureUserStatusActive` middleware (HIGH)
`find tests -name "EnsureUserStatusActive*"` = 0 rows. The H1 Z6-06 every-request gate has zero direct unit/feature coverage. Risk: regression on Kernel `$middlewarePriority` (`Kernel.php:80-92`) silently moves the middleware to position 0, bypassing the gate (this is exactly the failure mode the priority array was added to prevent — and it has no regression net).

### G-2 — No test for `EmployeeRequest` defense-in-depth (MEDIUM)
W-2 weakness has no regression sentinel. Add a `tests/Feature/Requests/EmployeeRequestTest.php` mirroring `AdministratorRequestTest` (if it exists; brief implies it does via Wave 5H).

### G-3 — No test for `IngredientController` route-only gate (MEDIUM)
W-4 risk has no regression sentinel. A "future-route" test asserting any added `Route::*` under `prefix('ingredients')` inherits a permission gate would harden against silent drift.

### G-4 — No standalone test for `LanguageController` RCE-class endpoint gate (MEDIUM)
Wave 5E patched `:27` but I found no `tests/Feature/Admin/LanguageControllerSecurityTest.php` or equivalent. Sentinel needed to prevent regression to the pre-Wave-5E open-state.

### G-5 — Z report PDF binary contract not asserted (LOW)
W-6 — once V1.0.2 lands real PDF rendering, asserting Content-Type + valid PDF magic header is needed.

### G-6 — `BranchScope::apply` console-exemption regression net (LOW)
`BranchScope.php:27` early-returns under `App::runningInConsole() && !runningUnitTests()`. The F-010 sentinel covers queue context, but no test enforces "every Artisan reports command sets actor before query". A meta-test scanning `app/Console/Commands/*` would suffice.

---

## 6. Recommendations

### R-1 (P1) — Reconcile BranchScope model count discrepancy
Brief internally states both 17 and 18. HEAD has 17 wrapped / 16 effective. Resolve which figure is canonical against `plans/GOAL_PRODUCTION_READINESS_LECAYENNE_2026-05-18.md`. If the canonical answer is 18, identify the missing model and either add the scope or document the exemption with the same rigor as the `User` early-return at `BranchScope.php:21-23`.

### R-2 (P1) — Plug `EmployeeRequest::authorize()`
Mirror `AdministratorRequest.php:16-27` pattern (verb-aware `$user->can('employees_create') || $user->can('employees_edit')`). Lift the wave 5H precedent; trivially safe, zero behaviour change for legitimate flows.

### R-3 (P2) — Add `EnsureUserStatusActive` regression suite
Three test cases minimum: (i) inactive user receives 401 + token deleted, (ii) middleware DOES NOT short-circuit anonymous routes, (iii) middleware sorts AFTER `AuthenticatesRequests` (priority array regression).

### R-4 (P2) — Sentinel for `LanguageController` write-gates
Assert HTTP 403 for non-admin sanctum tokens on `store/update/destroy/fileText/fileTextStore` (Wave 5E regression net).

### R-5 (P2) — Centralize `permission:settings` in controller base
The 30 manual constructor calls is a foot-gun (drift surface). Consider `AdminSettingsController` abstract base injecting the gate via constructor — incremental refactor, not urgent.

### R-6 (P3) — Z report PDF binary V1.0.2
Replace JSON bundle (`ZReportController.php:73-89`) with `Dompdf`/`spatie/laravel-pdf` render + signed binary attachment, retain JSON branch for API consumers (Content-Type negotiation).

### R-7 (P3) — `IngredientController` constructor convention
Add `$this->middleware(['permission:ingredients_manage'])` at the constructor for parity with the 30-controller pattern. Belt-and-suspenders against route-level drift.

---

**Sign-off**: All citations are file:line verified against HEAD `068461ffc` on branch `v1-0-1-hardening-2026-05-17`. Idempotency middleware + Fiscal services treated read-only per CLAUDE.md §7. No mutation performed.

GStack Architect — Admin — Wave 1
