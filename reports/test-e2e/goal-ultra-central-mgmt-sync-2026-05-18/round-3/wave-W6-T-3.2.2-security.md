# T-3.2.2 — Pusher channel cross-branch subscription attack: SECURITY audit (Round 3)

**Specialist**: SECURITY
**Scope**: read-only, hostile mindset, end-to-end attack chains
**Target task**: T-3.2.2 — Pusher branch channel authorization
**Date**: 2026-05-18
**Branch**: v1-0-1-hardening-2026-05-17

---

## 0. Threat model recap

Attacker capabilities:
- **Branch-A staff**: valid Sanctum admin token (`auth_token`, abilities `['*']`), `branch_id = A > 0`.
- **Authenticated guest/customer**: `kiosk:order` token via `GuestSignupController`; user row has `branch_id=0`. Registered customers via `SignupController` also have `branch_id=0`.
- **External unauth**: knows `/api/broadcasting/auth` URL + Pusher app key (Pusher key is intentionally public; ships in the JS bundle).
- **Compromised origin** (XSS on trusted host) able to replay a captured Bearer token.

Goals: subscribe to `private-branch.{B}` for B ≠ own branch → eavesdrop on `OrderCreated`/`OrderStatusChanged`/`SettingsUpdated` events of foreign branch (cross-tenant leak).

---

## 1. Inventory: broadcast channels + auth gates

Source `routes/channels.php` — only 2 channels registered (verified by `grep -rn "Broadcast::channel" routes/ app/`):

| # | Pattern | Auth callback | Notes |
|---|---|---|---|
| 1 | `App.Models.User.{id}` | `$user->id === $id` | Strict. Notifications. OK. |
| 2 | `branch.{branchId}` | tri-branch (kiosk / admin-zero / staff-own) | **All findings** |

No `PresenceChannel` registered (`grep PresenceChannel` = 0 hits) → no presence-channel PII leak surface. No public `Channel` for sensitive events: all order/catalog/settings outbox rows broadcast on `['private-branch.' . $branch_id]` (`PersistOrderCreatedToOutbox.php:43`, `PersistOrderStatusChangedToOutbox.php:52`, `PersistSettingsUpdatedToOutbox.php:19`). Good.

Frontend subscription happens at `resources/js/services/eventContract.js:337-338`: `Echo.private('branch.${branchId}')`. `branchId` is **client-controlled** — fine *iff* the server gate is strict.

Auth endpoint: `POST /api/broadcasting/auth` registered with `auth:sanctum` middleware only (`BroadcastServiceProvider.php:22`). CSRF excluded (api prefix). No explicit Origin gate.

---

## 2. The vulnerable code

```php
// routes/channels.php:25
Broadcast::channel('branch.{branchId}', function ($user, $branchId) {
    if ($user->currentAccessToken() && $user->tokenCan('kiosk:order')) {
        $machine = KioskMachine::where('user_id', $user->id)->first();
        return $machine && (int) $machine->branch_id === (int) $branchId;
    }
    if ((int) $user->branch_id === 0) { return true; }       // admin escape
    return (int) $user->branch_id === (int) $branchId;        // staff own
});
```

Intent: kiosk → machine's branch; admin → all; staff → own. Reality broken by **Sanctum `'*'` wildcard** (`vendor/laravel/sanctum/src/PersonalAccessToken.php:79-80`): `can()` returns true if `'*' ∈ abilities` OR ability present. So any `auth_token` issued with `['*']` (every admin login at `LoginController.php:113`) returns **true** for `tokenCan('kiosk:order')` and routes admins/staff through the kiosk branch first.

---

## 3. Findings

### F-SEC-W6-01 — **P0** — Branch-Staff-Channel-Crawl (incidental denial masking fragile gate)

**Chain on `branch_id=A>0` staff token**:
1. Abilities `['*']` → `tokenCan('kiosk:order')` true under wildcard.
2. Callback enters kiosk branch (line 27).
3. `KioskMachine::where('user_id', $user->id)` returns null (staff users have no machine row).
4. Callback returns false. Admin-zero branch (line 33) **unreachable**.

Today this denies cross-branch eavesdrop — but also denies legitimate staff their **own** branch channel. KDS at Branch A, authenticated with `auth_token`, gets 403 on `private-branch.A`; real-time push is silently broken for every non-admin staff, masked by polling fallback (`config/broadcasting.php:31-35`).

**Why P0**: the gate's correctness is **incidental** — it relies on the `&& $machine` shortcut, not on a proper distinguisher between kiosk-machine tokens and admin tokens. A one-line "fix admin doesn't get real-time" patch that removes `&& $machine` or replaces it with `return true` flips the gate from too-strict to too-loose. The trap is the only thing holding the line, and it's brittle. The same callback would need only one mutation (e.g., changing `tokenCan('kiosk:order')` to skip the kiosk branch for `['*']` tokens) to grant arbitrary cross-branch access to every admin/staff/customer.

**Mitigation**: replace `tokenCan('kiosk:order')` with strict token-name check — `$user->currentAccessToken()?->name === 'kiosk-token'`. Token names are server-set at create (`KioskMachineLoginController.php:99`) and unspoofable. Equivalent: assert `array_diff($token->abilities, ['kiosk:order']) === []`.

---

### F-SEC-W6-02 — **P0** — Guest-Echo-Bypass (latent: customer `branch_id=0` → admin escape)

`GuestSignupController.php:121` creates guest users with `branch_id=0`; line 146 issues a `['kiosk:order']` token. `SignupController.php:92` does the same for registered customers, who then receive `['*']` tokens via `LoginController.php:113` on subsequent login.

**Chain (guest)**:
1. Abilities `['kiosk:order']`, `branch_id=0`, no `KioskMachine` row.
2. Kiosk branch: `$machine === null` → returns false.
3. Admin-zero branch unreachable.
4. Today: denied. Same trap as F-SEC-W6-01.

**Chain (registered customer logged via /login)**:
1. Abilities `['*']`, `branch_id=0`, no `KioskMachine` row.
2. Kiosk branch (wildcard): `$machine === null` → false.
3. Admin-zero unreachable.
4. Denied today.

**Why this is P0 prospectively**: any one of three trivial refactors makes guests/customers eavesdrop on every branch:
- (a) reorder: move `branch_id===0 → true` above the kiosk branch.
- (b) "feature": let guest customers see their order push → remove `&& $machine`.
- (c) someone seeds a `KioskMachine` row pointed at a non-staff user (CI/staging/test) → that user receives full cross-branch grant.

Additionally, **the admin-zero clause itself (line 33) cannot distinguish a real admin from a customer-with-branch_id=0**. CLAUDE.md §9 ("Branch Isolation must never be weakened") is enforced by BranchScope using role checks (admin role bypass); channel layer uses only the integer sentinel. Non-uniform — gate is weaker than the data layer.

**Mitigation**: in the admin-zero clause, also require `$user->hasAnyRole([EnumRole::ADMIN, EnumRole::MANAGER])` and deny when `$user->is_guest === Ask::YES` or `hasRole(EnumRole::CUSTOMER)`.

---

### F-SEC-W6-03 — **P1** — Cross-Site-WebSocket-Auth (no Origin gate on `/api/broadcasting/auth`)

`BroadcastServiceProvider.php:22` registers the auth endpoint under `auth:sanctum` only — no CSRF (api prefix), no Origin/Sec-Fetch-Site enforcement. CORS at `config/cors.php:6-10` allows `[APP_URL, KIOSK_DOMAIN, ADMIN_DOMAIN]` with `supports_credentials: true`, which blocks browser-mediated cross-origin reads but **does not block server-side replay** of a leaked Bearer (no Origin header).

**Chain**: XSS on a trusted origin (APP_URL) → attacker JS reads localStorage Bearer (`bootstrap.js:206-216`) → POSTs to `/api/broadcasting/auth` from any host with `Authorization: Bearer <token>` → receives Pusher subscription signature for any channel the victim is authorized for. Combined with F-SEC-W6-02 if that path opens, this is the propagator.

**Mitigation**: append an Origin allowlist middleware on the broadcasting auth route (parity with `EnsureFrontendRequestsAreStateful` cookie-path checks).

---

### F-SEC-W6-04 — **P2** — Stale-Sub-After-Revoke (no active disconnect on token revocation)

`LoginController.php:108-109` revokes prior `auth_token` rows on relogin from another device. Pusher signs the subscription auth once at subscribe time. After revocation, the **already-open WebSocket** keeps receiving events until idle timeout. `bootstrap.js:234-235` sets `activityTimeout: 30000` + `pongTimeout: 5000` → ≤ 35s window for an ex-employee/lost device. No Pusher webhook handler exists (`grep -rn "pusher/webhook\|webhook_secret" app/` = 0 hits) → server has no `Pusher::trigger_force_disconnect` path.

**Mitigation**: on token revoke, write `cache::put('revoked_token:{id}', 1, ttl)` + Pusher webhook to disconnect. Or shorten activityTimeout to 5–10s on sensitive surfaces.

---

### F-SEC-W6-05 — **P3** — Receipt-Token-on-Wire (`order.token` in OrderStatusChanged payload)

`PersistOrderStatusChangedToOutbox.php:50` broadcasts `'token' => $order->token ?? null` in the channel payload. Public OSS surface (`/order-status-screen/?token=…`) looks orders up by this token. Today subscribers are branch-staff (after F-SEC-W6-01/02 fixes), so cross-customer leakage to staff is acceptable. **But** if a guest customer ever gains channel access (F-SEC-W6-02 future-state), they harvest every receipt token and impersonate any order at OSS.

**Mitigation**: drop `token` from broadcast payload; staff who need it can re-fetch with auth.

---

### F-SEC-W6-06 — informational — Pusher app KEY in JS bundle (not a finding)

`bootstrap.js:222` inlines `process.env.MIX_PUSHER_APP_KEY` via Mix. Per Pusher's threat model the `key` is intentionally public. Grep of `resources/js/` shows zero `MIX_PUSHER_APP_SECRET` references — server-side `config/broadcasting.php:53` reads `env('PUSHER_APP_SECRET')` only, never re-exported. Flagged for reviewer completeness so no phantom P0 is re-opened in later rounds.

---

## 4. Cross-validation vs Round 1+2

Round 1 wave-W4-T-2.1.1 (BranchScope multi-tenant) and Round 2 wave-W7-T-3.3.1 (webhook idempotency) did not cover channel auth — this audit closes a gap. NF525 invariants untouched (no `fiscal_sequence_no`/`audit_log_hash`/`z_report_*` in broadcast payloads — verified by grep of all `app/Listeners/Persist*ToOutbox.php`).

Critical non-uniformity: BranchScope's `branch_id=0` admin bypass is gated by role membership in production paths (`OrderService.php:2366`, `TransactionService.php:99`, etc. all combine `branch_id===0` with role context); channels.php uses only the integer sentinel. Same pattern, weaker enforcement.

---

## 5. Verdict

**BLOCK** for V1 production on PR-CENTRAL until F-SEC-W6-01 + F-SEC-W6-02 are resolved.

The current channels.php is **observably broken** for legitimate staff (incidental kiosk-branch trap returns false for non-kiosk `['*']` tokens) AND is **one trivial refactor away** from arbitrary cross-branch eavesdrop for every guest and customer (every guest/customer user row has `branch_id=0` and the admin-zero clause grants any branch). The gate's correctness today is incidental; CLAUDE.md §9 requires it be structural.

Pre-merge P0:
1. Strict kiosk distinguisher (token name `kiosk-token`, not ability-wildcard inclusion).
2. Admin-zero clause guarded by `hasRole(ADMIN|MANAGER)` and denied when `is_guest=YES`.

P1: Origin allowlist on `/api/broadcasting/auth`.
P2/P3: revocation-aware Pusher disconnect; drop `token` from `OrderStatusChanged` payload.

---

## 6. Anchors (file:line)

- `routes/channels.php:25-39` — vulnerable callback.
- `app/Providers/BroadcastServiceProvider.php:22` — auth route registration.
- `app/Http/Controllers/Auth/LoginController.php:113` — admin token `['*']`.
- `app/Http/Controllers/Auth/KioskMachineLoginController.php:98-102` — kiosk token name `kiosk-token`, abilities `['kiosk:order']`.
- `app/Http/Controllers/Auth/GuestSignupController.php:121,146` — guest `branch_id=0` + `kiosk:order`.
- `app/Http/Controllers/Auth/SignupController.php:92` — registered customer `branch_id=0`.
- `vendor/laravel/sanctum/src/PersonalAccessToken.php:77-81` — `can()` wildcard.
- `app/Models/KioskMachine.php:38` — BranchScope on KioskMachine.
- `app/Listeners/PersistOrderCreatedToOutbox.php:31-47` — broadcast payload + channel name.
- `app/Listeners/PersistOrderStatusChangedToOutbox.php:41-56` — payload incl. `token`.
- `resources/js/bootstrap.js:201-243` — Echo init + Bearer auth.
- `resources/js/services/eventContract.js:330-338` — `Echo.private(branch.{id})`.
- `config/cors.php:6-10` — allowed_origins.
- `config/broadcasting.php:48-65` — pusher driver.

— end —
