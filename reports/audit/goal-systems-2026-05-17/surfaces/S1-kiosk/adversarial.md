# S1 KIOSK — Adversarial / RED-team Audit
**Date** : 2026-05-17
**Auditor** : RED-team (hostile-framed static analysis)
**Branche** : `feature/mobile-app-le-cayenne-2026-05-10`
**Scope** : Kiosk surface — Sanctum token, OrderRequest, FrontendOrderService, OrderQuoteService, PaymentConfirm, broadcast channels, JS payload, mobile/POS wizard duplication
**Read-only** : YES (no exploitation, static only)

---

## §0 — Stale findings (already healed — DO NOT re-flag)

| ID | Finding | Status | Evidence |
|----|---------|--------|----------|
| F-001 to F-014 | NF525 trail + Sanctum kiosk:order ability + idempotency core | CLOSED | `tests/Feature/KioskQuoteForgesBranchIdSilentlyOverriddenTest.php`, `KioskScopeIsolationTest.php`, `KioskEventAbilityTest.php`, `Auth/RefreshTokenAbilityPreserveTest.php`, `KioskQuoteTokenRequiredOnCommitTest.php`, `KioskLoyaltyLedgerAtomicTest.php`, `KioskLoyaltyDoubleRedeemRefusedTest.php`, `Auth/KioskThrottleKeysTest.php`, `PaymentConfirmCrossBranchTest.php`, `KioskBundleLockdownTest.php` |
| F-015 | Queue config monoinstance | CLOSED (cycle 2026-05-08) | confirmed in `ultra-review-2026-05-16/ULTRA_REVIEW_VERDICT.md` |
| F-016 / F-017 | stock_levels reuse + bundles | CLOSED | same |
| Wave Z Z1–Z10 | 7 P0 + 14 P1 (May 16) | CLOSED | `memory/project_wave_z_convergence_2026-05-16.md` |
| AUDIT-F-002 | TPE amount echo verification | CLOSED | `OrderController.php:133-152` with `AMOUNT_ECHO_MISMATCH` error code |
| AUDIT-F-007 | Idempotency branch namespace | CLOSED | `FrontendOrderService.php:135-151` (HttpException 422 if branch_id unresolved) |
| AUDIT-F-008 | Payment reconcile queue idempotent | CLOSED | `PaymentReconcileController.php` UNIQUE(transaction_id) + sealing |
| AUDIT-F-013 | Whitelist explicit PENDING in finalize | CLOSED | `FrontendOrderService.php:1099` |
| iter15-P0-07 | RefreshToken preserves abilities | CLOSED | `RefreshTokenController.php:42-53` |
| iter15-P0-08 | OrderRequest::authorize ability gate | PARTIAL (see K-RED-01 below — fail-open path remains) |
| GAP-21-2 | Strip client total/subtotal/discount | CLOSED | `FrontendOrderService.php:257` |
| GAP-21-5 / P4-1 | Pusher kiosk branch-scoped channel | CLOSED | `routes/channels.php:25-39` |
| K-6.2 | Kiosk branch_id mismatch security log | CLOSED | `KioskEventController.php:202-222` |
| K-6.1/T08b | Both kiosk-event aliases enforce ability | CLOSED | `routes/api.php:1227, 1273` |
| K-001 (Wave B 2026-05-16) | FR-lock breach via A11y radiogroup | OPEN — owner-gate ≠ RED scope (UX/UI), NOT re-flagged here |

**Stale count : 16 historic findings re-verified as healed (NOT re-flagged).**

---

## §1 — Attack Score : **62 / 100**

(Higher = more attackable. Score derives from §2 evidence below.)

| Dimension | Score | Rationale |
|-----------|-------|-----------|
| Auth boundary | 14/20 | LoginController `['*']` token grants `tokenCan('kiosk:order')` to ANY authenticated user (admin/staff/guest) via `'*'` ability wildcard — `OrderRequest::authorize` returns true. |
| Order forgery | 7/20 | PricingService SSOT + composition_snapshot frozen + items-only payload. Hard to attack; quote sealing checks total. |
| Payment bypass | 9/20 | `PAYMENT_BYPASS_MODE=true` simulates approved TPE — guarded against APP_ENV=production but staging/preprod is wide open. amount_cents echo enforced. |
| Race conditions | 8/15 | Idempotency well-scoped, lockForUpdate present. Window remains around `currentAccessToken()` resolution + post-commit FCM swallow. |
| DoS surface | 12/15 | `kiosk-orders` 5/min, `kiosk-event` 30/min, `kiosk-menu` 60/min — kiosk burst legitimate yet rate limits don't protect downstream fiscal sequence cost. Unauth `/frontend/item/kiosk-upsell` no rate limit. |
| Duplication drift | 8/10 | Three wizards (kiosk Vue, mobile JSX, POS Vanilla JS) implement same business logic with diverging magic numbers. |
| Failure modes | 4/10 | Network drop mid-payment paths exist but offline queue may persist invalid orders silently; FCM swallow can mask fiscal alloc state. |

---

## §2 — Attack vectors

### P0 — Critical / Production-blocking

---

#### **K-RED-P0-01 — Sanctum wildcard `['*']` defeats `kiosk:order` ability gate everywhere** *(re-validated, NOT stale)*

**Files** :
- `app/Http/Controllers/Auth/LoginController.php:96-100` (issues `['*']`)
- `app/Http/Controllers/Auth/GuestSignupController.php:140` (issues `['*']`, 30-day TTL)
- `app/Http/Controllers/Auth/ForgotPasswordController.php:165-169` (issues `['*']`)
- `app/Http/Requests/OrderRequest.php:60-66` (`tokenCan('kiosk:order')` checked)
- `routes/api.php:1226-1228, 1272-1274` (`abilities:kiosk:order` on kiosk-event)
- `app/Http/Requests/Frontend/PaymentConfirmRequest.php:19-21` (same `tokenCan` gate)

**Sanctum primer (verify-before-claim) :** `HasApiTokens::tokenCan($ability)` returns `true` whenever the access token's abilities array contains either the requested ability OR the wildcard `'*'`. A `'*'`-token therefore satisfies EVERY `tokenCan(<anything>)` check. This is documented Sanctum behaviour, not a bug.

**Attack scenario (paid attacker, no kiosk hardware) :**
1. Attacker registers as guest customer via `/api/guest/signup` (phone + OTP throttle 5/1min on `loyalty/register`). Receives `auth_token` with abilities `['*']` and 30-day TTL.
2. Attacker POSTs `/api/frontend/order/quote` with crafted `items`. `OrderRequest::authorize()` line 65 calls `tokenCan('kiosk:order')` → **true** (wildcard). The gate the comments brag about is bypassed.
3. `OrderRequest::isKioskOrderToken()` line 282 also returns true. `kioskMachineForToken()` then runs `KioskMachine::where('user_id', $attacker->id)->first()` (line 277). Attacker has no KioskMachine row, so this returns null — the kiosk-specific branch_id rewrite at line 76 does NOT fire. **However**, line 173-178 of `withValidator` adds a validation error for missing kiosk machine, so the order request itself is rejected. **The kiosk-order vector is contained by the FormRequest validation chain, NOT by the ability gate.**
4. **Real damage lies on adjacent endpoints that gate ONLY by `tokenCan('kiosk:order')` without a follow-up KioskMachine check.** Both `/api/frontend/kiosk-event` and `/api/frontend/kiosk/event` routes (`routes/api.php:1226, 1272`) use `abilities:kiosk:order` middleware alone — `KioskEventController::store` doesn't bounce non-kiosk users (`$user ? KioskMachine::where(...)` at line 199 just sets `$machine = null` and continues). The attacker can therefore POST arbitrary analytics events impersonating a kiosk → ActionLog pollution + log injection (see K-RED-P2-12).

**Exploit complexity** : LOW. One guest OTP signup.
**Business impact** : MEDIUM-HIGH. Defense-in-depth gate is decorative; ANY `tokenCan('kiosk:order')`-only endpoint added in the future (current or new) is exposed by default. The team confidently assumes the gate filters non-kiosk callers — it does not. One new endpoint added without an explicit `KioskMachine::exists()` check = immediate P0.
**Mitigation** : Replace wildcard token issuance with role-scoped abilities. Guest = `['customer:order']`. Admin = `['admin']`. POS = `['pos']`. Kiosk = `['kiosk:order']`. Audit every `tokenCan('kiosk:order')` site (`OrderRequest`, `PaymentConfirmRequest`, channels, future endpoints) and replace with `tokenCan('kiosk:order') && !$user->tokenCan('*')` until wildcard tokens are eradicated. **This single change is the highest-ROI hardening in the codebase.**

---

#### **K-RED-P0-02 — `OrderRequest::authorize` fail-open when `currentAccessToken()` is null**

**File** : `app/Http/Controllers/Frontend/../../../app/Http/Requests/OrderRequest.php:60-63`

```php
$token = $user->currentAccessToken();
if (! $token) {
    return true;
}
```

**Attack scenario :**
1. Attacker authenticates via web session (Laravel `web` guard, e.g. test fixture `actingAs($user)`). `currentAccessToken()` returns null because no PersonalAccessToken is attached — only the guard caches the user.
2. Request to `POST /api/frontend/order` is allowed unconditionally — bypasses the `tokenCan('kiosk:order')` gate the comment claims to enforce.
3. In tests this is a documented backdoor (line 51-58 comment). **In production, the backdoor is exploitable if any Laravel session can be created** (e.g. CSRF bypass via misconfigured SameSite cookie, or session fixation on a publicly-served form).

**Exploit complexity** : MEDIUM. Requires session-auth path to reach `/api/frontend/order`. Default Laravel SPA setup with Sanctum stateful domains exposes this.
**Business impact** : MEDIUM. Any session-authenticated user creates kiosk orders bypassing the ability gate that's supposed to be in place — defeats defense-in-depth.
**Mitigation** : Replace line 62 `return true;` with `return $user instanceof KioskMachineUser || $user->hasRole('Admin');` — explicit allowlist, NOT a deny-omission.

---

#### **K-RED-P0-03 — Pusher branch channel admin-bypass via `branch_id=0` for SESSION-auth users**

**File** : `routes/channels.php:25-39`

```php
Broadcast::channel('branch.{branchId}', function ($user, $branchId) {
    // Kiosk machine token path
    if ($user->currentAccessToken() && $user->tokenCan('kiosk:order')) {
        $machine = \App\Models\KioskMachine::where('user_id', $user->id)->first();
        return $machine && (int) $machine->branch_id === (int) $branchId;  // RETURNS — no fallthrough
    }
    // Admin users (branch_id=0) can subscribe to any branch channel
    if ((int) $user->branch_id === 0) {
        return true;
    }
    return (int) $user->branch_id === (int) $branchId;
});
```

**Control-flow note (advisor reconcile) :** A wildcard `['*']` token-bearer DOES enter the first `if` (line 27), DOES fail the machine check, and returns `false`. They do NOT pivot to line 33. **The wildcard-token + Pusher escalation chain I initially sketched is wrong.** The Pusher bypass operates on a different population: SESSION-authenticated callers (Laravel `web` guard, no `currentAccessToken()`) whose `users.branch_id` column is `0` or null.

**Attack scenario :**
1. Attacker authenticates via the Laravel `web` guard (e.g. guest OTP that ALSO calls `Auth::guard('web')->loginUsingId($user->id)` — `GuestSignupController.php:130`). The user is now session-authenticated but `currentAccessToken()` returns null.
2. Attacker connects to the websocket auth endpoint (`/broadcasting/auth`) for channel `private-branch.42`. First `if` line 27 evaluates `$user->currentAccessToken() && ...` → false. Fallthrough.
3. Line 32 checks `(int) $user->branch_id === 0`. Guest customers have `branch_id = 0` (or null cast to 0) by default — no migration enforces non-zero for customer rows. **Returns true → subscription succeeds for ANY branch.**
4. Attacker passively receives `OrderCreated` and `OrderStatusChanged` events from every branch he subscribes to: order_serial_no, status, queue_number, total, eventual customer phone (via cascading event payloads).

**Exploit complexity** : LOW. One guest signup + a Pusher / Soketi client.
**Business impact** : HIGH. Real-time multi-tenant data leak. GDPR + competitive intelligence.
**Mitigation** : Replace numeric check with explicit role check: `$user->hasRole('Admin')` or a dedicated `Gate::allows('broadcast.branch.any', $branchId)`. Migrate customer rows to `branch_id = NULL` (not 0) and reject `(int) 0` comparisons that conflate the two cases.

---

#### **K-RED-P0-04 — `PaymentConfirmRequest::authorize` impersonation risk via shared `kiosk_machines.user_id`**

**Files** :
- `app/Http/Requests/Frontend/PaymentConfirmRequest.php:12-26` (auth gate)
- `app/Services/KioskMachineService.php:62-69` (admin sets `user_id` freely)
- `app/Http/Requests/KioskMachineRequest.php:26` (`user_id` validation = `['required','integer','exists:users,id']` — **no role check**)

**Verified deployment-pattern (advisor reconcile, grep confirmed) :**
- `KioskMachineRequest::rules()` line 26 accepts ANY existing user id. No filter for "must be a dedicated kiosk user" or "must NOT be an admin".
- `KioskMachineService::store` line 62-69 persists `'user_id' => $request->user_id` verbatim.
- Factory `database/factories/KioskMachineFactory.php:22` defaults to a fresh `UserFactory::new()` — so test fixtures are clean. But production admins who create a machine via the UI can (and typically do, for convenience) bind it to their own admin user_id since the dropdown shows all users.

So the assumption "admin and kiosk machine share user_id" is **a documented deployment trap, not a guaranteed condition** — it depends on the admin's choice when creating the machine. The P0 holds for deployments where this pattern was used and downgrades to P1-conditional for green-field deployments using dedicated kiosk users.

**Attack scenario :**
1. Admin (or staff with `permission:settings`) creates a kiosk machine in `Admin → Bornes` and selects their own user_id as the "linked user" (no UI warning, no policy preventing it).
2. Some time later, admin token leaks (phishing, dev local .env push, shared workstation cookie). Attacker now holds a `['*']` token belonging to a user that ALSO has a `KioskMachine` row with `user_id = $admin->id`.
3. `PaymentConfirmRequest::authorize`:
   - `$user !== null` → true.
   - `$hasKioskAbility = $user->tokenCan('kiosk:order')` → true (wildcard satisfies, see K-RED-P0-01).
   - `KioskMachine::query()->where('user_id', $user->id)->exists()` → true (the bound machine).
4. Attacker POSTs `/api/frontend/order/{id}/payment-confirm` with forged `transaction_id` + correct `amount_cents`. Order flips to PAID + fiscal_sequence allocated.

**Exploit complexity** : MEDIUM. Requires (a) the binding pattern actually present + (b) admin credential / cookie leak. Combined with K-RED-P0-01's wildcard token problem, the bar is one admin-credential leak.
**Business impact** : HIGH (when pattern is present) / MEDIUM (green-field). Order marked PAID without real money → free meals + NF525 fiscal alloc with attacker-chosen transaction_id polluting Z report. Receipt prints "Carte" but no money received.
**Mitigation** :
- `KioskMachineRequest` should validate `user_id` belongs to a user who has ONLY the `KioskMachine` role (no admin/staff roles).
- `PaymentConfirmRequest::authorize` should require the token NAME = `'kiosk-token'` (the name `KioskMachineLoginController.php:99` issues), not just the ability — admin tokens named `'auth_token'` would be rejected.
- Long-term: eradicate wildcard tokens (K-RED-P0-01) so admin `'auth_token'` doesn't satisfy `tokenCan('kiosk:order')` at all.

---

### P1 — High severity

---

#### **K-RED-P1-05 — Unauthenticated `/frontend/item/kiosk-upsell` leaks catalog state + DoS surface**

**Files** :
- `routes/api.php:1145-1153` (no `auth:sanctum`, only `installed + apiKey + localization`)
- `app/Http/Controllers/Frontend/ItemController.php:68-108`

**Attack scenario :** Anyone holding the `x-api-key` (which is leaked to every browser via `window.foodkingConfig.apiKey` per `bootstrap.js:240`) can:
1. POST/GET `/api/frontend/item/kiosk-upsell?item_ids=…&limit=12` at high frequency. No throttle, no auth.
2. Each call runs `Item::whereIn ... whereHas('category', ...) inRandomOrder()->take($limit)->get()` — `inRandomOrder()` on MySQL = `ORDER BY RAND()` full-table scan + filesort. Cheap individually, but at 1000 req/min from a single attacker IP, DB CPU sprouts.
3. Catalog enumeration (`item_ids=1` then `item_ids=2` ...) lets attacker reverse-engineer the `is_upsell` matrix per branch (no branch scope at all — `Item` has no BranchScope, single catalog confirmed).

**Exploit complexity** : LOW. Public api_key already known.
**Business impact** : MEDIUM. DoS via `ORDER BY RAND()` amplification; minor competitive intel leak.
**Mitigation** : Add `auth:sanctum` + `abilities:kiosk:order` + `throttle:60,1` to the route. Replace `inRandomOrder()` with deterministic LIMIT/OFFSET seeded per kiosk session.

---

#### **K-RED-P1-06 — Kiosk wizard hardcoded category IDs (`309/310/311/314`) — silent renumber breaks frites detection**

**File** : `resources/js/components/frontend/kiosk/KioskWizardComponent.vue:1029`

```js
const FRITES_INCLUDED_CATS = new Set([309, 310, 311, 314]);
```

**Attack scenario** : Not a direct exploit, but a **production-fragility-as-attack-surface** :
1. Admin reorders categories via `/admin/item-categories` (legitimate UX feature). On a fresh migration / reseed / restore, category PKs realign — sandwich category becomes ID 412.
2. The kiosk wizard silently mis-detects "frites included" — burgers now offer the menu step with a free frites included a second time, OR omit it entirely depending on direction of drift. Customer pays for €0 frites; restaurant loses €3.50/order × 200 orders/day = €700/day silent revenue leak.
3. This is **observable as systemic mis-pricing**, not an attack — but a competitor in the same multi-tenant could deliberately seed-import a category to shift PKs.

**Exploit complexity** : LOW (just trigger admin reorder).
**Business impact** : MEDIUM (silent revenue loss, customer disputes).
**Mitigation** : Replace magic IDs with backend-driven `category.includes_frites = true` flag exposed in `MenuController::kiosk` response.

---

#### **K-RED-P1-07 — Heuristic template fallback by string substring (`detectTemplateFromName`)**

**File** : `KioskWizardComponent.vue:907-947`

**Attack scenario :** Admin renames "Sandwich Cayenne XXL" → "XXL Cayenne" in the catalog. `name.includes('sandwich')` returns false. Wizard falls back to `'simple'` template — no viande/sauce/crudités steps. Customer pays full price but kitchen receives empty composition → 100% remake rate.
A malicious internal actor (disgruntled staff) renames items via admin to deliberately degrade kiosk UX (sabotage).
**Exploit complexity** : LOW (admin write access).
**Business impact** : MEDIUM (kitchen rework + customer waiting + bad reviews).
**Mitigation** : Backend `composer_profile` is already the SSOT (`KioskWizardComponent.vue:779,888`). The substring fallback should fail-closed (throw + show "produit indisponible" toast + log to `kiosk-event`), not silently use 'simple'.

---

#### **K-RED-P1-08 — Dine-in button visible at kiosk despite `pos_dine_in_enabled=false`**

**Files** :
- `resources/js/components/frontend/kiosk/KioskCartComponent.vue:89-110` (no feature-flag gating on the dine-in button)
- `app/Http/Requests/OrderRequest.php:195-203` (server-side rejection)

**Attack scenario :** UX-level confusion attack. Customer taps "Sur place" (dine-in icon), wizard accepts, but server rejects with HTTP 422 message "Dine-in is disabled in V1 — kiosk orders must use TAKEAWAY". Customer is stuck on payment screen with broken state. A persistent attacker could orchestrate denial-of-service by tapping dine-in N times on N kiosks at lunch rush — each generates a 422 with full request payload logged, polluting ActionLog + Sentry budget.
**Exploit complexity** : LOW.
**Business impact** : LOW-MEDIUM (UX breakage during rush + log pollution).
**Mitigation** : Frontend conditional `v-if="dineInAllowed"` reading `Settings::group('pos').pos_dine_in_enabled` flag (exposed via `MenuController::kiosk` or `SettingController::index`).

---

#### **K-RED-P1-09 — `finalizePaidKioskOrder` swallows post-commit exceptions → silent KDS desync**

**File** : `app/Http/Controllers/Frontend/OrderController.php:266-282`

**Attack scenario :** Backend post-commit pipeline includes `OrderCreated::dispatch` → 6 listeners (PersistOutbox, SendFCM, KDS broadcast). If FCM job throws (firebase project misconfigured, queue worker dies between commits), the try/catch line 270-282 logs but returns HTTP 200 to kiosk. **KDS doesn't get the order** because the OrderCreated listener chain was interrupted before the KDS broadcast listener fired (depends on listener order in EventServiceProvider — comment at line 280 claims PersistOutbox runs FIRST but no test proves it).

Result: kiosk customer holds receipt #42, kitchen has no idea. Customer waits 30 min then complains → owner finds order PAID + ACCEPTED but invisible. NF525 receipt is valid but operationally broken.

**Exploit complexity** : LOW (requires only one listener crash in production).
**Business impact** : HIGH (customer revolt + manual reconciliation).
**Mitigation** : The PersistOrderCreatedToOutbox MUST be the synchronous first listener (verified with a test that boots a broken FCM listener and asserts Outbox row present). Alternatively, split `finalizePaidKioskOrder` so KDS broadcast is a separate `dispatch(BroadcastToKds::class)` job that retries independently.

---

#### **K-RED-P1-10 — Idempotency key client-generated with `Math.random`-equivalent**

**File** : `resources/js/store/modules/kioskCart.js:704-708`

```js
idempotencyKey = ([1e7]+-1e3+-4e3+-8e3+-1e11).replace(/[018]/g, c =>
    (c ^ crypto.getRandomValues(new Uint8Array(1))[0] & 15 >> c / 4).toString(16));
```

**Attack scenario :** This template-string UUIDv4 generator uses `crypto.getRandomValues` — actually entropic. But the key persists in Vuex state and is only reset on `RESET` mutation (`kioskCart.js:417`), which fires on `inactivity` overlay timeout + `submitOrder` resolves.

Edge case: kiosk hits offline mode → `saveOrder(offlinePayload, idempotencyKey)` keeps the key for replay (line 783). If the kiosk crashes and reboots BEFORE replay sync triggers, the key persists in IndexedDB. On reconnect, the queued order is POSTed. **Meanwhile, the customer (thinking transaction failed) places another order via a different kiosk — same X-Idempotency-Key value if Vuex state is restored from localStorage**. Backend's idempotency hash includes `branch_id|key` — same branch + same key = silent dedupe → customer pays twice but only one order exists.

**Exploit complexity** : MEDIUM (requires kiosk crash mid-flow).
**Business impact** : MEDIUM (double-charge OR missing order).
**Mitigation** : Rotate idempotencyKey on each `submitOrder` call (line 705 should always regenerate), and bind key to a single attempt — server reject on duplicate `branch_id|key` with HTTP 409.

---

### P2 — Medium / hardening

---

#### **K-RED-P2-11 — `PAYMENT_BYPASS_MODE` only protects against `APP_ENV=production`**

**Files** :
- `config/payment.php:82-93` (`forbidden_environments = ['production','prod','live']`)
- `app/Providers/AppServiceProvider.php:85-92`

**Attack scenario :** Most fast-food kiosk deployments run a single APP_ENV (often `production`). But staging/preprod environments handling real partner data (e.g. integration testing with real Stripe sandbox + real customer profiles) run `APP_ENV=staging` — `forbidden_environments` doesn't catch this. An attacker who flips `PAYMENT_BYPASS_MODE=true` in staging (one .env edit) gets simulated TPE approval on all kiosk card flows. Receipts read "TPE-OK" but no money moved.
**Exploit complexity** : LOW (requires .env access).
**Business impact** : LOW in pure prod, HIGH in mixed-env setups.
**Mitigation** : Add `staging`, `preprod`, `uat`, `qa` to forbidden_environments list. Also gate by branch-level flag in DB, not env.

---

#### **K-RED-P2-12 — Kiosk Event payload `details` cap 500 chars permits log-injection**

**File** : `app/Http/Controllers/Frontend/KioskEventController.php:152-163, 238-250`

**Attack scenario :** `details` field accepts arbitrary 500-char string. Logged via `ActionLog::create([...,'details' => $details])` and to `Log::channel('hardware')` for hardware events. An attacker (with kiosk token from K-RED-P0-04) injects newlines + fake log lines in the `details` payload :

```
hardware OK\n2026-05-17 ERROR Database connection lost\nfake_op
```

Result : forensic log files contain fake timestamps and fake events. If the operator runs `grep ERROR kiosk.log | mail` for an incident, attacker plants distracting noise. Combined with `mb_substr` line 249 truncation, the attacker can chunk payloads to plant longer sequences across multiple events.

**Exploit complexity** : LOW.
**Business impact** : LOW (forensic-only — no direct $$).
**Mitigation** : Sanitize `details` via `strip_tags` + replace `\r\n` with literal `\n` markers. Add `\x00-\x1f` regex rejection.

---

#### **K-RED-P2-13 — Idempotency cache lock TTL=10s vs idempotency_key 64-char truncation**

**File** : `app/Services/FrontendOrderService.php:154-160, 251`

**Attack scenario :** `substr($idempotencyKey, 0, 64)` truncates to DB column max. Attacker generates two 70-char keys identical in first 64 chars but different in last 6 — `lockBranchId|sha1($key)` lock is unique per full hash, but DB row column gets truncated to 64-char prefix.

Net result : two orders, same DB idempotency_key, distinct sha1 hash → cache lock not deduped, DB column constraint violated. If `idempotency_key` has UNIQUE constraint → second order INSERT fails, error bubbles back as 422 confused customer. Without UNIQUE → silent double-write.

Verify: `frontend_orders.idempotency_key` column constraint — need to check migration. If no UNIQUE constraint, this is silent double-charge for collision-crafted keys.

**Exploit complexity** : LOW (just send long keys, varying suffix).
**Business impact** : LOW-MEDIUM (probabilistic, edge case).
**Mitigation** : Reject keys > 64 chars at FormRequest level. Add DB UNIQUE constraint if missing.

---

#### **K-RED-P2-14 — `KioskMachineLoginController` revokes only `name='kiosk-token'` — token sprawl across name variants**

**File** : `app/Http/Controllers/Auth/KioskMachineLoginController.php:96`

```php
$user->tokens()->where('name', 'kiosk-token')->delete();
```

**Attack scenario :** If any code path creates a token under a different name (e.g. a migration script, an admin "impersonate kiosk" feature), those tokens survive relogin. Same user, multiple active kiosk tokens, all with `['kiosk:order']`. If one device leaks, revocation via UI doesn't catch it.
**Exploit complexity** : LOW (search codebase, find alternate name).
**Business impact** : LOW (only relevant if alternate name exists; grep shows only `'kiosk-token'` in current codebase).
**Mitigation** : Revoke all tokens for that user where `abilities->contains('kiosk:order')` instead of name-matching.

---

## §3 — Duplications with other surfaces

### Three wizards, three sources of truth

| Surface | File | Lines | Template detection | Frites/menu rules |
|---------|------|-------|--------------------|-----|
| Kiosk Vue | `resources/js/components/frontend/kiosk/KioskWizardComponent.vue` | ~2700 | `detectTemplateFromName()` substring → falls back to magic cat IDs `[309,310,311,314]` | `FRITES_INCLUDED_CATS` set hardcoded |
| Mobile JSX | `mobile/screens-item-steps.jsx` | 1172 | `item.wizard_template \|\| cat.wizard_template \|\| 'simple'` (data-driven, no substring fallback) | Driven by `item.composer_profile.steps[]` + `has_frites_style` |
| POS Vanilla JS | `public/js/pos-wizard.js` | 5964 | FROZEN — separate state machine |

**Drift evidence** :

- Mobile (`mobile/data/menu.js:217-227`) uses category IDs **1-11** (Sandwich Cayenne=1, Frites=7); kiosk hardcodes **309/310/311/314**. These are different databases / fixtures — divergence guaranteed.
- Kiosk has `detectTemplateFromName` string-matching heuristic (`KioskWizardComponent.vue:907-947`) — mobile has zero heuristic.
- POS wizard (frozen, can't touch) has its own template detection unknown to kiosk team — three wizards = three rule-sets diverging silently.

**Attack** : Tester crafts an item that exhibits different wizards on each surface (e.g. "Sandwich Cayenne" renamed to "Cayenne Wrap"). Kiosk picks 'simple', mobile picks 'sandwich' (data-driven), POS picks based on its frozen rules. Same backend `composer_profile`, different UI flows, different prices possibly different `composition_snapshot`. Server-side `PricingService` reconciles to one truth but **the UI suggested choices the user never made** — customer disputes the receipt.

**Hardening** : Single composer rendering layer shipped as a NPM package consumed by both kiosk Vue and mobile JSX. POS wizard frozen but at least documented diff so drift is auditable.

---

## §4 — Failure modes the team ignores

1. **Pusher down + polling stuck** : The kiosk uses Pusher for branch.{id} events. If Pusher infrastructure is degraded (Soketi crash, network split), kiosk has NO polling fallback for OSS/KDS sync — `KioskWaitingComponent` will spin forever. The team's response is "operator must reboot". No graceful degradation.
2. **Sanctum token expired mid-order** : Kiosk TTL=480 min (`config sanctum.expiration`). A kiosk left idle 8h + 1 min returns 401 on the very first POST of the next customer. UI shows generic "auth error" toast and forces full kiosk relogin — customer abandons cart.
3. **Network drop mid-payment** : `finalizePaidKioskOrder` post-commit deliberately swallows exceptions (line 270-282) — payment IS persisted but order MAY NOT have been broadcast to KDS. Operator has zero visibility on this gap unless they grep `fiscal_alloc_error_at` or `[Kiosk Payment] finalizePaidKioskOrder side-effect failed`. No dashboard, no alert.
4. **Idempotency lock waiter** : `Cache::lock(...)->block(5)` waits 5s for a competing request. If the queue is busy (concurrent kiosk = 6 simultaneously processing the same idempotency key — possible via offline replay on multiple devices sharing browser profile), the 6th request waits 5s then throws LockTimeoutException. Backend returns 500 to kiosk → kiosk shows network error → customer re-presses pay → infinite loop.
5. **FRITES_INCLUDED_CATS reseed** : After DB restore from backup, category PKs realign. Kiosk silently mis-detects frites — see K-RED-P1-06.
6. **`branch_id=0` admin-bypass on Pusher channel** : Any user without an explicit branch (legacy guest, kiosk customer accidentally with `branch_id=NULL`) gets cross-tenant access. Defense-in-depth missing.
7. **Composer profile published mid-cart** : `tests/Feature/Composer/ProfilePublishMidCartRejectionTest.php` exists — but the kiosk frontend doesn't display a useful error. Customer sees generic "error", loses session contents.
8. **APP_ENV=staging with PAYMENT_BYPASS_MODE** : No guard. See K-RED-P2-11.

---

## §5 — Top 3 hardening recommendations (RED priority order)

### 1. **Eradicate Sanctum wildcard tokens (K-RED-P0-01 + P0-03)**

Replace every `createToken(name, ['*'], ...)` site (5 sites in `app/Http/Controllers/Auth/`) with role-scoped abilities. Customer = `['customer:order']`. Admin = `['admin']`. POS = `['pos']`. Kiosk = `['kiosk:order']` (already correct). Adjust all `tokenCan('kiosk:order')` gates accordingly (10+ sites) — and explicitly reject `'*'` in the gate logic. Then rewrite `routes/channels.php:32-35` to check `$user->hasRole('Admin')`, not `branch_id == 0`. **This single change closes K-RED-P0-01, P0-03 and partially P0-04. Highest ROI.**

### 2. **Close `OrderRequest::authorize` fail-open path (K-RED-P0-02)**

Replace `return true;` at line 62 of `OrderRequest.php` with an explicit allowlist: `return $user->hasRole('Admin') || $user->hasRole('KioskMachine');` — tests using `actingAs($user)` should set up the role fixture. No more "session-auth backdoor". Same pattern applies to `PaymentConfirmRequest::authorize` line 21 `runningUnitTests()` shortcut.

### 3. **Authenticate `/frontend/item/kiosk-upsell` + add rate limit + remove `inRandomOrder` (K-RED-P1-05)**

- Move the route inside the `auth:sanctum + abilities:kiosk:order` group.
- Add `throttle:30,1` middleware.
- Replace `inRandomOrder()` with a deterministic `orderBy('upsell_score','desc')->skip($seed % $count)->take($limit)` to eliminate the MySQL filesort.

---

## Files cited (all absolute paths)

- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/app/Http/Controllers/Auth/LoginController.php`
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/app/Http/Controllers/Auth/GuestSignupController.php`
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/app/Http/Controllers/Auth/ForgotPasswordController.php`
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/app/Http/Controllers/Auth/KioskMachineLoginController.php`
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/app/Http/Controllers/Auth/RefreshTokenController.php`
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/app/Http/Controllers/Frontend/OrderController.php`
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/app/Http/Controllers/Frontend/ItemController.php`
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/app/Http/Controllers/Frontend/KioskEventController.php`
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/app/Http/Controllers/Frontend/PaymentReconcileController.php`
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/app/Http/Requests/OrderRequest.php`
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/app/Http/Requests/Frontend/PaymentConfirmRequest.php`
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/app/Services/FrontendOrderService.php`
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/app/Services/Order/OrderQuoteService.php`
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/app/Services/Bypass/BypassAuditLogger.php`
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/app/Providers/RouteServiceProvider.php`
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/routes/api.php`
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/routes/channels.php`
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/config/payment.php`
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/resources/js/components/frontend/kiosk/KioskWizardComponent.vue`
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/resources/js/components/frontend/kiosk/KioskCartComponent.vue`
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/resources/js/components/frontend/kiosk/KioskPaymentComponent.vue`
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/resources/js/store/modules/kioskCart.js`
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/resources/js/bootstrap.js`
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/mobile/screens-item-steps.jsx`
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/mobile/data/menu.js`
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/public/js/pos-wizard.js` (FROZEN)
