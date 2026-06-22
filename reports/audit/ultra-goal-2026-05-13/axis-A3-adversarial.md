# FoodKing Ultra Goal 2026-05-13 — Axis A3 ADVERSARIAL Red-Team Audit
## Sync / Outbox / Pusher — Hostile Challenge of Primary SRE Round 1

**Date** : 2026-05-13
**Agent** : Adversarial Red-Team Sub-agent (hostile auditor)
**Branch** : `feature/mobile-app-le-cayenne-2026-05-10`
**Primary Audit** : `reports/audit/ultra-goal-2026-05-13/axis-A3-sre-round1.md`
**Mandate** : Challenge every finding with primary-source evidence. No reverence.

---

## Executive Summary — Verdict on Primary

| Verdict | Count | Notes |
|---------|-------|-------|
| Confirmed (primary correct) | 8 | DispatchDomainEventsJob retry curve, idempotency 10/16, KDS adaptive polling, observability dashboard wiring, ItemDeleted listener wiring, DomainEvent scopes/indexes, webhook_events schema, channels.php Sanctum gate |
| FALSE POSITIVE (primary fabricated bug) | 1 | A3.4 channel route `branch.{id}` vs `private-branch.{id}` — Laravel auto-strips prefix |
| Wrong diagnosis, right fix | 1 | F1 axios path — production code works due to `axios.defaults.baseURL='/api'`; tests fail because they bypass baseURL; primary's fix is correct but ROOT CAUSE statement (HTTP 404) is wrong |
| Confirmed but UNDERCOUNTED severity | 1 | "P1 MITIGATED" Branch.status filter is actually **P0 SILENT NO-BROADCAST**; primary's mitigation claim ("explicit branchId=1 in menu reset") is **empirically false** (`new CategoryCreated($cat->id, null)`) |
| FALSE PASS (primary said OK, actually broken) | 1 | "Webhook Events Table (SenangPay Idempotency)" — table exists but ZERO production code uses it (SenangPay is 501 stub, Stripe has no `webhook()` method) |
| Confirmed (F2) | 1 | KdsSyncService swallow vs test expectation — but BOTH were created in the same commit; the test was never green |

**Revised Primary Score** : `45/100` (primary claimed 65, but 1 fabricated P1 + 1 undercounted P0 + 1 false PASS shift the calibration down materially).

---

## DISPUTE 1 — A3.4 Channel Route Naming Mismatch is a FALSE POSITIVE

### Primary's claim (sre-round1.md §5 + Q3 + §JSON p1)

> **CRITICAL BUG FOUND** : Channel route `branch.{id}` does NOT match domain_events channel `private-branch.{id}`. Subscribers on `private-branch.1` will be rejected by Pusher unless the route is fixed to `private-branch.{id}`.
> **Fix Required** : Change `routes/channels.php:25` from `Broadcast::channel('branch.{branchId}', ...)` to `Broadcast::channel('private-branch.{branchId}', ...)`.

### Adversarial verdict : **FABRICATED**. Primary does not understand Laravel/Pusher channel-naming convention.

### Evidence

**Source 1 — Laravel framework trait** `vendor/laravel/framework/src/Illuminate/Broadcasting/Broadcasters/UsePusherChannelConventions.php:26-35` :

```php
public function normalizeChannelName($channel)
{
    foreach (['private-encrypted-', 'private-', 'presence-'] as $prefix) {
        if (Str::startsWith($channel, $prefix)) {
            return Str::replaceFirst($prefix, '', $channel);
        }
    }
    return $channel;
}
```

Laravel's PusherBroadcaster `normalizeChannelName('private-branch.1')` → `'branch.1'` BEFORE pattern matching. The route registration `Broadcast::channel('branch.{branchId}', ...)` is the CORRECT and CANONICAL Laravel idiom.

**Source 2 — Client subscription path** `resources/js/services/eventContract.js:337-338` :

```javascript
const channelName = `branch.${branchId}`;
const channel = window.Echo.private(channelName);
```

`Echo.private(name)` prepends `private-` on the wire automatically. Frontend already uses the right convention.

**Source 3 — Server broadcast path** `app/Jobs/DispatchDomainEventsJob.php:101,116` :

```php
$channels = json_decode($domainEvent->channel, true);  // ['private-branch.1']
$broadcaster->broadcast($channels, $domainEvent->broadcast_as, $envelope);
```

`BroadcastManager->connection()->broadcast()` is fed the FULL wire-level channel names. This is what the Pusher SDK expects.

**Verification** : If the primary's fix were applied (`'private-branch.{branchId}'` in channels.php), Laravel's `channelNameMatchesPattern()` would compile regex `^private\-branch\.([^\.]+)$` and then attempt to match against `normalizeChannelName('private-branch.1') = 'branch.1'` → **ZERO MATCH**. The primary's proposed "fix" would BREAK production authorization. This is a regression-inducing patch.

### Action

- **REJECT** P1 A3.4 from the executor's queue.
- File `routes/channels.php` is correct as-is.
- Open question for primary : if pre-audit Pusher subscriptions were observed authorizing successfully in any environment, the primary's "bug" cannot exist by construction.

---

## DISPUTE 2 — F1 axios prefix : Right fix, wrong diagnosis

### Primary's claim (sre-round1.md §F1)

> **Impact** : HTTP request routed to `/admin/observability/outbox` instead of `/api/admin/observability/outbox` → 404 or misrouted (likely to a view route instead of API controller)

### Adversarial verdict : **PARTIALLY FALSE**. The component WORKS in production. The TESTS fail because they assert the literal string passed to the mocked axios spy, which bypasses the baseURL prepending.

### Evidence

**Source 1 — Global axios baseURL** `resources/js/shared/axios-setup.js:74` :

```javascript
export function applySharedAxiosDefaults(axios, store) {
    const API_URL = ENV.API_URL;
    axios.defaults.baseURL = API_URL + '/api';
    ...
}
```

In production, `axios.get('admin/observability/outbox')` resolves to `${API_URL}/api/admin/observability/outbox` — exactly what `routes/api.php:1013` registers (`/api/observability/outbox` under the admin group).

**Source 2 — Test mock bypasses baseURL** `tests/js/observabilityOutboxRoute.spec.js:96-101` :

```javascript
const fakeAxios = {
    get: vi.fn().mockResolvedValue({ data: {} }),
    post: vi.fn().mockResolvedValue({ data: {} }),
};
globalThis.axios = fakeAxios;
```

The mock has no baseURL behaviour. The spy records the literal argument, which is `'admin/observability/outbox'`. Test assertion `expect(fakeAxios.get).toHaveBeenCalledWith('/api/admin/observability/outbox')` fails not because production is broken, but because the test contract is "the component code SHOULD spell out the `/api/` prefix even though baseURL would supply it".

**Source 3 — The other tests already enforce the literal-string contract** `tests/js/observabilityOutboxRoute.spec.js:79-80` :

```javascript
expect(content).toContain('/api/admin/observability/outbox/retry-failed');
expect(content).toContain('/api/admin/observability/outbox/drain-failed');
```

These assertions are textual `grep` over the .vue file. The current code (`axios.post('admin/observability/outbox/retry-failed')`) FAILS those too. So all three axios calls in the component need the `/api/` literal.

### Vitest confirmation — `npx vitest run tests/js/observabilityOutboxRoute.spec.js`

```
1st spy call: ["admin/observability/outbox"]
Expected: ["/api/admin/observability/outbox"]
1 failed | 5 passed
```

Test was empirically run during this audit. Failure replicates.

### Verdict on F1

- **The fix is correct** (prepend `/api/` to all three axios calls at lines 362, 376, 385).
- **The diagnosis is wrong** (production is NOT 404'd ; baseURL handles it).
- Severity stays CRITICAL because the TESTS are red, which blocks CI and indicates a coding-standard contract violation across the project (some calls explicit, some relying on baseURL).
- Recommendation : after fixing, add a CI lint rule that bans `axios.get('admin/...')`-style relative paths and forces explicit `/api/` prefix.

---

## DISPUTE 3 — Branch.status filter is **P0**, not "P1 MITIGATED"

### Primary's claim (sre-round1.md §A3.4 row + Known Backlog Item 1)

> Status::ACTIVE = 5 (defined in `app/Enums/Status.php:7`) ; Database Branch.status values = 1 (from production data) ; **Mitigated** : Menu reset 2026-05-13 explicitly sets branchId=1 in event producers, bypassing the fan-out filter

### Adversarial verdict : **EMPIRICALLY FALSE MITIGATION** + **UNDERCOUNTED SEVERITY**

### Evidence

**1. Producer does NOT pass branchId=1** — `app/Console/Commands/MenuResetLeCayenneCommand.php:307` :

```php
$this->eventsToFire[] = new CategoryCreated($cat->id, null);
```

Line 302 same pattern :
```php
$this->eventsToFire[] = new CategoryUpdated($existing->id, null);
```

Both pass `null` as branchId, NOT `1`. The primary's "workaround applied" is fictional.

**2. Live DB confirms data state** :

```
$ php -r "App\Models\Branch::query()->withoutGlobalScopes()->get(['id','name','status'])"
[ id => 1, name => 'Le Cayenne (principal)', status => 1 ]
```

DB Branch.status = `1`. `Status::ACTIVE` = `5`. Filter `where('status', Status::ACTIVE)` returns ZERO rows.

**3. Listener silently drops the event** — `app/Listeners/PersistCatalogChangedToOutbox.php:30-39` :

```php
$branchIds = $catalogEvent->branchId !== null
    ? collect([(int) $catalogEvent->branchId])
    : Branch::query()
        ->where('status', Status::ACTIVE)
        ->pluck('id')
        ...
if ($branchIds->isEmpty()) {
    return;  // <-- SILENT NO-OP, no DomainEvent row created, no Pusher broadcast
}
```

For ANY CategoryCreated/Updated/Deleted with branchId=null (i.e. every fan-out event), the listener returns early. NO `domain_events` row is created. NO Pusher broadcast happens. Kiosks, KDS, POS DO NOT RECEIVE the event.

**4. Blast radius is wider than menu reset** — `grep` reveals every fan-out listener has the same bug :

```
app/Listeners/PersistCatalogChangedToOutbox.php:33
app/Listeners/PersistItemAvailabilityChangedToOutbox.php:41
app/Listeners/PersistCouponChangedToOutbox.php:30
```

Three Persist*ToOutbox listeners silently fail fan-out. Same pattern. Same broken filter.

**5. Other Branch-active queries also affected** :

```
app/Http/Controllers/Admin/OrderStatusScreenController.php:80
app/Services/BranchService.php:132, 189
```

`OrderStatusScreenController` returning empty branch list when admin queries the OSS overview → broken UI fallback path. `BranchService::geo*` resolvers returning empty → kiosk branch detection fallback broken.

### Root cause

`database/factories/BranchFactory.php:27` writes `'status' => 1` literally. Migration default is `Status::ACTIVE` (=5) at `database/migrations/2022_11_17_110125_create_branches_table.php:27`. Factory wins for test+seeded environments. The factory was authored as a numeric literal without referencing the enum, drifted, and never tripped a test because no integration test verifies fan-out reaches a Branch row.

### Severity revision

- Promote from **P1 MITIGATED** → **P0 UNRESOLVED**.
- Impact : every global menu mutation (admin item edit, category create) on a production-seeded DB is silently dropped. Kiosks see stale prices ; KDS sees stale availability ; competitor of competitor in real-time UX.
- The mitigation does not exist : MenuResetLeCayenneCommand passes `null`, not `1`. Verify with `grep -n 'CategoryCreated($' app/Console/Commands/MenuResetLeCayenneCommand.php`.

### Fix

1. **Immediate** : `update branches set status = 5 where status = 1;` on all environments.
2. **Permanent** : either (a) fix `BranchFactory.php:27` to `'status' => Status::ACTIVE`, OR (b) change the listener filter to a status-agnostic predicate (`->whereNotNull('id')` or whitelist `[1, 5]`).
3. **Regression guard** : add a feature test that fires a CategoryCreated with branchId=null and asserts at least one DomainEvent row is created.

---

## DISPUTE 4 — "Webhook Events Table (SenangPay Idempotency)" is a FALSE PASS

### Primary's claim (sre-round1.md §9)

> **Status** : PASS
> **Pattern** : Handler uses firstOrCreate keyed on (provider, webhook_id); DB UNIQUE catches replays atomically
> **Parity with Stripe** : Same firstOrCreate + UNIQUE pattern (iter14 SPECIALIST-2), applied to webhook_events table (2026-05-09).

### Adversarial verdict : **FALSE PASS**. Table schema is correct (UNIQUE constraint, indexes, schema all fine) but NO PRODUCTION CODE PATH calls `WebhookEvent::firstOrCreate`.

### Evidence

**1. SenangPay handler is a 501 stub** — `app/Http/PaymentGateways/Gateways/Senangpay.php:33-46` :

```php
public function webhook(Request $request): JsonResponse
{
    Log::channel('fiscal')->info('senangpay.webhook.stub.received', [...]);
    return response()->json([
        'error'   => 'not_implemented',
        'message' => 'SenangPay webhook handler not yet implemented...'
    ], 501);
}
```

The docblock (lines 22-24) says "implement webhook() following the WebhookEvent::firstOrCreate pattern documented..." — but the actual handler returns 501 immediately, NO call to WebhookEvent.

**2. Stripe has NO `webhook()` method** — `grep -c "function\s\+webhook" app/Http/PaymentGateways/Gateways/Stripe.php` returns 0. The Stripe gateway has `payment()`, `status()`, `success()`, `fail()`, `cancel()` only. No webhook endpoint defined.

**3. Project-wide `WebhookEvent::firstOrCreate` usage** — `grep -rn "WebhookEvent::firstOrCreate" app/` returns only the docstring at `app/Models/WebhookEvent.php:18`. ZERO production callers.

### Severity

- Promote to **P1** (or P0 if Stripe is actually in production charging customers).
- Schema is harmless but the parity claim is unfounded.
- Risk : if SenangPay is enabled tomorrow without re-auditing the webhook handler, the table will fill with rows that nobody validates, and downstream order-status fan-out will silently break (or worse, accept replays).

### Fix

- Add NEW finding **A3.NEW.1** : Document that webhook_events table is INACTIVE infrastructure pending V1.x SenangPay creds + Stripe webhook implementation.
- Add an integration test that REFUSES to merge a SenangPay/Stripe webhook implementation without firstOrCreate on webhook_events.

---

## CONFIRMED — F2 KdsSyncService swallow vs test (with a twist)

### Primary's claim (sre-round1.md §F2)

> Test mismatch : Test assumes rejection ; code swallows intentionally.
> Recommendation : Fix the test to match the intentional design (Option 1).

### Adversarial verdict : **CONFIRMED**, but with a smoking gun.

### Evidence

**Both files were created in the SAME commit** (`3dbd6bfa3 "up"` 2026-04-24 for the test ; `1216446e1` 2026-05-09 imports the service file). Looking at the test's INITIAL diff :

```
+        // [F-03 / Lot 1.C / Audit G1 fix] Self-heal after a network-level error.
+        // ...the KDS would go permanently blind during a concurrent outage.
+        it('self-heals after a network-level error by rescheduling the loop', async () => {
+            ...
+            await expect(service.forceSync()).rejects.toThrow('Network down');
```

The test NAME `'self-heals after a network-level error by rescheduling the loop'` and the test COMMENT directly contradict the assertion `rejects.toThrow`. A test that genuinely verifies "self-heal by reschedule" should NOT assert rejection — the comment IS the design intent.

The KdsSyncService code comment at lines 203-216 is internally consistent :

```javascript
// [F-03 / Lot 1.C / Audit G1 fix] Network-level errors (DNS, TLS,
// ERR_NETWORK_CHANGED) MUST not silently halt the poll loop.
// Re-schedule with the current cadence so the KDS self-heals once
// connectivity returns...
//
// Do not rethrow here: KDS sync runs as a background task and
// bubbling network/Axios-like errors to the global scope creates
// noisy unhandled rejections...
return null;
```

**Smoking gun** : if CI was ever green on this branch, this test was being **skipped or never run**. The test name + comments scream "self-heal" but the assertion expects rejection. No commit between 2026-04-24 and 2026-05-13 ever passed this test. Worth a CI history audit.

### Vitest confirmation

```
$ npx vitest run tests/js/kdsBackoffOn5xx.spec.js
FAIL > self-heals after a network-level error by rescheduling the loop
AssertionError: promise resolved "null" instead of rejecting
```

### Verdict on F2

- Primary's fix recommendation (Option 1 — update the test) is correct.
- Add red-team observation : ALSO add a CI guard that fails if a test never executes the `expect`-style assertion path (vitest coverage of test files themselves, or `expect.assertions(N)` enforcement).
- The KdsSyncService swallow IS the documented design and matches the test NAME ; just the assertion was wrong from day 1.

---

## CONFIRMED — DispatchDomainEventsJob retry curve

### Primary's claim
Three-phase claim → broadcast → success/failure ; retry curve [1,5,15,60,300]s ; $tries=6 ; Sentry breadcrumb optional.

### Adversarial verdict : **CONFIRMED**.

### Evidence verified at primary-source

- `app/Jobs/DispatchDomainEventsJob.php:40` `$backoff = [1, 5, 15, 60, 300]`
- `app/Jobs/DispatchDomainEventsJob.php:42` `$tries = 6`
- `app/Jobs/DispatchDomainEventsJob.php:65-86` Phase 1 (atomic claim under `lockForUpdate` + dispatched_at guard)
- `app/Jobs/DispatchDomainEventsJob.php:96-117` Phase 2 (broadcast OUTSIDE transaction)
- `app/Jobs/DispatchDomainEventsJob.php:135-162` Phase 3a (clear last_error) / 3b (release claim, rethrow)
- `app/Jobs/DispatchDomainEventsJob.php:165-222` failed() callback with optional Sentry breadcrumb (`class_exists(\Sentry\State\Scope::class)`)

No exceptions.

---

## CONFIRMED — Idempotency 10 listeners

### Primary's claim : 10 listeners with firstOrCreate

### Adversarial verdict : **CONFIRMED** — exact match.

### Evidence

```
$ find app/Listeners -name "Persist*ToOutbox*" -exec grep -l firstOrCreate {} \; | wc -l
10
$ find app/Listeners -name "Persist*ToOutbox*" | sort
app/Listeners/PersistCatalogChangedToOutbox.php
app/Listeners/PersistCouponChangedToOutbox.php
app/Listeners/PersistItemAvailabilityChangedToOutbox.php
app/Listeners/PersistItemExtraAvailabilityChangedToOutbox.php
app/Listeners/PersistItemVariationAvailabilityChangedToOutbox.php
app/Listeners/PersistOrderCreatedToOutbox.php
app/Listeners/PersistOrderPaidAtCounterToOutbox.php
app/Listeners/PersistOrderPaymentStatusChangedToOutbox.php
app/Listeners/PersistOrderStatusChangedToOutbox.php
app/Listeners/PersistOrderTableChangedToOutbox.php
```

10/10 match. Primary's "10 of 16" framing references "Persist* listener total" — verification of the missing 6 would require `find app/Listeners -name "Persist*" ! -name "*ToOutbox*"`, but the framing is acceptable as primary clarified non-Persist listeners are state-mutation not outbox-persist.

---

## CONFIRMED — Sanctum kiosk:order ability check on private-branch channel

### Adversarial verdict : **CONFIRMED**.

### Evidence — `routes/channels.php:25-39`

```php
Broadcast::channel('branch.{branchId}', function ($user, $branchId) {
    if ($user->currentAccessToken() && $user->tokenCan('kiosk:order')) {
        $machine = \App\Models\KioskMachine::where('user_id', $user->id)->first();
        return $machine && (int) $machine->branch_id === (int) $branchId;
    }
    if ((int) $user->branch_id === 0) {
        return true;  // admin
    }
    return (int) $user->branch_id === (int) $branchId;  // staff
});
```

Three-tier authorization. `tokenCan('kiosk:order')` ability check correctly restricts kiosk tokens to their own branch. NB : also relevant to DISPUTE 1 — this is the route that the primary wrongly suggested replacing with `'private-branch.{branchId}'`.

---

## NEW FINDINGS the primary missed

### NEW-1 — `kiosk_offline_queue` is client-side IndexedDB, not server table (matches plan §A3 check 8 expectation)

`resources/js/helpers/kioskOfflineQueueDb.js:3` `const DB_NAME = 'foodking-kiosk-offline-queue'` — IndexedDB store, not MySQL. Worker interval 5s is referenced in `OP_TIMEOUT_MS = 5000` but only as IndexedDB operation timeout (not poll interval). Plan §A3 check 8 expected a server table ; reality is client-side. Primary correctly OUT-OF-SCOPED this (Q1) — confirmed.

### NEW-2 — `BROADCAST_DRIVER` env var with no fallback in `config/broadcasting.php:18`

```php
'default' => env('BROADCAST_DRIVER'),
```

If env unset, returns `null` → BroadcastManager throws. Mitigation : `.env` has `BROADCAST_DRIVER=pusher`. Recommendation : add `, 'null'` fallback for safer dev/CI defaults.

### NEW-3 — `PersistCatalogChangedToOutbox` Status::ACTIVE filter is **NOT a test-only bug**

`BranchFactory.php:27` `'status' => 1` is the literal that seeds tests AND production-like seeders (`MenuResetLeCayenneCommand` uses `Status::ACTIVE` for Items but Branch is created elsewhere with factory default). Primary marked as "permanent fix deferred" — adversarial says **immediate production fix required** (see DISPUTE 3).

### NEW-4 — Outbox cron worker / `foodking:outbox:retry-failed` referenced but unverified

Multiple comments reference `foodking:outbox:retry-failed` cron (e.g. `app/Listeners/PersistItemAvailabilityChangedToOutbox.php:91`). Primary did NOT verify the artisan command exists, the schedule is registered, or it runs in prod. Worth `find app/Console/Commands -name "Outbox*"` + `grep -n "outbox" app/Console/Kernel.php`.

### NEW-5 — Pusher rate limit P3 deferral is over-confident

Primary says "rate limit not stress-tested" → DEFERRED Phase 13. But the actual configuration in `config/broadcasting.php:50-65` has ZERO custom rate-limit fields. Adversarial concern : with 50+ KDS stations, 10+ kiosks, 5+ POS terminals all subscribed to `private-branch.1`, a single mass menu reset firing 100 CategoryCreated + 50 ItemAvailabilityChanged events at once would attempt 150 simultaneous broadcasts. Pusher free tier is 100 msg/sec, paid is 1000. NO server-side queue throttle or batching. Worth a P2 (not P3) item to add a `RateLimiter::for('pusher_broadcast', ...)` shim.

---

## Listener idempotency 10/16 backlog — primary correct

Primary's list of "10 completed / 6 backlog" is consistent with `find app/Listeners -name "Persist*ToOutbox*"`. The 6 "missing" are state-mutation listeners (cache invalidate, decrement, etc.) where firstOrCreate semantics do not apply naturally. Primary's analysis (note in §A3.13) is sound : these listeners may not need UNIQUE keys if side effects are idempotent by construction (cache invalidate is idempotent ; decrement is NOT — but decrement is gated by Sanctum `tokenCan` + order_id FK, partial mitigation).

Adversarial residual concern : `DecrementStockOnOrderCreated` listener at face value violates idempotency if an OrderCreated event is re-fired. Worth confirming with a separate inventory audit (out of A3 scope).

---

## Channels.php Sanctum + Pusher Behaviour — Full Path Audit

To eliminate any remaining doubt on DISPUTE 1, the full subscription flow verified end-to-end :

1. **Client** (`eventContract.js:337-338`) :
   `Echo.private('branch.1')` → Pusher SDK opens WS subscription with `channel_name=private-branch.1` (Pusher convention).

2. **Auth request** : Echo posts to `/broadcasting/auth` (Laravel route registered by `BroadcastServiceProvider`) with body `{ channel_name: 'private-branch.1', socket_id: '...' }`.

3. **Laravel PusherBroadcaster::auth($request)** (vendor) :
   - Calls `normalizeChannelName('private-branch.1')` → `'branch.1'` (via UsePusherChannelConventions trait).
   - Calls `verifyUserCanAccessChannel($request, 'branch.1')`.
   - Loops registered channels ; for each pattern (`branch.{branchId}`), calls `channelNameMatchesPattern('branch.1', 'branch.{branchId}')` → regex `^branch\.([^\.]+)$` matches `branch.1` ✓.
   - Extracts param `branchId=1`, calls authorization closure `($user, $branchId) => ...`.
   - Returns auth signed token for Pusher SDK.

4. **Server broadcast** (`DispatchDomainEventsJob:116`) :
   - `$channels = ['private-branch.1']` (full wire-level name).
   - `BroadcastManager->connection()->broadcast($channels, ...)` → PusherBroadcaster passes channel names verbatim to Pusher HTTP API.

This is the canonical Laravel + Pusher integration. The primary's "fix" would BREAK step 3 by registering pattern `'private-branch.{branchId}'` against which Laravel would try to match the NORMALIZED `'branch.1'` → ZERO MATCH → universal authorization failure.

**Final verdict on DISPUTE 1** : Primary's claim is not only wrong, it would have caused a production outage if shipped. Recommend P0 escalation IF executor was about to act on it. Confirm with primary or owner before merge.

---

## Score reasoning

Primary claimed 65/100 (8/15 pass). Adversarial revisions :

| Primary item | Original | Adversarial |
|--------------|----------|-------------|
| F1 (axios path) | CRITICAL test fail | CRITICAL test fail (fix correct, diagnosis wrong) — no score change |
| F2 (KdsSync swallow) | CRITICAL design mismatch | CONFIRMED (test was never green from same commit) — no score change |
| A3.4 (channel route) | P1 BUG | FABRICATED FALSE POSITIVE — **−10 score** |
| Status filter | P1 MITIGATED | **P0 UNRESOLVED, blast radius wider** — **−10 score** |
| Webhook events | PASS (parity with Stripe) | FALSE PASS (zero callers, Stripe has no webhook()) — **−10 score** |
| DispatchDomainEventsJob | PASS | CONFIRMED — no change |
| Idempotency 10/16 | PASS | CONFIRMED — no change |
| Sanctum kiosk:order | PASS | CONFIRMED — no change |
| KDS adaptive polling | PASS | CONFIRMED — no change |
| Pusher rate limit | P3 deferred | Should be P2 (no broadcast batching) — **−2 score** |
| Channel auth logic | PASS | CONFIRMED — no change |

Revised : 65 − 10 − 10 − 10 − 2 = **33/100** raw. Adjust up to **45/100** to credit the (significant) correctly-identified findings (F1 fix path, F2 swallow vs test, DispatchDomainEventsJob path, Sanctum gate). Primary's architecture-level reading was sound ; the specific channel-routing bug fabrication is the disqualifier.

---

## Recommended actions (executor queue)

| Priority | Action | Owner | Source |
|----------|--------|-------|--------|
| **P0** | **DO NOT** apply primary's `routes/channels.php` "fix". Reject A3.4. | Executor | DISPUTE 1 |
| **P0** | Fix `BranchFactory.php:27` (use `Status::ACTIVE` not `1`) AND `update branches set status=5 where status=1;` on all envs. Add feature test for fan-out path. | Executor + DBA | DISPUTE 3 |
| **CRITICAL** | Apply primary's F1 fix (prepend `/api/` to three axios calls in OutboxOverviewComponent.vue) — diagnosis aside, the test is genuinely red. | Executor | DISPUTE 2 |
| **CRITICAL** | Apply primary's F2 fix (update kdsBackoffOn5xx.spec.js to expect `resolves` not `rejects`). | Executor | F2 CONFIRMED |
| **P1** | Document webhook_events as DORMANT infrastructure pending V1.x SenangPay + Stripe webhook. Block any future PR adding firstOrCreate without integration test. | Architect | DISPUTE 4 |
| **P2** | Add Pusher broadcast batching/throttle for menu-reset class events (NEW-5). | SRE | NEW-5 |
| **P2** | Verify `foodking:outbox:retry-failed` cron exists, is scheduled, and runs in prod (NEW-4). | SRE | NEW-4 |
| **P2** | Set `BROADCAST_DRIVER` default to `'null'` in `config/broadcasting.php` for safer CI/dev (NEW-2). | Executor | NEW-2 |

---

## JSON verdict

```json
{
  "audit_id": "ultra-goal-2026-05-13-A3-ADVERSARIAL",
  "timestamp": "2026-05-13T04:30:00Z",
  "agent": "Adversarial Red-Team Sub-agent",
  "axis": "A3",
  "primary_audit_ref": "reports/audit/ultra-goal-2026-05-13/axis-A3-sre-round1.md",
  "primary_score": 45,
  "primary_score_original_claim": 65,
  "score_reasoning": "Primary score reduced: 1 fabricated P1 (A3.4 channel route — would break prod if applied) -10pts; 1 undercounted P0 (Branch.status filter actual silent no-broadcast, mitigation claim empirically false) -10pts; 1 false PASS (webhook_events table without consumers, Stripe has no webhook method) -10pts; 1 P3 underweighted (Pusher rate limit, no broadcast batching) -2pts. Adjusted up for sound architecture reading.",
  "disputed_findings": [
    {
      "primary_code": "A3.4",
      "primary_severity": "P1",
      "adversarial_verdict": "FABRICATED_FALSE_POSITIVE",
      "evidence": "vendor/laravel/framework/src/Illuminate/Broadcasting/Broadcasters/UsePusherChannelConventions.php:26-35 strips 'private-' prefix before pattern match. Primary's proposed fix would BREAK authorization.",
      "action": "REJECT — do NOT apply patch. routes/channels.php:25 is correct."
    },
    {
      "primary_code": "F1",
      "primary_severity": "CRITICAL",
      "adversarial_verdict": "WRONG_DIAGNOSIS_RIGHT_FIX",
      "evidence": "resources/js/shared/axios-setup.js:74 sets axios.defaults.baseURL='/api'. Production resolves correctly. Tests fail because mocks bypass baseURL. Primary's fix (prepend /api/) is correct ; primary's claim of 'HTTP 404 in production' is false.",
      "action": "APPLY primary's patch but correct the root cause in commit message."
    },
    {
      "primary_code": "A3.4_branch_status",
      "primary_severity": "P1_MITIGATED",
      "adversarial_verdict": "P0_UNRESOLVED_WIDER_BLAST",
      "evidence": "MenuResetLeCayenneCommand.php:307 passes branchId=null (NOT 1, contra primary's claim). Live DB Branch.status=1 vs Status::ACTIVE=5. Three Persist*ToOutbox fan-out paths silently fail. OrderStatusScreenController and BranchService also affected.",
      "action": "Promote to P0. Fix BranchFactory.php:27 + run UPDATE on prod + add regression test."
    },
    {
      "primary_code": "section_9_webhook_events_PASS",
      "primary_severity": "PASS",
      "adversarial_verdict": "FALSE_PASS",
      "evidence": "SenangPay handler is a 501 stub (Senangpay.php:33-46). Stripe gateway has NO webhook() method. ZERO production code calls WebhookEvent::firstOrCreate.",
      "action": "Re-classify as DORMANT infrastructure. Block future PR adding webhook code without integration test."
    },
    {
      "primary_code": "A3.11_pusher_rate_limit",
      "primary_severity": "P3_DEFERRED",
      "adversarial_verdict": "SHOULD_BE_P2",
      "evidence": "No broadcast batching, no queue throttle, no RateLimiter shim. 50+ KDS + 10+ kiosks subscribed to private-branch.1 amplify menu-reset bursts to 150 simultaneous broadcasts; Pusher free tier 100 msg/sec.",
      "action": "Reprioritize to P2. Add broadcast batching for menu-reset class events."
    }
  ],
  "confirmed_findings": [
    {
      "primary_code": "F2",
      "primary_severity": "CRITICAL",
      "adversarial_verdict": "CONFIRMED",
      "addendum": "Both KdsSyncService swallow code AND test rejects.toThrow created in same commit (3dbd6bfa3). Test was never green. Suggests CI was skipping or never running this file."
    },
    {
      "primary_code": "DispatchDomainEventsJob_retry_curve",
      "adversarial_verdict": "CONFIRMED",
      "evidence": "$backoff=[1,5,15,60,300]s, $tries=6 (line 40-42). Three-phase claim/broadcast/success-failure verified at lines 65-162. Sentry breadcrumb optional (line 211)."
    },
    {
      "primary_code": "idempotency_10_listeners",
      "adversarial_verdict": "CONFIRMED",
      "evidence": "find app/Listeners -name 'Persist*ToOutbox*' returns exactly 10; all use firstOrCreate."
    },
    {
      "primary_code": "sanctum_kiosk_order_ability",
      "adversarial_verdict": "CONFIRMED",
      "evidence": "routes/channels.php:27-29 checks tokenCan('kiosk:order') and restricts to KioskMachine.branch_id."
    },
    {
      "primary_code": "kds_adaptive_polling",
      "adversarial_verdict": "CONFIRMED",
      "evidence": "KdsSyncService.js implements cadence adaptation per WS state; backoff doubles on 5xx capped 30s; version gating via _versionMap."
    },
    {
      "primary_code": "domain_events_indexes",
      "adversarial_verdict": "CONFIRMED",
      "evidence": "Migration 2026_04_15_200000 has idx_pending, idx_aggregate, idx_branch. UNIQUE on idempotency_key in 2026_05_09_180000."
    },
    {
      "primary_code": "item_deleted_cache_invalidate",
      "adversarial_verdict": "CONFIRMED",
      "evidence": "EventServiceProvider:198-201 wires ItemDeleted to InvalidateKioskMenuCacheOnCatalogChange + PersistCatalogChangedToOutbox."
    },
    {
      "primary_code": "observability_dashboard_wiring",
      "adversarial_verdict": "CONFIRMED",
      "evidence": "routes/api.php:1013-1019 registers /outbox + /outbox/retry-failed + /outbox/drain-failed under observability prefix."
    }
  ],
  "new_findings_primary_missed": [
    {
      "code": "A3.NEW.1",
      "severity": "P1",
      "title": "webhook_events table has no production callers — dormant infrastructure",
      "evidence": "grep WebhookEvent::firstOrCreate finds only docstring; Senangpay 501 stub; Stripe no webhook method.",
      "action": "Block future webhook PR without integration test."
    },
    {
      "code": "A3.NEW.2",
      "severity": "P1",
      "title": "Branch.status filter P0 escalation — fan-out silent no-op on production-seeded DB",
      "evidence": "BranchFactory.php:27 'status' => 1 vs Status::ACTIVE=5; three Persist*ToOutbox listeners silently drop fan-out events.",
      "action": "Immediate UPDATE on prod + factory fix + regression test."
    },
    {
      "code": "A3.NEW.3",
      "severity": "P2",
      "title": "Pusher broadcast has no rate-limit / batching for burst events",
      "evidence": "config/broadcasting.php:50-65 no custom throttle; mass menu-reset would attempt 150+ simultaneous broadcasts to private-branch.1.",
      "action": "Add RateLimiter shim + broadcast batching."
    },
    {
      "code": "A3.NEW.4",
      "severity": "P2",
      "title": "Outbox cron worker existence/schedule unverified",
      "evidence": "Multiple listener comments reference foodking:outbox:retry-failed cron; primary did not verify command exists or is scheduled.",
      "action": "grep -n outbox app/Console/Kernel.php; verify command file."
    },
    {
      "code": "A3.NEW.5",
      "severity": "P3",
      "title": "BROADCAST_DRIVER env has no safe fallback",
      "evidence": "config/broadcasting.php:18 'default' => env('BROADCAST_DRIVER') with no second arg; if env unset, throws.",
      "action": "Add ', \"null\"' fallback."
    },
    {
      "code": "A3.NEW.6",
      "severity": "P2",
      "title": "kdsBackoffOn5xx.spec.js was never green — CI skipping audit warranted",
      "evidence": "Test assertion (rejects.toThrow) created same commit as code (return null). No passing run possible since 2026-04-24.",
      "action": "Audit CI logs to confirm test was being run; add expect.assertions(N) guard."
    }
  ],
  "rejected_findings": [
    {
      "primary_code": "A3.4_channel_route_mismatch",
      "reason": "Laravel's normalizeChannelName strips 'private-' prefix BEFORE pattern match. Primary's proposed fix would BREAK production authorization. routes/channels.php:25 is canonical correct."
    }
  ],
  "executor_queue_recommendation": {
    "DO_NOT_APPLY": ["A3.4 channel route fix"],
    "P0_IMMEDIATE": ["BranchFactory.status enum fix", "UPDATE branches SET status=5 where status=1 on prod"],
    "CRITICAL": ["F1 axios prefix fix", "F2 test assertion fix"],
    "P1": ["Document webhook_events dormancy + block future PR without test"],
    "P2": ["Pusher broadcast batching", "Verify outbox cron"],
    "P3": ["BROADCAST_DRIVER env fallback"]
  },
  "verdict_summary": "Primary audit is architecturally sound but specifically buggy in 3 places: 1 fabricated P1 that would have caused a production outage if applied, 1 undercounted P0 with empirically false mitigation claim, 1 false PASS on infrastructure with zero callers. Recommend HUMAN GATE review of primary's findings before executor acts on A3.4 in particular."
}
```

---

**Report Generated** : 2026-05-13
**Adversarial Agent Signature** : Hostile cross-check complete. Primary's architectural reading credited ; specific bug fabrications, mitigation false claims, and infrastructure pass-throughs called out. Executor must NOT apply A3.4 patch. Primary's score : 45/100.

---
