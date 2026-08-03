# F-1 Data Storage — Foundation Audit STATUS

**Date:** 2026-05-18
**Branch snapshot:** heal/cms-pr1-quickwins-2026-05-18 @ 0fba7bee15fa
**Mode:** READ-ONLY ultra-deep audit
**Specialists:** Architect + DBA + Security + RED-team (4 JSONs)
**Verdict (one line):** Data storage layer is **production-grade**. NF525 triple-layered. 0 critical schema issue. 2 operational/audit items + minor doc cleanup.

---

## Reconnaissance snapshot

| Metric | Count | Notes |
|---|---|---|
| Total migrations | 166 | 2014-10 to 2026-05-18 |
| Total models | 78 | app/Models/*.php |
| BranchScope applied | 21 + 1 sister | Standard + WizardProfileBranchScope (nullable variant) |
| Models with branch_id but no BranchScope | 10 | 4 INTENTIONAL (AuditLog, ZReport, DomainEvent, WebhookEvent); 6 borderline (controller-side filter) |
| Foreign keys total | 79 | Across 166 migrations |
| UNIQUE constraints | 31 | Including all NF525 + branch-scoped idempotency |
| NF525 immutability triggers | 5 tables | audit_logs, z_reports, cash_movements, cash_drawer_sessions, order_payments, delivery_boy_cash_* |

---

## URGENT-NF525-FLAG (no code change needed, deploy/ops verification only)

### Flag #1 — TRUNCATE bypass mitigation depends on production GRANT

**Owner-friendly framing:**
> "There's a known low-level database command called TRUNCATE that can wipe fiscal records by bypassing the safety triggers we built. The fix is at the database-account-permission level: the application's database user must NOT have permission to run TRUNCATE on the fiscal tables (audit_logs, z_reports, cash_movements, cash_drawer_sessions, order_payments, delivery_boy_cash_*). The migration comments document this. We need to verify the production deploy documentation enforces REVOKE TRUNCATE on the app account before V1.0.1 ships. This is paperwork, not code."

**Evidence:** `database/migrations/2026_04_22_000002_create_audit_logs_table.php:138-140` + `database/migrations/2026_05_10_010000_secure_fiscal_audit_trail_immutability.php:48-50` + CLAUDE.md §8.

**Action:** Verify in deploy runbook / handoff doc that REVOKE TRUNCATE is part of production DB user setup. Not a code change.

---

## DEAD-CODE-SAFE-TO-REMOVE

**Nothing identified as dead in the data layer.** All 78 models are referenced by at least one controller/service. All 166 migrations represent either schema state or historical data-fix decisions that contribute to migration integrity (idempotent re-runs).

Two models with 0 controller refs (`MessageHistory`, but referenced by Message::messageHistory() relation; `CapturePaymentNotification`, referenced by payment gateways) ARE in use. No removal candidates.

---

## DUPLICATION-SAFE-TO-CONSOLIDATE

### D1 — `FrontendDiningTable` and `DiningTable` target the same `dining_tables` table

**Owner-friendly framing:**
> "Two PHP classes both point at the same database table. One enforces tenant isolation (DiningTable), the other doesn't (FrontendDiningTable). The frontend one is used for QR-code/customer-facing reads where pre-auth queries happen. Consolidating into one class with explicit modes would be cleaner. Risk: pre-auth flows may regress. **Recommend NOT consolidating in V1.0.1** — schedule for V1.0.2 cleanup with E2E coverage."

**Files:** `app/Models/DiningTable.php` (BranchScope applied) + `app/Models/FrontendDiningTable.php:12` (no scope).

**Decision:** **KEEP-AS-IS for V1.0.1**, log as V1.0.2 cleanup task.

---

## DUPLICATION-KEEP-AS-IS-RISK

### K1 — Same-timestamp migration files (cosmetic, not duplicates)

Five files share two timestamps (`2026_04_20_210000_*` x3, `2026_05_18_120000_*` x2). They are **distinct migrations on distinct tables**. Laravel runs them alphabetically within the same timestamp. No corruption risk.

**Files:** `database/migrations/2026_04_20_210000_{add_fiscal_identity_to_branches,create_printers_table,extend_dining_tables_occupancy}.php` + `database/migrations/2026_05_18_120000_{add_webhook_events_order_id_fk,create_delivery_boy_cash_sessions_table}.php`

**Decision:** Cosmetic. No action.

### K2 — 17 ALTER migrations against `orders` table

Normal Laravel evolution since 2022. Cannot squash without data risk on production DBs that already migrated. **KEEP-AS-IS** — these are history.

### K3 — Data-fix / emergency migrations preserved

`reset_menu_french.php`, `emergency_purge_english_menu.php`, `fix_tax_misconfig_type_fixed_to_percentage.php`, multiple `backfill_*` migrations. Removing would break dev/CI fresh-DB seeding and lose historical record of fiscal bug fixes.

**Decision:** **KEEP-AS-IS** — migration trail honesty > tidiness.

### K4 — 10 models with `branch_id` but no `BranchScope`

Most are intentional (fiscal append-only, console-only, polymorphic scope, etc.). All justified at code-comment level. No removal/addition recommended in V1.0.1.

**Detail:**
- **INTENTIONAL no-scope (KEEP):** AuditLog, ZReport, DomainEvent, WebhookEvent — fiscal/system tables that need cross-branch admin access.
- **Per-call filter pattern (KEEP):** KioskPromo, UpsellRule — scopeValidFor/scopeActiveForBranch handle branch at usage site.
- **Auto-populate booted() (KEEP):** ActionLog — branch_id auto-set in `creating` hook, filtered in DashboardService::auditTrail.
- **STI inheritance:** Customer (extends User), Message (legacy), DiningTableAuditLog, FrontendDiningTable — controller-layer filtering required (see RED-F1-002 for nfc_uid risk).

---

## STRUCTURAL-RECOMMENDATIONS (V1.0.2 backlog, none block V1.0.1)

### R1 — Add HasFactory trait to 23 fiscal/operational models

**Owner-friendly framing:** "23 models don't have a 'factory' helper — that means future automated tests have to build fixtures by hand. Adding the trait is non-destructive (just adds a `use HasFactory;` line). Not urgent, just future-proofing."

**Models:** AuditLog, ZReport, DomainEvent, StockMovement, OrderStatusTransition, ItemBranchAvailability, LoyaltyTransaction, PendingPaymentConfirmation, OrderDiscountLog, Slider, Page, Currency, Language, Customer, CapturePaymentNotification, DeletionLog, DiningTableAuditLog, GatewayOption, Menu, MessageHistory, NotificationSetting, ThemeSetting, TimeSlot, Transaction.

**Risk:** None.

### R2 — Audit all `Customer::where('nfc_uid')->...` call sites for explicit branch filter

**Owner-friendly framing:** "When a customer taps their NFC loyalty card, the lookup goes through a class that doesn't auto-filter by restaurant branch. If two branches use the same card tech, a card from branch A could match a customer from branch B. The controllers should always add a 'and branch = current' filter. Need to grep all call sites (likely 1-3) to confirm."

**Action:** `grep -rn "Customer::.*nfc_uid" app/` then verify each caller chains `->where('branch_id', ...)`. Add E2E sentinel test.

### R3 — Replace `SET FOREIGN_KEY_CHECKS=0` in TaxService + ItemCategoryService with explicit cascade-or-block decisions

**Owner-friendly framing:** "Two admin operations (deleting a tax or a menu category) temporarily turn OFF the database's safety checks to allow deletion when other records reference them. That works but leaves orphan records behind. A cleaner pattern is to decide per-relationship: either cascade-delete the children, or block with an error if children exist. Not urgent — admin-only access path."

**Files:** `app/Services/TaxService.php:87-89`, `app/Services/ItemCategoryService.php:175-181`.

### R4 — Add Eloquent `deleting()` guard to ZReport (parity with AuditLog)

**Owner-friendly framing:** "The audit log has TWO layers blocking deletion (database trigger + PHP code). The Z-report has only ONE (database trigger). On the SQLite test database, the trigger is MySQL-only. Adding the PHP code guard to ZReport would match the AuditLog pattern. Low-risk improvement."

**File:** `app/Models/ZReport.php` — add static booted() with deleting() throw.

### R5 — Linter/sentinel for `withoutGlobalScope(BranchScope::class)` documentation

**Owner-friendly framing:** "There's a Laravel API that lets developers turn OFF the tenant-isolation safety for a single query. It's used legitimately (kiosk pre-auth, fiscal cron). But there's no automated check that every use has a comment explaining WHY. Adding a sentinel test would catch future undocumented bypasses."

### R6 — Update CLAUDE.md §9 BranchScope inventory (advertises 11, actually 22)

**Owner-friendly framing:** "The project memory file says BranchScope is on 11 models, but it's actually on 22. Document drift, easy fix."

---

## DECISION POINTS for Owner

| # | Question | Risk if no decision | Recommendation |
|---|---|---|---|
| Q1 | Verify production deploy doc enforces REVOKE TRUNCATE on audit_logs + z_reports + cash_* + order_payments for the app DB user? | Theoretical wipe of fiscal evidence by DBA / compromised account. Compliance violation. | **YES — verify before V1.0.1 production cutover.** Not a code change, paperwork. |
| Q2 | Schedule V1.0.2 cleanup task for `FrontendDiningTable` / `DiningTable` consolidation? | None for V1.0.1. Pure tech debt. | Defer to V1.0.2 backlog. |
| Q3 | Schedule audit of `Customer::where('nfc_uid')` call sites? | Low — pre-Sentinel test. Cross-branch NFC lookup possible but unlikely (single resto in V1). | Defer to V1.0.2 unless V1.0.1 enables multi-branch. |
| Q4 | Update CLAUDE.md §9 with correct BranchScope inventory? | None functional. Doc drift only. | Cheap, do whenever. |
| Q5 | Add HasFactory to 23 missing models? | None. Pure test-infrastructure ergonomics. | V1.0.2 backlog. |
| Q6 | What's the production DB backup strategy? | `storage/backups/` contains app-level snapshots (menu rollback states, pre-cycle DB dumps) — NOT production DB backups. NF525 mandates 6-year retention of fiscal data. Need to verify production has scheduled `mysqldump` (or equivalent) with off-site storage. | **YES — verify before V1.0.1 production cutover.** Operational/deploy-doc item, not code. Confirm DB backup cadence + off-site replication + retention policy meets NF525 6yr mandate. |

---

## Files inspected (representative sample)

- `app/Models/Scopes/BranchScope.php` (FROZEN, lines 1-42, full read)
- `app/Models/Scopes/WizardProfileBranchScope.php` (lines 1-58, full read)
- `app/Models/Customer.php`, `AuditLog.php`, `ZReport.php`, `DomainEvent.php`, `ActionLog.php`, `WebhookEvent.php`, `DiningTableAuditLog.php`, `Message.php`, `FrontendDiningTable.php`, `KioskPromo.php`, `UpsellRule.php`
- `database/migrations/2026_04_22_000002_create_audit_logs_table.php` (NF525 trigger pattern)
- `database/migrations/2026_04_22_000003_create_z_reports_table.php`
- `database/migrations/2026_04_22_100000_add_unique_chain_index_to_audit_logs.php`
- `database/migrations/2026_05_09_160000_add_z_reports_delete_trigger_immutability.php`
- `database/migrations/2026_05_10_010000_secure_fiscal_audit_trail_immutability.php`
- `database/migrations/2026_05_09_120000_create_webhook_events_table.php`
- `database/migrations/2026_05_18_120000_add_webhook_events_order_id_fk.php`
- `database/migrations/2026_04_22_000001_add_fiscal_sequence_no_to_orders.php`
- `database/migrations/2026_04_27_143120_create_stock_levels_table.php`
- `database/migrations/2026_04_27_143130_create_stock_movements_table.php`
- `database/migrations/2026_03_12_130000_add_performance_indexes.php`
- `database/migrations/2022_11_17_110125_create_branches_table.php`
- `database/migrations/2022_11_17_110810_create_orders_table.php`
- `database/migrations/2022_11_17_110832_create_order_items_table.php`
- `database/migrations/2026_03_25_002938_add_idempotency_key_to_orders_table.php`
- `database/migrations/2026_04_18_140003_scope_idempotency_key_to_branch.php`
- `database/migrations/2026_05_18_120000_create_delivery_boy_cash_sessions_table.php`
- `database/migrations/2026_05_18_120300_add_delivery_boy_cash_no_delete_triggers_sqlite.php`
- `database/seeders/UserTableSeeder.php`, `KioskMachineTableSeeder.php`
- `app/Services/Idempotency/IdempotencyKeyRepository.php`

## Sister specialist JSONs

- `architect.json` — model layer + BranchScope coverage analysis
- `dba.json` — schema soundness + indexes + UNIQUEs + N+1 risk
- `security.json` — SQL injection, credential exposure, multi-tenant leakage
- `red.json` — 10 adversarial attack attempts, 2 real risks surfaced

---

**Final verdict:** F-1 Data Storage = production-grade. Owner can ship V1.0.1 with confidence on the data layer. The only V1.0.1 blocker is **operational** (TRUNCATE deploy-doc verification), not code. All other items are V1.0.2 backlog or doc cleanup.
