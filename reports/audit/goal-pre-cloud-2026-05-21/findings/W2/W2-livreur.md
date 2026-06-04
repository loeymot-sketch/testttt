# W2 — Livreur (Delivery boy) deep audit

Branch: `heal/cms-pr1-quickwins-2026-05-18`
HEAD at audit start: `1116b39578`
Audit date: 2026-05-21
Scope: dispatch + cash session + reconciliation (NF525 doorstep cash trail)

---

## 1. Path map / anchors

### Controllers
- `app/Http/Controllers/Admin/DeliveryBoyController.php` (CRUD livreur staff; permission split `_show` / `_create` / `_edit` / `_delete` consistent).
- `app/Http/Controllers/Admin/DeliveryBoyAddressController.php` (LIV-Z4-SEC-01 P1 hardened 2026-05-18 — permission split fix for IDOR mutating endpoints).
- `app/Http/Controllers/Admin/DeliveryBoyCashSessionController.php` (NEW — V1.0.2 Sub-6.3 BUILD-1, thin admin wrapper).
- `app/Http/Controllers/Frontend/DeliveryBoyOrderController.php` (livreur self-service; status whitelist `in:PREPARED,OUT_FOR_DELIVERY,DELIVERED,RETURNED` from LIVREUR-Z4-ARCH-04 P1).

### Services
- `app/Services/DeliveryBoyService.php` (legacy CRUD).
- `app/Services/Delivery/DeliveryBoyCashSessionService.php` (NEW — Path B mirror of CashDrawerService; 434 LOC).
- `app/Services/Delivery/DeliveryFeeService.php` + `DeliveryQuoteService.php`.

### Models
- `app/Models/DeliveryBoyCashSession.php` — `BranchScope` global scope (line 79), statuses `open/closed/reconciled`, decimal:2 casts.
- `app/Models/DeliveryBoyCashMovement.php` — `BranchScope` global scope (line 64), `signedAmount()` helper (+1.0 IN / −1.0 OUT). Both consistent with sentinel coverage.

### Frontend
- `resources/js/router/modules/deliveryBoyRoutes.js` (5 lazy-loaded SFC routes; **no cash-session route present**).
- `resources/js/components/admin/deliveryBoyCashSession/` — 3 scaffolded SFCs:
  - `DeliveryBoyCashSessionListComponent.vue` (filters, table, pagination)
  - `DeliveryBoyCashSessionShowComponent.vue`
  - `DeliveryBoyCashSessionFormComponent.vue`

### Routes
- `routes/api.php:643-665` — `Route::prefix('delivery-boy/cash-sessions')` wires `index`, `show`, `open`, `close`, `reconcile` correctly under admin middleware stack.

---

## 2. NEW migrations 2026-05-18 — confirmed PRESENT

| # | File | Verified |
|---|------|----------|
| 1 | `2026_05_18_100000_add_delivery_fee_settings_to_branches.php` | present |
| 2 | `2026_05_18_110000_add_delivery_minimum_order_to_branches.php` | present |
| 3 | `2026_05_18_120000_create_delivery_boy_cash_sessions_table.php` | present, decimal(10,2), 3 indexes, `BranchScope` |
| 4 | `2026_05_18_120100_create_delivery_boy_cash_movements_table.php` | present, FK `restrictOnDelete` (NF525) |
| 5 | `2026_05_18_120200_add_unique_partial_delivery_boy_cash_open.php` | present — driver-matrix (sqlite/pgsql native partial; MySQL stored generated column `open_livreur_lock`) |
| 6 | `2026_05_18_120300_add_delivery_boy_cash_no_delete_triggers_sqlite.php` | present — **SQLite-only** triggers; MySQL no-op (deploy doc gap, see Findings §5) |

All 6 idempotent (`Schema::hasTable` / `Schema::hasColumn` guards).

---

## 3. Tests — PHPUnit filter "DeliveryBoyCashSession"

```
Tests:  35 passed
Time:   6.59s
```

Sentinels exercised:
- `DeliveryBoyCashSessionLifecycleTest` (5 tests) — open → +10 +20 −1 → close 79€ → reconcile expected=79 variance=0 (math correct), idempotency, strict/non-strict semantics.
- `DeliveryBoyCashSessionConcurrentOpenTest` (4 tests) — service-level 409, L3 UNIQUE partial blocks raw INSERT, distinct livreurs not over-blocked, reopen-after-close works.
- `DeliveryBoyCashSessionAuditChainTest` (4 tests) — one audit row per transition, chain `verifyChain()` returns null (intact), shared chain with POS (no fork), canonical payload keys.
- `DeliveryBoyCashSessionBranchIsolationTest` (4 tests) — BranchScope cross-branch isolation on sessions + movements; explicit bypass pattern.
- Controller tests (HTTP layer) — open/close/reconcile/show/index permission gates, BranchScope 404 cross-branch, pagination, filter by `status`/`delivery_boy_id`.

---

## 4. Adversarial dispute results

| Challenge | Verdict | Evidence |
|-----------|---------|----------|
| UNIQUE partial blocks concurrent open race | VERIFIED | `DeliveryBoyCashSessionConcurrentOpenTest::test_layer3_unique_partial_index_blocks_raw_insert` passes — raw `DB::table::insert` rejected with QueryException ; triple defense (Cache::lock 5s/block 3s + `DB::transaction` + `lockForUpdate` + UNIQUE partial) all wired. |
| DELETE forbidden trigger active on managed RDS migration | PARTIAL | Migration 120300 is **SQLite-only** (`if ($driver !== 'sqlite') return;`). MySQL production relies on the same GRANT/REVOKE pattern as `audit_logs` + `z_reports` (CLAUDE.md §8). Deploy doc/Ansible task for `delivery_boy_cash_sessions` + `delivery_boy_cash_movements` not yet present. See §5 DEFERRED. |
| reconcile signed-movement sign error | VERIFIED | Lifecycle sentinel: open 50€ + IN 10 + IN 20 + OUT 1 → expected 79€ (50 + 10 + 20 − 1). Variance test: open 50€ + IN 10 + close 65€ → expected 60€, variance +5€ (over-count) ; reason persisted. `signedAmount()` Line 80-85 of model: `$sign = $direction === IN ? 1.0 : -1.0` ; arithmetic correct. |
| Offline tablet flap → duplicate session open after Cache::lock 3s timeout | MITIGATED-NOT-PROVEN | Service line 108-115: `LockTimeoutException` caught → 409 returned. L3 UNIQUE partial backstops any L1+L2 bypass (multi-pod split-brain). Not exercised in PHPUnit (single-process limitation). |
| DEL-5 wire-up missing (recordMovement hook in OrderService DELIVERED) | CONFIRMED — see §5 |

---

## 5. Critical findings DEFERRED (out of W2 surface-zone)

### DEL-5 — DELIVERED hook MISSING (CRITICAL but planned)
`OrderService::deliveryBoyOrderChangeStatus` (lines 1583-1787) at `DELIVERED` with payment_method=`CASH_ON_DELIVERY` writes ONE NF525 audit row (`delivery.cash_collected_escrow`, OrderService.php:1740-1755) but **never calls** `DeliveryBoyCashSessionService::recordMovement`. Consequence: the livreur's `delivery_boy_cash_sessions` row gets no `order_collect` movement → `reconcileSession()` cannot detect variance against the orders actually collected.

`grep -rn DeliveryBoyCashSessionService app/` returns **zero** non-self call sites. Only audit_log + ZReportCashEnrichmentService (read-side reconciliation, line 516) references the table.

Status: Planned Wave 6b-1.4 (lifecycle sentinel comment line 180 explicitly: "Lock the contract for the future DELIVERED hook (Wave 6b-1.4)"). NOT a regression. NF525 audit chain still intact via `delivery.cash_collected_escrow` row.

**Manual workaround risk**: until DEL-5 wired, livreur cash drawer reconciliation requires manual `recordMovement` POSTs (no surface UI exists for that). Recommend prioritising before MySQL multi-livreur rollout.

### DEL-6 — Admin UI cash-session components NOT router-wired
3 SFCs scaffolded under `resources/js/components/admin/deliveryBoyCashSession/` but `deliveryBoyRoutes.js` (10 routes, lines 11-69) has **no route to them**. Backend `/api/admin/delivery-boy/cash-sessions/*` is fully wired ; UI unreachable through normal navigation. Status: planned Wave 6b-1.3b per BUILD-1 evidence doc. Surface-zone but >30 LOC (route + nav-menu config + permission menu) → deferred.

### DEL-7 — MySQL DELETE-immutability triggers NOT installed
Migration 120300 SQLite-only by design (`if ($driver !== 'sqlite') return;`). MySQL prod tables `delivery_boy_cash_sessions` + `delivery_boy_cash_movements` rely on the same GRANT/REVOKE pattern as `audit_logs` + `z_reports` (CLAUDE.md §8), but the Ansible task / deploy doc entry to apply this is **not yet present** in this branch. Status: deploy-doc gap, not code gap. Recommend pre-cloud DBA task: REVOKE DELETE on both tables for the application MySQL user.

### DEL-8 — `ar/all.php` does NOT carry `cash_session_*` keys
Pattern consistent with existing V1 (FR+EN maintained, AR best-effort) per BRAIN. Surface acceptable for V1 Le Cayenne LOCAL.

---

## 6. Surface fixes APPLIED (max 5 budget)

### FIX-1 — i18n keys for delivery cash session UI (EN+FR)
Added 4 `delivery_cash_*` label keys missing from `lang/en/all.php` and `lang/fr/all.php`. Without these, `DeliveryBoyCashSessionListComponent.vue:7,22-24,70` renders raw `label.delivery_cash_sessions`, `label.delivery_cash_status_open|closed|reconciled` strings.

Files: `lang/en/all.php`, `lang/fr/all.php` (≤8 LOC each, label section, after existing `cash_session_*` block).

AR locale left as-is (V1 partial coverage per project convention).

---

## 7. Wave L D.2 cashBack DB::transaction verification

Commit `5a487c64a` still at HEAD `1116b39578` (verified via `git show --stat`). The four side-effects (Transaction::create('cash_back') + User->balance increment + AuditLogService::write + RefundCreated::dispatch) are wrapped in a single `DB::transaction(...)` envelope. Idempotent early-return stays outside the tx (perf). No regression in this branch.

---

## 8. Verdict

**GO — V1 Le Cayenne LOCAL**.

- 6 migrations applied, sentinel-covered (35/35 PASS).
- Triple defense-in-depth on concurrent-open VERIFIED at L3.
- NF525 audit chain intact (chain `verifyChain()` returns null in dedicated sentinel).
- BranchScope isolation correct on both new models.
- 0 frozen-zone touch ; 0 NF525-adjacent service logic modified.
- 1 surface i18n fix applied within budget (1 of 5).

**Required before multi-livreur production scaling (post-V1)**:
- DEL-5 DELIVERED → `recordMovement(order_collect)` wire-up (Wave 6b-1.4) — critical for reconciliation usefulness.
- DEL-6 admin nav route + sidebar entry (Wave 6b-1.3b) — UX completeness.
- DEL-7 MySQL GRANT/REVOKE deploy task — NF525 production immutability parity.

No P0 / P1 surface regressions detected. Wave L D.2 cashBack heal preserved.
