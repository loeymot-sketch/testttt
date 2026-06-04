# S4 — OSS (Order Status Screen) — RED-TEAM Adversarial Audit

**Date:** 2026-05-17
**Auditor:** RED-TEAM (read-only, hostile, anti-drift)
**Scope:** Public customer-wall display surface (`/admin/order-status-screen`),
its public + admin API siblings (`/api/frontend/oss-order*`,
`/api/admin/oss-order*`), Pusher channel security for live `OrderStatusChanged`
/ `OrderCreated` broadcasts, and the JS sync service.

**Method:** Static analysis only. No dynamic probing. Every finding cites
`file:line`. Anti-drift: where the implementation is correct, that is stated
explicitly with line references rather than invented as a finding.

**Out-of-scope (explicit boundary):** Mobile app and any third-party caller
of `/api/frontend/order/*`. Tokens exposed by OSS flow into whatever those
surfaces accept; static read here cannot exhaust that pathway.

---

## 0. Files audited

- `app/Http/Controllers/Admin/OrderStatusScreenController.php` (admin + public siblings)
- `app/Services/OrderStatusScreenOrderService.php`
- `app/Http/Resources/CDSOrderDetailsResource.php`
- `app/Http/Resources/CDSPopularItemResource.php`
- `app/Http/Resources/OrderDetailsResource.php` (cross-check token leak chain)
- `routes/api.php:1030-1033` (admin), `routes/api.php:1099-1104` (public)
- `routes/api.php:1122-1130` (frontend order sibling — token reachability)
- `routes/channels.php` (Pusher broadcast authorization)
- `app/Providers/BroadcastServiceProvider.php`
- `app/Events/OrderStatusChanged.php`, `app/Events/OrderCreated.php`
- `app/Listeners/PersistOrderStatusChangedToOutbox.php`,
  `PersistOrderCreatedToOutbox.php`
- `app/Jobs/DispatchDomainEventsJob.php`
- `app/Domain/Events/EventContract.php:60-92` (envelope schema)
- `resources/js/router/index.js:202-213` (public-friendly auth bypass)
- `resources/js/router/modules/orderStatusScreenRoutes.js`
- `resources/js/components/admin/orderStatusScreen/*.vue` (3 SFCs)
- `resources/js/store/modules/orderStatusScreenOrder.js`
- `resources/js/services/OssSyncService.js`
- `resources/js/services/eventContract.js:326-385`

---

## 1. Attack dimension verdicts

### 1.1 PII leak in public display — VERDICT: NOT VULNERABLE (server-side); WEAKNESS via display semantics

**Evidence FOR.** `CDSOrderDetailsResource::toArray`
(`app/Http/Resources/CDSOrderDetailsResource.php:17-24`) returns exactly six
fields:

```
id, order_serial_no, token, queue_number, order_type, status
```

No `user`, `phone`, `email`, `address`, `payment_method`, `total`, `items`,
`reason`, `instructions`, `allergens`, `discount`. The full
`OrderDetailsResource` (`app/Http/Resources/OrderDetailsResource.php:17-60`)
exposes `user`, `order_address`, `payment_method`, `total`, `coupon`,
`transaction` etc. — none of which are reachable through the OSS endpoints.

The CDS resource is reused by both the auth-gated admin endpoint
(`OrderStatusScreenController::index`, controller:24-33) and the public sibling
(`publicIndex`, controller:75-100). Both render the same minimal payload.

**Weakness (low).** The display shows `token` for orders that were not assigned
a `queue_number` (kiosk eat-in path lights `queue_number`; legacy/online
takeaway paths may carry `token`). The `token` is the same string a customer
sees on their printed kiosk slip — passers-by reading the wall can memorize
arbitrary tokens. See §1.2 for the question of what those tokens unlock.

### 1.2 Order# / token enumeration — VERDICT: BOUNDED (token harvest does NOT yield PII)

**The enumeration question.** Token is exposed for every PREPARING/PREPARED
order via the public endpoint. The relevant question: can a token be exchanged
for any customer or order detail?

**Routes accepting an order identifier.**
- `GET /api/frontend/order/show/{frontendOrder}` (`routes/api.php:1124`,
  inside the `auth:sanctum` group at `routes/api.php:1122`).
- Controller `Frontend\OrderController::show(FrontendOrder $frontendOrder)`
  (`app/Http/Controllers/Frontend/OrderController.php:71`) uses default
  route-model binding.
- Neither `app/Models/FrontendOrder.php` nor `app/Models/Order.php` override
  `getRouteKeyName()` (verified by grep — zero matches). Default key = primary
  key `id` (integer), NOT `token`.

**Conclusion.** Even if an attacker mass-harvests tokens from OSS, the show
endpoint will not resolve them: `{frontendOrder}` is matched by integer `id`,
the lookup yields 404 for any non-numeric token, and the route requires
`auth:sanctum` regardless. No `where('token', $x)` lookup exists in any
public controller (grep of `app/Http/Controllers` for `where.*'token'` and
`byToken`/`findByToken` returns zero hits in unauth paths).

**Token harvest is therefore bounded to display semantics only** — an attacker
gains "this branch has order X-NNN currently in PREPARED state". Useful for
the impersonation attack in §1.3, not for direct PII exfiltration.

**Latent risk (anti-drift call-out).** If a future endpoint adds
`Order::where('token', $request->token)` or overrides `getRouteKeyName()` to
`'token'`, this finding flips from "bounded" to P0 "mass PII enumeration"
overnight. Recommend a sentinel test asserting
`(new FrontendOrder)->getRouteKeyName() === 'id'` and a grep guard against
`where(.*token.*)` in unauthenticated routes.

### 1.3 Display drift exploit — IMPERSONATION VECTOR (P2, social-engineering)

The wall display shows `Nº42` in the PRÊT column. Customer claims a counter
order by speaking the number. There is no:

- customer-facing PIN
- receipt-side QR code that the counter staff scan to confirm
- proof-of-ownership step

An angry ex-employee or competitor who watches the wall for 60 seconds can
walk up to the counter, claim "Nº42", and (depending on staff training) be
handed the order.

**Server-side controls cannot fix this** (the wall is, by design, public). The
mitigation is operational — counter staff must require the customer's printed
kiosk slip / receipt before handing off. This is a **product/operations
finding**, not a code defect; documented here so the consolidated report does
not silently lose it.

### 1.4 Pusher channel security — VERDICT: NOT VULNERABLE (correctly gated)

**Channel authorization** (`routes/channels.php:25-39`):

```php
Broadcast::channel('branch.{branchId}', function ($user, $branchId) {
    if ($user->currentAccessToken() && $user->tokenCan('kiosk:order')) {
        $machine = KioskMachine::where('user_id', $user->id)->first();
        return $machine && (int) $machine->branch_id === (int) $branchId;
    }
    if ((int) $user->branch_id === 0) { return true; }  // global Admin
    return (int) $user->branch_id === (int) $branchId;
});
```

Three observations:

1. **Auth endpoint hardened.** `app/Providers/BroadcastServiceProvider.php:22`
   requires `auth:sanctum` on `/api/broadcasting/auth`. An unauthenticated
   browser cannot subscribe to any private channel.
2. **Kiosk tokens are clipped** to their own `KioskMachine.branch_id` (cited
   `[GAP-21-5]` in the comment header at `routes/channels.php:20-24`). A
   stolen kiosk token cannot pivot to other branches.
3. **Branch staff are scoped to own branch.** Only `branch_id === 0`
   (Admin/Tenant Admin) gets unfiltered subscribe. This matches the OSS
   `resolveBranchScope` discipline in
   `OrderStatusScreenOrderService:144-162`.

**Public OSS wall consequence.** The customer wall, being unauthenticated,
**cannot** subscribe to `private-branch.{N}` channels. It falls back to
polling (`OssSyncService` + `frontend/oss-order`). The
`PreparingAndReadyComponent.vue:190-223` `subscribeEcho()` path is guarded by
`if (branchId <= 0) return;` — for the unauth path `authBranchId()` returns
`0`, so the Echo wiring is a no-op on the wall.

### 1.5 Fake order injection / broadcast spoofing — VERDICT: NOT VULNERABLE

Pusher private channels are **server-broadcast only**. Subscribers receive
events; they cannot inject events. The only injection paths are:

- Forge a request to `/api/admin/order` → blocked by `auth:sanctum` +
  `permission:` middleware.
- Forge a request to `/api/frontend/order` (`routes/api.php:1126`) → blocked
  by `auth:sanctum` + `idempotency` + `throttle:kiosk-orders`.
- Compromise the outbox → would require DB write access (privileged).

No client-broadcastable channel ("public" / "presence" client-events)
exists for `OrderCreated` / `OrderStatusChanged`.

### 1.6 Branch leak (multi-tenant cross-bleed) — VERDICT: VULNERABILITY (P1 enumeration)

**Defect.** The public endpoint accepts an attacker-controlled `?branch_id=`
without authorization:

```php
// app/Http/Controllers/Admin/OrderStatusScreenController.php:75-94
public function publicIndex(\Illuminate\Http\Request $request)
{
    $branchId = (int) $request->query('branch_id', 0);
    if ($branchId <= 0) { /* fallback to first active branch */ }
    $rows = $this->orderStatusScreenOrderService->listForBranch($branchId);
```

`listForBranch` (`OrderStatusScreenOrderService:107-142`) blindly filters by
the integer with no policy check. Any internet caller can iterate
`?branch_id=1,2,3,...N` and harvest the live order queue (id +
order_serial_no + token + queue_number + status) for every branch in the
fleet — without authentication.

**Why this matters for V1 (Le Cayenne, single-branch).** Today the blast
radius is one branch. The same code, once Le Cayenne becomes the SaaS
multi-tenant launch, becomes a public competitive-intelligence feed: a rival
chain can scrape every branch's hourly throughput, queue depth, prep/ready
ratio. This is a SaaS-launch P0 disguised as a single-resto P2.

**Compounding (anti-drift):** §1.2 says token harvest is bounded TODAY. If
the latent risk in §1.2 ever materializes, this branch iteration turns into
full-fleet PII enumeration.

**Recommended fix (read-only audit; for the consolidated report's remediation
section):** gate `?branch_id` to either (a) require a signed branch token
mounted on the wall at provisioning, or (b) require an exact match against
the host header / a per-branch subdomain. The "first active branch" fallback
in `controller:80-84` is acceptable for V1 single-resto but must be killed
for SaaS launch.

### 1.7 XSS via order data displayed — VERDICT: NOT VULNERABLE

`PreparingAndReadyComponent.vue:24-28` and `:44-49` use `{{ ... }}` mustache
binding for `item.queue_number` and `item.token`. Vue HTML-escapes mustache
content by default. There is no `v-html` in the OSS Vue tree (verified by
grep of the three OSS SFCs).

Even if a token contained `<script>`, it would render as text. The two
columns are pure number/short-string display — the data ingress is the same
server (`Order::token`) controlled by `OrderService:1976-1983` and
schema-typed `'token' => 'string'` (no length cap visible, but no concat
into HTML either).

### 1.8 Resource exhaustion (multi-tab / DoS) — VERDICT: WEAKNESS (P2 self-DoS, no attacker amplification)

**Per-tab cost.** `OssSyncService.js:16` sets
`intervalMsWhenDisconnected: 2000` ms = 30 req/min/tab. The visibility
burst-poll (`OssSyncService.js:203-241`) adds a one-shot fetch on tab focus,
throttled at 1 s minimum gap (`visibilityBurstMinIntervalMs: 1000`,
`OssSyncService.js:24`).

**Server throttle.** `routes/api.php:1100` applies `throttle:120,1` on
`/api/frontend/oss-order` and `throttle:60,1` on `/popular-items`. Throttle
key in Laravel default is per-IP for unauthenticated routes.

**Self-DoS math (V1 single-NAT fleet).** Four walls behind one NAT, all in
the disconnected fallback (WS port 6001 down — explicitly stated in
`OssSyncService.js:11-15`), poll at 30 req/min × 4 = 120 req/min = exactly
the throttle ceiling. The popular-items endpoint is mounted on the same wall
(`OrderStatusScreenComponent.vue:15`), adding ~12 req/min per tab. Combined
load is **above** the 60/min popular-items ceiling for any fleet of ≥ 2
walls in disconnected mode.

A burst-poll storm from visibilitychange events (screen wake on idle TV)
adds 4 × 1 sustained near the throttle edge.

**External DoS amplification.** A hostile caller can max 120 req/min/IP on
the OSS endpoint with no auth. The query
(`OrderStatusScreenOrderService:107-137`) hits `orders` with two
`whereIn`/`whereDate` predicates and an `orderBy` — fine per-call, but no
caching layer is visible. 120 req/min × N attacker IPs = N × table scan of
`PREPARING`/`PREPARED` slice. The defensive line is the throttle alone;
there is no SSE / no shared cache.

**Recommended note for remediation:** add `Cache::remember` with TTL ≤ poll
interval, and consider an `If-None-Match`/`ETag` short-circuit so repeat
polls don't re-query when the queue is static. Move the throttle key to
include `branch_id` so cross-branch enumeration in §1.6 isn't masked by a
single-IP attacker hitting the same shared bucket.

### 1.9 Response integrity — VERDICT: LOW (HTTPS-mitigated)

No HMAC / signature on the OSS payload. A network attacker who breaks TLS
(or sits on a captive-portal MITM) can inject fake PRÊT entries to the
wall display. This is the standard expectation for a non-financial display
endpoint and the codebase nowhere claims otherwise. Flag for completeness;
not a code defect under the V1 threat model (LAN-to-cloud over TLS only).

---

## 2. Anti-drift summary — where the code is correct

These are deliberately called out so the consolidated report does not invent
defects in these areas:

| Area | File:Line | Verdict |
|---|---|---|
| OSS payload shape (no PII) | `CDSOrderDetailsResource.php:17-24` | NOT VULNERABLE |
| Pusher channel auth requires `auth:sanctum` | `BroadcastServiceProvider.php:22` | CORRECT |
| Branch-scoped channel callback | `channels.php:25-39` | CORRECT (kiosk-clipped + branch-staff-clipped) |
| Vue auto-escaping on display | `PreparingAndReadyComponent.vue:24-49` | NOT VULNERABLE |
| Outbox pattern guarantees commit-before-broadcast | `PersistOrderStatusChangedToOutbox.php:68-86`, `DispatchDomainEventsJob.php:65-86` | CORRECT |
| Envelope schema validation before broadcast | `EventContract.php:99-148` + `DispatchDomainEventsJob.php:107-116` | CORRECT |
| Pre-broadcast dedupe at consumer | `eventContract.js:359-361` | CORRECT |
| Default route-model binding is id, not token | `FrontendOrder.php` (no override) | CORRECT |

---

## 3. Findings table — RED-team consolidated

| ID | Title | Severity | File:Line | Mitigated by |
|---|---|---|---|---|
| F-OSS-RED-1 | Cross-branch order-queue enumeration via unauth `?branch_id=N` | **P1** (P0 at SaaS launch) | `OrderStatusScreenController.php:75-94`, `routes/api.php:1099` | None — defect open |
| F-OSS-RED-2 | Default-branch fallback leaks first-active-branch queue with zero knowledge | P2 | `OrderStatusScreenController.php:80-84` | None for V1 single-resto |
| F-OSS-RED-3 | Counter-claim impersonation (no proof-of-ownership at handoff) | P2 (operational) | `PreparingAndReadyComponent.vue:26,47` | Counter staff training only |
| F-OSS-RED-4 | Self-DoS under disconnected fallback × NAT-shared fleet | P2 | `OssSyncService.js:16`, `routes/api.php:1100,1103` | Throttle exists but is the only line |
| F-OSS-RED-5 | No response integrity (HMAC) on public OSS payload | P3 | `CDSOrderDetailsResource.php` | TLS only |
| F-OSS-RED-6 | Token field in public payload — latent enumeration vector if any future endpoint resolves orders by token | P3 (latent), flips P0 if a token-lookup endpoint lands | `CDSOrderDetailsResource.php:22` | Sentinel test recommended |

Total: 6 findings. No P0 in V1 single-resto threat model. **F-OSS-RED-1 is P0
at SaaS launch** and must be closed before multi-tenant cutover.

---

## 4. Boundary acknowledgements

1. Mobile app (`mobile/`) and any third-party caller of `/api/frontend/order/*`
   are out of scope. Tokens exposed by OSS flow into whatever those surfaces
   accept; the audit cannot exhaust that pathway statically.
2. Dynamic probing (actually hitting `?branch_id=` iteratively, attempting
   token replay) was not performed — this audit is read-only by mandate.
3. The legacy Senangpay path flagged in `memory/project_route_audit_2026-05-08.md`
   is unrelated to the OSS surface; not re-examined here.

---

## 5. Reproduction recipe (for the remediation owner; not executed)

**F-OSS-RED-1 verification:**

```
# Anonymous, no Authorization header, internet-reachable host
for i in $(seq 1 50); do
  curl -s "https://${HOST}/api/frontend/oss-order?branch_id=${i}" \
    | jq '.data | length'
done
```

Any non-empty `.data` for an unknown branch_id confirms the defect. Expected
fix: return 403 unless `?branch_id` matches the value provisioned on the
wall's identity (signed URL token, branch-bound subdomain, or device cert).
