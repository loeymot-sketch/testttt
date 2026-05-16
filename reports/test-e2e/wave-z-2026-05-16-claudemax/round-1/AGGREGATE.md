# Round 1 Aggregate — Wave Z (2026-05-16)

**HEAD audited**: `c3ba89863`
**10 Z-systems audited in parallel** (single dispatch, 10 read-only sub-agents).

---

## Verdict matrix

| Z | System | P0 NEW | P1 NEW | P2 | P3 | Healed-verified | Open-from-sister | Per-Z verdict |
|----|--------|--------|--------|----|----|------------------|-------------------|----------------|
| Z1 | POS Caisse + Cash trail | 1 | 3 | 1 | 0 | POS-A1, POS-A2, F-3 (block guard) | POS-A4 frozen LOCK, POS-A6 JS calc | GO-CONDITIONAL |
| Z2 | Kiosk FR-lock | 0 | 0 | 1 | 1 | K-001 | K-002, K-003, K-004 (test-affordance / V1.0.1) | GO |
| Z3 | KDS V2 + Delivery enrich | 2 | 2 | 1 | 1 | KDS-W3-002 (V2 default), KDS-W3-001/003 (via V2), DEL-3 | KDS-W3-004 partial (allergens snapshot backfill) | GO-CONDITIONAL |
| Z4 | OSS | 0 | 2 | 4 | 4 | n/a (no sister findings) | n/a | GO-CONDITIONAL |
| Z5 | Admin Catalogue + Items | 0 | 4 | 5 | 3 | n/a (no sister findings) | V1.0.1 polish | GO-CONDITIONAL |
| Z6 | Auth / RBAC / Sanctum | 0 | 4 | 4 | 0 | n/a (Sprint 4 hardening backlog) | POS-A3 (quote still bypass), K-002 (downgrade P2) | GO-CONDITIONAL |
| Z7 | Fiscal NF525 chain | 0 | 1 | 2 | 2 | chain intact, triggers active, frozen-zone diff=0 | F-2 schema-only (terminal_id dead column) | GO-CONDITIONAL |
| Z8 | Sync Outbox + Webhooks | 0 | 2 | 3 | 2 | P1-SYNC-01 webhook idemp (silent in 80dbc79c2), P1-SYNC-02 cron, P1-SYNC-03 partial 2/8 | 6/8 listeners still no guard, no DLQ webhooks | GO-CONDITIONAL |
| Z9 | Delivery flow | 3 | 1 | 0 | 0 | DEL-1, DEL-2, DEL-3, DEL-4 partial | DEL-5/6/7/8/9 (Sprint 4 backlog) | NO-GO |
| Z10 | Cash drawer UI + TPE rates | 1 | 4 | 3 | 1 | F-1, F-2, F-3, F-4, F-5, F-6, F-8, F-9 partial | F-10, F-11, F-12 (defense-in-depth) | GO-CONDITIONAL |
| **TOTAL** | | **7** | **23** | **24** | **14** | **24 sister findings closed** | | **NO-GO (Z9 P0)** |

---

## P0 list (Round 1 — must heal to converge)

| ID | Z | Description | File:line | Heal scope |
|----|----|-------------|-----------|------------|
| Z1-NEW-001 | Z1 | EN+AR locales missing 21 `cash_session_*` keys → POS dialog dead UX | `lang/fr/cash_session.php` exists, `lang/en/cash_session.php` missing | Add EN keys (~22 LOC) |
| Z3-NEW-001 | Z3 | V2 default flip dropped Items Board (station-level batch prep regression) | `resources/js/components/admin/KdsV2Grid.vue` — no Items Board pane | **DEFER V1.0.1** owner-gate (feature decision) |
| Z3-NEW-002 | Z3 | `kdsLegacyShouldShowDelivery` only on onlineOrder lane, not dineinOrder/takeawayOrder/kioskOrder | `resources/js/components/admin/KitchenDisplaySystemComponent.vue:479` | Add same guard to 3 lanes (~15 LOC) |
| Z9-P0-01 | Z9 | `ValidPhone` checks digit-count 8..15 only, not strict E.164. `12345678` passes — commit subject "E.164 required" is false | `app/Rules/ValidPhone.php:26-43` | Strict regex (~5 LOC) |
| Z9-P0-02 | Z9 | `User::creating` hook silently injects `PENDING_CREATE_<hex>` when phone empty → NOT NULL invariant decorative | `app/Models/User.php:107-111` | Throw instead of inject (~10 LOC) |
| Z9-P0-03 | Z9 | `SimpleOrderResource` + `KDSOrderDetailsResource` ship `customer_phone` unconditionally — GDPR data-minimization defect on admin/sales/online-order endpoints | `app/Http/Resources/SimpleOrderResource.php:52`, `KDSOrderDetailsResource.php:62-67` | Gate on `isDeliveryOrder` (~10 LOC) |
| Z10-NEW-001 (F-7) | Z10 | `CashDrawerController::open` (hardware drawer pop) never writes `cash_movements TYPE_DRAWER_OPEN` → NF525 forensic gap | `app/Http/Controllers/Admin/Pos/CashDrawerController.php:19-33` | Write drawer_open movement (~15 LOC) |

**P0 heal scope** : ~80 LOC across 7 files. Z3-NEW-001 deferred V1.0.1 (owner gate required).

---

## P1 list (Round 1 — V1 blockers + V1.0.1 backlog)

### V1 ship-blockers (heal Wave Z)

| ID | Z | Description | File:line | Heal scope |
|----|----|-------------|-----------|------------|
| Z1-NEW-002 | Z1 | POS-A3 only partially healed — walk-in got `can('pos')`, quote still no perm | `app/Http/Controllers/Admin/PosController.php:149` | Add `quote` to middleware (~3 LOC) |
| Z3-NEW-004 | Z3 | `customer_phone` in JSON for non-delivery — overlaps Z9-P0-03 fix | (covered by Z9-P0-03 heal) | Same fix |
| Z4-P1-01 | Z4 | `label.popular_menu_items` rendered raw — missing in FR/EN/AR | `resources/js/components/frontend/PopularItemComponent.vue:10` + 3 lang files | Add 3 i18n keys (~6 LOC) |
| Z4-P1-02 | Z4 | OSS non-deterministic display order — no `->orderBy()` | `app/Services/OrderStatusScreenOrderService.php:45-72,103-126` | Add `->orderBy('id')` (~4 LOC) |
| Z6-01 | Z6 | `LoginController:87` doesn't revoke prior `auth_token` rows on relogin → token sprawl (CLAUDE.md §9 violation) | `app/Http/Controllers/Auth/LoginController.php:84-90` | Add `$user->tokens()->where('name','auth_token')->delete()` (~3 LOC) |
| Z6-02 | Z6 | `GuestSignupController:140` mints `['*']` ability for guest customers | `app/Http/Controllers/Auth/GuestSignupController.php:140` | Change to `['kiosk:order']` (~1 LOC) |
| Z8-P1-01 | Z8 | 6/8 outbox listeners still no `wasRecentlyCreated` guard (Sprint 3B stopped 2/8) | `PersistOrderStatusChangedToOutbox.php:59`, `PersistOrderPaymentStatusChangedToOutbox.php:66`, `PersistOrderTableChangedToOutbox.php:82`, `PersistItemAvailabilityChangedToOutbox.php:86`, `PersistItemExtraAvailabilityChangedToOutbox.php:63`, `PersistItemVariationAvailabilityChangedToOutbox.php:62` | Add guard each (~18 LOC) |
| Z8-P1-02 | Z8 | No dead-letter cron for `webhook_events` (handler docblocks promise one, Kernel has none) | `app/Console/Kernel.php` | Add schedule (~5 LOC) |
| Z9-P1-03 | Z9 | `PENDING_*` sentinels render raw in `KdsOrderCard.vue:99-103` `tel:` link | `resources/js/components/admin/KdsOrderCard.vue:99-103` | Display guard (~5 LOC) |
| Z10-P1-05 | Z10 | EN i18n parity 22 `cash_session_*` keys (overlap Z1-NEW-001) | (covered by Z1-NEW-001 heal) | Same |

**V1 ship-blocker P1 heal scope** : ~45 LOC across 9 files.

### V1.0.1 backlog (document, do NOT heal Wave Z)

| ID | Z | Description | Rationale |
|----|----|-------------|-----------|
| Z1-NEW-003 | Z1 | `cash_movements` lacks UNIQUE(order_id, type, direction) | Defense-in-depth (idempotency middleware already prevents) |
| Z1-NEW-004 | Z1 | PosComponent client-side gate missing (backend 422 sole enforcement) | Defense-in-depth |
| POS-A4 | Z1 | Frozen-zone POS +237 lines no LOCK doc | Pre-existing (sister verdict known) |
| POS-A6 | Z1 | JS-side total/subtotal calc still sent | Pre-existing P2 (sister) |
| Z3-NEW-001 | Z3 | V2 KDS dropped Items Board (station-level batch prep) | Owner decision — restore V2 OR document as removed |
| Z3-NEW-003 | Z3 | `?v2=0` rollback path is broken in 3 ways | Acceptable since V2 is default; rollback is emergency only |
| Z3-NEW-005 | Z3 | `allergens_snapshot` no backfill for pre-2026-04-18 orders | Legacy data; backfill script V1.0.1 |
| Z5-P1-01 | Z5 | Admin items form has NO `channels` UI | V1.0.1 admin UX |
| Z5-P1-02 | Z5 | `barcode` + `kds_station` not in `ItemRequest` validation | V1.0.1 admin schema |
| Z5-P1-03 | Z5 | Hardcoded FR labels in `ItemListComponent.vue` | V1.0.1 i18n pass |
| Z5-P1-04 | Z5 | `ItemAttributeController::index` unguarded | V1.0.1 auth hardening (Sprint 4) |
| Z6-05 | Z6 | User `$fillable` has `branch_id`, `is_guest`, `status` (mass-assignment surface) | V1.0.1 security hardening |
| Z6-06 | Z6 | Tokens survive `users.status` change up to 480 min | V1.0.1 security hardening |
| Z7-P1-01 | Z7 | `terminal_id` dead column in `SplitPaymentService` + `RefundWithCounterEntryService` writes | UI work needed for terminal selector; V1.0.1 |
| Z10-P1-02 | Z10 | No `closed_by_user_id`/`reconciled_by_user_id` columns | V1.0.1 forensic enrichment |
| Z10-P1-03 | Z10 | Manager-gate covers only variance branch, not routine close | V1.0.1 auth |
| Z10-P1-04 | Z10 | Frozen `pos-wizard.js` cannot proactively block CASH tile | Backend 422 enforces; LOCK plan needed for proactive UI |
| DEL-5/6/7/8/9 | Z9 | Hardcoded fee, missing i18n keys (some), branch zone exclusion, no min order, no auto-dispatch | Sprint 4 backlog already in sister plan |

---

## Heal plan (Wave Z Round 1 → Round 2)

### Sprint 5A — Delivery + KDS hardening
- Z9-P0-01 ValidPhone E.164 strict
- Z9-P0-02 User::creating throw
- Z9-P0-03 + Z3-NEW-004 Resources gate phone on delivery
- Z9-P1-03 KdsOrderCard PENDING display guard
- Z3-NEW-002 Legacy delivery guard on 3 more lanes

### Sprint 5B — Cash + POS auth hardening
- Z10-NEW-001 (F-7) Drawer pop CashMovement
- Z1-NEW-002 POS quote permission middleware

### Sprint 5C — i18n + sync polish
- Z1-NEW-001 / Z10-P1-05 EN cash_session_* keys (22 keys)
- Z4-P1-01 label.popular_menu_items (3 lang files)
- Z4-P1-02 OSS deterministic order
- Z8-P1-01 6 listeners wasRecentlyCreated
- Z8-P1-02 Webhook DLQ schedule

### Sprint 5D — Auth quick-wins
- Z6-01 LoginController token revoke
- Z6-02 GuestSignupController ['kiosk:order']

**Total heal scope** : ~130 LOC across ~17 files. All inline-eligible (no frozen-zone touch, no schema migrations).

**V1.0.1 backlog deferred** : Z3-NEW-001 (V2 Items Board, owner-gate), POS-A4 LOCK, Z6-05/06 security, Z7-P1-01 terminal wire, Z10-P1-02/03/04 forensic + manager-gate + POS-wizard LOCK.
