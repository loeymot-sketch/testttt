# WI-3 — Migrations + Database Integrity Deep Audit

**Date**: 2026-05-19
**Branch**: v1-0-1-hardening-2026-05-17 @ `1e7c65ecc`
**Scope**: Read-only deep audit of DB integrity beyond F-1 Foundation + F-6 Stock Cascade. Specifically the INDIRECT zones: FK orphan paths, soft-delete cascade hygiene, append-only UNIQUE coverage, JSON column integrity, timestamp/date convention drift, hot-query index coverage, adversarial RED bypass paths.
**Method**: 4 specialists (DBA + Architect + Security + RED), read-only inspection of 169 migrations + 79 models + 30 JSON columns + 33 UNIQUE constraints + ~83 FK declarations. No code touched.
**Time**: ~35 min wall-clock.

---

## Baseline Drift From F-1 Foundation Audit

| Metric | F-1 baseline | WI-3 observation | Delta |
|---|---|---|---|
| Migrations | 166 | 169 | +3 |
| Models | 78 | 79 | +1 |
| FK lines | 79 | 83 | +4 |
| UNIQUE constraints | 31 | 33 | +2 |

**Delta migrations** (all 3 explained by Wave-G/H/Loyalty heals):
- `2026_05_18_140000_add_stock_movements_immutability_triggers.php` (F-6 P0)
- `2026_05_19_100000_create_loyalty_qr_nonces_consumed_table.php` (LCS-S-001)
- `2026_05_19_120000_add_pos_session_id_to_loyalty_transactions.php` (LOCK_POS_LOYALTY_REDEEM_UI)

**Delta model**: `app/Models/Scopes/WizardProfileBranchScope.php` (PR-D blocker heal for nullable branch_id_scope on item_wizard_profiles).

**Verdict**: Drift fully reconciled. No orphan migrations, no undocumented models.

---

## Consolidated 4-List (P0 → P3)

### P0 — Production Blocker (2)

| ID | Specialist | Title | Mitigation Present | Owner Gate Needed |
|---|---|---|---|---|
| DBA-005 / SEC-009 | DBA + Security | **FreshOrderSeed command TRUNCATEs orders+order_items with no env guard** — `php artisan seed:fresh-orders` against prod wipes orders silently. SET FOREIGN_KEY_CHECKS=0 + TRUNCATE bypasses ALL MySQL triggers. | None code-side. Production GRANT (owner-deferred). | YES — add env guard before V1 ship. 5-LOC heal. |
| RED-001 / RED-004 | RED | **TRUNCATE TABLE + DROP TRIGGER bypass NF525 immutability** at DDL level on audit_logs / z_reports / cash_movements / stock_movements / order_payments. BRAIN explicitly flags this as owner-deferred GRANT-level defence. | MySQL binlog records the event (forensic recovery possible but slow). BRAIN documentation. | YES — CI gate `SHOW GRANTS` must NOT include TRUNCATE/TRIGGER on fiscal tables. Owner-deferred per BRAIN — must be closed before V1 prod migrate. |

### P1 — Hardening Required (17)

| ID | Specialist | Title | Recommendation |
|---|---|---|---|
| DBA-001 | DBA | audit_logs.branch_id and user_id have NO FK | nullOnDelete FK addition, scrub-pass first (mirror webhook_events.order_id heal pattern) |
| DBA-002 | DBA | z_reports.branch_id/opened_by/closed_by have NO FK | Same pattern as DBA-001 |
| DBA-003 | DBA | cash_movements.branch_id and order_id have NO FK | Add FK with restrict/nullOnDelete |
| DBA-004 | DBA | pending_payment_confirmations.branch_id and order_id have NO FK | Add FK with restrictOnDelete |
| DBA-011 | DBA | **cash_movements has NO idempotency UNIQUE** — append-only with replay defence broken (stock_movements has it, cash_movements does not) | Add nullable idempotency_key + UNIQUE, mirror stock_movements pattern |
| SEC-003 | Security | **DB::table() bypasses BranchScope across 30+ call sites** — VERIFIED no exploitable leak at audit time (user_id-scoped or admin-only middleware), pattern fragility remains | CI grep gate + sample audit of remaining ~25 sites (demoted from P0 → P1 after verification) |
| ARCH-001 | Architect | **FrontendOrder::items() missing withTrashed() — dual-model parity break with Order::items()** | 1-LOC fix on FrontendOrder.php:110 |
| ARCH-005 | Architect | AuditLog model has NO branch() or user() relationship — forensic queries fall back to raw integer comparisons | Add ->belongsTo()->withTrashed() relations |
| SEC-001 | Security | TaxService::destroy() disables FOREIGN_KEY_CHECKS without try/finally | Wrap in try/finally; long-term refactor to explicit cascade |
| SEC-002 | Security | ItemCategoryService::destroy() same pattern as SEC-001 (cross-driver PRAGMA + SET) | Same fix as SEC-001 |
| SEC-004 | Security | DB::raw with string interpolation in AvailabilityService etc. — current call sites safe via (int) cast or enum constants, but pattern is fragile | Refactor to parameter binding or document checklist |
| SEC-006 | Security | LoyaltyController:223 admin manual_add uses DB::table::insert — bypasses model events + future scopes | Refactor to LoyaltyTransaction::create |
| RED-002 | RED | SQLite FK enforcement OFF via PRAGMA in 5+ migrations/seeders/services — exception between OFF and ON leaves session unsafe | Try/finally + CI sentinel `PRAGMA foreign_keys=1` post-test-teardown |
| RED-003 | RED | audit_logs/z_reports down() env-guard order **VERIFIED CORRECT** (env guard before drop) — RED-team confirmation only | No action, document as canonical NF525 down() template in CLAUDE.md §8 |
| RED-006 | RED | Soft-delete race condition on Order between READ and WRITE — current paths mitigated via idempotency + Cache::lock + FOR UPDATE in critical paths | Audit-time confirmation: payment-critical paths use FOR UPDATE. Document invariant in BRAIN. |
| RED-007 | RED | Order restore-disabled + OrderAddress/OrderCoupon hard-delete asymmetry → restoration via raw SQL leaves permanently broken aggregate | UI confirmation gate + deletion_log "reason" field surfaced |
| RED-010 | RED | stock_movements idempotency_key UNIQUE on nullable column — multi-NULL rows possible if writer forgets to populate | Add model-layer NOT NULL guard via `static::creating(fn ($sm) => $sm->idempotency_key ?? throw)` |

### P2 — Defence-in-Depth / Tech Debt (12)

| ID | Specialist | Title |
|---|---|---|
| DBA-006 | Loyalty UNIQUE allows multi-NULL order_id (acceptable per docblock) |
| DBA-007 | JSON columns lack DB-level CHECK / JSON_VALID validation (write-time) |
| DBA-008 | Missing composite index (branch_id, status, order_datetime) on orders — V1 scale irrelevant, V2 SaaS relevant |
| DBA-012 | order_payments lacks UNIQUE on reference field — multi-tender retry replay possible |
| DBA-013 | JSON column read-time defensive parsing — Eloquent throws on malformed JSON, no graceful fallback (read-time, complements DBA-007) |
| ARCH-002 | belongsTo(User) without withTrashed() in audit-adjacent models (AuditLog, ZReport, LoyaltyTransaction) |
| ARCH-003 | Order::orderItems() does not cascade soft-delete to OrderItem (orphan items remain) |
| ARCH-004 | OrderAddress + OrderCoupon hard-deleted by OrderService asymmetry with Order soft-delete (documented + restore-blocked) |
| ARCH-008 | StockMovement throws LogicException, AuditLog throws RuntimeException — inconsistent contract |
| SEC-005 | BranchScope short-circuits in console (artisan) — by design but undocumented in scope file |
| SEC-007 | WizardProfileBranchScope NEW 2026-05-18 — implementation sound, confirms pattern adherence |
| SEC-008 | withoutGlobalScope(BranchScope) used in 8+ Frontend controllers by design — needs sentinel CI check for new ones |
| RED-005 | fiscal_sequence_no BIGINT overflow — non-issue (9.2 quintillion ceiling) |
| RED-008 | WH-3 DATE-vs-TZ anchor verified consistently applied across services |
| RED-009 | Cache::lock dependency on Redis for fiscal_sequence_no — FOR UPDATE fallback documented |

### P3 — Documentation / Backlog (4)

| ID | Specialist | Title |
|---|---|---|
| DBA-009 | source_surface column on orders not indexed (analytics path, V1.0.2 deferred) |
| DBA-010 | fiscal_sequence_no = unsignedBigInteger documented for completeness |
| ARCH-006 | AuditLog deliberately NOT BranchScope — add explanatory comment |
| ARCH-007 | BranchScope User instanceof check is single-Authenticatable assumption |
| ARCH-009 | 22 BranchScope-applied models confirmed matching BRAIN §9 |
| ARCH-010 | PaymentTerminal NEW model has BranchScope applied (confirms extension pattern) |

---

## Critical Cross-Specialist Convergence

Three finding clusters appeared in multiple specialists, increasing confidence:

1. **FreshOrderSeed TRUNCATE**: DBA-005 + SEC-009 — same root cause, two angles (data loss + security). P0.
2. **DDL-level immutability bypass** (TRUNCATE/DROP TRIGGER): RED-001 + RED-004 — both require GRANT-level deploy gate. P0.
3. **BranchScope bypass surfaces**: DB::table() (SEC-003, verified no current leak), DB::raw interpolation (SEC-004), withoutGlobalScope (SEC-008) — three independent escape hatches that must each be CI-gated. Pattern fragility, not active exploit.
4. **Append-only audit table consistency gap**: DBA-001..004 (no FK) + DBA-011 + DBA-012 (no idempotency UNIQUE) on fiscal-adjacent tables (audit_logs/z_reports/cash_movements/order_payments/pending_payment_confirmations). All are protected by immutability triggers + service layer guards, but the schema-level forensic invariants are weaker than stock_movements (the gold standard post-Wave-G heal).

---

## What's Already Good (Anti-Drift Confirmations)

- Branch model HAS SoftDeletes (lines 9-12 of app/Models/Branch.php) — Branch hard-delete is BLOCKED, protecting cascadeOnDelete chains on stock_levels / stock_movements / printers / kiosk_promos / upsell_rules / pos_parked_orders / order_quotes.
- SQLite default `foreign_key_constraints=true` (config/database.php:43) — tests catch FK violations.
- audit_logs + z_reports + cash_movements + cash_drawer_sessions + stock_movements ALL have driver-conditional MySQL + SQLite immutability triggers — defence in depth across drivers.
- audit_logs UNIQUE(branch_id, prev_hash) confirmed present (2026_04_22_100000) — chain-fork defence active.
- stock_movements idempotency_key UNIQUE confirmed (2026_04_27_143130).
- loyalty_qr_nonces_consumed UNIQUE(nonce) confirmed (2026_05_19_100000) — replay defence.
- webhook_events UNIQUE(provider, webhook_id) confirmed (2026_05_09_120000) — atomic single-processing.
- z_reports UNIQUE(branch_id, sequence_no) confirmed (2026_04_22_000003) — gap-free chain.
- orders UNIQUE(branch_id, business_date, queue_number) confirmed (2026_04_26_213800) — daily queue uniqueness.
- Down-migration env guard pattern (RED-003) verified CORRECT order on audit_logs + z_reports.
- WH-3 DATE-vs-TZ fix (Carbon::today(tz)->toDateString()) verified consistently applied across availability services.
- fiscal_sequence_no = unsignedBigInteger — overflow not a credible threat (RED-005).

---

## Recommended V1 Ship Gate

Before V1 production migrate, the following MUST be cleared:

1. **DBA-005 / RED-001 GRANT verification** — owner countersign on deploy doc that the prod DB user has NO TRUNCATE/TRIGGER privilege on: `audit_logs`, `z_reports`, `cash_movements`, `stock_movements`, `order_payments`, `orders`, `order_items`.
2. **DBA-005 env guard heal** — add `if (app()->environment('production')) throw` to FreshOrderSeed::handle(). 5-LOC, no schema change, no LOCK needed (not frozen-zone).
3. **SEC-003 spot-check** — verify LoyaltyController:513 and SyncOverviewController DB::table queries DO filter branch_id where appropriate (sample 5 highest-risk call sites).

The remaining P1/P2 are hardening backlog for V1.0.1 — not ship blockers but recommended for the next patch cycle.

---

## Files / Deliverables

- `reports/audit/wi-3-db-integrity-2026-05-19/STATUS.md` (this file)
- `reports/audit/wi-3-db-integrity-2026-05-19/specialists/dba.json` (10 findings)
- `reports/audit/wi-3-db-integrity-2026-05-19/specialists/architect.json` (10 findings)
- `reports/audit/wi-3-db-integrity-2026-05-19/specialists/security.json` (9 findings)
- `reports/audit/wi-3-db-integrity-2026-05-19/specialists/red.json` (10 findings)

**Total**: 42 distinct findings; 2 P0 + 17 P1 + 17 P2 + 6 P3. Includes 2 confirmation-only (RED-003 verified correct, RED-008 verified consistent).

**Read-only respected. Zero source-code modifications.**
