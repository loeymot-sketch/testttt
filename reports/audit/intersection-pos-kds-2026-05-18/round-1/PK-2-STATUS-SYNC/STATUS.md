# PK-2 Status Sync — Synthesis (Round 1)

**Zone**: Bidirectional status transitions POS <-> KDS, recall, undo, concurrency
**Mode**: Read-only audit (4 specialists parallel), heal-deferred to orchestrator
**Date**: 2026-05-18

---

## Verdict

**1 P0 (production-blocker if flag stays ON) + 1 P1 (paired with P0) + 5 P2/P3 (review) + 12 attestations PASS**

The bidirectional status sync architecture itself is **sound**: forward-only state machine FROZEN, double-layer transition guards (FormRequest + service), DB::transaction + lockForUpdate at every entry point, optimistic expected_status conflict detection, broadcast wrapped in try/catch, outbox pattern via PersistOrderStatusChangedToOutbox.

The single live defect is a **system-wide propagation gap** of the PS-2 audit heal (commit-labeled `[PS-2 audit 2026-05-18]` in `resources/js/store/modules/posOrder.js:258-263`): the `X-Idempotency-Key` header was added to POS change-status / change-payment-status but NOT propagated to the sister Vuex stores `kitchenDisplaySystemOrder.js`, `onlineOrder.js`, `tableOrder.js`. With `IDEMPOTENCY_MIDDLEWARE_ENABLED=true` in `.env:92` and four matching routes in `config/idempotency.php:53-58`, every chef KDS bump, every admin online-order status flip, and every dine-in table-order status flip return 422. POS surface (already healed) and kiosk surface (separate offline queue path with its own header) are unaffected.

Scope clarification for PK-2 deliverable: while the gap is system-wide, KDS is in the explicit PK-2 zone and the most user-visible failure (chef-bump button broken on every tap). Online + table channels share the root cause and the same one-line fix; orchestrator should propagate the patch to all four files in a single sequential heal commit.

---

## 4-list

### P0 (production-blocker)
- **PK-2-SRE-06** — Five Vuex callsites omit `X-Idempotency-Key` header on routes flagged required in `config/idempotency.php` with `IDEMPOTENCY_MIDDLEWARE_ENABLED=true` (.env:92):
  - `resources/js/store/modules/kitchenDisplaySystemOrder.js:38` — KDS change-status (PK-2 primary)
  - `resources/js/store/modules/onlineOrder.js:101` — online change-status
  - `resources/js/store/modules/onlineOrder.js:111` — online change-payment-status
  - `resources/js/store/modules/tableOrder.js:101` — table change-status
  - `resources/js/store/modules/tableOrder.js:111` — table change-payment-status

  Sister `resources/js/store/modules/posOrder.js:258-263` already healed `[PS-2 audit 2026-05-18]` via `buildIdempotencyHeaders(payload)` — pattern simply was not propagated. Every affected POST returns 422 "Header X-Idempotency-Key requis pour cette opération." KDS is the most user-visible failure (chef bump broken on every tap). Online + table share root cause and a single 5-line fix.

### P1 (latent, paired)
- **PK-2-SRE-07** — 425 `IDEMPOTENCY_IN_FLIGHT` not handled by Vuex store (only 409 has special branch at line 42-44). Will surface as misleading "status conflict" banner once P0 is fixed and transient network blips trigger duplicate-key races.

### P2 (review / V1.0.2 candidates)
- **PK-2-ARCH-04** — Spec drift: "recall" in brief implies backend PREPARED->PREPARING transition; implementation is client-side only (no backend recall endpoint; OrderStateMachine forbids reverse). Two parallel client mechanisms (3s pre-PATCH V2 undo + 60s legacy localStorage per-item marker) with different windows. Recommend declaring current design final or escalating to owner-gate for state-machine change (FROZEN).
- **PK-2-ARCH-05** — Time-window drift between client undo paths (3s V2 vs 60s legacy per-item). Confusing if both UIs co-exist.
- **PK-2-SEC-05** — Idempotency replay bypasses controller authorization on cached 2xx (replayed inside middleware before route authorize). Low practical risk; sentinel recommended.

### P3 (low priority)
- **PK-2-ARCH-08** — Auto-promote watcher bypasses 3s undo window (by design per kdsAutoTransition.js comment); V1.0.2 should gate behind per-branch toggle for multi-station deployments.
- **PK-2-SEC-07** — `expected_status` is integer-cast but not bound to actor's last-seen status — by design, no data corruption possible (server's lockForUpdate read is authoritative).

### Attestations PASS (12)
- OrderStateMachine FROZEN integrity preserved — read-only attest (ARCH-01)
- KDS FormRequest whitelist {ACCEPT, PREPARING, PREPARED} double-layered with service KitchenReleaseRule (ARCH-02, ARCH-03)
- Bidirectional sync wiring symmetric via OrderStatusChanged on private-branch.{id} (ARCH-06)
- PersistOrderStatusChangedToOutbox correlation_id-scoped idempotency (ARCH-07, SRE-05)
- Triple-layer authz: Spatie permission + role FormRequest + branch row check INSIDE lock (SEC-01)
- kiosk:order cannot reach KDS endpoints (SEC-02)
- Undo + recall hold no server authority (SEC-03, SEC-04)
- Broadcast scoped to private-branch — no cross-tenant leakage (SEC-06)
- DB::transaction + lockForUpdate at all mutation entry points; afterCommit deferred dispatch (SRE-01, SRE-02, SRE-09)
- Optimistic conflict detection layered over pessimistic lock (SRE-03)
- Broadcast failure isolated via try/catch — never blocks HTTP (SRE-04)
- KdsSyncService polling fallback covers WS outage (SRE-08)

### Test gaps (RED)
- Concurrent two-station bump (multi-pdo race) — RED-06
- Idempotency replay post-role-revoke — RED-09 (paired with SEC-05)

---

## HEAL applied this round

**NONE.** PK-2 task explicitly says "HEAL safe non-frozen non-dirty files only" and advisor flagged that the Vuex store fix, while one-line and trivially safe, is in a shared frontend file (`resources/js/store/modules/kitchenDisplaySystemOrder.js`) being potentially touched by sister sub-agents PK-1/PK-3/PK-4 running in parallel. Patch is documented in PK-2-SRE-06 with exact line + diff; orchestrator should apply sequentially after parallel sub-agents converge.

**No frozen-zone file was touched.** OrderStateMachine.php was read-only. No dirty files from `git status` were modified.

---

## Recommended HEAL diff (defer to orchestrator — apply to 5 callsites together)

Reuse the existing `buildIdempotencyHeaders(payload)` helper already imported by `posOrder.js` (locate via `grep -n buildIdempotencyHeaders resources/js/store/modules/posOrder.js`). Pattern to apply at each of the 5 callsites:

```js
// resources/js/store/modules/kitchenDisplaySystemOrder.js — changeStatus
import { buildIdempotencyHeaders } from '../../helpers/idempotencyHeaders'; // path = posOrder.js's import

changeStatus: function (context, payload) {
    return new Promise((resolve, reject) => {
        // [PK-2-SRE-06 heal — propagate PS-2 audit 2026-05-18 pattern]
        // Without this header, IDEMPOTENCY_MIDDLEWARE_ENABLED=true returns 422.
        axios.post(`admin/kds-order/change-status/${payload.id}`, payload, {
            headers: buildIdempotencyHeaders(payload),
        }).then((res) => {
            context.dispatch("lists", payload).then().catch();
            resolve(res);
        }).catch((err) => {
            if (err.response && err.response.status === 409) {
                context.dispatch("lists", payload).catch(() => {});
                context.dispatch("orderItems").catch(() => {});
            }
            // [PK-2-SRE-07 follow-up] 425 IDEMPOTENCY_IN_FLIGHT — retry once
            // after race_wait_ms with the same key, or refresh and bail.
            reject(err);
        });
    });
},
```

Same one-line `headers: buildIdempotencyHeaders(payload)` injection to:
- `resources/js/store/modules/onlineOrder.js:101` (changeStatus)
- `resources/js/store/modules/onlineOrder.js:111` (changePaymentStatus)
- `resources/js/store/modules/tableOrder.js:101` (changeStatus)
- `resources/js/store/modules/tableOrder.js:111` (changePaymentStatus)

Rollback: revert the five header injections. Risk: nil — header is purely additive and follows the established `[PS-2 audit 2026-05-18]` pattern already shipped on posOrder.

---

## Files referenced

### Read-only audit targets
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/app/Domain/Order/OrderStateMachine.php` (FROZEN — attest)
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/app/Events/OrderStatusChanged.php`
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/app/Listeners/PersistOrderStatusChangedToOutbox.php`
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/app/Listeners/DispatchKdsTicket.php`
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/app/Services/KitchenDisplaySystemOrderService.php`
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/app/Services/OrderService.php` (changeStatus + delivery paths)
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/app/Domain/Kds/KitchenReleaseRule.php`
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/app/Rules/ValidStatusTransition.php`
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/app/Http/Requests/Kds/KdsOrderStatusRequest.php`
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/app/Http/Requests/OrderStatusRequest.php`
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/app/Http/Controllers/Admin/KitchenDisplaySystemController.php`
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/app/Http/Controllers/Admin/KdsSyncController.php`
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/app/Http/Controllers/Admin/PosController.php`
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/app/Http/Middleware/IdempotencyKeyMiddleware.php`
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/config/idempotency.php`
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/routes/api.php:1048-1059`
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/resources/js/components/admin/kitchenDisplaySystem/KdsV2Grid.vue`
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/resources/js/components/admin/kitchenDisplaySystem/KdsUndoToast.vue`
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/resources/js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue`
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/resources/js/store/modules/kds.js`
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/resources/js/store/modules/kitchenDisplaySystemOrder.js`
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/resources/js/helpers/kdsAutoTransition.js`
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/tests/Feature/KdsTransitionWhitelistTest.php`
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/tests/Feature/KdsChangeStatusConcurrencyTest.php`
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/tests/Feature/KdsExpectedStatusConflictTest.php`
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/tests/Feature/Sentinels/KdsTransitionWhitelistSentinelTest.php`
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/tests/Feature/Sentinels/KdsExpectedStatusConflictSentinelTest.php`

### Deliverables (this round)
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/reports/audit/intersection-pos-kds-2026-05-18/round-1/PK-2-STATUS-SYNC/architect.json`
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/reports/audit/intersection-pos-kds-2026-05-18/round-1/PK-2-STATUS-SYNC/security.json`
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/reports/audit/intersection-pos-kds-2026-05-18/round-1/PK-2-STATUS-SYNC/sre.json`
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/reports/audit/intersection-pos-kds-2026-05-18/round-1/PK-2-STATUS-SYNC/red.json`
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/reports/audit/intersection-pos-kds-2026-05-18/round-1/PK-2-STATUS-SYNC/STATUS.md`
