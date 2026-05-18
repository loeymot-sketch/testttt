# T-1.3.1 SECURITY findings — BranchScope coverage / cross-branch leak attack surface
**Agent**: SECURITY (read-only)
**Round**: 2
**Date**: 2026-05-18
**Threat model**: attacker holds **staff Sanctum credentials on Branch B** (any role: POS Operator, Chef, Branch Manager, Delivery Boy, Waiter). Goal = read/write Branch A data via injection, scope bypass, request-controlled override, or model-without-BranchScope side channel. Hostile framing — every assumption questioned.

---

## Cross-reference to existing PASS tests (do not duplicate)

| Attack vector                                        | Defended by PASS test |
|---|---|
| `Order` list cross-branch + prefix-match leak        | `tests/Feature/Branch/OrderBranchIsolationTest.php` (exact match assertion) |
| Cross-branch `OssAdmin` policy filter                | `OssAdminBranchPolicyTest.php` |
| Sanctum token revoke on branch destroy / deactivate  | `BranchDestroyRevokesTokensTest.php` + `BranchDeactivationTokenRevokeTest.php` |
| Fiscal identity per branch                           | `BranchFiscalIdentityTest.php` |
| Queue-context BranchScope sentinel (job auth)        | `tests/Feature/Sentinels/F010BranchScopeQueueContextSentinelTest.php` |
| WizardProfileBranchScope (composer profiles)         | `tests/Feature/Multitenant/WizardProfileBranchScopeTest.php` |
| Cleanup-vs-confirm race                               | `tests/Feature/Sentinels/CleanupVsConfirmRaceSentinelTest.php` |
| Cross-branch `Order` show via PosOrderController     | `OrderShowBranchGuardSentinelTest:44` (403 baseline) |

Verified ≥18 models registered via `addGlobalScope(new BranchScope())` at `boot()` — Order, OrderPayment, FrontendOrder, KioskMachine, CashDrawerSession, PaymentTerminal, PendingPaymentConfirmation, OrderQuote, User (guarded via `instanceof User` short-circuit inside the scope), OrderItem, StockLevel, CashMovement, PosParkedOrder, PushNotification, Printer, DiningTable, StockMovement, WebhookEvent. `ItemWizardProfile` uses the dedicated `WizardProfileBranchScope`. Catalog tables (Item / Category / ItemAttribute / ItemExtra / Branch) intentionally global per CLAUDE.md §9.

---

## Finding S-1 — Cross-branch staff-User creation via Branch Manager + `DefaultAccessModelTrait::setBranch` dead path (P0)

```yaml
finding_id: S-1
severity: P0
category: cross_branch_persistent_foothold_via_user_creation
file_evidence:
  - app/Traits/DefaultAccessModelTrait.php:32-50  (setBranch — the dead $branchId reassignment branch)
  - app/Observers/UserObserver.php:13-20         (creating + updating call setBranch unconditionally)
  - app/Services/EmployeeService.php:69-98        ('branch_id' => $request->branch_id, line 80)
  - app/Services/WaiterService.php:60-79          ('branch_id' => $request->branch_id, line 69)
  - app/Services/ChefService.php:60-79            ('branch_id' => $request->branch_id, line 68)
  - app/Services/DeliveryBoyService.php:60-79     ('branch_id' => $request->branch_id, line 72)
  - app/Http/Requests/EmployeeRequest.php:16-26   (authorize: return true; branch_id: nullable, numeric)
  - database/seeders/RolePermissionTableSeeder.php:55-62 ('employees_create', 'waiters_create', 'chefs_create', 'delivery-boys_create' all granted to Branch Manager)
attacker_capability_required: any **Branch Manager** credential on Branch B (real role, normal permissions)
trigger:
  load_mode: irrelevant — single-request exploit
  failure_mode: |
    Attack chain (verified by reading the four services + the trait):

      1. Branch B Manager opens /admin/employees/create (or /chefs, /waiters, /delivery-boys).
      2. Controller middleware `permission:employees_create` PASSES — Branch Manager has it.
      3. `EmployeeRequest::authorize()` returns `true` unconditionally (line 18).
      4. `branch_id: ['nullable', 'numeric']` accepts ANY positive integer (line 56).
      5. `EmployeeService::store` calls `User::create([..., 'branch_id' => $request->branch_id, ...])` (line 80).
      6. `UserObserver::creating` fires (User boots `addGlobalScope(BranchScope)` but BranchScope short-circuits on `instanceof User`, so the scope does NOT bite here — User-creation is NOT filtered).
      7. UserObserver calls `setBranch($branchId)` from DefaultAccessModelTrait.
      8. In `setBranch`, **none** of the three explicit conditions match:
           - `$branchId != '0' && ($branchId == '' || $branchId == null)` → FALSE (branch_id is a positive int like "1")
           - `$branchId == '0' && $branchId == Auth::user()->branch_id` → FALSE (attacker's branch_id is B≠0)
           - `else { $this->branch(); }` → executes but **discards the return value** (line 39-40)
      9. `setBranch` returns the **user-supplied** `$branchId` unchanged.
      10. `User` row persists with `branch_id=A` (attacker's chosen Branch A), `role=POS Operator` (or Waiter / Chef / Delivery Boy — Branch Manager's `blockRoles` exclusion bars only Admin/Customer/Waiter/Chef/DeliveryBoy from `EmployeeService`, but Waiter/Chef/DeliveryBoy services each have their own create endpoints with the same flaw — see file_evidence).
      11. Attacker logs in as the new user → lands on Branch A's POS/KDS/dashboard surface; `BranchScope` now legitimately scopes them to A.

    Net effect: **one-click persistent cross-branch foothold per branch the Manager touches**. The attacker can repeat the exploit to mint N users across all branches.

    Worse: each created user shows up in `EmployeeService::list` for Branch A's own Branch Manager as "their" employee — the legitimate owner has no signal except that the user list grew by one ghost employee with an unfamiliar name. No `audit_logs` row is written by `User::create` (audit_logs is fiscal-chain only; user-management writes only go to `action_logs` which is best-effort).

    The same flaw applies to `ChefService::store`, `WaiterService::store`, `DeliveryBoyService::store`, `EmployeeService::update` (line 115), and `AdministratorService::store/update` (line 67/93). For Administrator, the role gate (`permission:administrators_create`) restricts to global Admin, so cross-branch is moot — but Chef/Waiter/DeliveryBoy/Employee are all reachable by Branch Manager.

    Edge case: `Auth::user()->id != $administrator->id && $administrator->id != 1` (AdministratorService:115) does NOT prevent cross-branch ID 2+ admin creation — but again, only an Admin role can reach it.
v2_saas_impact: |
  V2 SaaS turns this from "one rogue Branch Manager" into "one rogue Branch
  Manager OR one stolen Branch Manager Sanctum token = persistent foothold
  in every tenant whose Branch Manager has the same credential pattern."
  In a multi-tenant deploy, every tenant must trust every other tenant's
  Branch Manager role configuration. There is no `tenant_id` separator;
  `branch_id` is the only fence. Cross-tenant pivoting becomes mechanical.
cost_of_delay: |
  **Customer-trust collapse + GDPR breach risk** — the rogue user can READ
  customers, order history, sales reports, payment_terminals, push
  notification logs, stock movements on Branch A. NF525 not directly violated
  (audit_logs remain bound to actual cashier session), but the audit trail
  becomes ambiguous: a Branch A cashier shift report names "John Smith
  (POS Operator)" but the row was created from a Branch B IP.
recommendation: |
  Fix at FOUR layers (defense in depth):

  1. **Fix the dead branch in `DefaultAccessModelTrait::setBranch`** —
     replace `$this->branch();` (line 40) with `$branchId = $this->branch();`.
     This makes the trait actually enforce the documented "non-admin staff
     cannot pivot to another branch" semantics.

  2. **Tighten FormRequest validators** — `EmployeeRequest`, `ChefRequest`,
     `WaiterRequest`, `DeliveryBoyRequest`, `AdministratorRequest` should
     all replace `'branch_id' => ['nullable', 'numeric']` with:
       'branch_id' => ['nullable', 'numeric', Rule::in(Auth::user()->branch_id === 0 ? Branch::pluck('id')->all() : [Auth::user()->branch_id])]
     And replace `authorize(): return true;` with the same Branch Manager
     check pattern used in `OrderRequest::authorize`.

  3. **Add an Eloquent guard at User model level** — in `User::booted()`:
       static::saving(function (User $u): void {
           $auth = Auth::user();
           if ($auth && (int) $auth->branch_id !== 0 && (int) $u->branch_id !== (int) $auth->branch_id) {
               // unless caller has the explicit privilege override
               throw new \RuntimeException('Cross-branch User assignment forbidden.');
           }
       });

  4. **Sentinel test** — `tests/Feature/Branch/StaffCrossBranchCreationDeniedTest.php`
     covering all four services + Admin path.

  Heal effort: ~3h trait + 5 form requests + 1 model guard + 1 sentinel test.
```

---

## Finding S-2 — `IdempotencyKeyMiddleware::resolveBranchId` accepts payload `branch_id` for non-admin users with `branch_id=0` and no KioskMachine pivot (P1)

```yaml
finding_id: S-2
severity: P1
category: idempotency_scope_pivot_attacker_chosen
file_evidence:
  - app/Http/Middleware/IdempotencyKeyMiddleware.php:182-220
attacker_capability_required: any authenticated user whose `users.branch_id=0` but who does NOT hold the `Admin` Spatie role AND has no `KioskMachine` pivot row (e.g. a malformed Customer, a misconfigured staff user, a guest account with `branch_id=0` legacy seed)
trigger:
  load_mode: any
  failure_mode: |
    `resolveBranchId()` flow:
      1. `$authBranchId = (int) $user?->branch_id ?? -1`
      2. If `$authBranchId === 0` AND `hasRole('Admin')` → return payload `branch_id` (intentional, Admin global).
      3. If `$authBranchId > 0` → return that (forced).
      4. If `$authBranchId === 0` AND user has KioskMachine pivot → return pivot branch_id (kiosk path).
      5. **Fallback (line 219): `return (int) $request->input('branch_id', -1);`**

    Step 5 is reached when:
      - User exists with `branch_id=0` in DB
      - User does NOT hold `Admin` role
      - User does NOT have a KioskMachine pivot row

    For such a user, the idempotency `scopedKey` is computed using the
    **attacker-chosen** payload `branch_id`. Effect:
      - Attacker submits POST /api/.../some-route with `X-Idempotency-Key:
        K + branch_id: A` payload.
      - Middleware computes `idempotency:v1:A:userId:sha256(K)`.
      - On replay of a 2xx response, the cache key conflict scope is also
        scoped to the attacker's chosen `A`.
      - Two **different users** with the same anomalous `branch_id=0`
        state could now collide on `(branch_id, user_id, hash(key))`
        scopes if `user_id` clashes (which it shouldn't under normal
        UNIQUE constraints — but the cache key INPUT validation is weak).

    This is NOT a direct write authorization bypass: downstream controllers
    enforce branch authz independently. But:
      - The idempotency layer becomes **unenforceable** for these users
        (no protection from duplicate POST → duplicate writes if the
        downstream controller doesn't have its own UNIQUE guard).
      - The `branch_id` chosen by the attacker leaks into Redis/cache
        keys (information disclosure via cache enumeration if attacker
        also has Redis access).

    Likelihood: low — production users either have branch_id>0 (staff)
    or have a KioskMachine pivot (kiosk). But legacy seed data and
    `EnsureAdminLoginCommand` (which conditionally sets branch_id=0 with
    no role check at line 132) could produce affected rows.
v2_saas_impact: |
  V2 SaaS = more users with anomalous setups (migration imports, partial
  signups). The fallback becomes more exposed.
cost_of_delay: |
  Latent — no active breach. Disables idempotency for affected users; risk
  is duplicate write rather than confidentiality.
recommendation: |
  Change line 219 from:
    `return (int) $request->input('branch_id', -1);`
  to:
    `return -1; // reject below at the !($branchId >= 0) guard.`
  Then the existing line 70 guard (`$branchId < 0 || $userId <= 0`)
  throws `MissingIdempotencyKeyException` — fail-closed semantics for
  unresolvable branch_id. Add `tests/Feature/Idempotency/UnresolvableBranchIdRejectedTest.php`.
```

---

## Finding S-3 — `withoutGlobalScope(BranchScope::class)` callsites audited — 0 unsafe (CLEAN)

```yaml
finding_id: S-3
severity: PASS
category: scope_bypass_audit
file_evidence:
  - 35 production-code callsites (grep -rn "withoutGlobalScope" --include="*.php" excluding tests/ and worktrees)
attacker_capability_required: n/a — verification finding
trigger:
  load_mode: n/a
  failure_mode: |
    Every `withoutGlobalScope(BranchScope::class)` callsite was traced.
    Categorisation:

      A. Pre-auth lookups (legitimate, scope cannot apply because Auth::check()
         is false at the time of the lookup):
           - app/Http/Controllers/Auth/KioskMachineLoginController.php:55,90
           - app/Http/Controllers/Auth/GuestSignupController.php:98
           - app/Console/Commands/Ensure*Login*.php (admin/chef/pos-operator)
           - app/Console/Commands/EnsureKioskMachineCommand.php:80

      B. Cross-branch by design + explicit branch_id filter applied immediately
         after (verified line-by-line):
           - app/Http/Controllers/Frontend/PaymentReconcileController.php:143,194,232,247,288 — every call followed by `->where('id', $orderId)->first()` then explicit `(int)$order->branch_id !== $kioskMachine->branch_id` rejection (line 151)
           - app/Http/Controllers/Frontend/OrderController.php:159,184 — followed by lockForUpdate + line 168 branch_id rejection
           - app/Http/Controllers/Admin/PosOrderController.php:113 — followed by `auth()->user()?->branch_id === 0 || $order->branch_id === auth()->user()?->branch_id` check (line 118)
           - app/Jobs/CleanupStalePendingKioskOrders.php:30,47 — job-level cleanup, intentional cross-branch
           - app/Services/OrderService.php:2114-2116 — Spatie role scope, defensive
           - app/Services/Order/RefundWithCounterEntryService.php:163 — fiscal counter-entry, intentional
           - app/Services/Fiscal/ZReportCashEnrichmentService.php:54,77,154,181 — Z report aggregate, fiscal cross-branch by design
           - app/Services/Fiscal/ZReportService.php:337,589 — Z report
           - app/Services/Fiscal/FiscalSequenceService.php:88 — max() lookup for next seq alloc
           - app/Console/Commands/BackfillAllergensSnapshotCommand.php:64 — backfill job
           - app/Console/Commands/FiscalArchiveCommand.php:337 — fiscal archive
           - app/Console/Commands/RetryFiscalAllocCommand.php:61 — retry job
           - app/Services/Hardware/EscPosPrinterService.php:93,99 — printer lookup (printer.branch_id is matched after)
           - app/Services/Payments/SplitPaymentService.php:127 — payment terminal lookup, branch matched

      C. Test scripts only:
           - scripts/prodlike-concurrency-worker.php — load test harness, not deployed

    **Conclusion**: zero `withoutGlobalScope(BranchScope::class)` callsite was
    found that lacked a subsequent explicit `branch_id` filter or fell into
    category A (pre-auth). The convention is rigorously applied. The
    refactor risk is when a new contributor introduces a callsite without
    the subsequent filter — see recommendation.

    Negative finding still worth noting: there is no static-analysis
    sentinel that ASSERTS this convention. A future PR could regress.
v2_saas_impact: |
  Same convention applies; sentinel becomes more valuable at SaaS scale.
cost_of_delay: |
  No active breach. Recommendation = preventive.
recommendation: |
  Add `tests/Feature/Sentinels/WithoutGlobalScopeBranchSentinelTest.php` that:
    1. Greps production source for `withoutGlobalScope(BranchScope::class)`.
    2. For each match, asserts a `branch_id` token appears within 30 lines
       below OR the match is on a known-safe allowlist of pre-auth files.
    3. Fails the build on a new callsite without follow-up filter.
  Heal effort: ~3h test + allowlist doc.
```

---

## Finding S-4 — Sub-resource leak via parent (`Order->items()`) — CLEAN, double-fenced

```yaml
finding_id: S-4
severity: PASS
category: sub_resource_inheritance_audit
file_evidence:
  - app/Models/Order.php:92         (Order BranchScope)
  - app/Models/OrderItem.php:15-27  (OrderItem BranchScope explicitly re-added P0-FIX-2 NF525-V1 2026-05-09)
  - app/Models/OrderPayment.php:67  (OrderPayment BranchScope)
  - tests/Feature/Branch/OrderBranchIsolationTest.php (proves Order-level filter exact)
attacker_capability_required: n/a — verification finding
trigger:
  load_mode: n/a
  failure_mode: |
    Vector 3 from the threat model: "Order has BranchScope but accessing
    Order::find($id)->items()->where(...) — does OrderItem's BranchScope
    auto-apply, OR does it inherit Order's already-loaded relationship?"

    Verified by reading the models:
      - `Order::find($id)` applies BranchScope at the parent → returns
        null if order belongs to Branch A and auth is Branch B. So
        `Order::find($id)->items()` would NPE BEFORE OrderItem is
        even queried.
      - Even if the attacker uses `Order::withoutGlobalScope(BranchScope::class)->find($id)->orderItems`,
        OrderItem ALSO has its own BranchScope (line 27, added 2026-05-09 with the comment
        "Without this, ItemService::destroy:365 leaks historical-order counts across all
        tenants → branch isolation breach"). The relationship query
        `belongsTo` injects a `where order_id = X` clause but does NOT
        disable the global scope on OrderItem → the secondary scope
        bites and the relationship returns an empty collection.
      - OrderPayment is also independently scoped (Order.php:67).

    **Double-fence**: even if Order's scope is intentionally bypassed
    by a developer, OrderItem and OrderPayment scope independently. Two
    independent failures would be required.
v2_saas_impact: same — well architected
cost_of_delay: none
recommendation: keep convention; sentinel from S-3 recommendation covers it.
```

---

## Finding S-5 — Admin branch_id=0 recognition uses BOTH branch_id AND role check (CLEAN, defense in depth)

```yaml
finding_id: S-5
severity: PASS
category: admin_bypass_verification
file_evidence:
  - app/Services/OrderService.php:2362-2369                       (isGlobalAdmin requires BOTH branch_id===0 AND hasRole('Admin'))
  - app/Services/OrderStatusScreenOrderService.php:226             (same pattern)
  - app/Services/TransactionService.php:95                          (same pattern)
  - app/Services/Order/OrderQuoteService.php:483                    (same pattern)
  - app/Http/Controllers/Admin/AdminController.php:15-40            (authorizeBranchScope, authorizeWritableBranchScope — same dual gate)
  - app/Http/Middleware/IdempotencyKeyMiddleware.php:188-195        (admin path requires branch_id===0 AND hasRole('Admin'))
attacker_capability_required: n/a — verification finding
trigger:
  load_mode: n/a
  failure_mode: |
    Vector 5 from the threat model: "Admin branch_id=0 bypass — can a
    manager set their own branch_id=0 via mass-assignment to bypass scope?"

    Verified by grep + read of all `branch_id === 0` callsites:
      Every gate checks BOTH `branch_id === 0` AND `hasRole('Admin')`
      (or hasRole('Admin'|'Tenant Admin')). Mass-assigning `branch_id=0`
      WITHOUT the Admin role grants the user NO cross-branch visibility
      because:
        - BranchScope at apply() reads `branch()` which reads `branch_id`
          from `Auth::user()` (DefaultAccessModelTrait:21-25). If
          branch_id===0 from a non-Admin user, the scope returns early
          (BranchScope.php:34) — the user sees branch_id=0 rows only,
          which by convention are admin-tenant rows and have minimal data.
        - All authorization gates above reject non-Admin users with
          branch_id=0 explicitly (e.g. SyncOverviewController:200-207
          returns 403, OrderService::isGlobalAdmin rejects).
        - User-creation `setBranch` BUG (S-1) does not affect this surface
          because Admin role is a separate Spatie attribute.

    However, S-1 above DOES allow a Branch Manager to create a user
    with `branch_id=0`. That user would be a "ghost admin" with NO
    Admin role assigned — so cross-branch visibility is BLOCKED by
    every dual-gate. The ghost user would see only branch_id=0 rows
    (which are admin-tenant orphans). Limited blast radius for that
    vector; the real damage comes from S-1's branch_id=A path.
v2_saas_impact: same defense; verify Tenant Admin equivalence in SaaS rollout
cost_of_delay: none
recommendation: |
  Document the dual-gate convention in `docs/AUTHZ_MATRIX.md` if not
  already. Add `tests/Feature/Authz/GhostAdminBranchIdZeroNoBypassTest.php`
  that creates a user with `branch_id=0` + role=POS Operator and proves
  no cross-branch read leaks.
```

---

## Finding S-6 — JWT/Sanctum claim tampering — branch read from Auth::user(), not request header (CLEAN)

```yaml
finding_id: S-6
severity: PASS
category: server_trusted_branch_resolution
file_evidence:
  - app/Models/Scopes/BranchScope.php:29              ($userBranch = $this->branch();)
  - app/Traits/DefaultAccessModelTrait.php:17-26       (branch() reads Auth::user()->branch_id; no header read)
attacker_capability_required: n/a — verification finding
trigger:
  load_mode: n/a
  failure_mode: |
    Vector 6 from the threat model: "does BranchScope read branch from
    Auth::user()->branch_id (server-trusted) or from a request header
    that's user-controllable?"

    Verified — branch() reads ONLY from:
      - `DefaultAccess::where(['user_id' => Auth::id(), 'name' => 'branch_id'])` (DB-resolved)
      - `Auth::user()->branch_id` (server-resolved via Sanctum token →
        PersonalAccessToken → user_id → users table → branch_id column)
      - `Settings::group('site')->get('site_default_branch')` (fallback,
        console only)

    No header is read; no payload field is read. Token tampering is
    blocked by Sanctum's HMAC-signed token format (PersonalAccessToken
    table stores hashed tokens). Even if an attacker injects
    `X-Branch-Id` header, neither the scope nor the trait reads it.

    Caveat: `KioskEventController:201` reads `$request->input('branch_id')`
    but only LOGS it as `claimed_branch_id` for forensic mismatch
    detection; the actual scope (line 200) uses `$machine?->branch_id`
    from the server-side KioskMachine pivot. Same for `IdempotencyKeyMiddleware`
    admin path (S-2 covers the residual edge case).
v2_saas_impact: same, robust
cost_of_delay: none
recommendation: keep; document explicitly in `docs/AUTHZ_MATRIX.md`
```

---

## Summary

| ID  | Severity | Recommendation TLDR |
|---|---|---|
| S-1 | **P0**   | Cross-branch User creation via Branch Manager — fix `DefaultAccessModelTrait::setBranch` dead branch (line 40) + FormRequest authz + User model guard + sentinel |
| S-2 | P1       | Idempotency middleware fallback at line 219 — fail-closed (return -1) instead of accepting payload `branch_id` |
| S-3 | PASS     | 35 `withoutGlobalScope(BranchScope::class)` callsites audited; all safe. Add sentinel to prevent regression |
| S-4 | PASS     | Sub-resource leak (`Order->items()`) double-fenced via OrderItem's own BranchScope |
| S-5 | PASS     | Admin branch_id=0 bypass requires BOTH branch_id===0 AND hasRole('Admin') across 6+ gates |
| S-6 | PASS     | BranchScope reads from server-trusted Auth::user(), not from request headers |

---

## Verdict for T-1.3.1

**NO-GO-V1 ABSOLUTE-AS-IS — S-1 is a P0 cross-branch persistent foothold exploitable by any Branch Manager credential.** Round 1 already cataloged MGMT-P0-B (cross-tenant Ingredient DoS, single-request side-channel). S-1 is a **deeper-pivot variant**: full User-creation cross-branch is more catastrophic because the foothold is **persistent** (the rogue User row survives, the rogue role is assigned, the attacker can log in N times). MGMT-P0-B is single-action DoS; S-1 is persistent compromise.

The BranchScope implementation itself is **architecturally sound**: 18 models scoped, exact-match filter, admin bypass requires dual gate, sub-resources double-fenced, no header injection surface, no withoutGlobalScope callsite without follow-up filter. The breach point is NOT the scope — it is the **`setBranch` writer-side function** that fails to enforce non-admin staff cannot pivot branch_id at User creation/update time. The trait reads correctly from `Auth::user()->branch_id` (S-6) but its `setBranch` companion silently drops the constraint when the value is a positive int different from the auth user's branch_id.

**Heal required before V1 ship**:
1. S-1 fix as recommended (4-layer defense, ~3h) — adds to PR #1 CENTRAL or new PR-multi-tenant
2. S-2 fix (~30 min, one line + 1 test) — adds to PR #1 CENTRAL
3. S-3 sentinel (~3h, preventive) — adds to PR #1 CENTRAL or V1.0.2 backlog

Total heal: ~6h critical (S-1+S-2) + ~3h preventive (S-3).

Word count: ~1490.
