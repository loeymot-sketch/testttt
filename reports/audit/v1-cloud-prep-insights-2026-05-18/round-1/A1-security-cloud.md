# A1 — Security + Cloud Prep audit (V1 Cloud-Prep insights round 1)

**HEAD**: `1235e3e1a` + uncommitted working tree (5 files modified)
**Branch**: `v1-0-1-hardening-2026-05-17`
**Verdict**: **GO-CONDITIONAL** — uncommitted tree contains a P0 NF525 cents fix + a P1 bypass flag wired into 3 services. Block cloud deploy until tree state is reconciled.

---

## Heal verification (9 items)

### 1. LanguageController RCE primitive — **VERIFIED HEALED (with nuance)**
- `app/Http/Controllers/Admin/LanguageController.php:27` applies `permission:settings` via `->only('store','update','destroy','fileText','fileTextStore')` — NOT a blanket constructor gate. The owner claim "constructor middleware" is loose; the actual implementation is method-scoped, which is correct.
- `routes/api.php:477-487` lives inside an `admin` group `routes/api.php:269` with `['installed','apiKey','auth:sanctum','localization','throttle:admin-mutation']`. An unauth session cannot reach any of these endpoints.
- Read primitive `fileText` and write primitive `fileTextStore` (`LanguageService.php:181-220`, both still wrap `include($path)` and `file_put_contents`) are now both gated.
- `fileList` (`LanguageController.php:77`) is intentionally NOT in the only-list — read-only filename enumeration, no LFI/RCE primitive. Acceptable.

### 2. POS IDOR `PosOrderController::show` cross-branch leak — **VERIFIED HEALED**
- `PosOrderController.php:112-121`: `withoutGlobalScope(BranchScope::class)->findOrFail()` wrapped in try/catch `ModelNotFoundException` → `abort(403, 'Cross-branch access denied')`. Then `abort_unless($user->branch_id === 0 || $order->branch_id === $user->branch_id, 403)`. Wave 5I A.1 timing-leak heal landed.
- Admin path (`branch_id=0`) explicitly allowed. Foreign branch + non-existent id both return 403 with same message → existence enumeration closed.
- `destroy`/`changeStatus`/`changePaymentStatus`/`selectDeliveryBoy`/`reorderItems`/`refundWithCounterEntry` rely on Laravel route-model binding + `BranchScope` global → 404 path was the previous problem on `show` specifically. Other methods get BranchScope filtering naturally. `refundWithCounterEntry` adds explicit `abort 403` for cross-branch (line 58-61).

### 3. Ansible Phase D playbook — **VERIFIED HEALED (with one P2 gap)**
- `deploy/ansible/site.yml` 174 LOC. Tasks cover base packages, UFW, PHP, MySQL, Redis (auth + maxmemory), Nginx + Certbot, Soketi via Supervisor, app deploy, pre-migrate mysqldump (Wave 5I A.2), migrate, cron, logrotate.
- Vault refs proper: `{{ vault_db_password }}`, `{{ redis_password }}` with `no_log: true` on the dump task (`site.yml:122-130`).
- `templates/nginx-foodking.conf.j2:28-30` headers: X-Content-Type-Options nosniff, X-Frame-Options SAMEORIGIN, Referrer-Policy strict-origin. **HSTS commented out at line 31** because certbot injects after TLS — acceptable but **P3**: post-Certbot run no automated verification that HSTS landed.
- `templates/supervisor-foodking.conf.j2:7-19`: queue worker with `--tries=3 --timeout=120 --max-jobs=1000`, autorestart=true, logrotation 5×20MB. Soketi same. Scheduler block commented (cron is primary).
- **P2 GAP**: `nginx-foodking.conf.j2:9` defines `limit_req_zone` at server block scope — Nginx requires this to be at `http {}` context. Adding it inside a server block file in `sites-available/` works only because Nginx parses the file in the http context via `sites-enabled/` include. Confirmed safe in this setup, but fragile — flag as P3.

### 4. bcrypt rounds upgrade — **VERIFIED HEALED**
- `config/hashing.php:39`: `'rounds' => env('BCRYPT_ROUNDS', 12)` — confirmed.
- `app/Http/Controllers/Auth/LoginController.php:95-98`: rehash on login. **NOTE**: This is COMMITTED in HEAD (no diff vs HEAD). Reading `git diff HEAD` returned empty.
- `tests/Feature/Auth/BcryptRoundsUpgradeTest.php` has 4 assertions: stale rehash, post-rehash login, no-op when already current, config sentinel reads file string `env('BCRYPT_ROUNDS', 12)`. Tests are well-formed.
- **P3 timing-attack concern**: Hash::needsRehash + Hash::make adds ~100ms ONLY for stale users on the first post-bump login. Once. Not statistically distinguishable from network jitter; flag for awareness only.
- **P2**: `$user->password = Hash::make(...); $user->save()` writes the new hash but does NOT log a security event. A bulk-password-reset would silently rehash thousands of users with no audit trail. Recommend `Log::channel('security')` line in the rehash branch.

### 5. PhpSpreadsheet CVEs — **PARTIALLY VERIFIED**
- `composer.lock:4610-4611`: `"phpoffice/phpspreadsheet"` at `"version": "1.30.4"`. Bump from 1.30.0 → 1.30.4 confirmed.
- **UNVERIFIED**: The CVE IDs in commit `46fb4ef2d` message (CVE-2026-34084 SSRF/RCE, CVE-2026-40902 DoS, CVE-2026-40863 DoS, CVE-2026-40296 XSS, CVE-2026-35453 XSS) cannot be independently confirmed from this audit context. The version bump is real; the CVE attributions are asserted by the commit message, not verified.
- **P2 RECOMMENDATION**: Run `composer audit` pre-deploy and attach output to the convergence doc. The commit claims advisory count 17 → 12 (−5 exact match). That should be reproducible in 30s.

### 6. FormRequest authz refactor — **VERIFIED HEALED (5/93 — incomplete coverage)**
- `AdministratorRequest.php:authorize`: `$user->can('administrators_create') || $user->can('administrators_edit')` (with null-guard). Correct.
- `BranchRequest.php`, `CurrencyRequest.php`, `RoleRequest.php`, `TaxRequest.php`: all return `$this->user()?->can('settings') ?? false`. Correct.
- Total FormRequest files: **93** in `app/Http/Requests/`. The 5 healed are a defense-in-depth subset on already-gated controllers. The owner claim of "FormRequest authz refactor 91/93 + 88 endpoints" is **NOT supported by Wave 5H+5G alone** — the broader 88-endpoint refactor is roadmap V1.0.1 per CLAUDE.md §9, not closed by this cycle.
- **P2**: The remaining 88 FormRequests are still controller-middleware-only. Acceptable for V1 (middleware fires before FormRequest); but document this explicitly in CONVERGENCE_FINAL.md to avoid future drift confusion.

### 7. Branch deactivation token revoke — **VERIFIED HEALED (with one P2 gap)**
- `app/Events/BranchStatusChanged.php`: 3-arg constructor `(branchId, oldStatus, newStatus)`. Uses `DispatchableAfterCommit`. Correct.
- `app/Listeners/RevokeTokensOnBranchDeactivated.php:27-33`: no-op guards on `$old === $new` and `$new !== INACTIVE`. Lines 52-55: scope strict `where('tokenable_type', User::class)` — kiosk-machine tokens (different `tokenable_type`) are NOT touched. Matches CLAUDE.md §9 constraint.
- `BranchController.php:66-72`: captures `$oldStatus = $branch->status` BEFORE service mutation (because `tap()->update()` would mutate in place), fires event only if `$oldStatus !== $newStatus`. Correct.
- **P2 GAP**: `BranchController::destroy` (`BranchController.php:80-89`) hard-deletes a branch without firing `BranchStatusChanged`. Any users still scoped to that branch keep Sanctum tokens valid until the 480-min TTL expires. Recommend: fire `BranchStatusChanged(branchId, ACTIVE, INACTIVE)` from destroy() before delete, OR add explicit token revocation in BranchService::destroy.

### 8. Outbox + Webhook pruning commands — **VERIFIED HEALED**
- `app/Console/Commands/PruneOutboxCommand.php`: signature `foodking:outbox:prune`, default 90d, chunked LIMIT 1000, dry-run support, observability logging. Safe-set predicate covers (A) dispatched_at + cutoff AND (B) attempts ≥ 6 + cutoff. Explicit NF525 comment: "domain_events is an OPERATIONAL outbox, NOT a fiscal audit table. audit_logs + z_reports never touched." Correct.
- `app/Console/Commands/PruneWebhookEventsCommand.php`: signature `foodking:webhook:prune`, safe-set `status IN ('processed','duplicate')` — explicitly NOT pending/failed (DLQ owns those). Same chunking + observability.
- `app/Console/Kernel.php:100-119`: scheduled 04:00 (outbox) + 04:15 (webhook), `dailyAt`, `withoutOverlapping`, `onOneServer`, `runInBackground`. Correct staggering.
- Hard-delete is used (not soft-delete), but the safe-set predicate is bulletproof: fiscal data lives in different tables (`audit_logs`, `z_reports`) with their own BEFORE DELETE triggers (CLAUDE.md §8) — NOT reachable by these commands.

### 9. Uncommitted working tree concern — see dedicated section below

---

## P0 findings NEW (post-Wave-5I or in working tree)

### P0-A1-01 — Uncommitted Stripe.php cents-truncation fix is a financial NF525 violation NOT in any commit
- **File**: `app/Http/PaymentGateways/Gateways/Stripe.php:58` (uncommitted, working tree only)
- **Issue**: The pre-fix code `(int) $order->total * 100` casts `$total` to int FIRST (dropping decimals), THEN multiplies. €9.99 becomes 900 cents = €9.00 charged. The patch changes it to `(int) round((float) $order->total * 100)` — the correct pattern matching `OrderController.php:137`, `PaymentReconcileController.php:173`, `SplitPaymentService.php:103/110`.
- **Risk**: If HEAD `1235e3e1a` is merged/deployed as-is WITHOUT the working-tree mods, Stripe online orders silently undercharge by up to €0.99 per order. The OrderPayment row and the fiscal sequence will reference an amount that doesn't match the Stripe charge → NF525 receipt/payment reconciliation mismatch. **Prison time risk per CLAUDE.md §8**.
- **Heal**: Commit `app/Http/PaymentGateways/Gateways/Stripe.php` immediately. Tag the commit `fix(stripe-cents): NF525 cents truncation`. Owner-gate.

### P0-A1-02 — `POS_SIMULATION_HARDWARE` bypass flag wired into 3 critical services, uncommitted, contradicts CLAUDE.md §8
- **Files (uncommitted)**:
  - `app/Http/Controllers/Admin/PosController.php:92-97` — `assertCashDrawerSessionOpenIfCashInvolved` returns early when `config('pos.simulation_hardware') === true`
  - `app/Services/PaymentService.php:278-282` — `$strict` mode downgraded to `false` when flag is true
  - `app/Services/Payments/SplitPaymentService.php:200-207` — `$hasCashTranche && ! $simulating` short-circuit — OrderPayment rows still written, but `cash_movement` rows are NOT created when no `$cashSession` exists
- **Config**: `config/pos.php:37` defaults to `false`. Commit `1235e3e1a` adds `POS_SIMULATION_HARDWARE=false` to `docs/cloud/PRODUCTION_ENV_TEMPLATE.env.txt` (committed) — but the consumer code is UNCOMMITTED. **The commit message claims a fix that doesn't exist in any commit.** Doctrine/code coherence break.
- **CLAUDE.md §8 violation**: "Aucun env flag pour bypass — toujours actif". This flag bypasses cash-drawer-open precondition + idempotency strict mode + cash_movement write. Even if production-defaults to false, the existence of an env-flag escape hatch contradicts the doctrine.
- **Risks**:
  - No runtime assertion `App::environment() !== 'production'` — a misconfigured `.env` in prod silently disables a cash-drawer precondition
  - No `audit_log` entry when the bypass fires — silent reconciliation gap (CASH OrderPayment rows + zero `cash_movement` rows = Z-report variance that flags but doesn't attribute to root cause)
  - `SplitPaymentService` continues to write OrderPayment rows for CASH tranches without a corresponding `cash_movement` ledger entry — Z-report close will produce a variance every single time
- **Heals required**:
  1. Add `Assert::notTrue(config('pos.simulation_hardware') && app()->environment('production'))` in a service-provider boot hook
  2. Emit `Log::channel('security')->warning('pos.simulation_hardware.bypass_fired', [...])` whenever the bypass executes
  3. Document in CONVERGENCE_FINAL.md that V1.0.2 must remove this flag entirely once hardware lands
  4. Commit the 3 files OR revert them — current tree state is incoherent

---

## P1 findings NEW

### P1-A1-01 — `BranchController::destroy` does not fire `BranchStatusChanged` → orphan tokens valid 480min
- **File**: `app/Http/Controllers/Admin/BranchController.php:80-89`
- **Issue**: Hard-delete path does not trigger token revocation. Users still bound to a deleted branch retain Sanctum auth_token validity until natural expiry.
- **Heal**: Dispatch `BranchStatusChanged($branch->id, ACTIVE, INACTIVE)` BEFORE `$this->branchService->destroy($branch)` OR add token revocation directly in `BranchService::destroy`.

### P1-A1-02 — bcrypt rehash on login has no security audit log entry
- **File**: `app/Http/Controllers/Auth/LoginController.php:95-98`
- **Issue**: Silent password rehash on login is correct behavior, but generates ZERO observability when fired. A future bulk-rehash event (e.g. config bump 12 → 14) would touch thousands of users with no audit evidence.
- **Heal**: Add `Log::channel('security')->info('auth.password.rehashed', ['user_id' => $user->id, 'old_cost' => ..., 'new_cost' => ...])` in the rehash branch.

### P1-A1-03 — CVE labels in Wave 5H commit are unverified
- **Source**: Commit `46fb4ef2d` message claims 5 CVE IDs (CVE-2026-34084, -40902, -40863, -40296, -35453).
- **Issue**: Cannot independently confirm CVE existence/attribution from audit context. PhpSpreadsheet 1.30.0 → 1.30.4 bump is real and the diff is small enough to be CVE-bearing, but the CVE numbers themselves must be cross-checked.
- **Heal**: Run `composer audit --format=json > reports/audit/v1-cloud-prep-insights-2026-05-18/composer-audit-pre-deploy.json` and attach to convergence doc.

---

## P2 findings

### P2-A1-01 — Remaining 88 FormRequests still rely on controller middleware only
- **Files**: 93 total FormRequests in `app/Http/Requests/`. Only 5 have hard `authorize()` returning permission-gated bool. The rest (88) still effectively `return true;` (controller `__construct()` middleware is the only line of defense).
- **Issue**: Defense-in-depth is incomplete. A future route refactor that bypasses controller middleware (e.g. console invocation of a FormRequest) would skip authz.
- **Heal**: V1.0.2 backlog item per CLAUDE.md §9. Document explicitly in CONVERGENCE_FINAL.md.

### P2-A1-02 — Nginx HSTS only injected by Certbot, no post-deploy verification
- **File**: `deploy/ansible/templates/nginx-foodking.conf.j2:31` — HSTS header commented "Certbot injects after TLS".
- **Issue**: If Certbot task fails silently or HSTS injection is skipped (Certbot version drift), HTTPS deployments lack HSTS for an unbounded window.
- **Heal**: Add a final Ansible task `ansible.builtin.command: curl -sI https://{{ nginx_server_name }} | grep -i strict-transport-security` with `failed_when: rc != 0` after the Certbot task.

### P2-A1-03 — `limit_req_zone` declared in server-block file is convention-fragile
- **File**: `deploy/ansible/templates/nginx-foodking.conf.j2:9`
- **Issue**: `limit_req_zone` must live at `http {}` context. Nginx parses sites-enabled within http context so this works, but a future Nginx upgrade or include refactor could break it without warning.
- **Heal**: Move zone declaration to `/etc/nginx/conf.d/zones.conf` deployed as a separate Ansible template task.

---

## P3 findings

### P3-A1-01 — bcrypt rehash timing leak (~100ms one-time)
- LoginController rehash adds bcrypt cost-12 work (~100ms) on a one-time basis per stale user. Not statistically distinguishable from network jitter; flag for awareness.

### P3-A1-02 — `composer.json` constraint stays `^1.30.0` for phpspreadsheet
- The composer.json constraint allows automated downgrades back into the vulnerable range on `composer update`. Pin to `^1.30.4` or use `composer.lock` discipline in CI.

---

## Uncommitted working tree risk register

| File | Risk | Severity |
|------|------|----------|
| `app/Http/Controllers/Admin/PosController.php:92-97` | `simulation_hardware` flag bypasses cash-drawer-open precondition. Defaults false in prod, but CLAUDE.md §8 forbids env-flag bypass. | **P0** (doctrine + coherence) |
| `app/Services/PaymentService.php:278-282` | Same flag downgrades `$strict` → `false`, silently. No log entry when fired. | **P0** (silent bypass) |
| `app/Services/Payments/SplitPaymentService.php:200-207` | Same flag skips cash_movement creation while still writing OrderPayment row → Z-report variance every single time. | **P0** (NF525 reconciliation) |
| `app/Http/PaymentGateways/Gateways/Stripe.php:58` | NF525-critical cents truncation fix. NOT IN ANY COMMIT. If deployed as HEAD, Stripe undercharges by up to €0.99/order. | **P0** (NF525 financial) |
| `app/Http/Controllers/Auth/LoginController.php` | `git diff HEAD` returned empty — already committed. No working-tree risk. | N/A |

**Coherence break**: Commit `1235e3e1a` documents `POS_SIMULATION_HARDWARE=false` in the cloud env template but the consumer code is uncommitted. Either commit the consumers OR revert the template addition — the current state is incoherent.

---

## Convergence recommendation

- **P0 NEW = 2** (P0-A1-01 Stripe cents + P0-A1-02 simulation flag triad)
- **P1 NEW = 3** (branch destroy, rehash audit log, CVE verification)
- **P2 NEW = 3** (88 FormRequests, HSTS verification, nginx zone)
- **P3 NEW = 2**

**Block cloud deploy? YES.** Required closures before Phase D ansible-playbook execution:

1. **MUST** — Commit `Stripe.php` cents fix (P0-A1-01) — prevents NF525 financial violation on first prod Stripe charge
2. **MUST** — Decision on `POS_SIMULATION_HARDWARE` triad (P0-A1-02): commit + add runtime production-guard + emit audit log; OR revert all three files
3. **MUST** — Run `composer audit` + attach output (P1-A1-03)
4. **SHOULD** — Fix `BranchController::destroy` orphan-token path (P1-A1-01)
5. **SHOULD** — Add rehash security log line (P1-A1-02)

The 8 main heal items are genuinely verified — the cycle's documented work is real. But the uncommitted tree introduces TWO P0 issues that the commit history doesn't reflect. The owner's "13 P0 closed" claim is supportable for HEAD `1235e3e1a` only if the working tree is reverted; with the working tree mods included, the count is **13 closed + 2 P0 introduced**.

**File path**: `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/reports/audit/v1-cloud-prep-insights-2026-05-18/round-1/A1-security-cloud.md`
