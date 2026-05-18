# T-3.2.2 Pusher Channel Auth + Per-Branch Isolation — ARCHITECT Report — Round 3

## Verdict (one line): GO-CONDITIONAL on V1 — channel auth is functionally correct for the current production frontend, but the callback contains two latent isolation hazards (wildcard-ability short-circuit + missing `withoutGlobalScope`) and the admin-bypass branch is dead code that masks a future V2 escalation path

The branch-channel authorization SSOT is `routes/channels.php:25-39` (39 lines total in that file) gated by `Broadcast::routes(['prefix' => 'api', 'middleware' => ['auth:sanctum']])` declared in `app/Providers/BroadcastServiceProvider.php:22`. End-to-end, an authenticated Sanctum user POSTs to `/api/broadcasting/auth` with `channel_name=private-branch.{id}` and the callback returns `true|false`. Channel naming is consistent across layers — backend listeners persist `private-branch.{id}` (`PersistOrderCreatedToOutbox.php:43`, `PersistOrderStatusChangedToOutbox.php:52`, `PersistOrderPaymentStatusChangedToOutbox.php:59`, `PersistOrderTableChangedToOutbox.php:75`, `PersistCouponChangedToOutbox.php:73`, `PersistSettingsUpdatedToOutbox.php:68`, `PersistCatalogChangedToOutbox.php:83`, three `PersistItem*AvailabilityChangedToOutbox.php`), the channel definition uses the public name `branch.{branchId}` (Laravel automatically prepends `private-` on the wire for `Echo.private()` calls), and the frontend builds `Echo.private(\`branch.${branchId}\`)` in `resources/js/services/eventContract.js:337-338`. No wire-name mismatch exists. Defense-in-depth: client handlers re-check `payload.branch_id` against subscribed branch (`KioskAppComponent.vue:678,760`, `useCatalogChangeNotifier.js:294`) and drop mismatches with `reason: 'branch_mismatch'`. **Net**: an attacker who obtained a valid Sanctum token cannot subscribe to a foreign branch via the documented path, AND even if they could, the payload-level check would discard cross-branch events. The architectural risks below concern correctness of the callback under future changes and edge users, not exploitable cross-branch leak today.

## Top findings

### [P1 V2 / P2 V1] Wildcard-ability short-circuit denies admin WS subscription and is fragile

trigger:
  load_mode: "Login flow: `app/Http/Controllers/Auth/LoginController.php:111-115` mints `$user->createToken('auth_token', ['*'], …)` — wildcard abilities. `LoginController.php:111` is reached by every staff/admin login (any `branch_id`, including admin `branch_id=0`). The Sanctum implementation `vendor/laravel/sanctum/src/PersonalAccessToken.php` defines `can($ability)` as `return in_array('*', $this->abilities) || array_key_exists($ability, array_flip($this->abilities))` — meaning `$user->tokenCan('kiosk:order')` returns **TRUE** for any token created with `['*']`. The `routes/channels.php:27` callback checks `if ($user->currentAccessToken() && $user->tokenCan('kiosk:order'))` FIRST, then attempts `KioskMachine::where('user_id', $user->id)->first()` — an admin/staff user has no matching `kiosk_machines` row, so the branch returns `false` (line 29). The admin-bypass branch (lines 33-35) is **never reached** for any user whose token was minted by LoginController, ForgotPasswordController:165, or RefreshTokenController:49 (all three use `['*']` per grep). Today the production frontend does not subscribe admins to branch channels at all (`KitchenDisplaySystemComponent.vue:1778 if (branchId <= 0) return; // Admin: polling fallback`, OSS/POS analogous), so the latent denial is invisible."
  failure_mode: "Two regression paths. (a) **V1 latent**: any future frontend feature that attempts admin WebSocket subscription (e.g. multi-branch live dashboard, sync overview real-time tile) will fail silently — the auth endpoint will 403 the admin's `private-branch.{anyId}` subscription, and `pusher:subscription_error` will trip the F-12 sliding-window counter after 3 retries → admin's session is marked `SESSION_INVALID` by `WebSocketService:191`. This converts a UX bug into a forced relogin. (b) **V2 SaaS escalation**: if a SaaS deploy later adds a non-`['*']` token issuance path that mints a custom ability without `kiosk:order` but with cross-branch privilege (e.g. `tenant_admin:read`), the kiosk-machine short-circuit will not catch them, the admin-bypass at line 33 *will* fire, and any user with `branch_id=0` (which `SignupController.php:92` and `GuestSignupController.php:121` create for new customers!) becomes a cross-branch subscriber. `branch_id=0` is also assigned to every customer registered via SignupController/GuestSignupController — these are non-staff accounts that should never see branch fan-out. Combined with V2 SaaS where `branch_id=0` no longer means 'admin' but 'unassigned tenant', this becomes an explicit cross-tenant leak."

v2_saas_impact:
  blocks: "Multi-tenant SaaS rollout cannot ship with `branch_id=0 ⇒ admin` as the implicit invariant. The semantic overload of branch_id=0 (admin marker AND customer-default AND unassigned-fallback) was tolerable in single-tenant Le Cayenne; in V2 it is a cross-tenant disclosure primitive."
  enables: "Replacing the dual-check (`tokenCan(kiosk:order)` OR `branch_id===0`) with explicit ability-only auth (`tokenCan('admin:cross-branch')` for admin, `tokenCan('kiosk:order')` for kiosk, `tokenCan('staff:own-branch')` for staff) clarifies the SSOT and removes the wildcard ambiguity. Existing customer-side `branch_id=0` users lose channel access (they never had it in production anyway)."

cost_of_delay_if_v1_ships:
  customer: "Zero — no production frontend code path exercises admin WebSocket subscription today; admins poll exclusively per KDS/OSS code. End users observe no behaviour change."
  fiscal: "None. Channel auth is orthogonal to NF525 chain integrity — outbox dispatch (`DispatchDomainEventsJob.php:116`) broadcasts irrespective of who subscribes."
  business: "Adds friction to future admin live dashboards. Engineer onboarding cost ~2-4h debugging 'why does admin not receive WS events' before grepping LoginController and finding the `['*']` ⇒ short-circuit trap."

recommendation:
  scope: "**Phase 1 (V1.0.2 hardening)**: Add `_disambiguate_kiosk_token` helper inside `routes/channels.php` that checks `$token->name === 'kiosk-token'` (Sanctum stores the token name; `KioskMachineLoginController.php:99` uses 'kiosk-token', LoginController uses 'auth_token'). Replace `$user->tokenCan('kiosk:order')` with `$token && $token->name === 'kiosk-token'`. This decouples the kiosk branch from wildcard expansion. **Phase 2 (V2 SaaS prep)**: Mint LoginController tokens with explicit non-wildcard abilities (`['staff:own-branch']` for branch staff, `['admin:cross-branch']` for admins) and rewrite the channel callback to gate on these abilities instead of `branch_id` zero-check. Effort: Phase 1 ~30 lines + 2 unit tests = ~2h. Phase 2 spans LoginController + channel callback + every controller that uses `auth:sanctum` (~88 endpoints per CLAUDE.md §9 roadmap)."
  rollback: "Phase 1 is additive — the helper preserves existing behaviour for kiosk tokens and only tightens admin/staff token rejection (which is already the production behaviour). Phase 2 is V2-only."
  owner_gate: "N for Phase 1 (routes/channels.php is not in CLAUDE.md §7 frozen list, but is security-sensitive — recommend owner sign-off and `tests/Feature/Broadcasting/ChannelAuthorizationTest.php` regression test covering all 4 callsite combinations: admin × kiosk-channel, staff × own-branch, staff × other-branch, kiosk × machine-branch). Y for Phase 2 (touches 88 controllers — full LOCK + owner sign-off mandatory)."

### [P2] `KioskMachine::where('user_id', …)->first()` in `routes/channels.php:28` does NOT call `withoutGlobalScope(BranchScope::class)` — inconsistent with documented defense-in-depth pattern

trigger:
  load_mode: "`routes/channels.php:28` does `\\App\\Models\\KioskMachine::where('user_id', $user->id)->first()` inside the auth callback. The KioskMachine model attaches `BranchScope` as a global scope (`app/Models/KioskMachine.php:39`). `BranchScope::apply` (`app/Models/Scopes/BranchScope.php:21-23`) bails early for the User model only; KioskMachine queries are filtered by `$user->branch_id` when `Auth::check()=true` AND user is not admin. Channel-auth callback runs under `auth:sanctum` middleware (`BroadcastServiceProvider.php:22`), so `Auth::check()=true`. Comparable callsites in the codebase use `withoutGlobalScope(BranchScope::class)` explicitly with intent-marking comments: `KioskMachineLoginController.php:55,90` ('PRE-AUTH lookup: scope explicitly bypassed'), `EnsureKioskMachineCommand.php:80`, `IdempotencyKeyMiddleware.php:207`. `channels.php` is the only post-auth callsite that does **not** use it."
  failure_mode: "Today the kiosk's linked User is `User::find(1)` (admin user, `branch_id=0`) per `EnsureKioskMachineCommand.php:53` — admin bypasses BranchScope (`BranchScope.php:33-36`), so the query returns the kiosk machine row correctly. **But** the CLI command accepts `--user-id=N` (`EnsureKioskMachineCommand.php:26`) to link a kiosk to an arbitrary user. If an operator provisions a kiosk against a staff user with `branch_id=5`, then the kiosk session's `Auth::user()->branch_id=5`, and `KioskMachine::where('user_id', $user->id)->first()` becomes `… WHERE branch_id=5 …`. The KioskMachine row's `branch_id` field MUST equal 5 for the lookup to succeed. If a kiosk was provisioned in branch 5 then later reassigned to branch 7 (UPDATE kiosk_machines SET branch_id=7), the channel auth lookup returns NULL → kiosk loses WebSocket access on next reconnect, polls only. Diagnostic surface is poor (silent 403 + F-12 SESSION_INVALID after 3 retries) and the misconfigured operator has no log signal pointing at the scope filter."
  v2_saas_impact: "**V2 critical**: SaaS migrations between tenants will trigger this exact branch-reassignment scenario. A kiosk that follows a franchise rebrand from `branch_id=5 → branch_id=12` loses WS access without operator visibility."

v2_saas_impact:
  blocks: "Tenant-migration tooling cannot reliably move kiosk machines across branches without manually re-issuing tokens (current flow assumes the kiosk's token survives a `branch_id` rewrite, but channel auth silently breaks)."
  enables: "Adding `withoutGlobalScope(BranchScope::class)` makes the lookup self-contained, mirrors the rest of the codebase, and removes one class of tenant-migration surprises."

cost_of_delay_if_v1_ships:
  customer: "None today — production deploys all use `--user-id` default (admin user, branch_id=0)."
  fiscal: "None — channel auth is orthogonal to fiscal chain."
  business: "Operator confusion if `--user-id` is ever used with a staff user. Latent time-bomb at first tenant migration in V2."

recommendation:
  scope: "Single-line patch: `KioskMachine::withoutGlobalScope(\\App\\Models\\Scopes\\BranchScope::class)->where('user_id', $user->id)->first()`. Add a comment matching the pattern in KioskMachineLoginController:48-54. Add a regression test `tests/Feature/Broadcasting/ChannelAuthorizationTest.php` that provisions a staff-linked kiosk on branch 5, reassigns the kiosk to branch 7, and asserts the channel auth still resolves correctly. Effort: ~10 lines + 1 test = 30 min."
  rollback: "Trivial — git revert one line. Behaviour is strictly broader (no functional regression possible, only fewer false-NULL lookups)."
  owner_gate: "N — `routes/channels.php` is not in the frozen list and the change is intent-marking + defense-in-depth alignment."

### [P2] Cross-references R1 SRE-001 + R2 T-3.2.1 ARCH-03 — channel-auth layer is correct, asymmetry lives at handler subscription matrix

trigger:
  load_mode: "R1 wave-W6-T-3.1.1-sre.md flagged `ws:heartbeat` cache key read by `SyncOverviewController.php:531` is never written in current branch (`grep Cache::put.*ws:heartbeat` returns only worktree `.claude/worktrees/blissful-mclean-c915c2/app/Jobs/DispatchDomainEventsJob.php:104` — not in main). R2 T-3.2.1 ARCH-03 flagged that only KDS subscribes `OrderTableChanged`. **Cross-check at the channel-auth layer**: both observations are orthogonal to T-3.2.2 — `routes/channels.php` is symmetric (any authorized user can subscribe to their branch's channel and receive any event broadcast there). The asymmetry is purely client-side (`KitchenDisplaySystemComponent.vue:1782-1810` lists 4 event types; `KioskWaitingComponent.vue:222`, `PreparingAndReadyComponent.vue:274` list 1 type each). The auth surface does not enforce per-event-type filtering, which is correct — fan-out filtering is the subscriber's responsibility."
  failure_mode: "R1 SRE-001 ws:heartbeat absence is unrelated to channel auth — the heartbeat is a *broadcaster-uptime* signal consumed by SyncOverviewController, not a per-subscription liveness signal. R2 ARCH-03 asymmetric subscription matrix means an OSS terminal that should receive an `OrderTableChanged` event (table reassignment) will not — but the channel auth would have allowed it, and the broadcast envelope is bit-identical for all branch subscribers. **The channel-auth layer is not the place to fix either finding** — heartbeat needs a Pusher webhook handler (per `PR_PACKAGE_3_SYNC.md:56-62`), and the subscription matrix is a per-surface event-binding decision."
  v2_saas_impact: "Both findings escalate in V2 but neither is fixable in `routes/channels.php`. Calling out the architectural separation now prevents future drift where someone tries to fix R2 ARCH-03 by adding per-event filtering at auth time."

v2_saas_impact:
  blocks: "Nothing at the channel-auth layer."
  enables: "Clear architectural separation: channel auth = membership; payload contract = event-type validation; handler binding = subscription matrix. Three concerns, three layers — preserve the separation."

cost_of_delay_if_v1_ships:
  customer: "None."
  fiscal: "None."
  business: "None — clarifying note for the synthesis layer."

recommendation:
  scope: "Document the three-layer separation in the synthesis report (channel auth ≠ event contract ≠ subscription matrix). No code change at the channel-auth layer."
  rollback: "N/A."
  owner_gate: "N — documentation."

## Coverage map

**8 questions × answers**:

1. **Channel auth SSOT**: `routes/channels.php:25-39` callback, gated by `Broadcast::routes(['prefix' => 'api', 'middleware' => ['auth:sanctum']])` (`BroadcastServiceProvider.php:22`). Authentication identity = Sanctum Bearer token resolved by auth:sanctum middleware. No JWT layer.
2. **Per-branch isolation**: Yes for staff (`routes/channels.php:38` returns `(int) $user->branch_id === (int) $branchId`). User from branch A subscribing `private-branch.{B}` → callback returns `false` → Laravel returns HTTP 403 → `pusher:subscription_error` → F-12 counter (`WebSocketService.js:174-193`) → SESSION_INVALID after 3 failures.
3. **Admin cross-branch**: Lines 33-35 grant `branch_id===0` users unconditional access — BUT lines 27-30 short-circuit FIRST and admins' `['*']` tokens satisfy `tokenCan('kiosk:order')`, so admin path is effectively dead code today. Today this is invisible because frontend admin code (`KitchenDisplaySystemComponent.vue:1778`) skips WS subscription entirely (`branchId <= 0 return`).
4. **Kiosk Sanctum kiosk:order + channel auth**: Lines 27-30 — kiosk token holders are restricted to their `KioskMachine.branch_id`. Verified token-can pattern: `tokenCan('kiosk:order')` + `KioskMachine::where('user_id')->first()` + branch equality.
5. **JWT vs Sanctum mismatch**: No JWT layer in this codebase. Single identity source = Sanctum. `Broadcast::routes` middleware = `auth:sanctum`. `currentAccessToken()` in channel callback resolves the same `PersonalAccessToken` row used by controllers. **No identity-provider split.**
6. **WebSocket connection lifecycle**: Pusher SDK auto-reconnects on connection drop. The auth endpoint is re-hit on every fresh subscription (each `Echo.private()` call after `Echo.leave()` triggers a new POST `/api/broadcasting/auth`). Token refresh wired via `window._refreshEchoAuth` from `bootstrap.js:248-253`, called by `auth.js:153` post-login and `kioskCart.js:386` post-kiosk-login, and reactively on `pusher:subscription_error` (`bootstrap.js:266`). Session persists across reconnects iff the Bearer token has not been revoked.
7. **Multi-device same-branch**: Both terminals POST `/api/broadcasting/auth` with same `branch.{id}`; callback returns `true` for both; fan-out is Pusher-side; both receive. **Verified by symmetry of channel-auth callback** — no per-device cap.
8. **Presence channels**: None used in production (`routes/channels.php` defines zero presence channels; `Echo.join` rewrap in `bootstrap.js:284-287` is defensive dead code). No 'who is online' feature exists.

## Coverage matrix

| Question | Verdict | Anchor |
|---|---|---|
| Q1 SSOT | OK | `routes/channels.php:25` + `BroadcastServiceProvider.php:22` |
| Q2 Per-branch isolation | OK | `routes/channels.php:38` |
| Q3 Admin cross-branch | **DEAD CODE** | `routes/channels.php:33-35` — unreachable |
| Q4 Kiosk + branch | OK with P2 scope-bypass nit | `routes/channels.php:27-30` |
| Q5 JWT/Sanctum mismatch | None — single Sanctum SSOT | `BroadcastServiceProvider.php:22` |
| Q6 WS lifecycle | OK | `bootstrap.js:248-253`, `auth.js:153`, `kioskCart.js:386` |
| Q7 Multi-device fan-out | OK | Pusher-side fan-out, callback symmetric |
| Q8 Presence channels | NONE | `routes/channels.php` defines none |

## What this report does NOT cover (delegated)

- **Heartbeat write** (R1 SRE-001): not at channel-auth layer, delegated to SRE specialist (`PR_PACKAGE_3_SYNC.md:56-62` already proposes the heal).
- **Asymmetric subscription matrix** (R2 ARCH-03): client-side event-binding decision, delegated to per-surface UX/handler audit.
- **Payload contract / BROADCAST_MAP coverage** (R1 T-3.1.1): event-contract layer, delegated to T-3.2.1 architect (Round 2).
- **`auth_token` token sprawl** (Sprint 5D Z6-01 closed): historical, verified mitigated at `LoginController.php:109`.
