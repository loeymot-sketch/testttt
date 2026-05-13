# K19 — Admin Kiosk Setup + Promo + Cleanup

**HEAD** : `6a33a9763b7ef8da9ffb350732b1cdff1fab2261` (branche `feature/mobile-app-le-cayenne-2026-05-10`).
**Mode** : READ-ONLY, citations primaires lues, pas d'invention.

## Files audited

- `app/Http/Controllers/Admin/KioskMachineController.php` — 90 LOC
- `app/Http/Controllers/Admin/KioskSetupController.php` — 40 LOC
- `app/Services/KioskMachineService.php` — 197 LOC
- `app/Services/KioskSetupService.php` — 39 LOC
- `app/Services/Kiosk/KioskPromoService.php` — 122 LOC
- `app/Http/Requests/KioskMachineRequest.php` — 45 LOC
- `app/Http/Requests/KioskSetupRequest.php` — 25 LOC
- `app/Http/Resources/KioskMachineResource.php` — 30 LOC
- `app/Http/Resources/KioskSetupResource.php` — 28 LOC
- `app/Jobs/CleanupStalePendingKioskOrders.php` — 85 LOC
- `app/Console/Commands/SimulateKioskOrders.php` — 60 LOC
- `app/Models/KioskPromo.php` — 123 LOC
- `database/migrations/2026_04_18_120005_create_kiosk_promos_table.php` — 57 LOC (schema cross-ref)
- `routes/api.php:269,310-313,494-502,1239-1242` — route registration
- `app/Console/Kernel.php:54-57,145-150` — cleanup schedule + commands auto-load
- `app/Http/Controllers/Frontend/PromoController.php` — 66 LOC (consumes `KioskPromoService`)
- `app/Http/Requests/Kiosk/PromoValidateRequest.php` — 38 LOC
- `app/Providers/AppServiceProvider.php:62-128` — prod boot guards (cross-ref, no simulator guard)
- `app/Http/Controllers/Admin/AdminController.php:15-40` — `authorizeBranchScope*` helpers (unused)

## Findings

### P0 (blocker pre-merge V1)

- **K19-P0-01 : `SimulateKioskOrders` artisan command has zero production guard and bypasses NF525 + PricingService SSOT.**
  - File: `app/Console/Commands/SimulateKioskOrders.php:28-58` + `app/Console/Kernel.php:147` (`$this->load(__DIR__.'/Commands')` auto-registers it in every environment).
  - Issue: The command `php artisan kiosk:simulate-orders {count=50}` is exposed in production. `handle()` raw-inserts rows directly into `orders` via `Order::create([...])` with hardcoded `branch_id=1`, hardcoded `payment_status=5`, hardcoded `status=1`, hardcoded `source=10`, no fiscal sequence allocation (`fiscal_sequence_no` left null bypassing `FiscalSequenceService`), no idempotency key, no `composition_snapshot` (SSOT pricing bypassed), and dispatches REAL `SendOrderGotPush` / `SendOrderGotMail` / `SendOrderGotSms` events. Running this on prod would: (a) inject 50 fake paid orders into branch 1's books, (b) break NF525 chain integrity (gap-free monotonic sequence expectation violated), (c) push 150 emails+SMS+notifications to real customers if `users.branch_id=1` user has a phone/email, (d) pollute Z-reports for that branch.
  - Evidence: `SimulateKioskOrders.php:36-50` shows `subtotal=15.00`, `total_tax=1.50`, `total=16.50`, `payment_status=5` (=PAID per `PaymentStatus`), `status=1` (=ACCEPT per `OrderStatus`). No `app()->environment()` check anywhere. `Kernel.php:145-149` loads all `Commands/*` unconditionally. Comparable production guards in `AppServiceProvider.php:78-128` exist for printing/payment bypass and broadcasting/queue/cache drivers — none for this simulator.
  - Suggested fix: top of `handle()`:
    ```php
    if (app()->isProduction()) {
        $this->error('kiosk:simulate-orders is forbidden in production.');
        return self::FAILURE;
    }
    ```
    AND/OR scope the command's `$signature` registration via `Kernel::commands()` to non-prod only:
    ```php
    if (!app()->isProduction()) { $this->commands([SimulateKioskOrders::class]); }
    ```
    Add a feature test asserting the command refuses to run when `APP_ENV=production`.

### P1 (high — V1.0.1 sprint)

- **K19-P1-01 : `KioskMachineController::index` is NOT gated by `permission:settings`.**
  - File: `app/Http/Controllers/Admin/KioskMachineController.php:22`
  - Issue: `->only('show', 'store', 'update', 'destroy', 'logout', 'changeStatus')` excludes `index`. Any authenticated user holding ANY admin token (POS Operator, Chef, Branch Manager) that reaches the admin route group (`routes/api.php:269` middleware `auth:sanctum + apiKey + admin throttle`) can `GET /api/admin/kiosk-machine` and enumerate every kiosk machine: `KioskMachineResource` returns `username` + `branch_name` + `is_login` + `machine_id` (`KioskMachineResource.php:18-28`). Username + branch ↔ enables targeted credential-stuffing against `KioskMachineLoginController`. Compare to `KioskSetupController.php:18-20` where a sibling audit (`[GAP-19-2]`) explicitly added `index` AND `update` to its `->only()` whitelist — the same pattern was missed here.
  - Suggested fix: change to `->only('index', 'show', 'store', 'update', 'destroy', 'logout', 'changeStatus')` (or invert to `->except([])`).

- **K19-P1-02 : `KioskMachineRequest::authorize() returns true` — no actor-vs-target branch ownership check on create/update/move.**
  - File: `app/Http/Requests/KioskMachineRequest.php:13-16` + `app/Services/KioskMachineService.php:62-95`
  - Issue: Branch Manager (`branch_id=X`, `permission:settings`) can `POST /api/admin/kiosk-machine` with `branch_id=Y` and successfully create a machine in another tenant's branch. Same vector on update — they can move a machine row from branch X to branch Y. `KioskMachine` has `BranchScope` (model:38) so they couldn't `index` or `show` the resulting row, but the WRITE landed across tenant boundaries, polluting branch Y's KDS/Pusher channel and burning a `username` that exists already on branch Y. `AdminController::authorizeWritableBranchScope` (AdminController.php:29-40) is the right helper but never called from `KioskMachineController`.
  - Suggested fix: enforce in FormRequest `authorize()`:
    ```php
    $u = $this->user();
    if (!$u) return false;
    if ($u->hasRole('Admin') || $u->hasRole('Tenant Admin')) return true;
    return (int)$u->branch_id === (int)$this->input('branch_id');
    ```
    Mirror behaviour for `user_id`: `users.branch_id == $this->input('branch_id')`.

- **K19-P1-03 : `KioskMachineController::changeStatus` accepts unvalidated `status` payload (raw `Request`, not a FormRequest).**
  - File: `app/Http/Controllers/Admin/KioskMachineController.php:81-89` + `app/Services/KioskMachineService.php:147-153`
  - Issue: `$request->input('status')` is forwarded to `$kioskMachine->update(['status' => $request->input('status')])` without any `validate()` call. The casts (`KioskMachine.php:21-22`) coerce to int but pre-cast PHP accepts strings/arrays. While `KioskMachineRequest::rules()` constrains `status` to `numeric|max:24` for store/update, **changeStatus uses a different request class with no rules at all**. A POST `{"status": null}` flips `status` to 0 (cast int) → undefined behaviour vs `Status::ACTIVE/INACTIVE`. Worse: nothing validates that the new value is one of the allowed `Status` enum values.
  - Suggested fix: dedicated `KioskMachineStatusRequest` with `'status' => ['required', Rule::in([Status::ACTIVE, Status::INACTIVE, ...])]`, or inline `$request->validate([...])`.

- **K19-P1-04 : `KioskMachineService::list` filters use `like '%...%'` on `status` and `branch_id` — full unindexed table scan + cross-branch query.**
  - File: `app/Services/KioskMachineService.php:40-48`
  - Issue: `$query->where($key, 'like', '%'.$request.'%')` for `branch_id` (integer FK) and `status` (small int) produces `WHERE branch_id LIKE '%1%'` which (a) matches every branch_id containing the digit 1, (b) cannot use the index, (c) leaks one tenant's machines to another (when paired with P1-01 above) because the `BranchScope` is still in effect but `LIKE %branch_id%` widens within an Admin (branch_id=0) context. Plus SQL injection surface via the `$request` keys (mitigated by Eloquent param binding but the type mismatch is real).
  - Suggested fix: split filters into exact-match (`status`, `branch_id`, `user_id`, `is_login`) vs LIKE (`username`, `machine_id`). Apply integer cast on numeric columns.

- **K19-P1-05 : Coupon fallback in `KioskPromoService::validate` is NOT branch-scoped.**
  - File: `app/Services/Kiosk/KioskPromoService.php:60-87`
  - Issue: After `kiosk_promos` miss, falls through to `Coupon::query()->where('code', $code)->first()`. The `coupons` table has no `branch_id` column on this branch (verified — no `whereBranch` clause) and `Coupon` model carries no `BranchScope`. In a franchise deployment, a coupon issued for branch B can be redeemed at branch A's kiosk. Documented at file-level as intentional "Fallback `coupons` globaux (table existante, inchangée)" (KioskPromoService.php:13-19) — but task brief explicitly asks about branch scope on KioskPromoService.
  - Suggested fix: if coupons are intentionally cross-tenant (single-tenant Cayenne V1 case), explicitly document this in `BUSINESS_RULES.md`. For franchise V2, add `Coupon::where('branch_id', $branchId)->orWhereNull('branch_id')` or split into `branch_coupons` vs `tenant_coupons`.

### P2 (medium — backlog)

- **K19-P2-01 : `kiosk_admin_pin` Settings value transit unhashed.**
  - File: `app/Http/Requests/KioskSetupRequest.php:22` + `app/Services/KioskSetupService.php:32` + `app/Http/Resources/KioskSetupResource.php:25` + Settings store (Smartisan settings table).
  - Issue: PIN regex `/^\d{4}$/` is sent plaintext to `Settings::group('kiosk_setup')->set(...)` (KioskSetupService.php:32) which writes it cleartext to the `settings` table column. Resource correctly returns only `kiosk_admin_pin_set` boolean (no leakage in JSON response — verified KioskSetupResource.php:25), but DB-level exposure remains: a DBA, a backup leak, or `Settings::group(...)->get('kiosk_admin_pin')` from any other service reveals it. A 4-digit numeric PIN has only 10⁴ combinations — should be `bcrypt`/`Hash::make` stored + `Hash::check` for verification.
  - Suggested fix: hash PIN before `Settings::set` ; provide `KioskSetupService::verifyAdminPin($input): bool` for any verifier ; clear migration to re-hash existing rows.

- **K19-P2-02 : `KioskMachineService::destroy` deletes machine while it may still have an active Sanctum token + active pending orders.**
  - File: `app/Services/KioskMachineService.php:108-129`
  - Issue: `$kioskMachine->delete()` is unconditional. Active tokens linked to the underlying `user_id` (kiosk-user) remain valid for up-to-8h, but `BranchScope` queries on now-orphaned `KioskMachine` return empty → kiosk shows infinite spinner / 503 on next menu fetch. Worse: any in-flight `FrontendOrder::pending` from this kiosk loses its `device_token`/`source_surface`='kiosk' linkage for push notification. No `kioskMachine->tokens()->delete()` revocation either.
  - Suggested fix: `DB::transaction` should also: (a) revoke `PersonalAccessToken::where('tokenable_id', $kioskMachine->user_id)->delete()`, (b) refuse delete if `FrontendOrder::where('source_surface','kiosk')->whereIn('status',[PENDING,ACCEPT,PREPARING])->exists()` for this machine.

- **K19-P2-03 : `kiosk_promos` schema has no `priority` column ; spec-vs-impl mismatch.**
  - File: `database/migrations/2026_04_18_120005_create_kiosk_promos_table.php:25-49` + `app/Models/KioskPromo.php:41-45`
  - Issue: K19 brief asks "priority?" — implementation has no `priority` column. `KioskPromo::findValid` returns `->first()` ordered by primary key default (`KioskPromo.php:78-82`). If two codes have identical strings for the same branch (prevented by `unique(branch_id, code)`) or — more realistically — when admins create multiple overlapping `valid_from`/`valid_to` windows, there's no precedence. Probably non-issue for Cayenne V1 (single-branch, single-promo). Flag for franchise V2.

- **K19-P2-04 : `KioskPromoService::validate` does not increment `uses_count` at validate time (intentional, TOCTOU on order create).**
  - File: `app/Services/Kiosk/KioskPromoService.php:36-87` + `KioskPromo.php:87` (`uses_count >= max_uses`)
  - Issue: Validate is read-only ; consumption happens at `POST /order`. Between validate and order creation, a parallel kiosk could push uses_count over `max_uses` so the order create rejects the code even though the customer was told it was valid. UX defect, not a security defect (SSOT recompute at create-time is correct). Plan an explicit UX message: "Code épuisé entre temps."
  - Suggested fix: backend, return remaining-uses count + at order create, downgrade to 200 with `promo_consumed: false` flag and a localized message banner.

- **K19-P2-05 : `KioskMachineRequest::rules()` `status` rule `max:24` is meaningless.**
  - File: `app/Http/Requests/KioskMachineRequest.php:33`
  - Issue: `'status' => ['required', 'numeric', 'max:24']`. `Status::ACTIVE` and `Status::INACTIVE` are typically 0/1 — `max:24` allows any number ≤ 24 including invalid ones. Suggest `Rule::in([Status::ACTIVE, Status::INACTIVE])`.

### P3 (low — nice-to-have)

- **K19-P3-01 : `KioskMachineService::destroy` push notification message hardcoded English "Logged Out Successfully."**
  - File: `app/Services/KioskMachineService.php:114-115`, lines 181-182
  - Issue: No `trans()` call ; FR-lock V1 means all customer/operator-facing strings should resolve from i18n.

- **K19-P3-02 : `KioskMachineService::list` paginate filter accepts arbitrary keys but silently drops unknown ones — no 4xx feedback for malformed admin search payload.**

- **K19-P3-03 : `CleanupStalePendingKioskOrders` does NOT release `released_qty` on `PendingPaymentConfirmation` rows linked to the order.**
  - File: `app/Jobs/CleanupStalePendingKioskOrders.php:65-82`
  - Issue: Comment line 80 mentions `OrderCanceled` event handles `released_qty` for stock counters via downstream listener — verified via grep — but not for pending payment confirmation rows. If `PendingPaymentConfirmation` has a `status=pending` row, it remains. Low impact for V1 (kiosk paid-card is `confirmed/canceled` only) but should self-clean.

- **K19-P3-04 : `CleanupStalePendingKioskOrders::handle` 15-minute staleness threshold is hardcoded.**
  - File: `app/Jobs/CleanupStalePendingKioskOrders.php:21`
  - Issue: `now()->subMinutes(15)` magic number. Move to `config('kiosk.stale_pending_threshold_minutes', 15)` for ops tuning without redeploy.

- **K19-P3-05 : `KioskPromo::computeDiscount` does not enforce `value > 0` ; an admin-set `value=0` percent promo silently does nothing.**
  - File: `app/Models/KioskPromo.php:97-109`

## Cleanup vs NF525 — verdict SAFE

- `CleanupStalePendingKioskOrders.php:30-39` filters EXACTLY: `deleted_at IS NULL` + `status=PENDING` + `payment_status=UNPAID` + `source_surface='kiosk'` + `order_type IN (KIOSK, TAKEAWAY)` + (created>15min OR datetime>15min). PENDING+UNPAID kiosk orders are PRE-fiscal allocation in the kiosk paid-card flow (fiscal alloc happens at payment confirmation, not at PENDING creation per `iter14 SPECIALIST-3 FISCAL-ORPHAN-RETRY` comment in `Kernel.php:77`). The transition PENDING→REJECTED is allowed by `OrderStateMachine::allows` (`OrderStateMachine.php:38`). The job uses `withoutGlobalScope(BranchScope::class)` correctly (CLI without Auth context), keeps SoftDeletingScope intact per `[W9-AUDIT FIX-5]` (lines 24-29). `lockForUpdate` + `OrderStateMachine::apply` is the right idempotent pattern. **No NF525 closed/archived order is touched** by this filter. Safe.

## Existing E2E coverage

- `tests/Feature/Orders/CleanupStalePendingOrdersTest.php` (92 LOC) — covers cleanup job basic transition, freshness window, idempotent re-run.
- `tests/Feature/KioskPhase1/KioskPromoModelTest.php` (110 LOC) — covers `KioskPromo::findValid` edge cases (expired, future, exhausted max_uses).
- `tests/Feature/KioskPhase1/KioskEndpointsTest.php` (437 LOC) — includes `/promo/validate` endpoint shape + auth (`tokenCan('kiosk:order')`).
- **Gap** : No test for `KioskMachineController` admin authorization (P0/P1 vectors).
- **Gap** : No test for `KioskSetupResource` PIN masking on JSON response.
- **Gap** : No test for `SimulateKioskOrders` command (zero coverage).
- **Gap** : No test for `KioskPromoService` coupon fallback cross-tenant behaviour.

## Proposed new E2E tests

- **T-K19-01 : Production guard on simulate-orders command.**
  - Steps: `App::detectEnvironment(fn() => 'production')` then `Artisan::call('kiosk:simulate-orders', ['count' => 1])`.
  - Assertions: exit code !== 0 ; `Order::count()` unchanged ; output contains "forbidden in production".

- **T-K19-02 : Cross-branch machine creation blocked for Branch Manager.**
  - Steps: actor with `permission:settings`, `branch_id=1`. `POST /api/admin/kiosk-machine` with payload `branch_id=2`.
  - Assertions: HTTP 403 ; no row in `kiosk_machines` ; observability log emitted.

- **T-K19-03 : KioskMachineController index gated by permission:settings.**
  - Steps: actor with NO `settings` permission, valid sanctum token. `GET /api/admin/kiosk-machine`.
  - Assertions: HTTP 403 ; response body does not contain any `username` or `machine_id`.

- **T-K19-04 : KioskSetupResource never returns plaintext `kiosk_admin_pin`.**
  - Steps: admin actor stores `kiosk_admin_pin=1234`. `GET /api/admin/setting/kiosk-setup`.
  - Assertions: response JSON has `kiosk_admin_pin_set=true`, no key `kiosk_admin_pin`, no value `"1234"` anywhere in body.

- **T-K19-05 : Cleanup job preserves orders with allocated `fiscal_sequence_no`.**
  - Steps: seed PENDING UNPAID kiosk order WITH `fiscal_sequence_no=42`. Run cleanup.
  - Assertions: order stays at PENDING (defensive — current filter excludes by status/payment, but assertion guards regression where filter mistakenly includes fiscal-allocated rows).

- **T-K19-06 : KioskPromoService coupon fallback branch isolation (documented as cross-tenant — TEST documents intent).**
  - Steps: branch A user, validate code `"GLOBAL50"` (only in `coupons`, not `kiosk_promos`).
  - Assertions: returns valid=true, `source='coupon'`. Documents the intentional cross-tenant fallback for franchise V2 planning.

## Risks & open questions

- **OWNER GATE Q1** : Coupon fallback in `KioskPromoService` is cross-tenant by design (K19-P1-05). For Cayenne V1 (single tenant) this is OK. For franchise V2 should we make `coupons` table branch-scoped or split into per-branch and global tables? Decision impacts loyalty roadmap.
- **OWNER GATE Q2** : `kiosk_admin_pin` storage — hash or keep cleartext? (K19-P2-01). Hashing breaks any "show PIN to authorized admin" UX flow if such exists in admin UI.
- **OWNER GATE Q3** : `SimulateKioskOrders` — should the command be removed entirely from prod-shipped artisan list (via `Kernel::commands()` env gate) or only fail-fast on env=production? First is harder to forget, second is more discoverable. Recommended: belt-and-suspenders both.
- **OWNER GATE Q4** : `KioskMachineService::destroy` revoke tokens behaviour (K19-P2-02) — should be combined with `KioskMachineLoginController` token-on-rotate logic to avoid double-revocation in normal logout flow.

## Verdict

**NO-GO V1 merge** until K19-P0-01 (simulator production guard) lands. P1 batch (5 findings) should ship in V1.0.1 sprint, especially K19-P1-01 (index endpoint gating) and K19-P1-02 (cross-branch machine writes) which combine into a real cross-tenant write+enumerate vector.

**Counts** : P0=1, P1=5, P2=5, P3=5.
