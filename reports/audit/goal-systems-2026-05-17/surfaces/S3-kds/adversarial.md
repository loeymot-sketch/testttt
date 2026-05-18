# S3-KDS — RED-team Adversarial Audit
**Date:** 2026-05-17
**Auditor:** RED-team (hostile static analysis, READ-ONLY)
**Scope:** KDS attack surface — double-bump race, stale order, allergen pill miss (FIC 1169), KDS-OSS sync drift, payload contract drift, KDS event injection, branch leak, memory leak, auth, recall abuse.

## Methodology
- READ-ONLY static analysis. No tests executed, no Playwright.
- Test-first reading: gaps in test coverage = real attack surface.
- All findings carry `file:line` + concrete attack scenario.
- Worktree copies (`.claude/worktrees/*`) skipped — stale.

---

## Findings

### F-S3-RED-01 — [P0] Allergen pill miss on KDS Items Board (FIC 1169 legal exposure)
**File:line:**
- `app/Http/Resources/KDSOrderItemsResource.php:18-27` — payload definition
- `resources/js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue:124-165` — items-board render loop
- Contrast: `app/Http/Resources/OrderItemResource.php:37` exposes `allergens_snapshot` correctly to order-cards.

**Attack scenario:**
1. Customer A orders Cheeseburger declaring **peanut** allergy → `OrderItem.allergens_snapshot = ['arachides']`.
2. Customer B orders an identical Cheeseburger declaring **gluten** allergy → `OrderItem.allergens_snapshot = ['gluten']`.
3. Backend `KitchenDisplaySystemOrderService::orderItems()` (line 267-289) correctly splits the two rows by `allergens_hash` (defense F-G-5 lot 2.I) — they appear as **two distinct lines**.
4. But `KDSOrderItemsResource::toArray()` (line 18-27) does NOT emit the `allergens_snapshot` field. The frontend items-board loop (`KitchenDisplaySystemComponent.vue:124-165`) renders item_variations/extras/addons/instruction — never allergens.
5. Chef working from the left-hand items board sees:
   `Cheeseburger ×1` … `Cheeseburger ×1` — visually identical. They can swap plates between customers.
6. Peanut allergen reaches customer B → anaphylactic shock / FIC EU 1169/2011 violation → criminal prosecution (France: up to 5 years prison + €375 000 fine for non-compliant food labelling causing harm).

**Evidence — contract asymmetry:**
- `OrderItemResource.php:37` (used by order cards): `'allergens_snapshot' => $this->safeJsonDecode($this->allergens_snapshot)` → present.
- `KDSOrderItemsResource.php:18-27` (used by items board): no `allergens_snapshot` key at all.
- Component template parity: order cards render allergen chips at line 830-838 + allergen badge at line 281, 442, 613, 763 (gated by `orderHasAllergens`); items board template (line 124-165) renders zero allergen cue.

**Defense-by-design that fails:** the backend G-5 split (`KitchenDisplaySystemOrderService.php:267-300`) does its job — `tests/Feature/KDS/KdsAllergenAggregationSplitTest.php` proves it. The split is silent: two lines hide two allergens behind two identical labels. Without the badge/chip, the chef cannot distinguish them. The defense becomes the trap.

**Exploitability:** **Trivial** — natural cooking workflow. No attacker needed; a busy chef using the items board (its documented purpose: "items_board_scope" pacing view) will eventually swap.

**Cost vector:** FIC 1169 legal exposure → criminal liability, brand-destruction PR, restaurant licence revocation. Workflow caveat: if chef ONLY works from order-card view (right pane, 9 cols), badge gates them. Default rating **P0** because: (a) items board is the documented pacing surface for stations (cuisine_chaude/cuisine_froide), (b) FIC 1169 prison-time exposure forbids softening on workflow assumption, (c) the asymmetry vs. order cards proves the field exists and was deliberately omitted/forgotten.

**Fix:** add `'allergens_snapshot' => $this->safeJsonDecode($this->allergens_snapshot)` to `KDSOrderItemsResource::toArray()` + render chips in the items-board loop (mirror lines 830-838 inside the `<li>` at line 124-165).

---

### F-S3-RED-02 — [P2] Double-tap Démarrer / Prêt buttons have no client-side disable
**File:line:**
- `KitchenDisplaySystemComponent.vue:403-412` (dinein CTA)
- `KitchenDisplaySystemComponent.vue:575-583` (online CTA)
- `KitchenDisplaySystemComponent.vue:721-729` (takeaway CTA)
- `KitchenDisplaySystemComponent.vue:870-878` (kiosk CTA)
- `KitchenDisplaySystemComponent.vue:1974-1981` — `orderStatus` sets `loading.isActive = true` AFTER click handler entry, but no `:disabled="loading.isActive"` binding on the button.

**Attack scenario:**
1. Chef double-clicks "Prêt" on order #X (status PREPARING → PREPARED).
2. Two POST requests fly to `/api/admin/kds-order/change-status/X` with same `expected_status=PREPARING`.
3. Vue `loading.isActive` set at line 1976 only after the second click already dispatched.
4. Both reach `KitchenDisplaySystemOrderService::changeStatus` (line 151-233). One wins the `lockForUpdate` (line 158-161), sets status to PREPARED, dispatches `OrderStatusChanged`. The second one reads `locked->status === PREPARED ≠ expected_status === PREPARING` → aborts 409 (line 170-183).
5. UI receives 409 → catch block at `kitchenDisplaySystemOrder.js:42-45` triggers `lists` + `orderItems` re-fetch; `orderStatus` method at line 1998-2001 shows `message.kds_status_conflict` toast.

**Why not P0:** The server-side defense is airtight — `KdsExpectedStatusConflictTest.php:30-67` proves it (`Event::assertDispatchedTimes(OrderStatusChanged::class, 1)`). No double-bump reaches OSS. NF525 chain unaffected. Worst-case: spurious error toast + refresh shimmer for the chef.

**Cost vector:** UX (false alarm noise during rush). No data corruption.

**Exploitability:** **Trivial** — accidental double-tap on touch tablet.

**Fix:** add `:disabled="loading.isActive"` + CSS dim state to all 4 Démarrer/Prêt buttons. Cheap, prevents 100% of toast spam.

---

### F-S3-RED-03 — [P2] `kds.bumped_items_v1` localStorage grows unboundedly across shifts
**File:line:**
- `resources/js/store/modules/kds.js:1` — `STORAGE_BUMPED = 'kds.bumped_items_v1'`
- `resources/js/store/modules/kds.js:55-62` — `bumpItem` action persists `{orderId: {itemId: timestamp}}` to localStorage
- `resources/js/store/modules/kds.js:66-85` — `recallItem` only removes a single item, never garbage-collects whole orders
- Contrast: `kdsInflight` module got 10-min TTL purge per CV1-KDS-INFLIGHT-OOS-MARKER-001 — this one didn't.

**Attack scenario:**
1. 12-hour kitchen shift = ~300 orders × ~5 items = 1500 bumps.
2. Each bump appends `{orderId, itemId, timestamp}` JSON tuple to `localStorage['kds.bumped_items_v1']`.
3. Orders move to DELIVERED/CANCELED and disappear from KDS list, but their bumped-items entries remain forever in localStorage.
4. After 30 shifts (1 month) → ~45k tuples, ~3-5 MB JSON serialised + parsed on every bump (line 11-19 reads full JSON on each `loadMap()` call only at module init, but `persistMap` at line 22-27 writes the full state every time → I/O cost grows O(n)).
5. On a low-RAM kitchen tablet → tab freezes, KDS becomes unresponsive during peak.

**Cost vector:** Performance degradation (kitchen blind during rush — operational cost) + tablet OOM (hardware cost).

**Exploitability:** **Trivial** — happens naturally over time, no attacker.

**Fix:** when `OrderStatusChanged` arrives with status DELIVERED/CANCELED/REJECTED, drop `state.bumpedByOrder[orderId]` from the map. Mirror the lazy-TTL purge pattern from `kdsInflight.js`.

---

### F-S3-RED-04 — [P2] `kdsRecall` is client-only fiction — desync if backend already advanced
**File:line:**
- `resources/js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue:1601-1612` — `kdsBump` auto-promotes (`orderStatus(PREPARED)`) when all items bumped
- `resources/js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue:1614-1622` — `kdsRecall` dispatches client-only `kds/recallItem`
- `resources/js/store/modules/kds.js:66-85` — `recallItem` removes localStorage entry only; **no HTTP call**.
- Backend: no `/admin/kds-order/recall` endpoint exists (`routes/api.php:1003-1009`).

**Attack scenario:**
1. Order has 3 items. Chef bumps all 3 in <60s.
2. `kdsBump` on the 3rd bump triggers `isReadyOrder` → auto-calls `orderStatus(PREPARED)` (line 1604-1609). Backend status → PREPARED. `OrderStatusChanged` broadcast → OSS shows "Prêt".
3. Chef realizes one item burned, clicks "Rappel" (recall) on it. UI calls `kds/recallItem` → removes localStorage flag, "Bump" button reappears on the item.
4. Backend status is still **PREPARED**. OSS still shows "Prêt". Customer is called to pick up.
5. Customer arrives, food incomplete → dispute, refund, brand damage.

**Cost vector:** Customer-experience regression (false "ready" signal); silent data desync between KDS UI state and DB state.

**Exploitability:** **Conditional** — requires chef to recall after auto-promotion. The 60s grace (`kds.js:71`) tries to mitigate but doesn't address the desync — the server already broadcast PREPARED.

**Fix:** either (a) implement true backend recall endpoint with `OrderStateMachine.allows(PREPARED → PREPARING)` (currently forbidden line 54-55 — would need exception for chef inside grace window), or (b) refuse the local recall when `order.status === PREPARED` and show a toast "Impossible — commande déjà annoncée prête". Option (b) is safer and minimal-scope.

---

### F-S3-RED-05 — [P3] No dedicated KDS_BUMPED outbox event — piggybacks on OrderStatusChanged
**File:line:**
- `app/Listeners/PersistOrderStatusChangedToOutbox.php:13-87` — single listener handles all status transitions
- No `PersistKdsBumpedToOutbox.php` exists (task brief assumed one).
- `app/Services/KitchenDisplaySystemOrderService.php:222-226` — fires `DispatchKdsTicket::dispatch` which itself emits `OrderStatusChanged` (`DispatchKdsTicket.php:17`).
- `resources/js/services/eventContract.js:16-25` — `BROADCAST_MAP` has no `KdsBumped` entry; KDS state changes consumed via generic `OrderStatusChanged`.

**Attack scenario / observation:**
- Bumping an individual item does NOT generate any backend event — `kdsBump` is purely client-side (localStorage). Only an aggregate `OrderStatusChanged` fires when ALL items bumped trigger auto-promotion.
- Downstream consumers (OSS, analytics, sentinels) cannot distinguish "chef bumped 1 item" from "chef started prep" from "chef declared ready". Status-level granularity only.
- The composition_snapshot → KDS contract is one-way: backend → frontend. No client→server line-level events exist to drift.

**Cost vector:** Limits future per-line analytics (e.g. per-item prep-time metrics, station bottleneck detection). No current data integrity risk.

**Exploitability:** **Theoretical** — design observation, not exploit.

**Fix:** roadmap item — if per-line bump analytics ever needed, introduce a dedicated outbox event `KitchenItemBumped(order_id, order_item_id, bumped_at)` with its own idempotency key.

---

## Defenses Observed (NO FINDING — clean)

### D6 / D7 — KDS event injection + branch leak: **CLEAN**
- `routes/channels.php:25-39` — branch.{branchId} channel: kiosk tokens restricted to their own machine's branch (line 27-30); staff restricted to own `branch_id` (line 38); admin (branch_id=0) allowed all. Cannot forge `OrderStatusChanged` on another branch's channel without auth.
- `KitchenDisplaySystemOrderService.php:163-166` — `changeStatus` enforces `(int) $locked->branch_id === $userBranchId` server-side after `lockForUpdate`. Cross-branch mutation → 403.
- `KdsSyncController.php:60-66` — `/sync` endpoint: if requested `branch_id !== userBranchId` and user not admin → 403.
- `KdsOrderStatusRequest.php:11-21` — role-gated (Admin / Branch Manager / Chef / POS Operator / Cashier) at FormRequest authorize.
- `KdsBranchFilterExactTest.php` proves `LIKE '%1%'` substring leak fixed (line 26-57). Branch 1 ≠ branch 10/11/12.

### D9 — Auth: **CLEAN**
- `api.php:269` — `kds-order` group under `auth:sanctum` + `apiKey` + `localization` + `throttle:admin-mutation`.
- `KitchenDisplaySystemController.php:22` + `KdsSyncController.php:29` — `permission:kitchen-display-system` middleware on every method.

### D1 — Server-side double-bump: **CLEAN**
- `KitchenDisplaySystemOrderService.php:157-209` — DB transaction + `lockForUpdate` + expected_status check + `recordTransition` audit row.
- 3 tests prove: `KdsChangeStatusConcurrencyTest.php` (stale in-memory vs locked → 409), `KdsExpectedStatusConflictTest.php` (replay → 409 + `Event::assertDispatchedTimes(OrderStatusChanged, 1)`), `KdsTransitionWhitelistTest.php` (non-kitchen target → 422).
- Only F-S3-RED-02 (UI no-disable) remains — server fully absorbs the race.

### D2 — Stale order indefinite display: **CLEAN**
- `KdsSyncService.js:192-217` — network errors re-schedule poll cadence (NEW-02 / Lot 1.C / Audit G1). Kitchen self-heals when connectivity returns.
- `KdsSyncService.js:247-265` — `reconnect_storm` event triggers immediate `forceSync` with 0-500ms jitter (Audit G10) — thundering-herd mitigation when broadcasting server restarts.
- `KdsSyncService.js:330-354` — when WS connected, 60s drift poll runs (line 338-344). When degraded, 3-10s adaptive interval.
- `KitchenDisplaySystemComponent.vue:1644-1656` — `_pollingInterval()`: 60s when WS up, 5s when WS down.

### D8 — Memory leak (KDS open 12h): **PARTIAL** (kdsInflight clean, kds.bumpedByOrder leaks — see F-S3-RED-03)
- `kdsInflight.js` — 10-min TTL lazy purge (CV1-KDS-INFLIGHT-OOS-MARKER-001).
- `eventContract.js:99-104` — correlation dedupe bounded to 2048 entries + 10-min TTL.
- `KdsSyncService.js:48` + `:380-393` — version map capped at 256 entries (FIFO eviction).
- Gap: `kds.bumpedByOrder` localStorage is the only unbounded structure (F-S3-RED-03).

### D5 — Payload contract drift: **CLEAN at envelope level**
- `eventContract.js:31-53` — `validateEnvelope` rejects payloads where `version !== 1`. Frontend would log + drop a v2 envelope, not silently consume.
- `eventContract.js:346-366` — handler wraps in try/catch + logs `[eventContract] Failed to parse` on bad shapes.
- `PersistOrderStatusChangedToOutbox.php:38-57` — payload schema centralised in listener. Adding a field is additive (consumers ignore unknown keys); removing a field would surface in handler-level errors via `parseEvent`.

### D10 — Recall fiscal abuse: **NEUTRALIZED at API layer**
- `KdsOrderStatusRequest::kdsStatuses()` (line 34-41) — only ACCEPT/PREPARING/PREPARED valid as `status` or `expected_status`. Cannot CANCEL via KDS endpoint.
- `OrderStateMachine::allows()` (line 54-55) — PREPARED can transition only to OUT_FOR_DELIVERY or DELIVERED. PREPARED → PREPARING is forbidden unless `hasRole('Admin')` (line 63-69 covers RETURNED/CANCELED/REJECTED → admin-only).
- Fiscal sequence allocated at order creation (kiosk paid) or POS close — never at KDS bump. Chef cannot trigger fiscal mismatch via KDS UI.
- Residual: F-S3-RED-04 (UI-only recall desync) is operational, not fiscal.

---

## Summary

The KDS surface has **strong backend defenses** and **one P0 frontend gap with criminal-law consequences**.

**P0 (must fix before V1 ship):** `F-S3-RED-01` — the KDS Items Board (`KitchenDisplaySystemComponent.vue:124-165` consuming `KDSOrderItemsResource`) does not render `allergens_snapshot` and the resource does not expose it (`KDSOrderItemsResource.php:18-27`). The order-cards surface (`OrderItemResource.php:37`) does expose and render it (`KitchenDisplaySystemComponent.vue:830-838`, `:281,442,613,763` badge). Backend G-5 allergen split (`KitchenDisplaySystemOrderService.php:267-300`) correctly creates two distinct lines for two customers with different allergies — but on the items board they appear as visually identical `Cheeseburger ×1` cards. Chef working a station from the items board (its documented pacing purpose) can swap plates between allergic customers. Direct FIC EU 1169/2011 violation → French criminal exposure up to 5 years + €375 000 fine if anaphylactic harm follows. Fix is one resource-field addition + one Vue template block.

**P2 cluster (operational quality):**
- `F-S3-RED-02` — Démarrer/Prêt buttons (4 surfaces, lines 403/575/721/870) lack `:disabled="loading.isActive"`. Double-tap → server 409 absorbs the race (`KdsChangeStatusConcurrencyTest` proves it) but UI noise spam during rush.
- `F-S3-RED-03` — `localStorage['kds.bumped_items_v1']` (`kds.js:55-62`) grows unboundedly; no TTL/GC for completed orders. Multi-week shifts → MB-scale JSON read/write on every bump → tablet freezes. Sibling `kdsInflight` got 10-min TTL; this module forgotten.
- `F-S3-RED-04` — `kdsRecall` (`kds.js:66-85`, component:1614-1622) is client-only. If `kdsBump` already triggered auto-promotion to PREPARED, backend status is locked PREPARED (`OrderStateMachine.php:54-55` forbids PREPARED → PREPARING), OSS announced "Prêt" — but UI shows recall available for 60s. Customer comes to pick up incomplete food.

**P3 (design observation):** `F-S3-RED-05` — no dedicated `KdsBumped` outbox event; bumps either piggyback on `OrderStatusChanged` (aggregate) or stay purely client-side (per-line). Limits future per-station analytics, no current integrity impact.

**Defenses verified clean (8 dimensions):** D1 server-side double-bump (lockForUpdate + expected_status + 409); D2 stale order (network-error re-schedule + reconnect-storm jitter + adaptive cadence); D5 payload contract (envelope version gate + dedupe by correlation_id); D6/D7 event injection + branch leak (channels.php auth + service-layer branch check + sync 403 + substring-LIKE fix); D9 auth (sanctum + permission middleware); D10 recall fiscal abuse (KdsOrderStatusRequest whitelist + StateMachine guards); D8 memory partial — only `kds.bumpedByOrder` leaks (F-S3-RED-03).

**Recommendation:** fix F-S3-RED-01 (P0) before any V1 release that puts kitchen staff in front of the items board. Bundle F-02/03/04 into the next polish cycle.
