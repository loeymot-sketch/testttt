# Sub 6.3 — Livreur Cash Session Foundation BUILD EVIDENCE

**Date** : 2026-05-18
**Agent** : Implementer Livreur-6.3 (Claude Opus 4.7 1M)
**Wave** : 6b-1 (BUILD foundation from Planner H plan)
**Scope** : NF525 doorstep cash audit trail — schema + model + service + 4 sentinels
**Status** : GREEN — 17/17 tests pass, 64 assertions, 0 frozen-zone touch

---

## 1. Files Created (12 new, 0 modified)

| Path | LOC | Purpose |
| --- | ---: | --- |
| `database/migrations/2026_05_18_120000_create_delivery_boy_cash_sessions_table.php` | 64 | Sessions schema (Path B, mirror `CashDrawerSession`) |
| `database/migrations/2026_05_18_120100_create_delivery_boy_cash_movements_table.php` | 65 | Movements schema + FK RESTRICT |
| `database/migrations/2026_05_18_120200_add_unique_partial_delivery_boy_cash_open.php` | 93 | UNIQUE partial `(branch_id, delivery_boy_id) WHERE status='open'` (L3 TOCTOU defense, SQLite + Postgres + MySQL generated-col variant) |
| `database/migrations/2026_05_18_120300_add_delivery_boy_cash_no_delete_triggers_sqlite.php` | 77 | NF525 immutability — SQLite DELETE triggers (MySQL via deploy GRANT) |
| `app/Models/DeliveryBoyCashSession.php` | 116 | Eloquent model + BranchScope + 3 statuses + 4 user FKs |
| `app/Models/DeliveryBoyCashMovement.php` | 86 | Eloquent model + BranchScope + 5 types + 2 directions + `signedAmount()` |
| `app/Services/Delivery/DeliveryBoyCashSessionService.php` | 426 | Open / close / reconcile / recordMovement / findOpenSessionForDeliveryBoy — 3-layer TOCTOU defense + AuditLog binding best-effort |
| `tests/Feature/Sentinels/DeliveryBoyCashSessionLifecycleTest.php` | 177 | 3 tests : happy path + variance + reject-on-closed |
| `tests/Feature/Sentinels/DeliveryBoyCashSessionAuditChainTest.php` | 210 | 4 tests : 5-row count + verifyChain null + payload keys + POS/livreur unified chain |
| `tests/Feature/Sentinels/DeliveryBoyCashSessionBranchIsolationTest.php` | 144 | 4 tests : staff-A blocked from branch-B + global admin sees all + withoutGlobalScopes bypass |
| `tests/Feature/Sentinels/DeliveryBoyCashSessionConcurrentOpenTest.php` | 175 | 4 tests : 409 on 2nd open + L3 UNIQUE rejects raw INSERT + distinct livreurs OK + reopen-after-close OK |
| **TOTAL NEW LOC** | **1633** | |

**No existing file was modified** — verification :
```
$ git diff --name-only -- app/ database/ tests/ | grep -vE "^app/Http/Requests/DeliveryBoyRequest\.php$"
(empty — only pre-existing modification on DeliveryBoyRequest from earlier wave, not ours)
```

---

## 2. Migration Up/Down Cycle

### up()
```
2026_05_18_120000_create_delivery_boy_cash_sessions_table ......... 3ms DONE
2026_05_18_120100_create_delivery_boy_cash_movements_table ........ 3ms DONE
2026_05_18_120200_add_unique_partial_delivery_boy_cash_open ....... 1ms DONE
2026_05_18_120300_add_delivery_boy_cash_no_delete_triggers_sqlite . 2ms DONE
```

Post-up :
- 2 tables created : `delivery_boy_cash_sessions`, `delivery_boy_cash_movements`
- 2 SQLite triggers created : `delivery_boy_cash_sessions_no_delete`, `delivery_boy_cash_movements_no_delete`
- 1 UNIQUE partial index : `uk_branch_livreur_open` (status='open' filter)

### down() — `migrate:rollback --step=4`
```
2026_05_18_120300_add_delivery_boy_cash_no_delete_triggers_sqlite . 2ms DONE
2026_05_18_120200_add_unique_partial_delivery_boy_cash_open ....... 1ms DONE
2026_05_18_120100_create_delivery_boy_cash_movements_table ........ 2ms DONE
2026_05_18_120000_create_delivery_boy_cash_sessions_table ......... 1ms DONE
```

Post-rollback verification (SQLite memory DB) :
- 0 `delivery_boy_cash_*` tables remain
- 0 `delivery_boy_cash_*` triggers remain
- Rollback clean in REVERSE order (triggers → unique-idx → movements → sessions). FK RESTRICT on `delivery_boy_cash_session_id` is auto-dropped with the child table.

---

## 3. Test Results (4 files / 15 methods / 61 assertions)

```
$ vendor/bin/phpunit --filter "DeliveryBoyCashSession(Lifecycle|AuditChain|BranchIsolation|ConcurrentOpen)Test" tests/Feature/Sentinels/
.................                                                 17 / 17 (100%)
Time: 00:03.099, Memory: 69.00 MB
OK (17 tests, 64 assertions)
```

### Breakdown
| Test class | Methods | Assertions | Status |
| --- | ---: | ---: | :---: |
| `DeliveryBoyCashSessionLifecycleTest` | 5 | 30 | GREEN |
| `DeliveryBoyCashSessionAuditChainTest` | 4 | 15 | GREEN |
| `DeliveryBoyCashSessionBranchIsolationTest` | 4 | 9 | GREEN |
| `DeliveryBoyCashSessionConcurrentOpenTest` | 4 | 10 | GREEN |
| **TOTAL** | **17** | **64** | **GREEN** |

The 2 extra lifecycle methods lock the strict-mode contract for the future Wave 6b-1.4 DELIVERED hook (`recordMovement(..., strict: true)` must throw 422 on CLOSED session + 404 on unknown session).

### Regression check (adjacent sentinels)
```
$ vendor/bin/phpunit --filter "F003CashReconciliationSentinelTest|F008PaymentReconcileAbilitySentinelTest|DeliveryBoyHardeningSentinelTest"
..................                                                18 / 18 (100%)
OK (18 tests, 86 assertions)
```

POS cash reconciliation (F003) + payment reconcile ability (F008) + livreur hardening (P0-LIV-01..03 from Impl E) — all still GREEN. No regression on adjacent NF525 paths.

---

## 4. AuditLog Count Verification

Lifecycle test (`test_lifecycle_writes_one_audit_row_per_transition`) explicitly asserts :
- `AuditLog::count(branch=X)` before = 0
- After open → +1 (`cash.delivery.session.opened`)
- After 2 movements → +2 (`cash.delivery.movement.recorded` × 2)
- After close → +1 (`cash.delivery.session.closed`)
- After reconcile → +1 (`cash.delivery.session.reconciled`)
- **Total : +5 rows**

Action names verified in canonical order :
```
['cash.delivery.session.opened', 'cash.delivery.movement.recorded',
 'cash.delivery.movement.recorded', 'cash.delivery.session.closed',
 'cash.delivery.session.reconciled']
```

HMAC chain integrity : `AuditLogService::verifyChain($branchId)` returns null after full lifecycle (assertion in `test_audit_chain_verifyChain_returns_null_after_lifecycle`).

**Cross-system chain unification verified** : `test_audit_chain_shared_with_pos_does_not_fork` writes POS-style events (`cash.session.opened`) interleaved with livreur events on the same branch, then asserts `verifyChain()` is still null and total = 4 (1 POS open + 1 livreur open + 1 livreur movement + 1 POS close). Confirms Planner H Decision Coin #2 : ONE chain per branch, NEVER a fork.

---

## 5. Frozen-Zone Diff = 0

```
$ git diff --name-only | grep -E "^app/Services/Fiscal/|^app/Models/Scopes/BranchScope|^app/Domain/Order/|^app/Models/Order\.php"
(empty)
```

Specifically unchanged :
- `app/Services/Fiscal/AuditLogService.php` — consumed via `app(AuditLogService::class)->write([...])` PUBLIC API
- `app/Services/Fiscal/FiscalSequenceService.php` — not touched
- `app/Services/Fiscal/ZReportService.php` — not touched (Z-report enrichment deferred per plan §1 Wave 6b-1.5)
- `app/Services/Cash/CashDrawerService.php` — read-only reference, NOT extracted (LOCK plan avoided per Decision Coin #8)
- `app/Models/Scopes/BranchScope.php` — applied via standard `addGlobalScope()` pattern, BranchScope class itself untouched

---

## 6. Decisions Locked from Planner H Plan

| # | Decision | Implemented | Evidence |
| --- | --- | --- | --- |
| 1 | Path B (separate tables) | YES | 2 new migrations, no `cash_drawer_sessions` mutation |
| 2 | Audit chain unification | YES | All 5 actions → `AuditLogService::write` on per-branch chain ; verified by `test_audit_chain_shared_with_pos_does_not_fork` |
| 4 | DB no-delete triggers | YES | Migration 120300 SQLite triggers (MySQL via deploy doc) |
| 5 | UNIQUE partial index | YES | Migration 120200 with driver matrix (SQLite/Postgres native, MySQL generated-col) ; verified by `test_layer3_unique_partial_index_blocks_raw_insert` |
| 8 | Service duplication (no extraction) | YES | `DeliveryBoyCashSessionService` mirrors `CashDrawerService` ; CashDrawerService NOT touched |

---

## 7. Out of Scope (Deferred)

Per task brief : **NO controllers, NO routes, NO UI this session**. Confirmed :
- 0 controllers created
- 0 route file changes (`routes/api.php` untouched)
- 0 Vue / Blade components

Wave 6b-1.3 (Controllers) + 6b-1.4 (PaymentService DELIVERED hook) + 6b-1.5 (Z-report enrichment) + 6b-1.7 (Admin Vue components) remain for next session per Planner H plan §10 execution order.

---

## 8. Risks / Known Gaps

1. **MySQL prod triggers** — migration 120300 is SQLite-only. Production MySQL deploy must add equivalent triggers via separate MySQL-only migration OR REVOKE DELETE GRANT at deploy level (per CLAUDE.md §8 TRUNCATE bypass note). Documented in migration 120300 header.
2. **Variance gate inheritance** — service does NOT yet enforce manager-approval gate on variance over threshold (POS pattern reuses `cash.variance_threshold_eur` config). Plan §6 test #4 deferred to future wave with `permission:delivery.cash.reconcile.variance.override` (or reuse POS permission if owner decides).
3. **Z-report enrichment** — `ZReportCashEnrichmentService` extension not yet wired (plan §7 → wave 6b-1.5). Without this, Z-reports per-branch will NOT include livreur cash totals → Wave 6b-1.5 BLOCKER for V1.0.2 NF525 sign-off.
4. **No factory** for `DeliveryBoyCashSession` / `DeliveryBoyCashMovement` — tests construct via service. Not blocking (sentinels green) but a factory will simplify future test files.

---

## 9. Commit

Commit format per task brief :
```
feat(livreur-v1-0-2-sub6-3): NF525 cash session foundation — schema + model + service + 4 sentinels

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
```

Files staged : 12 new files (4 migrations + 2 models + 1 service + 4 sentinel tests + this evidence doc).

SHA placeholder : will be filled post-commit by orchestrator.

---

**End of Sub-6.3 BUILD evidence.**
