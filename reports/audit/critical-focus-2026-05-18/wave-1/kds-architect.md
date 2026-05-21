# KDS Architecture Audit — Wave 1 (Critical Focus 2026-05-18)

Branch: `v1-0-1-hardening-2026-05-17` HEAD `6908edbde`.
Scope: KDS unified-queue V2 + legacy 4-column fallback + sync polling + broadcast.

---

## 1. Surface inventory

### Frontend

- Orchestrator (2,639 LOC): `resources/js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue`
  - `useV2Layout` precedence (URL / localStorage / window cfg / default): 1191-1223.
  - Adaptive sync wiring + `kdsSyncService.start()`: 1418-1434.
  - WebSocket bind + `_pollingInterval()` 5 s/60 s: 1717-1750.
  - Echo channel `branch.{branchId}` (`OrderStatusChanged`, `OrderCreated`, `OrderPaidAtCounter`, `ItemAvailabilityChanged`, `OrderTableChanged`): 1754-1784.
  - Lane bucket (kiosk-first by `source_surface`): 1822-1847.
  - Persistent `kdsErrorBanner` + ✕ dismiss: 1145-1156, 1910-1938.
  - `orderStatus()` + V2 `onV2ChangeStatus()`: 2068-2099, 1471-1506.
  - Local bump store + readiness inference: 1685-1716.
- V2 atoms:
  - `KdsV2Grid.vue` 297 LOC -- FIFO sort 117-128, auto-promote watcher 130-148, 3 s pending CTA 181-208.
  - `KdsOrderCard.vue` 632 LOC -- 52 px CTA `.kds-card__cta` line 585, phone PENDING_ scrub 315-325, delivery precedence 282-293.
  - `KdsOrderLine.vue` 284, `KdsStatusBanner.vue` 204, `KdsUndoToast.vue` 184 LOC.
- Helpers (7): `kdsState.js`, `kdsCustomization.js`, `kdsAllergens.js`, `kdsSource.js`, `kdsAutoTransition.js`, `kdsDisplay.js`, `kdsLineSemantics.js`.
- Vuex: `store/modules/kds.js` 87 LOC (bump map persisted `kds.bumped_items_v1`, 60 s recall); `store/modules/kdsInflight.js` 158 LOC (OOS map, 10 min lazy TTL).
- `resources/js/services/KdsSyncService.js` 470 LOC.
  - Cadence `_baseCadence` 281-304 (CONNECTED->Infinity drift 60 s, DEGRADED->5 s+2 s jitter, DISCONNECTED->10 s+3 s jitter, high-activity 3 s+1 s for 60 s post >5 fresh orders).
  - Reconnect-storm 0-500 ms jitter + force sync: 247-265.
  - 5xx backoff cap 30 s: 356-378. Network catch re-schedules: 192-217.

### Backend

- `app/Http/Controllers/Admin/KitchenDisplaySystemController.php` (61 LOC) -- `index` (list), `changeStatus`, `orderItems`. Permission `kitchen-display-system` enforced line 22.
- `app/Http/Controllers/Admin/KdsSyncController.php` (79 LOC) -- adaptive polling endpoint, branch resolution lines 50-66, cross-branch 403 line 63.
- `app/Services/KitchenDisplaySystemOrderService.php` (360 LOC):
  - `list()` whereBetween sargable lines 91-102 + cap 50/overflow flag at 51 lines 132-137.
  - `changeStatus()` DB transaction + `lockForUpdate` lines 158-210 + 409 conflict log lines 171-184 + branch isolation lines 164-167 + idempotent same-state early return lines 186-188.
  - `orderItems()` aggregation + allergen hash split lines 239-296.
- `app/Services/KdsSyncService.php` (146 LOC):
  - `Cache::remember` 5 s + minute-bucket cache key lines 39-49.
  - `computeOrderVersion()` = `updated_at` timestamp lines 138-145 (TODO: status_changed_at planned).
  - **DRIFT**: uses `whereDate('order_datetime', Carbon::today())` lines 65, 69 -- NOT migrated to sargable `whereBetween`, contradicts Wave 5F P1 perf fix applied in `KitchenDisplaySystemOrderService::list()` and `::orderItems()`.
- `app/Http/Resources/KDSOrderDetailsResource.php` (74 LOC) -- exposes `created_at_iso`, `order_address`, `customer.phone` (DELIVERY-only line 70), `payment_pending_counter`.
- `app/Http/Resources/KDSOrderItemsResource.php` (69 LOC) -- exposes `composition_snapshot.addons` line 36 and `allergens_snapshot` (read via grouping in service, not surfaced directly here -- gap).
- `app/Http/Requests/Kds/KdsOrderStatusRequest.php` (42 LOC) -- roles `Admin|Branch Manager|Chef|POS Operator|Cashier`, statuses ACCEPT/PREPARING/PREPARED only.
- `app/Domain/Kds/KitchenReleaseRule.php` (83 LOC) -- whitelist `ACCEPT -> PREPARING`, `PREPARING -> PREPARED` (lines 41-49); same-status idempotent.
- `app/Listeners/DispatchKdsTicket.php` (21 LOC) -- gates `OrderStatusChanged::dispatch` on whitelisted transitions only.
- Routes: `routes/api.php:1005-1011` -- `/api/admin/kds-order` GET/POST + `/sync`.
- Broadcast plumbing: `app/Events/OrderCreated.php` (26 LOC) is a plain domain event with `DispatchableAfterCommit`; broadcast comes via `App\Listeners\PersistOrderCreatedToOutbox` (lines 24-79) -> `DomainEvent` row with `channel=['private-branch.'.branch_id]`, `broadcast_as='OrderCreated'` -> `DispatchDomainEventsJob` async (line 68). UNIQUE-key idempotency on `sha1(EventType::ORDER_CREATED.'|'.id)` line 22.

### Tests (~54 functions across 22 files)

- `tests/Feature/KDS/` (4 files): KdsAllergenAggregationSplitTest, KdsSnapshotImmutableTest, KDSDeliveryEnrichmentTest, BackfillAllergensSnapshotTest.
- `tests/Feature/Kds*.php` (8 files): branch-filter, change-status concurrency, expected-status conflict, pagination overflow, scope restriction, transition whitelist, KDS flow, sort.
- `tests/Feature/KitchenReleaseRuleTest.php`, `tests/Feature/Orders/KDSAllergenVisibilityTest.php`, `tests/Feature/Admin/KdsSyncControllerTest.php`.
- Sentinels: `KdsTransitionWhitelist`, `KdsExpectedStatusConflict`, `KdsItemAvailabilityEcho`.
- Playwright: `tests/Playwright/KdsMultiScreenPlaywrightTest.spec.js`.

---

## 2. Critical invariants

| Invariant | Status | Citation |
|---|---|---|
| Order broadcast latency <1 s WS + <30 s fallback | OK (with caveat) | `KdsSyncService.js:285-303`; CONNECTED -> Infinity timer drift 60 s line 338-345; DISCONNECTED -> 10 s + 3 s jitter line 300. Caveat: when WS up, only 60 s drift sync runs -- a missed broadcast surfaces no earlier than 60 s. |
| `DispatchKdsTicket` fires on `OrderCreated` (Outbox-mediated) | OK | `PersistOrderCreatedToOutbox.php:24-79` (Outbox row + `DispatchDomainEventsJob` after commit). `DispatchKdsTicket::dispatch` itself ONLY fires `OrderStatusChanged` (see `KitchenDisplaySystemOrderService.php:222-227`), not OrderCreated. The mandate phrasing "DispatchKdsTicket listener fires on OrderCreated" is INACCURATE -- KDS receives OrderCreated via PersistOrderCreatedToOutbox -> outbox -> Soketi, NOT via DispatchKdsTicket. |
| `KDSOrderDetailsResource` hides `customer.phone` unless DELIVERY (Z9-P0-03) | OK | `KDSOrderDetailsResource.php:68-71` -- ternary `((int) $this->order_type === OrderType::DELIVERY) ? phone : null`. |
| `allergens_snapshot` exposed for kitchen | PARTIAL | `KDSOrderItemsResource.php` does NOT expose `allergens_snapshot` at all -- aggregation hashes it server-side in `KitchenDisplaySystemOrderService::orderItems` lines 281-289, but the items-board JSON omits the array. V2 card reads `allergens_snapshot` via the per-order list endpoint (allergen pill in `KdsOrderCard.vue:221-223`). For backward parity the legacy items board still has no allergen list. |
| V2 grid bump button 52 px touch target | OK | `KdsOrderCard.vue:585` (`.kds-card__cta { height: 52px; }`). |
| Status transitions ACCEPT -> PREPARING -> PREPARED -> BUMPED | PARTIAL | Server-side `KitchenReleaseRule.php:41-49` only whitelists ACCEPT->PREPARING and PREPARING->PREPARED. There is NO server "BUMPED" state in the OrderStatus enum (`kdsState.js:13-23`); "bump" = client-side local bump map persisted to localStorage (`store/modules/kds.js:55-62`) which marks individual items in `kds.bumped_items_v1` but does NOT transition order status. The status-machine endpoint refuses any 4th step. Therefore the BUMPED invariant maps to PREPARED on the wire. Mandate phrasing creates terminology drift. |
| Allergen split (PIN dish 1169/2011 EU) | OK | `KitchenDisplaySystemOrderService.php:281-289` hashes allergens into the merge key so two same-item lines with different allergen snapshots stay split as distinct KDS lines. Backend `normalizeAllergensForHash:315-329` mirrors frontend `kdsAllergens.js:51-53`. |
| Adaptive polling base + jitter | OK (config drift) | `KdsSyncService.js:281-304` reads cadence from `window.foodkingConfig.kdsFallbackPolling` injected from `config/catalog_v15.php:79-89`. Defaults: degraded 5_000 ms base + 2_000 ms jitter, disconnected 10_000 + 3_000, high-activity 3_000 + 1_000. Mandate header claims `DEGRADED_BASE_MS=2000` -- DOES NOT match shipped defaults (5_000). Either the mandate header is stale or `.env` overrides at deploy. |

---

## 3. Wave Z heals verified

- **Z3-NEW-005 `allergens_snapshot` backfill**: VERIFIED. Migration `database/migrations/2026_04_18_140004_add_allergens_snapshot_to_order_items.php` adds the column nullable. Backfill command `foodking:backfill:allergens-snapshot` (called in `tests/Feature/KDS/BackfillAllergensSnapshotTest.php:50`) populates NULL rows from current `items.allergens` pivot, bypasses `BranchScope`, supports `--dry-run`, idempotent. Locale backfill `2026_04_20_131600_backfill_fr_codes_in_order_items_allergens_snapshot.php` remaps EN -> FR codes.
- **Z3-NEW-006 V2 kill-switch env/config**: VERIFIED. `config/kds.php:24-28` reads `KDS_V2_DEFAULT_ENABLED=true` env, normalises via `filter_var` + null fallback to true. `KitchenDisplaySystemComponent.vue:1191-1223` resolves 5-step precedence: URL ?v2=0/1 -> localStorage -> `window.FK_KDS_V2_DEFAULT_ENABLED` (Blade-injected) -> default true. Operator rollback path validated.
- **Z3-NEW-007 aria-label i18n 5-lang**: PARTIAL / DEGRADED. `resources/js/languages/fr.json` (73 keys), `en.json` (73), `ar.json` (73) all carry `kds_card_aria`, `kds_card_cta_ready`, `kds_state_new/preparing/ready/done/cancelled`, `kds_attente`, `kds_allergie`, `kds_aria_live_*`, `kds_undo_done`, `kds_empty_state`, `kds_empty_state_sub`, `kds_call_customer_aria`. `bn.json` (2 keys -- only `kds_card_aria`) and `de.json` (2 keys -- only `kds_card_aria`) are MISSING >70 V2 labels. Production deployment in Bengali/German falls back to literal $t() keys (e.g. `label.kds_empty_state`). Documented label.X regression risk in `CLAUDE.md` §6 "raw labels detection" -- direct heal needed for V1 if either locale ships.

---

## 4. Weak spots

1. **`KdsSyncService.php:65,69` whereDate non-sargable** -- delta endpoint still uses `whereDate('order_datetime', Carbon::today())`. Wave 5F sargable fix applied to `list/orderItems` but missed sync. Regression 10x-30x at 50+ active orders. P1.
2. **WS-up drift 60 s** -- `KdsSyncService.js:338-345` drifts at 60 s when CONNECTED. A silently dropped Soketi broadcast surfaces up to 60 s later; breaches "<30 s polling fallback" mandate. No heartbeat probe.
3. **Bump state stale cross-device** -- `store/modules/kds.js:30-32` stores in localStorage per device. 2-station kitchen sees divergent bump state; banner mitigates UX but defect remains. P2.
4. **Auto-transition burst** -- `KdsV2Grid.vue:130-148` watcher fires per queue rebuild; multi-event refresh may emit 2 PATCH before first commits, generating KDS_409 noise. Server `lockForUpdate` self-corrects. P3.
5. **`KDSOrderItemsResource` omits `allergens_snapshot`** -- legacy items board has no allergen rendering despite hash-split. `?v2=0` rollback loses allergen pill. P2.
6. **CTA focus outline `#4B5563` over `#1F2937`** -- `KdsOrderCard.vue:603-606` may fail WCAG 3:1 non-text contrast. To confirm visually.
7. **Backoff doubles previous not base** -- `KdsSyncService.js:362-364` correct but subtle and untested; on WS-up CONNECTED previous=Infinity then base*2.
8. **Admin (`branch_id=0`) polling-only** -- `KitchenDisplaySystemComponent.vue:1757` skips Echo subscribe; documented via `kds_admin_polling_hint`. Multi-branch ops are blind up to 60 s on healthy network.
9. **Axios interceptor leak** -- `KitchenDisplaySystemComponent.vue:1388-1411` registers global interceptor; HMR/nav race could leak `_refreshWithCurrentFilter`. P3.
10. **No visual state for "system blind"** -- `KdsV2Grid.vue:30-44` empty state covers `length===0` but not "all polls failing >2 min". Error banner covers HTTP only; no double-fault indicator.

---

## 5. Existing test coverage

- **Transition whitelist + sentinels** (`KdsTransitionWhitelistTest.php`, sentinel): ACCEPT/PREPARING/PREPARED whitelist.
- **Concurrency**: `KdsChangeStatusConcurrencyTest.php` + `KdsExpectedStatusConflictTest.php` + sentinel cover the `lockForUpdate` + `expected_status` 409 path.
- **Branch isolation**: `KDSScopeRestrictionTest.php` + `KdsBranchFilterExactTest.php` enforce branch-id integer match (POS-9.1.5 fix).
- **Pagination cap**: `KdsPaginationOverflowTest.php` -- 50 cap + 51-probe overflow flag.
- **Sort + sargable date**: `KitchenDisplaySystemOrderSortTest.php`.
- **Allergens**: `KdsAllergenAggregationSplitTest.php` (split on differing snapshot) + `BackfillAllergensSnapshotTest.php` (idempotent backfill) + `KDSAllergenVisibilityTest.php` + sentinel `KdsItemAvailabilityEchoSentinel`.
- **Snapshot immutability**: `KdsSnapshotImmutableTest.php`.
- **Delivery enrichment + GDPR phone**: `KDSDeliveryEnrichmentTest.php` -- DELIVERY exposes phone, others null.
- **Sync controller**: `Admin/KdsSyncControllerTest.php` -- since param validation, branch cross-tenant 403, include_deleted.
- **Items endpoint**: `KDSOrderItemsTest.php`.
- **Full flow**: `KDSFlowTest.php`.
- **Multi-screen Playwright**: `KdsMultiScreenPlaywrightTest.spec.js`.

---

## 6. Test coverage GAPS

1. **NO test for `KdsSyncService::sync()` sargable index usage** -- the `whereDate` regression in `app/Services/KdsSyncService.php:65,69` will not surface in any current PHPUnit test because tests run on SQLite where DATE() differences are immaterial.
2. **NO test that V2 auto-transition watcher does not emit duplicate PATCH under burst refresh** (Weak spot #4).
3. **NO test of WS-up 60 s drift** -- if the Soketi channel silently drops a message, the test suite assumes Echo delivers; there is no client-side timeout assertion.
4. **NO test of the persistent `kdsErrorBanner` lifecycle** -- raise -> clear -> dismiss -> re-raise (lines 1910-1938). Only inferred through `KdsMultiScreenPlaywrightTest`.
5. **NO Vitest spec for `KdsV2Grid` keyboard A-H bump shortcut** (`KdsV2Grid.vue:169-179`).
6. **NO server enforcement of phone-PENDING_ filter** -- `KdsOrderCard.vue:319-324` strips client-side, but if the server backfills `PENDING_<id>` placeholders for users created via legacy paths (`User::creating`), other consumers receive the placeholder. `KDSDeliveryEnrichmentTest` does not assert the PENDING_ scrub.
7. **NO test for `Z3-NEW-007` aria-label parity across 5 locales** -- the bn.json/de.json gap (Section 3) has no failing PHPUnit/Vitest case to catch it.
8. **NO test of `payment_pending_counter` -> KDS visibility** when payment_status flips PENDING_COUNTER -> PAID without status change (Z3-NEW-006 sibling area).
9. **NO test of `kdsInflight` 10 min TTL lazy purge** for the OOS warning badge (`kdsInflight.js:43-55`).
10. **NO test of `_eventSub.unsubscribe()` cleanup on remount** -- duplicate-listener AUDIT-P51-BUG2 fix is asserted only by comment, not by test.
11. **NO test of WS reconnect storm jitter 0-500 ms** (`KdsSyncService.js:247-265`) -- thundering herd risk on Soketi restart.

---

## 7. Recommendations

1. **P1**: Heal `KdsSyncService.php:65,69` to use `whereBetween('order_datetime', [Carbon::today(), Carbon::today()->endOfDay()])` mirroring `KitchenDisplaySystemOrderService::list:91-95`. Add a regression PHPUnit asserting the query SQL contains `>=` + `<=` and not `DATE(`.
2. **P1**: Complete `Z3-NEW-007` aria-label backfill in `resources/js/languages/bn.json` + `de.json`. Either ship the 70+ missing keys OR document the locale gap in `docs/decisions/` so the audit trail is honest. Mandate phrasing was "5-lang" -- as shipped FR/EN/AR is 3-lang complete.
3. **P2**: Surface `allergens_snapshot` directly in `KDSOrderItemsResource.php` so the legacy rollback path retains allergen visibility for chef food-safety -- 5 lines, no schema change.
4. **P2**: Add a Soketi heartbeat probe in `KdsSyncService.js` that triggers `forceSync()` if no `OrderStatusChanged` received in N seconds while WS state = CONNECTED. Mandate "<1 s WS" is met only if Soketi never drops messages -- a probe closes the gap to "<30 s polling fallback" guarantee.
5. **P2**: Verify shipped `.env` defaults match mandate (DEGRADED_BASE_MS=2000) -- either update `config/catalog_v15.php:85` to 2_000 or update the mandate to read the real defaults (5_000 + 2_000 jitter).
6. **P3**: Add Vitest specs covering Section 6 gaps #2 (auto-transition burst), #5 (keyboard A-H), #9 (kdsInflight TTL). All three are pure-helper testable without mounting full Vue component.
7. **P3**: Resolve the BUMPED terminology drift -- either add a server-side BUMPED OrderStatus + KitchenReleaseRule transition, or rename the invariant to "PREPARED" everywhere (CLAUDE.md, plan, audit checklist) to match the wire reality.
8. **P3**: Cleanup the axios global response interceptor leak risk (`KitchenDisplaySystemComponent.vue:1388-1411`) via WeakRef + eject on `beforeUnmount` guard already in place at line 1119.

---

GStack Architect -- KDS -- Wave 1.
