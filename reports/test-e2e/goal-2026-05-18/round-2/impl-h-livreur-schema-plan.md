# Impl H — Livreur Schema BUILD PLAN (Wave 6a mini-DISCOVERY)

**Date** : 2026-05-18
**Planner** : Claude Opus 4.7 (Planner H)
**Source** : `agent-7-livreur.md` §4 §5 + `99_SYNTHESIS_MASTER.md` Round 2 dispatch + `plans/GOAL_PRODUCTION_READINESS_LECAYENNE_2026-05-18.md` §8 Sub 6.3/6.4
**Status** : READ-ONLY plan — Wave 6b will BUILD from this doc
**Scope** : 2 schema gaps — Livreur Cash Session (Sub 6.3) + Livreur Equipment + Late Alerts (Sub 6.4)

---

## 0. Executive Summary (200 words)

**Schema overview** : Two new tables `delivery_boy_cash_sessions` + `delivery_boy_cash_movements` (Path B — fresh tables, mirror `CashDrawerSession`/`CashMovement` schema, do NOT extend POS tables via discriminator). New 1:1 `delivery_boy_profiles` table for equipment cols (do NOT pollute `users`). New `delivery_late_alerts` table for late-order tracking. Reuse `OrderStatusTransition` for delivery time tracking (no new timestamp cols on `orders`).

**Critical decisions locked** :
- **Path B** chosen over A (extend `CashDrawerSession`) or C (defer V1.0.2) — POS table is NF525-adjacent (DB triggers + 1+yr hardening) and discriminator pattern would pollute every Z-report query. Confirmed by gap report §4 path-table + advisor cross-check.
- **Service duplication** : new `DeliveryBoyCashSessionService` mirrors `CashDrawerService` (frozen-adjacent — extracting shared base needs LOCK plan, deferred V1.0.2).
- **NF525 audit chain** : SAME `audit_logs` HMAC chain per branch (`AuditLogService::write`) — new action names `cash.delivery.session.*`.
- **Sub 6.4 split** : equipment + late-alerts → **V1.0.2 deferred** OR keep as V1 stretch. Recommend Sub 6.3 hard-V1 (NF525 blocker if doorstep cash), Sub 6.4 V1.0.2.

**Wave 6b budget** : **Sub 6.3 = 1.5-2 day-agent ; Sub 6.4 = 1.5-2 day-agent** (total 3-4 day-agent, NOT 2-3 as initially scoped). Split into 6b-1 (cash, V1) + 6b-2 (equipment/alerts, V1.0.2 candidate).

---

## 1. Discovery — File Anchors (all opened + verified)

### Existing patterns (reuse — DO NOT modify)
| File | Purpose | Reuse |
| --- | --- | --- |
| `app/Models/CashDrawerSession.php` (91L) | POS cashier session, BranchScope, 3 statuses, actor cols (H2 F-10) | MIRROR schema → `delivery_boy_cash_sessions` |
| `app/Models/CashMovement.php` (81L) | Atomic cash event, BranchScope, 5 types, in/out, signedAmount() | MIRROR schema → `delivery_boy_cash_movements` |
| `app/Services/Cash/CashDrawerService.php` (549L) | open/close/reconcile/recordMovement + Cache::lock + DB tx + variance gate F-4 + manager gate H2 F-11 + audit binding F-8 | DUPLICATE as `DeliveryBoyCashSessionService` (no extraction — frozen-adjacent) ; reuse `cash.variance_threshold_eur` config keys |
| `app/Services/Fiscal/AuditLogService.php` (375L) | HMAC SHA-256 chain writer, branch-scoped, Cache::lock + UNIQUE chain defense | CONSUME as-is via `app(AuditLogService::class)->write([...])` |
| `app/Http/Controllers/Admin/Pos/CashDrawerSessionController.php` (241L) | open/close/reconcile/current/movements + 403 guard + serialize helper | MIRROR as 2 controllers (admin + livreur self-service) |
| Migrations `2026_05_08_140000` + `_140100` + `2026_05_10_020000` (UNIQUE partial) + `2026_05_16_130000` (no-delete trigger) | Schema patterns + race-defense + NF525 immutability | MIRROR all 4 patterns for new tables |

### Existing livreur surface (READ — DO NOT touch in 6b)
- `app/Services/DeliveryBoyService.php` (230L) — User CRUD with `assertTargetRole` defense (WAVE5-SEC-001). Extend via NEW sibling services.
- `app/Http/Controllers/Admin/DeliveryBoyController.php` (118L) — CRUD `permission:delivery-boys*` middleware pattern.
- `routes/api.php:596-620` (admin `delivery-boy` prefix) + `:1205-1210` (frontend `delivery-boy-order` auth:sanctum).
- `app/Models/Order.php:159` + `FrontendOrder.php:130` — `delivery_boy_id` belongsTo User ; NO `assign_at`/`pickup_at`/`delivered_at` cols ; `OrderStatusTransition` carries timestamps.

### Tests pattern reference
`tests/Feature/Pos/PosCashTrailTest.php` (Z10-F-7 sentinel) → mirror as `DeliveryBoyCashTrailTest`.

---

## 2. Schema Design (ASCII + types + FK + indexes + BranchScope)

### Table 1 — `delivery_boy_cash_sessions` (Sub 6.3)

```
Column                      | Type               | Null | Default | Index | FK
-----------------------------+--------------------+------+---------+-------+----------------------
id                          | bigint UNSIGNED PK | NO   | auto    | PK    |
branch_id                   | bigint UNSIGNED    | NO   |         | idx1  |
delivery_boy_id             | bigint UNSIGNED    | NO   |         | idx2  | users.id (NO action — keep audit even if user deleted)
opened_at                   | timestamp          | NO   |         | idx3  |
closed_at                   | timestamp          | YES  |         |       |
opening_amount              | decimal(10,2)      | NO   |         |       |  -- "float" pour faire la monnaie (e.g. 50€)
closing_amount              | decimal(10,2)      | YES  |         |       |
expected_closing_amount     | decimal(10,2)      | YES  |         |       |
variance                    | decimal(10,2)      | YES  |         |       |
variance_reason             | varchar(255)       | YES  |         |       |
status                      | varchar(16)        | NO   | 'open'  | idx1  |  -- open|closed|reconciled
opened_by_user_id           | bigint UNSIGNED    | NO   |         |       |  -- admin OR livreur self
closed_by_user_id           | bigint UNSIGNED    | YES  |         |       |
reconciled_by_user_id       | bigint UNSIGNED    | YES  |         |       |
notes                       | varchar(255)       | YES  |         |       |
created_at / updated_at     | timestamps         |      |         |       |

Indexes:
  idx1: (branch_id, status)
  idx2: (delivery_boy_id, status)
  idx3: (opened_at)
  UNIQUE partial idx (branch_id, delivery_boy_id) WHERE status='open'  -- iter15-P0-09 pattern

BranchScope: YES (mirror CashDrawerSession::boot)
```

### Table 2 — `delivery_boy_cash_movements` (Sub 6.3)

```
Column                       | Type               | Null | Default | Index | FK
------------------------------+--------------------+------+---------+-------+--------------------
id                           | bigint UNSIGNED PK | NO   | auto    | PK    |
delivery_boy_cash_session_id | bigint UNSIGNED    | NO   |         | idx1  | delivery_boy_cash_sessions.id CASCADE on session delete? NO — RESTRICT (immutability)
branch_id                    | bigint UNSIGNED    | NO   |         | idx2  |
order_id                     | bigint UNSIGNED    | YES  |         | idx3  | orders.id (no action — keep audit even on order deletion if any)
type                         | varchar(32)        | NO   |         |       |  -- order_collect | change_given | drawer_open | drawer_close | adjustment
amount                       | decimal(10,2)      | NO   |         |       |
direction                    | varchar(8)         | NO   |         |       |  -- in | out
notes                        | varchar(255)       | YES  |         |       |
created_at / updated_at      | timestamps         |      |         |       |

Indexes:
  idx1: (delivery_boy_cash_session_id)
  idx2: (branch_id, created_at)
  idx3: (order_id, type)

BranchScope: YES
NO-DELETE trigger: YES (NF525 immutability — mirror cash_movements pattern)
```

**Movement types — semantic difference vs POS** :
- `order_collect` (NEW name vs POS `order_payment`) : livreur collects cash from customer on doorstep (direction `in`)
- `change_given` (NEW name vs POS `cashback`) : livreur gives back change (direction `out`)
- `drawer_open` / `drawer_close` : audit moments
- `adjustment` : manual reconciliation entry

### Table 3 — `delivery_boy_profiles` (Sub 6.4 — 1:1 with users)

**RECOMMENDED** over polluting `users` table (agent-7 proposed direct cols ; advisor confirms cleaner pattern).

```
Column                          | Type               | Null | Default | Index | FK
---------------------------------+--------------------+------+---------+-------+----------------------
id                              | bigint UNSIGNED PK | NO   | auto    | PK    |
user_id                         | bigint UNSIGNED    | NO   |         | UNIQ  | users.id CASCADE
bag_size                        | varchar(8)         | YES  | NULL    |       |  -- small|medium|large
has_hot_compartment             | tinyint(1)         | NO   | 0       |       |
has_cold_compartment            | tinyint(1)         | NO   | 0       |       |
max_concurrent_orders           | smallint           | NO   | 3       |       |
vehicle_type                    | varchar(16)        | YES  | NULL    |       |  -- bike|scooter|car|walking
late_alert_threshold_minutes    | smallint           | YES  | NULL    |       |  -- per-livreur override of branch default
created_at / updated_at         | timestamps         |      |         |       |

Indexes:
  UNIQUE (user_id)

BranchScope: NO (User is BranchScope-exempt — see BranchScope::apply User check at L21).
   Access enforced via the parent User row.
```

### Table 4 — `delivery_late_alerts` (Sub 6.4)

```
Column                | Type               | Null | Default | Index | FK
-----------------------+--------------------+------+---------+-------+-------------------
id                    | bigint UNSIGNED PK | NO   | auto    | PK    |
order_id              | bigint UNSIGNED    | NO   |         | idx1  | orders.id NO action
delivery_boy_id       | bigint UNSIGNED    | YES  |         | idx2  | users.id NO action
branch_id             | bigint UNSIGNED    | NO   |         | idx3  |
alert_type            | varchar(32)        | NO   |         |       |  -- not_picked_up_30min | not_delivered_45min | (extensible)
threshold_minutes     | smallint           | NO   |         |       |  -- value at trigger time (audit)
triggered_at          | timestamp          | NO   |         | idx3  |
acknowledged_at       | timestamp          | YES  |         |       |
acknowledged_by_user_id | bigint UNSIGNED  | YES  |         |       | users.id NO action
notes                 | varchar(500)       | YES  |         |       |
created_at / updated_at | timestamps       |      |         |       |

Indexes:
  idx1: (order_id, alert_type)  -- 1 alert per order per type (UNIQUE? Decision: NO — allow re-fire if ack then re-late)
  idx2: (delivery_boy_id, triggered_at)
  idx3: (branch_id, triggered_at)
  UNIQUE (order_id, alert_type, triggered_at)  -- defensive against double-fire from cron race

BranchScope: YES
```

### Delivery time tracking (Sub 6.4 T-6.4.2)

**DECISION** : **REUSE `OrderStatusTransition`** (no new schema). Gap report §5 T-6.4.2 confirms `OrderStateMachine::recordTransition` already writes a row per status change with timestamp. The reporting layer (`DeliveryBoyPerformanceReportService` NEW) computes:
- `assign_at` = `transitions.where(field='delivery_boy_id', new_value=$id).created_at` (assumes transitions track FK changes ; verify in 6b — if not, fall back to `Order::updated_at` after `selectDeliveryBoy`)
- `pickup_at` = `transitions.where(to=OUT_FOR_DELIVERY).created_at`
- `delivered_at` = `transitions.where(to=DELIVERED).created_at`

If `OrderStatusTransition` doesn't capture `delivery_boy_id` changes, add a **new `OrderDeliveryAssignment` mini-table** (id, order_id, delivery_boy_id, assigned_at, assigned_by_user_id) — 1 row per assignment.

---

## 3. Migration Plan (rollback-safe)

### Sub 6.3 — Cash session (V1 hard-blocker if doorstep cash accepted)
| # | Migration filename (timestamp pattern) | Action |
| --- | --- | --- |
| 1 | `2026_05_19_100000_create_delivery_boy_cash_sessions_table.php` | CREATE table 1. `down()` = `dropIfExists`. |
| 2 | `2026_05_19_100100_create_delivery_boy_cash_movements_table.php` | CREATE table 2 + FK. `down()` = `dropIfExists`. **Drop order in down() : movements first (FK), sessions second.** |
| 3 | `2026_05_19_100200_add_unique_partial_delivery_boy_cash_open.php` | MySQL 8.0.13+ : `CREATE UNIQUE INDEX ... WHERE status='open'`. SQLite : same syntax works. `down()` drops index. |
| 4 | `2026_05_19_100300_add_delivery_boy_cash_movements_delete_trigger_sqlite.php` | NF525 no-delete trigger on `delivery_boy_cash_movements`. Mirror `2026_05_16_130000_add_cash_movements_delete_trigger_sqlite.php`. Production MySQL = separate deploy doc (GRANT REVOKE level). |
| 5 | `2026_05_19_100400_add_delivery_boy_cash_sessions_delete_trigger_sqlite.php` | Same for sessions (mirror `2026_05_10_010000_add_cash_drawer_sessions_no_delete_trigger.php` if exists ; verify in 6b). |

### Sub 6.4 — Equipment + alerts (V1.0.2 candidate)
| # | Migration filename | Action |
| --- | --- | --- |
| 6 | `2026_05_19_110000_create_delivery_boy_profiles_table.php` | CREATE table 3 + UNIQUE user_id + CASCADE FK. `down()` = `dropIfExists`. |
| 7 | `2026_05_19_110100_create_delivery_late_alerts_table.php` | CREATE table 4. `down()` = `dropIfExists`. |
| 8 | `2026_05_19_110200_add_late_alert_settings_to_branches.php` | Pure nullable add : `branches.late_alert_pickup_minutes` (default 30) + `late_alert_delivery_minutes` (default 45). Pattern : mirror 2026_05_18_100000 migration (3 nullable cols). |

**Rollback safety** : All `down()` operations are reverse-order safe. Triggers are auto-dropped on `dropIfExists`. UNIQUE indexes drop before tables. Zero data-mutation operations on existing rows (additive only). Pre-existing `orders.delivery_boy_id` rows = NULL → no backfill needed.

**GATED OWNER** : Migrations 1+2 + triggers 4+5 are NF525-significant. Owner gate required before prod `php artisan migrate` (cf. 2026_05_08_140000 header comment).

---

## 4. Controller + Route Plan

### Sub 6.3 — Cash session

**Admin** `app/Http/Controllers/Admin/DeliveryBoy/DeliveryBoyCashSessionController.php` (mirror `CashDrawerSessionController` ; middleware `permission:delivery-boys`) :
- `index(User $deliveryBoy)` — list sessions for a livreur
- `show(int $session)` — detail + movements
- `movements(int $session)` — movement list
- `adminClose(Request, int $session)` — admin force-close stuck open session
- `adminReconcile(Request, int $session)` — admin override reconcile

**Livreur self-service** `app/Http/Controllers/Frontend/DeliveryBoyShiftController.php` (auth:sanctum, mirror `/api/frontend/delivery-boy-order/*` gate ; livreur role check in service) :
- `openShift(Request)` — POST opening_amount → session open
- `currentShift(Request)` — GET current open session for `$req->user()`
- `closeShift(Request, int $session)` — POST closing_amount
- `reconcileShift(Request, int $session)` — POST variance_reason optional

### Sub 6.4 — Equipment + alerts (admin only)
- `DeliveryBoyEquipmentController` — CRUD on `delivery_boy_profiles` (`/admin/delivery-boy/{deliveryBoy}/equipment`)
- `DeliveryBoyLateAlertController` — index pending + show + acknowledge (`/admin/delivery-boy/late-alerts`)
- `DeliveryBoyPerformanceController` — aggregate metrics (`/admin/delivery-boy/performance`)

### Routes (additions to `routes/api.php`)

**Admin prefix `delivery-boy` (after L619)** : 5 routes for cash-session (GET list/show/movements + POST admin-close/admin-reconcile), 2 for equipment (GET/PUT), 2 for late-alerts (GET index + POST acknowledge), 1 for performance (GET). All POST → `->middleware('idempotency')`.

**Frontend new prefix `delivery-boy-shift` (auth:sanctum)** : POST `/open` (idemp), GET `/current`, POST `/{session}/close` (idemp), POST `/{session}/reconcile` (idemp). Mirror existing `/api/frontend/delivery-boy-order/*` middleware stack.

**Idempotency** : ALL POST endpoints `->middleware('idempotency')` per FoodKing cross-system flag #5.

### PaymentService integration (Sub 6.3 critical wireup)

When livreur calls `deliveryBoyOrderChangeStatus(status=DELIVERED)` AND order `payment_method === CASH_ON_DELIVERY`, controller must call `DeliveryBoyCashSessionService::recordMovement(sessionId, 'order_collect', $order->total, 'in', $order->id)`. **BLOCKER if no open session** : 422 code `LIVREUR_SHIFT_NOT_OPEN` (closes F-6.2.1 doorstep-cash NF525 hole). Optional `cash_collected_amount` + `change_given_amount` on the request enables overpay forensics → record both `order_collect` (in) + `change_given` (out) movements.

---

## 5. UI Plan

### Decision on livreur UI scope

**Agent-7 confirmed NO livreur-facing Vue exists**. Three options :

| Option | Pros | Cons |
| --- | --- | --- |
| **A** New mobile-responsive Vue pages under `resources/js/components/frontend/deliveryBoy/` | Self-contained, no external app dep | +1-2 day work, requires login flow refactor for livreur role |
| **B** API-only — defer livreur UI to native mobile app | Smallest scope, mobile app already presumed consumer | Cannot demo end-to-end in this codebase ; Le Cayenne ops must wait |
| **C** Inline blade page `resources/views/livreur/shift.blade.php` — minimalist standalone | No Vue overhead, easy bookmark on phone | One-off doesn't fit existing Vue pattern |

**RECOMMENDATION** : **Option B for V1** (defer to mobile app), **Option A for V1.0.1** (1-day add). This matches the gap report's framing : "consumer is presumably the mobile/native app". Document in BRAIN that V1 doorstep-cash flow REQUIRES the mobile app for shift open/close.

### Admin-facing UI (REQUIRED for V1 even if livreur UI defers)

New Vue components under `resources/js/components/admin/deliveryBoys/` :

1. `DeliveryBoyCashSessionListComponent.vue` (per-livreur sessions list, accessed from `DeliveryBoyShow`)
2. `DeliveryBoyCashSessionShowComponent.vue` (session detail + movements table + variance display)
3. `DeliveryBoyEquipmentFormComponent.vue` (4 fields : bag_size select, 2 checkboxes, max_concurrent_orders number, vehicle_type select)
4. `DeliveryBoyLateAlertListComponent.vue` (admin dashboard ; banner if pending unack)
5. `DeliveryBoyPerformanceComponent.vue` (table with cols : name, total_delivered, avg_time_min, late_count, total_cash_collected, variance_total) ; export Excel button reuses `DeliveryBoyExport` extended

**Visual capture surfaces (CLAUDE.md §6 mandate)** :
- `http://127.0.0.1:8000/admin/delivery-boys/show/{id}/cash-sessions` (NEW route)
- `http://127.0.0.1:8000/admin/delivery-boys/equipment/{id}` (NEW route)
- `http://127.0.0.1:8000/admin/delivery-boys/late-alerts` (NEW route)
- `http://127.0.0.1:8000/admin/delivery-boys/performance` (NEW route)

**i18n** : add keys under `livreur.shift.*`, `livreur.equipment.*`, `livreur.alerts.*`, `livreur.performance.*` in `resources/js/languages/{fr,en}.json` (NO hardcoded labels — cf. CLAUDE.md §6 raw-label gate).

---

## 6. Test Plan

All tests under `tests/Feature/Delivery/`.

### Sub 6.3 — 7 files

1. `DeliveryBoyCashSessionLifecycleTest` — open → 2 collect + 1 change → close → reconcile. `expected = float + sum_in − sum_out`.
2. `DeliveryBoyCashTrailTest` (sentinel, mirror `PosCashTrailTest`) — 6 paths : DELIVERED+session→1 IN ; no session→422 ; overpay→IN+OUT change ; non-CASH→0 movement ; double-call idempotent ; cross-branch→403.
3. `DeliveryBoyShiftDoubleOpenTest` — UNIQUE partial index : 2 concurrent open→409, 1 row.
4. `DeliveryBoyVarianceGateTest` — variance > threshold w/o reason→422 REASON_REQUIRED ; with reason non-manager→422 MANAGER_APPROVAL ; with reason+manager→OK.
5. `DeliveryBoyCashAuditChainTest` — `verifyChain()` null after lifecycle ; payload contains session_id+amount.
6. `DeliveryBoyCashBranchIsolationTest` — Branch-A admin can't see Branch-B sessions ; cross-branch close→403.
7. `DeliveryBoyCashSessionsMigrationRollbackTest` — `migrate:rollback` cleans both tables + index + trigger.

### Sub 6.4 — 6 files

1. `DeliveryBoyProfileCrudTest` — 1:1 UNIQUE user_id, bag_size whitelist.
2. `DeliveryBoyCapacityCheckTest` — `selectDeliveryBoy` rejects when active orders ≥ max_concurrent_orders.
3. `DeliveryBoyLateAlertTriggerTest` — `Carbon::setTestNow` + `Event::fake` ; assigned > 30min without pickup → alert + admin notification ; 2nd run no duplicate.
4. `DeliveryBoyLateAlertAcknowledgeTest` — sets ack timestamps ; idempotent.
5. `DeliveryBoyPerformanceReportTest` — aggregate query : 5 seeded orders → avg_time + late_count + cash totals.
6. `DeliveryBoyEquipmentMigrationRollbackTest` — `migrate:rollback` clean.

**Total : 13 test files / ~55 assertions.**

---

## 7. NF525 Integration

### Audit chain unification (CRITICAL)

**ALL** livreur cash events MUST write to the SAME `audit_logs` HMAC chain per `branch_id` via `app(AuditLogService::class)->write([...])`. **NEVER** a separate chain. The verifyChain() walk MUST stay deterministic across POS + livreur events.

### Action names (NEW — extend the canonical action namespace)
| Event | Action string | Resource | Payload |
| --- | --- | --- | --- |
| Open shift | `cash.delivery.session.opened` | `delivery_boy_cash_session` | `{session_id, opening_amount, delivery_boy_id}` |
| Close shift | `cash.delivery.session.closed` | `delivery_boy_cash_session` | `{session_id, closing_amount}` |
| Reconcile | `cash.delivery.session.reconciled` | `delivery_boy_cash_session` | `{session_id, expected, variance, variance_reason, threshold, over_threshold}` |
| Movement record | `cash.delivery.movement.recorded` | `delivery_boy_cash_session` | `{session_id, movement_id, order_id, type, amount, direction}` |
| Late alert | `delivery.late_alert.triggered` | `delivery_late_alert` | `{alert_id, order_id, delivery_boy_id, alert_type, threshold_minutes}` |
| Alert ack | `delivery.late_alert.acknowledged` | `delivery_late_alert` | `{alert_id, acknowledged_by_user_id}` |

### Audit binding pattern

Mirror `CashDrawerService::writeAuditLog` L516-548 verbatim — wrap `AuditLogService::write` in try/catch ; **best-effort** : chain failures downgrade to `Log::warning` ; DB-layer immutability (no-delete triggers) + UNIQUE chain constraint are the source of truth. Payload always passes `branch_id` (explicit, never null) per `AuditLogService::resolveBranchId` rule (F-C5).

### Z-report aggregation (Decision)

**RECOMMENDED option (a)** : Extend `ZReportCashEnrichmentService` to also aggregate `delivery_boy_cash_sessions` + `delivery_boy_cash_movements` per branch per day. Add a new "delivery cash" section in Z-report payload. The aggregation queries are independent (different tables) so no SELECT change to existing POS logic — additive only.

**Reason** : Single Z-report per branch per day is the NF525 expectation. Splitting POS + delivery into 2 Z-reports would break "1 Z per branch per day" invariant.

**Wave 6b task** : add `delivery_cash` subsection to `ZReportCashEnrichmentService::enrich()` output + extend `ZReport` model fillable + 1 migration `alter_z_reports_add_delivery_cash_columns` (3 nullable decimals : `delivery_cash_collected`, `delivery_change_given`, `delivery_cash_variance_total`).

### DB-level immutability (NF525 backstop)

Triggers MUST forbid DELETE on `delivery_boy_cash_sessions` + `delivery_boy_cash_movements` (mirror `2026_05_16_130000_add_cash_movements_delete_trigger_sqlite.php`). For MySQL prod, GRANT REVOKE at deploy level (cf. CLAUDE.md §8 TRUNCATE bypass mitigation).

### Retention

6-year retention post-close (CLAUDE.md §8) — same as POS cash. Prune service `PruneOutboxCommand` already handles audit_logs ; verify it does NOT delete `delivery_boy_cash_sessions` post-reconciled (would violate NF525). **Acceptance** : extend `PruneCommand` smoke test to assert `delivery_boy_cash_sessions` count unchanged after 7yr fast-forward.

---

## 8. Effort Estimate

### Sub 6.3 — Cash session (V1 hard-blocker — recommend in scope)

| Task | Hours |
| --- | ---: |
| Migrations (5 files) + factories | 2 |
| `DeliveryBoyCashSession` + `DeliveryBoyCashMovement` models | 1 |
| `DeliveryBoyCashSessionService` (duplicate + adapt CashDrawerService) | 4 |
| `DeliveryBoyCashSessionController` (admin) + `DeliveryBoyShiftController` (livreur) | 3 |
| Routes + middleware wiring | 1 |
| PaymentService / OrderService wireup (DELIVERED → recordMovement) | 2 |
| Z-report enrichment extension | 2 |
| 7 test files + assertions | 6 |
| i18n keys (fr/en) + Vue admin components (3 — list/show/sessions table) | 4 |
| Visual capture + analysis (CLAUDE.md §6) | 1 |
| Self-correct loops (1-2 expected) | 2 |
| **Sub 6.3 Total** | **~28h (≈ 3-4 day-agent)** |

### Sub 6.4 — Equipment + alerts (V1.0.2 candidate — recommend deferred)

| Task | Hours |
| --- | ---: |
| Migrations (3 files : profiles, alerts, branch alert settings) | 1 |
| `DeliveryBoyProfile` + `DeliveryLateAlert` models | 1 |
| `DeliveryBoyEquipmentController` + `DeliveryBoyLateAlertController` + `DeliveryBoyPerformanceController` | 3 |
| Routes + perm gates | 1 |
| `selectDeliveryBoy` capacity check wireup | 1 |
| New artisan command `foodking:delivery:scan-late` (mirror `stock:scan-rupture` pattern) | 2 |
| Kernel.php schedule registration (every 5 min) | 0.5 |
| Notification builder + listener (mirror `SendOrderDeliveryBoyPush`) | 2 |
| `DeliveryBoyPerformanceReportService` (aggregate query) | 3 |
| 6 test files + assertions | 5 |
| Vue admin components (3 — equipment / late-alerts list / performance dashboard) | 5 |
| i18n keys | 1 |
| Visual capture + analysis | 1 |
| Self-correct loops | 2 |
| **Sub 6.4 Total** | **~28h (≈ 3-4 day-agent)** |

### **GRAND TOTAL** : ~56h ≈ 7-8 day-agent total (not 2-3 as initially scoped).

**Recommendation** : Owner gate the V1 scope decision :
- **Option A** : Sub 6.3 only in V1 (NF525 blocker), Sub 6.4 in V1.0.2 → 3-4 day-agent for V1
- **Option B** : Both in V1 (full feature parity Le Cayenne) → 7-8 day-agent

If owner accepts doorstep cash MUST work in V1, Option A is the floor.

---

## 9. Risks & Dependencies

### Risks (what could break)

| # | Risk | Likelihood | Impact | Mitigation |
| --- | --- | --- | --- | --- |
| R1 | Audit chain forks if delivery + POS events written from different secrets | LOW | HIGH (NF525 alarm) | Reuse `AuditLogService::write` ; same `fiscal.audit_secret` config ; no per-event-type secret |
| R2 | Z-report aggregation drift (livreur cash double-counted or missed) | MEDIUM | HIGH | Extend ZReportCashEnrichmentService ONCE ; sentinel test assert `pos_cash + delivery_cash = total_cash_for_branch_day` |
| R3 | Cron `scan-late` fires repeatedly on same alert | MEDIUM | LOW (admin spam) | UNIQUE `(order_id, alert_type, triggered_at)` + DB constraint + idempotent insert in command |
| R4 | Livreur opens shift on multiple branches (cross-branch leak) | LOW | MEDIUM | BranchScope + UNIQUE partial index `(branch_id, delivery_boy_id) WHERE status='open'` per-branch |
| R5 | Race : 2 concurrent `recordMovement` calls on same session via OrderService | MEDIUM | LOW (data integrity) | Mirror `CashDrawerService::recordMovement` H2 P2-Z10-08 pattern : DB::transaction + lockForUpdate inside service |
| R6 | Migration rollback : trigger drops fail on partial state | LOW | LOW (dev only) | Test `migrate:rollback` in CI ; pre-prod rehearsal mandatory |
| R7 | `OrderStatusTransition` doesn't capture `delivery_boy_id` FK changes | MEDIUM | MEDIUM (time tracking wrong) | Wave 6b discovery step : verify ; fall back to `OrderDeliveryAssignment` mini-table if absent |
| R8 | Sub 6.4 deferred to V1.0.2 but Sub 6.3 wireup blocks on `delivery_boy_profile.max_concurrent_orders` | LOW | LOW | Make Sub 6.3 wireup not depend on Profile table ; capacity check is a Sub 6.4 enhancement only |

### Dependencies

- **Impl A (POS Payment heal)** : touches `PaymentService.php`. Sub 6.3 wireup must NOT collide on PaymentService changes — recommend Sub 6.3 wireup goes via OrderService DELIVERED hook (separate method), NOT PaymentService.
- **Impl E (Livreur P0 fixes)** : touches `Admin/PosOrderController.php::selectDeliveryBoy` + `Frontend/DeliveryBoyOrderController.php`. Sub 6.4 `capacity check` requires the F-6.1.2 branch/role hardening landed FIRST (sequential).
- **Impl F (Idempotency sweep)** : new POST routes from Sub 6.3 + 6.4 MUST be added to Impl F's audit list (re-run sweep after Wave 6b).
- **LOCK plans needed** : None for new files. NONE of the existing frozen-zone files touched. `CashDrawerService.php` is touched only by READ (we duplicate, don't extract a base class).

---

## 10. Wave 6b Execution Order (sequential — no parallelism inside 6b)

1. **6b-1.1** (1h) — Migrations 1-5 + factories (Sub 6.3 schema)
2. **6b-1.2** (4h) — `DeliveryBoyCashSessionService` (clone + adapt CashDrawerService)
3. **6b-1.3** (3h) — Controllers (admin + livreur) + routes + middleware
4. **6b-1.4** (3h) — OrderService DELIVERED hook + PaymentService non-collision
5. **6b-1.5** (2h) — ZReportCashEnrichmentService extension + new ZReport columns migration
6. **6b-1.6** (6h) — 7 test files + iterate until GREEN
7. **6b-1.7** (4h) — Admin Vue components (3) + i18n + visual capture analysis
8. **6b-1.8** (3h) — Self-correct loops + audit chain verification

**Owner Gate (G6b)** : after 6b-1.8, owner reviews:
- [ ] All 7 tests GREEN
- [ ] Visual captures GREEN (no raw labels)
- [ ] Audit chain `verifyChain($branchId)` returns null
- [ ] Sub 6.4 V1.0.2 deferral confirmed OR continue to 6b-2

If 6b-2 approved : proceed with similar 8-step pattern.

---

## Appendix A — Decision Coins (8 critical, locked)

Per advisor: each decision below is locked, future agents must not silently flip.

1. **Path B** (separate tables) NOT Path A (extend CashDrawerSession). Justification : NF525-adjacent, discriminator pollutes every Z-query.
2. **Audit chain unification** : ONE chain per branch (POS + livreur events together).
3. **Z-report extension** : option (a) extend ZReportCashEnrichmentService, NOT separate Z-report.
4. **DB no-delete triggers** : YES on both new tables (NF525 immutability).
5. **UNIQUE partial index** : YES on `(branch_id, delivery_boy_id) WHERE status='open'`.
6. **Equipment placement** : new `delivery_boy_profiles` table 1:1 with users, NOT cols on `users`.
7. **Time tracking** : reuse `OrderStatusTransition` (verify FK-change capture in 6b ; fallback `OrderDeliveryAssignment` table if absent).
8. **Service abstraction** : duplicate `CashDrawerService` as `DeliveryBoyCashSessionService` ; NO extraction (CashDrawerService is frozen-adjacent ; extraction would need LOCK plan).
9. *(bonus)* Mobile UI scope : Option B for V1 (API-only, defer to mobile app), Option A for V1.0.1.
10. *(bonus)* Late alert mechanism : artisan command `foodking:delivery:scan-late` scheduled every 5 min (NOT a Listener) ; mirror `stock:scan-rupture` pattern.

---

## Appendix B — Word Count

This plan : ~3870 words (target ≤4000 per rule).

---

**End of Planner H plan.**
