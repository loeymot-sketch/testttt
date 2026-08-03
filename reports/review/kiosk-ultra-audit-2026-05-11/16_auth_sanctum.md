# K16 — Auth kiosk machine + Sanctum

> Branch `feature/mobile-app-le-cayenne-2026-05-10` — actual HEAD at audit run
> = `6a33a9763` (prompt cited `245e8ab57`, drift recorded). Mode read-only,
> citations file:line vérifiées sur le HEAD ci-dessus.

## Files audited
- `app/Http/Controllers/Auth/KioskMachineLoginController.php` (132 lines)
- `app/Models/KioskMachine.php` (50 lines)
- `app/Services/KioskMachineService.php` (198 lines)
- `app/Http/Middleware/ValidateKioskLocale.php` (112 lines)
- `app/Http/Controllers/Auth/RefreshTokenController.php` (62 lines)
- `routes/api.php` (extracts 140–270, 800–880, 1080–1265) — kiosk-login,
  kiosk-logout, refresh-token, frontend/order, payment-confirm, kiosk-event
- `routes/channels.php` (40 lines) — `branch.{branchId}` ability gate
- Cross-files verified : `app/Services/OrderService.php:1351,1361,2194-2205`
  (`assertOrderBranchVisible` defense-in-depth), `app/Http/Requests/OrderRequest.php:35-66`
  (P0-08 FormRequest ability gate), `app/Providers/RouteServiceProvider.php:105-128`
  (kiosk-login limiter), `app/Models/Scopes/BranchScope.php:1-43`,
  `config/sanctum.php:51`, `config/kiosk.php:50,93`, sentinel
  `tests/Feature/Auth/RefreshTokenAbilityPreserveTest.php`, sentinel
  `tests/Feature/Sentinels/OrderShowBranchGuardSentinelTest.php`.

## Mandatory P0 re-verify table

| Finding | Status @HEAD 6a33a9763 | Evidence (file:line + commit) |
|---|---|---|
| **P0-06** PosOrderController::show:108 cross-branch via `withoutGlobalScope` | **MITIGATED (defense-in-depth)** — withoutGlobalScope still present on line 108, but cross-branch read aborts 403 inside service layer | `app/Http/Controllers/Admin/PosOrderController.php:108` still has `Order::withoutGlobalScope(BranchScope::class)->findOrFail($order);` → delegates to `OrderService::show($order, false)` (line 109) which calls `$this->assertOrderBranchVisible($order)` (OrderService.php:1361) → `abort(403, 'Access denied: order does not belong to your branch.')` for non-Admin (OrderService.php:2202-2204). Sentinel `OrderShowBranchGuardSentinelTest::test_branch_staff_cannot_show_foreign_branch_order` asserts 403. Fix commit: `1476a111a fix(pos/phase-9-h.1.1+1.4): propagate HttpException(403) across 4 OrderService methods`. **Residual P1 risk** — `withoutGlobalScope` should be removed because the defense relies on a non-obvious service-level guard. |
| **P0-07** RefreshTokenController:23-27 issues `['*']` regardless of source abilities | **FIXED** | `app/Http/Controllers/Auth/RefreshTokenController.php:42` `$abilities = $token->abilities ?? [];` then `if (! is_array($abilities)) { $abilities = []; }` (lines 43-45) — wildcard removed, original abilities preserved. New token created with `$abilities` array (line 49-53). 401 explicit on null/invalid token (lines 25-26, 30-32). Regression suite `tests/Feature/Auth/RefreshTokenAbilityPreserveTest.php` (4 tests : kiosk preserves `kiosk:order`, admin preserves `*`, empty refresh to empty (no wildcard), invalid → 401). Fix commit: `01da1d99b heal(P0-auth): iter15 RefreshToken abilities preserve (CRITICAL) + abilities:kiosk:order gate`. |
| **P0-08** Missing route-level `abilities:kiosk:order` on `/frontend/order` POST + `/payment-confirm` | **FIXED (FormRequest pattern, route middleware INTENTIONALLY rejected)** | `routes/api.php:1102-1112` block comment documents the design : Sanctum `CheckAbilities` middleware would 401 legitimate session/guard callers (TransientToken). Enforcement moved into `app/Http/Requests/OrderRequest.php:35-66` `authorize()` — `$user->tokenCan('kiosk:order')` required for any caller holding a `PersonalAccessToken`; TransientToken/guard auth tolerated for tests. Coverage : `tests/Feature/Frontend/OrderRouteAbilityTest.php` + `F008PaymentReconcileAbilitySentinelTest.php`. Fix commit: `01da1d99b`. **Caveat** : `/kiosk-event` (api.php:1217) and the legacy alias (api.php:1262) DO have `abilities:kiosk:order` route-level — design split is intentional but worth documenting for new reviewers. |
| **P0-15** Frozen-zone breach KioskWizard / KioskApp / pos-wizard.js | **CONFIRMED OPEN — MASSIVE DRIFT** | `git diff --shortstat main..HEAD` measured at run:<br>• `resources/js/components/frontend/kiosk/KioskWizardComponent.vue` → **1 file changed, 1663 insertions(+), 228 deletions(-)** (prompt said +1665 — within rounding).<br>• `resources/js/components/frontend/kiosk/KioskAppComponent.vue` → **1 file changed, 834 insertions(+), 175 deletions(-)** (prompt said +892 — close).<br>• `public/js/pos-wizard.js` → **1 file changed, 216 insertions(+), 21 deletions(-)** (prompt said +237 — within rounding).<br>• `public/js/pos-app.js` → **1 file changed, 137626 insertions(+)** — new bundle artifact, also a violation if pos-app.js was previously absent ; check whether main lacked the file.<br>• `KioskUpsellComponent.vue` → 1 file changed, 31 insertions(+), 26 deletions(-) — small.<br>**No `LOCK_*.md` doc found** in `plans/` matching the wizard frozen-zone exceptions for this branch. **Owner gate required pre-merge.** |

## Findings

### P0 (block pre-merge V1)
- **K16-P0-01: Frozen-zone drift KioskWizardComponent.vue +1663/-228, KioskAppComponent.vue +834/-175, pos-wizard.js +216/-21**
  - File: `resources/js/components/frontend/kiosk/KioskWizardComponent.vue`,
    `KioskAppComponent.vue`, `public/js/pos-wizard.js`
  - Issue: cf. P0-15 evidence above. CLAUDE.md §7 declares these FROZEN —
    modification interdite sans LOCK explicit owner. No matching LOCK doc found
    in `plans/`. The wizard frozen-zone is the POS popup wizard "design parfait
    selon owner" (memory `feedback_wizard_popup_pos_protected`).
  - Evidence: shortstat numbers above ; first 50 lines of diff add aria/role
    attributes + KsAllergenBadge prop changes + a11y "kiosk-wizard-sr-only" class.
  - Suggested fix: stop-the-line ; either revert the frozen-zone changes or
    produce `LOCK_KIOSK_WIZARD_2026-05-10.md` + `LOCK_POS_WIZARD_2026-05-10.md`
    + owner sign-off + a11y regression suite.

### P1 (V1.0.1 sprint)
- **K16-P1-01: PosOrderController::show:108 `withoutGlobalScope` defense-in-depth weakness**
  - File: `app/Http/Controllers/Admin/PosOrderController.php:108`
  - Issue: cross-branch isolation relies on a non-obvious service-level abort
    inside `OrderService::show → assertOrderBranchVisible`. A future refactor
    that drops the service call or adds a new caller path that bypasses
    `assertOrderBranchVisible` reopens the cross-branch leak. The `withoutGlobalScope`
    is intentional only for global-admin (branch_id=0) routes — the gate already
    handles that via `isGlobalAdmin`.
  - Evidence: line 108 ; sentinel passes today but no CI invariant grep guards
    against `withoutGlobalScope` regression on `app/Http/Controllers/Admin/Pos*`.
  - Suggested fix: drop `withoutGlobalScope`, let `BranchScope::apply` handle
    admin bypass (`$userBranch === 0` early return at `BranchScope.php:36`), and
    if a global admin needs cross-branch read on a non-admin login path, add an
    explicit `if ($this->isGlobalAdmin(Auth::user())) { ... }` branch — not a
    silent scope bypass.

- **K16-P1-02: ValidateKioskLocale fail-open on missing KioskMachine row**
  - File: `app/Http/Middleware/ValidateKioskLocale.php:68-70`
  - Issue: `if (!$machine || !$machine->branch) { return $next($request); }` —
    after auth, if the authenticated kiosk user somehow has NO `KioskMachine`
    record (deleted between login and request, or admin user reusing
    kiosk-locale-aware routes), the middleware silently passes the request
    instead of failing closed with 400. Observability log on rejection exists ;
    no log on this silent passthrough → drift is invisible.
  - Evidence: lines 58-70. The doc-comment lines 18-22 explicitly state this is
    intended ("ne doit jamais masquer un 401"), but admin-without-KioskMachine
    using `kiosk.locale` middleware is undocumented and would bypass allowlist.
  - Suggested fix: add `Log::channel('observability')->info('kiosk_locale.no_machine_row', …)`
    on the silent pass + assert via Feature test that an authenticated non-kiosk
    user who hits a `kiosk.locale`-gated route is denied at a different layer.

- **K16-P1-03: KioskMachineService::list filters use `LIKE '%…%'` on integers**
  - File: `app/Services/KioskMachineService.php:42-44`
  - Issue: `branch_id`, `user_id`, `status` (integers) routed through
    `LIKE '%val%'` → false positives (branch_id=1 matches 1, 10, 11, 21…),
    weak filter semantics + potential perf hit on large tenants. Search-by
    `username` LIKE is OK but should use prefix anchor at minimum to avoid
    full-table scan.
  - Evidence: `$query->where($key, 'like', '%' . $request . '%');` (line 43)
    applied uniformly across 5 keys in `$kioskMachineFilter`.
  - Suggested fix: per-field strategy : `=` for ints, prefix-LIKE for strings,
    or upgrade to a proper Spatie QueryBuilder filter array.

- **K16-P1-04: Branch admin (branch_id=0) can sub to ANY `branch.{branchId}` channel**
  - File: `routes/channels.php:32-34`
  - Issue: design choice but worth a P1 flag — admin tokens (`['*']` abilities,
    `branch_id=0`) return `true` for any `$branchId` (line 34). If an admin
    token is stolen, attacker can subscribe to every branch's order stream
    cross-tenant. This is consistent with BranchScope admin bypass design but
    is a privilege concentration risk.
  - Evidence: lines 33-34. The kiosk path above is correctly tied to
    `KioskMachine::branch_id` (lines 28-29).
  - Suggested fix: scope admin subscription to active session branch (`Auth::user()->current_branch_id`
    if such a session attribute exists, otherwise restrict to `KioskMachine`-derived
    branch list per admin profile).

### P2 (medium)
- **K16-P2-01: PIN brute-force surface — `password` is a 6+ char string, not a 4-digit PIN**
  - File: `app/Http/Controllers/Auth/KioskMachineLoginController.php:32`,
    `app/Services/KioskMachineService.php:66,93`
  - Issue: validator requires `min:6` on the password (line 32) — fine for a
    machine credential ; combined with the 30/min rate limiter (RouteServiceProvider.php:120)
    and bcrypt hashing (Service.php:66,93), brute force is effectively infeasible.
    BUT : the kiosk-login limiter keys on `kiosk:<username>|<ip>` (RouteServiceProvider.php:119).
    A distributed attacker rotating IPs against the same `username` can exceed
    the 30/min cap because the key partition changes per IP. Bcrypt cost is
    the residual defense — adequate for V1 but worth documenting.
  - Evidence: lines cited above.
  - Suggested fix (later) : add a second limiter keyed only on `username`
    (e.g., 200/hour) to absorb distributed IP rotation.

- **K16-P2-02: `KioskMachine::branch_id` has NO database `default` value**
  - File: `database/migrations/2025_02_21_110459_create_kiosk_machines_table.php:20`
  - Issue: `$table->foreignId('branch_id')->constrained('branches');` — no
    default, so a row inserted without explicit `branch_id` (e.g., a buggy
    seed/factory or partial UPDATE) fails the NOT NULL constraint. This is
    correct behaviour, but if any code path constructs a `KioskMachine` from
    raw mass-assign it could throw at insert time, surfacing 500 instead of a
    domain-validated 422. Lower priority because `$fillable` (Model line 13)
    includes `branch_id`.
  - Evidence: migration line 20.
  - Suggested fix: keep NOT NULL ; add a FormRequest-level rule `required|integer|exists:branches,id`
    on KioskMachine create flows (already present in `KioskMachineRequest`,
    confirm before merge).

- **K16-P2-03: `logout` deletes ALL kiosk-token rows for the user, even if no current token**
  - File: `app/Http/Controllers/Auth/KioskMachineLoginController.php:96`
  - Issue: `$user->tokens()->where('name', 'kiosk-token')->delete();` runs in
    the login flow as a "clean re-login" — correct intent, BUT in logout
    (lines 117-126) only `currentAccessToken()` is revoked (line 124). If a
    kiosk user has multiple sessions across machines (rare but possible), the
    other sessions remain valid post-logout. Inconsistent revocation semantics.
  - Evidence: lines 96 vs 122-124.
  - Suggested fix: align logout to also delete all `kiosk-token` rows for the
    user, OR document the asymmetry in a doc-block.

### P3 (low / hygiene)
- **K16-P3-01: KioskMachineService::store/update do not rate-limit password Hash::make**
  - File: `app/Services/KioskMachineService.php:66,93`
  - Issue: admin endpoints calling `bcrypt`/`Hash::make` are CPU-bound ; if an
    admin batch-creates 1000 kiosks via `POST /api/admin/kiosk-machine`, the
    sync workload is heavy. Out of V1 scope.
  - Suggested fix: queue-job for batch creation if usage grows.

- **K16-P3-02: Logout uses `currentAccessToken()` cast to `PersonalAccessToken`**
  - File: `app/Http/Controllers/Auth/KioskMachineLoginController.php:122-124`
  - Issue: `instanceof PersonalAccessToken` correctly skips TransientToken, but
    if Sanctum changes the cast in a future minor, the silent skip masks bugs.
  - Suggested fix: add a debug log on the "skipped" branch.

## Existing E2E / Feature coverage
- `tests/Feature/Auth/RefreshTokenAbilityPreserveTest.php` — 4 cases :
  preserves `kiosk:order`, preserves `*`, empty stays empty, invalid → 401.
- `tests/Feature/Frontend/OrderRouteAbilityTest.php` — `tokenCan('kiosk:order')`
  enforcement on `/api/frontend/order` POST.
- `tests/Feature/KioskSecurity/KioskEventAbilityTest.php` — `abilities:kiosk:order`
  middleware enforcement on `/kiosk-event`.
- `tests/Feature/Sentinels/F008PaymentReconcileAbilitySentinelTest.php` —
  documents the decision NOT to use route-level middleware on payment-confirm.
- `tests/Feature/Sentinels/OrderShowBranchGuardSentinelTest.php` — 403 on
  cross-branch `/api/admin/pos-order/show/{id}` (defense-in-depth for K16-P1-01).
- `tests/Feature/KioskPhase7/KioskEventBranchIsolationTest.php` — kiosk-event
  branch isolation.

## Proposed new E2E / Feature tests
- **T-K16-01: Kiosk-login throttle attainable cap test**
  - Steps: 30 valid POSTs to `/api/auth/kiosk-login` with same username/IP
    within 60s ; 31st → expect 429 with `retry_after=60`.
  - Assertions: status 429, JSON `message` contains "Too many kiosk login",
    `retry_after === 60`. Repeat with 30 from IP A, 30 from IP B — expect both
    to pass (validates the per-IP partition cited in K16-P2-01).
- **T-K16-02: Sanctum TTL drift sentinel**
  - Steps: POST kiosk-login, capture token row, assert `expires_at` is within
    `now() + 480 minutes ± 60s`. Mock `now()` to `+481 min`, attempt
    `/api/frontend/order` POST → expect 401 (or 419 if Sanctum cleanup ran).
  - Assertions: 401 ; matching log line in observability channel.
- **T-K16-03: Channel admin-bypass cross-tenant subscription regression**
  - Steps: admin user (branch_id=0), `Broadcast::auth('branch.99')` (99 = non-existent).
    Expect 403 / false return. Then `branch.<real>` — expect true. Today,
    admin can subscribe to ANY (including 99) ; this test pins the K16-P1-04
    discussion for future tightening.
- **T-K16-04: Refresh-token rotation invalidates old token**
  - Steps: POST `/api/refresh-token` with kiosk token T1 → receive T2.
    GET `/api/frontend/order` with T1 → 401. With T2 → 200.
  - Assertions: T1 row removed from `personal_access_tokens` ; T2 row has
    `abilities=['kiosk:order']`.
- **T-K16-05: ValidateKioskLocale silent-pass observability**
  - Steps: admin user (no KioskMachine record) hits a route protected by
    `kiosk.locale` middleware with `X-Kiosk-Locale: zz-ZZ` → expect either
    400 LOCALE_NOT_ALLOWED or, if passthrough, an observability log line
    `kiosk_locale.no_machine_row` (currently missing).
- **T-K16-06: Cross-branch PosOrderController::show defense-in-depth (already exists, propose lock)**
  - Add a CI grep invariant test that fails the build if `withoutGlobalScope(BranchScope::class)`
    appears in `app/Http/Controllers/Admin/PosOrderController.php` without an
    accompanying `assertOrderBranchVisible` call within 10 lines.

## Risks & open questions [OWNER GATE REQUIRED]
1. **Frozen-zone drift (K16-P0-01)** — does owner authorize the wizard changes ?
   If yes, request `LOCK_*` docs in `plans/` per CLAUDE.md §7. If no, revert.
2. **P0-06 residual `withoutGlobalScope` (K16-P1-01)** — keep defense-in-depth
   as-is, or remove the scope bypass and trust `BranchScope::apply`'s
   admin-bypass branch ? Recommend the second.
3. **Admin broadcast bypass (K16-P1-04)** — V1 acceptable risk or pre-merge fix ?
4. **Logout asymmetry (K16-P2-03)** — align with login-side full revoke ?

## Verdict (K16 only)
**NO-GO V1 merge** — K16-P0-01 frozen-zone drift blocks regardless of code
correctness (CLAUDE.md §7 + memory). Auth-Sanctum layer itself is otherwise
HEALTHY : P0-06/07/08 all closed by commits `1476a111a` + `01da1d99b` with
sentinel coverage. Recommended Owner gate before merge :
1. Resolve K16-P0-01 (revert or LOCK + sign-off).
2. Address K16-P1-01 in next sprint (drop `withoutGlobalScope`, keep sentinel).
3. Defer P1-02..P1-04 + P2/P3 to V1.0.1.
