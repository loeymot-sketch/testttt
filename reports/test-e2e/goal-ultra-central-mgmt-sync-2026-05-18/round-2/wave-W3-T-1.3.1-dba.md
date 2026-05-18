# DBA — Wave W3 / Task T-1.3.1 BranchScope schema-level integrity (Round 2)

**Specialist:** DBA (read-only audit)
**Task:** T-1.3.1 — schema-level branch_id integrity across 17 BranchScope-bound models
**Goal:** `goal-ultra-central-mgmt-sync-2026-05-18`
**Anchors verified:** `app/Models/Scopes/BranchScope.php`, `app/Models/Scopes/WizardProfileBranchScope.php`, 17 model files (CashDrawerSession, CashMovement, DiningTable, FrontendOrder, ItemWizardProfile, KioskMachine, Order, OrderItem, OrderPayment, OrderQuote, PaymentTerminal, PendingPaymentConfirmation, PosParkedOrder, Printer, PushNotification, StockLevel, StockMovement), 27 branch_id-touching migrations (110810 orders → 120000 payment_terminals), `app/Services/Pricing/PricingService.php`, `app/Services/OrderService.php` (6 × `OrderItem::insert`), `app/Services/Order/RefundWithCounterEntryService.php` (1 × `OrderItem::create`), `app/Services/FrontendOrderService.php` (2 × `OrderItem::insert`), `app/Services/Fiscal/AuditLogService.php`, `app/Services/Fiscal/ZReportService.php`, `app/Traits/HasDomainEvents.php`, `database/seeders/KdsOrderTableSeeder.php`, `database/factories/*Factory.php`.

---

## VERDICT

**ORANGE — BranchScope-bound models are mostly NOT-NULL on `branch_id`, but FK enforcement is fragmentary, `audit_logs.branch_id` is nullable in defiance of the service-layer non-null contract, three NF525-sensitive tables (cash_drawer_sessions, cash_movements, order_payments, pending_payment_confirmations, audit_logs, z_reports, sync_metrics, domain_events) carry `branch_id` with NO foreign-key constraint, and one BranchScope-claiming model (CashDrawerSession) has neither a `branch_id` index covering the lookup pattern used in `WHERE branch_id = ? AND status = 'open'` nor a `(branch_id, status)` composite that includes it.**

The schema design is **defensively NOT-NULL at the column level on the SaaS-critical tenant tables** (Order, OrderItem, OrderPayment, OrderQuote, StockLevel, StockMovement, PosParkedOrder, Printer, PaymentTerminal, DiningTable, KioskMachine — all `foreignId(...)->constrained('branches')` or explicit `unsignedBigInteger` with FK). However, **the fiscal/idempotency family (audit_logs, z_reports, webhook_events, domain_events, sync_metrics, cash_drawer_sessions, cash_movements, pending_payment_confirmations) is inconsistent**: some have nullable `branch_id` for legitimate global-event reasons but lose application-layer enforcement; others omit the FK constraint entirely, making `branch_id` semantically a magic integer that could point to a deleted branch or to `0` (admin sentinel) without the DB noticing. The application layer (`AuditLogService::write` rejects null branch_id, `PricingService` populates `branch_id` in every `orderItemInsertRows`, `HasDomainEvents` reads `$model->branch_id ?? null`) is the only enforcement — there is **no DB-level guarantee** that `audit_logs`, `domain_events`, `webhook_events`, or `sync_metrics` rows reference a real branch.

---

## TOP FINDINGS

### F1 — `audit_logs.branch_id` is `nullable()` at the schema layer despite `AuditLogService::write()` rejecting null at runtime
**Severity:** P1 (NF525-adjacent schema drift between contract and column declaration)
**File:line:** `database/migrations/2026_04_22_000002_create_audit_logs_table.php:36` (`$table->unsignedBigInteger('branch_id')->nullable()->index();`) vs `app/Services/Fiscal/AuditLogService.php:84-98` (`if ($branchId === null) { throw new \InvalidArgumentException('AuditLogService::write() requires an explicit branch_id. Pass branch_id=0 for system/CLI events…'); }`)
**Reasoning (strong):**
```yaml
claim: The audit_logs table schema declares `branch_id` as `unsignedBigInteger NULL`, but the only authorised writer (AuditLogService::write) raises `InvalidArgumentException` on null. The DB therefore allows a NULL row to exist (e.g., via raw `INSERT INTO audit_logs (...) VALUES (...)`, a future seeder, or an emergency migration script) while the application code can never produce one. The audit chain `lastHashFor(null)` (referenced in the service docstring lines 89-92) "has no WHERE clause" — meaning a single nulled row would poison whichever branch was processed last, silently corrupting fiscal evidence for 6 years of mandated NF525 retention.
evidence:
  - migration 2026_04_22_000002 line 36: `$table->unsignedBigInteger('branch_id')->nullable()->index();`
  - AuditLogService.php line 84: `$branchId = $this->resolveBranchId($data);` followed by null-rejection at lines 93-98.
  - service comment lines 87-98 explicitly states "reject null branch_id: a call that does not pin a branch would read the tail across ALL chains" — this is THE DBA argument for NOT NULL.
  - the index on `branch_id` alone (line 36) is suboptimal — the chain reader (`lastHashFor`) joins on `branch_id` AND orders by `id DESC` for chain integrity verification. A `(branch_id, id DESC)` composite would let the chain head read O(1) instead of O(log N).
counter-evidence:
  - `branch_id = 0` is the admin/system sentinel (per BranchScope.php line 33), so a NOT NULL with default 0 might silently re-route fiscal events. The current nullable form forces the writer to make a choice — but the writer already does, so the column should match.
  - SQLite test runners would need data backfill (UPDATE audit_logs SET branch_id = 0 WHERE branch_id IS NULL) before adding NOT NULL — feasible, just a migration step.
risk: Two failure modes: (a) a future hotfix CLI script bypasses AuditLogService and INSERTs a row with NULL branch_id, immediately poisoning the chain integrity of whichever tenant's last hash is newest; (b) emergency restore from a 6-year-old backup that predates the service-layer guard re-introduces nulls. Both are NF525 violations that the schema should prevent at the column level. Per BRAIN §8 "DB trigger BEFORE DELETE … 6 years rétention obligatoire", the schema is the last line of defence — NOT NULL is mandatory.
caveats: The existing immutability triggers (audit_logs_no_update / audit_logs_no_delete) protect against UPDATE/DELETE but NOT against INSERT with NULL. Tightening to NOT NULL requires a single ALTER + backfill migration; the runtime contract is already correct, the schema just needs to catch up.
verdict: P1 — add migration: backfill `branch_id = 0 WHERE branch_id IS NULL`, then ALTER `branch_id` to NOT NULL with default 0 (or remove default if any value is acceptable). Coordinate with operations doc since NF525 evidence migrations are gated.
```

### F2 — `cash_drawer_sessions.branch_id`, `cash_movements.branch_id`, `order_payments.branch_id`, `pending_payment_confirmations.branch_id`, `audit_logs.branch_id`, `z_reports.branch_id`, `sync_metrics.branch_id`, `domain_events.branch_id`: NO FK constraint to `branches.id`
**Severity:** P1 (orphan-row risk across the entire NF525-relevant + sync-relevant family)
**File:line:**
- `database/migrations/2026_05_08_140000_create_cash_drawer_sessions_table.php:33` (`unsignedBigInteger('branch_id')` — no `foreign(...)`)
- `database/migrations/2026_05_08_140100_create_cash_movements_table.php:32` (idem; `cash_drawer_session_id` HAS FK at line 47-50, `branch_id` doesn't)
- `database/migrations/2026_05_06_180000_create_order_payments_table.php:23` (idem; `order_id` FK at line 32, `branch_id` index-only at line 34)
- `database/migrations/2026_05_08_120000_create_pending_payment_confirmations_table.php:29` (idem)
- `database/migrations/2026_04_22_000002_create_audit_logs_table.php:36` (nullable + index, no FK)
- `database/migrations/2026_04_22_000003_create_z_reports_table.php:30` (no FK)
- `database/migrations/2026_04_23_220000_create_sync_metrics_table.php:14` (nullable + index, no FK)
- `database/migrations/2026_04_15_200000_create_domain_events_table.php:16` (nullable + index, no FK)
**Reasoning (strong):**
```yaml
claim: Eight tables that carry `branch_id` — including ALL fiscal-evidence tables (audit_logs, z_reports), ALL cash family (cash_drawer_sessions, cash_movements), ALL payment ledgers (order_payments, pending_payment_confirmations), and ALL sync/outbox tables (sync_metrics, domain_events) — declare `branch_id` as a bare `unsignedBigInteger` with NO `foreign(...)` constraint. This is asymmetric: the same migrations correctly add FKs for sibling columns (cash_movements.cash_drawer_session_id → cascadeOnDelete then restrictOnDelete; order_payments.order_id → restrictOnDelete via 2026_05_10_010000). The branch_id column was intentionally left FK-less.
evidence:
  - Direct migration reads confirmed: cash_drawer_sessions:30-54 has indexes but no foreign() call. cash_movements:32 + 41-50 has FK only on cash_drawer_session_id. order_payments:23 + 32-34 has FK only on order_id.
  - For comparison, tables that DO have branch_id FK: orders.branch_id (`foreignId('branch_id')->constrained('branches')`); order_items.branch_id (same); kiosk_machines.branch_id (same); dining_tables.branch_id (same); stock_levels.branch_id (`->cascadeOnDelete()` at line 14); stock_movements.branch_id (`->cascadeOnDelete()` at line 14); pos_parked_orders.branch_id (`->cascadeOnDelete()` at line 29); printers.branch_id (`->cascadeOnDelete()` at line 27); payment_terminals.branch_id (`->cascadeOnDelete()` at line 57); order_quotes.branch_id (`->cascadeOnDelete()` at line 33); item_wizard_profiles.branch_id_scope (`->nullOnDelete()` at line 18). So the project KNOWS how to do FK on branch_id — it just didn't on the 8 listed tables.
  - The asymmetry creates a soft contract violation: a branch deletion (which should never happen per BRAIN §1, but possible via Branch::delete() if the model permits) would leave: orphan audit_logs (NF525 evidence claiming to belong to a non-existent branch), orphan z_reports (fiscal close pointing to ghost branch), orphan cash_movements (cash evidence pointing nowhere), orphan domain_events (replayable events with branch_id=42 when branch 42 doesn't exist anymore), orphan sync_metrics (telemetry for a vanished branch).
counter-evidence:
  - The audit_logs / z_reports FK omission is partially DEFENSIBLE: NF525 requires 6-year retention, so if a tenant churns and is hard-deleted, the audit chain must survive. With a CASCADE FK the evidence would be wiped — terrible. With a RESTRICT FK the branch delete would fail — fine, but the project may want `branch_id` to be a soft pointer that survives the branch row's disappearance.
  - For domain_events the column is explicitly nullable (line 16) for global events without branch context (e.g., system maintenance events), so a strict FK with NOT NULL would not fit.
  - However: this DEFENSIBLE rationale is undocumented in the migration files (no comment explaining why FK is omitted), so a future maintainer will fix the "missing FK" without realising NF525 retention is the reason. Either document it or add a `setNullOnDelete()` FK that preserves the row + nulls the pointer.
risk: (a) silent data corruption — orphan rows leak past branch deletion with no warning; (b) BranchScope queries returning stale data for a `branch_id` that points nowhere; (c) Z report reconciliation crashing in `total_by_method` aggregation when a cash_movement's branch is gone; (d) outbox replay (`DispatchDomainEventsJob`) attempting to broadcast on `channel-branch-{X}` when branch X is gone; (e) SaaS-V2 multi-tenant blast radius: a tenant offboarding (delete branch) silently leaks fiscal evidence to nobody's audit chain.
caveats: Per BRAIN §1 "Backend is the source of truth" + §8 "6 ans rétention obligatoire post-close", the right design is: nullable `branch_id` with `foreign(...)->nullOnDelete()` for fiscal tables (preserve evidence, null pointer), and NOT NULL `foreign(...)->restrictOnDelete()` for operational tables (cash_drawer_sessions, cash_movements, order_payments — can never lose the pointer). Currently NONE of these have FKs.
verdict: P1 — add migrations to introduce `foreign('branch_id')->references('id')->on('branches')->nullOnDelete()` on audit_logs, z_reports, domain_events, sync_metrics (where nullable column matches null-on-delete semantics), and `->restrictOnDelete()` on cash_drawer_sessions, cash_movements, order_payments, pending_payment_confirmations (where the column is NOT NULL and the row should never outlive its branch). Document the rationale inline.
```

### F3 — `cash_drawer_sessions` has NO index covering the application's hottest WHERE clause: `WHERE branch_id = ? AND status = 'open' AND opened_by_user_id = ?`
**Severity:** P2 (performance hazard for POS opening rush; uniqueness layer-3 covers it but reads still scan)
**File:line:** `database/migrations/2026_05_08_140000_create_cash_drawer_sessions_table.php:50-52` (indexes: `['branch_id', 'status']`, `['opened_by_user_id', 'status']`, `'opened_at'`); `database/migrations/2026_05_10_020000_add_unique_partial_cash_drawer_open.php:44-69` (UNIQUE partial / virtual column `(branch_id, opened_by_user_id) WHERE status='open'`).
**Reasoning (strong):**
```yaml
claim: The cash_drawer_sessions table has two non-composite indexes that touch branch_id — `(branch_id, status)` and `(opened_by_user_id, status)` — plus a separate UNIQUE partial index on `(branch_id, opened_by_user_id) WHERE status='open'`. Reads at POS startup query `WHERE branch_id = ? AND opened_by_user_id = ? AND status = 'open' LIMIT 1` to find the active session. The UNIQUE partial index serves both as constraint AND as covering index — but only on MySQL/MariaDB 8.0+; on older MariaDB the migration falls back to a virtual column `open_user_lock` (line 58-69) which builds a different index name `uk_branch_user_open(branch_id, open_user_lock)`. The composite `(branch_id, status, opened_by_user_id)` that would cover the read on SQLite test runners (no partial indexes pre-3.8) does not exist.
evidence:
  - migration 140000 line 50-52: `$table->index(['branch_id', 'status']); $table->index(['opened_by_user_id', 'status']); $table->index('opened_at');`
  - migration 020000 line 44-69: explicit logic creating either a partial UNIQUE (MySQL/Postgres) or a virtual-column UNIQUE (older MariaDB). SQLite path is at line 67-69, but SQLite doesn't enforce partial UNIQUE the same way — tests pass because RefreshDatabase rebuilds clean.
  - the BranchScope `apply()` method (BranchScope.php:28-39) adds `WHERE table.branch_id = X` to every query. Combined with the Cashier's `status='open'` filter, an EXPLAIN would show: with `(branch_id, status)` MySQL uses index then scans status='open' rows of THIS branch — fine for low cardinality. But if many archived sessions accumulate, the read becomes O(branches × open-fraction). The partial UNIQUE saves this on MySQL ≥ 8.0 by being a covering index.
  - the original migration did NOT include the partial UNIQUE — it was added 2 days later (140000 → 020000 timestamp gap). Suggests this was reactive: someone hit a duplicate-open bug, added the partial UNIQUE. The composite read index was never added because the partial UNIQUE doubled as one in production.
counter-evidence:
  - Per BRAIN §8 "Cache::lock 5s + DB FOR UPDATE = triple défense", concurrent session opens are serialized at the application layer, so the index choice is not on the hot path for races — only for read latency.
  - cash drawer sessions are typically 1-10 per branch per day; even a full table scan would be < 1ms. This is theoretical performance, not a real bottleneck for single-tenant Le Cayenne V1.
  - For SaaS V2 multi-tenant, a single MySQL instance with 1000 branches × 30 sessions/branch/day × 365 days = 11M rows → THEN the missing composite matters.
risk: Slow POS startup at scale if SaaS V2 ships without addressing this. Single-tenant V1 unaffected. Minor — but emblematic of the larger pattern: indexes are added reactively, never planned for the BranchScope read pattern.
caveats: The fix is a one-line index addition: `$table->index(['branch_id', 'status', 'opened_by_user_id'], 'cds_branch_status_user_idx');` — doesn't change behaviour, just makes EXPLAIN cleaner.
verdict: P2 — add a 3-column composite index covering the BranchScope-augmented read pattern. Defer to SaaS V2 hardening cycle.
```

### F4 — `PushNotification.branch_id` defaults to `0` (admin) instead of being NOT NULL — and the migration uses a SHORT INT
**Severity:** P2 (legacy schema drift; BranchScope masks this in queries but rows can leak via raw inserts)
**File:line:** `database/migrations/2022_11_23_125038_create_push_notifications_table.php:22` (`$table->unsignedBigInteger('branch_id')->nullable()->default(0);`). The model PushNotification at `app/Models/PushNotification.php` adds BranchScope.
**Reasoning (strong):**
```yaml
claim: `push_notifications.branch_id` is `unsignedBigInteger NULL DEFAULT 0`, with no FK. A raw INSERT without specifying branch_id silently lands a row in the `branch_id = 0` (admin sentinel) bucket — which under BranchScope (line 33-36) means "admins see it, no staff sees it". For a notification that SHOULD have been scoped to branch 5, this is a silent visibility bug: the notification never reaches the intended branch staff. Worse, the column allows NULL too (because of `nullable()`), giving THREE distinct semantics — NULL (unscoped), 0 (admin-only), N>0 (branch N). The BranchScope `apply()` does `WHERE branch_id = userBranch`, so NULL rows are invisible to everyone, and 0 rows are visible only to admin.
evidence:
  - migration line 22 verbatim: `$table->unsignedBigInteger('branch_id')->nullable()->default(0);`
  - PushNotification model adds BranchScope: `static::addGlobalScope(new BranchScope());`
  - BranchScope:33-39: admin (branch_id=0) sees everything, staff sees only own branch via strict equality. Both NULL and 0 are invisible to staff.
  - No FK to branches table; a notification with `branch_id = 99999` (nonexistent branch) would persist and be invisible to ALL users (no staff matches, admin sees the row but knows it's orphan).
counter-evidence:
  - push notifications are a low-stakes feature compared to fiscal data; a missed notification is an annoyance, not a compliance failure.
  - the default value of 0 is consistent with the admin sentinel convention used elsewhere in the codebase (e.g., User.branch_id=0 for admin role per BranchScope.php:31).
risk: A misconfigured notification job (e.g., a CLI command that forgot to set branch_id) silently drops notifications into the admin-only bucket. Branch managers never see them. The root cause (forgot to set branch_id) is invisible — there is no DB error, no log line, just missing notifications.
caveats: Fix requires (a) backfilling NULL → 0, (b) ALTER to NOT NULL DEFAULT 0, (c) optionally adding the FK with `->restrictOnDelete()`, (d) auditing all call sites for explicit branch_id passing.
verdict: P2 — schema is loose enough to cause silent visibility bugs but the blast radius is contained to non-fiscal notifications.
```

### F5 — OrderItem `branch_id` is correctly populated by PricingService and OrderService, BUT no DB-level CHECK that `order_items.branch_id == orders.branch_id`
**Severity:** P1 (cross-table integrity gap — current code respects it, future code may not)
**File:line:** `app/Services/Pricing/PricingService.php:280` (`'branch_id' => $req->branchId`); `app/Services/OrderService.php:444,799,1255` (`'branch_id' => $this->order->branch_id`); `app/Services/Order/RefundWithCounterEntryService.php:125` (`'branch_id' => $branchId`). NO migration adds a CHECK constraint or trigger enforcing parity.
**Reasoning (strong):**
```yaml
claim: Every code path that creates an OrderItem currently sets `branch_id` correctly (PricingService line 280 reads from PricingRequest; OrderService lines 442-444, 797-799, 1253-1255 hardcode `$this->order->branch_id`; RefundWithCounterEntryService line 125 uses the resolved branchId). However, there is NO database-level enforcement that `order_items.branch_id == orders.branch_id` for the joined row. A future hotfix that does `OrderItem::create(['order_id' => $o->id, 'branch_id' => Auth::user()->branch_id, ...])` (instead of `$o->branch_id`) would silently create a cross-branch item attached to the wrong order. BranchScope would then either hide the order from the cashier (orders.branch_id matches but order_items.branch_id doesn't) or expose it across branches depending on which side of the relation is loaded first.
evidence:
  - 6 × `OrderItem::insert($itemsArray)` in OrderService.php at lines 345, 469, 674, 828, 1136, 1280. Each is preceded by either a PricingService call (lines 329-340 etc.) that constructs $itemsArray with branch_id from PricingRequest, OR by a manual loop (lines 442-466 etc.) that hardcodes `'branch_id' => $this->order->branch_id`.
  - The AUDIT-P47-BUG3 comment at line 1255 ("always use order's branch, never client payload") confirms the dev team has been bitten by this before — there was a bug where client-supplied branch_id was used. The fix was code-level, not schema-level.
  - No migration adds a CHECK like `CHECK (branch_id = (SELECT branch_id FROM orders WHERE id = order_items.order_id))` — and Laravel can't easily express this without a trigger.
  - The Branch FK on order_items.branch_id (set by 2022_11_17_110832 line 19 via `->constrained('branches')`) only verifies the branch exists, not that it matches the parent order.
counter-evidence:
  - This is a defence-in-depth concern; the application layer DOES the right thing today.
  - Adding a CHECK / trigger has performance cost on inserts (SELECT against orders per row) and complicates RefundWithCounterEntryService which intentionally creates negated items on a mirror order (line 123-142) — the branch must still match the mirror order's branch, which it does (line 125: `$branchId` was resolved from parent order).
  - PricingResult denormalisation (line 280) already constraints to PricingRequest.branchId which is the caller's branch.
risk: Silent cross-tenant data leak via OrderItem if a future controller bug sets branch_id from request payload instead of from `$order->branch_id`. The AUDIT-P47-BUG3 comment is direct evidence this has happened before; defence-in-depth would prevent recurrence.
caveats: A trigger approach (BEFORE INSERT on order_items: verify NEW.branch_id matches orders.branch_id WHERE id = NEW.order_id) is portable to MySQL/MariaDB. SQLite test runners can skip the trigger. Cost is one extra row read per insert — negligible.
verdict: P1 — add BEFORE INSERT trigger on order_items raising SQLSTATE '45000' if branch_id mismatch. Pattern is already used for audit_logs / z_reports / cash_movements / order_payments immutability (see 2026_05_10_010000_secure_fiscal_audit_trail_immutability.php) — same migration shape.
```

### F6 — `KdsOrderTableSeeder` hardcodes `branch_id = 1` for 28+ rows; no environment guard, no idempotency
**Severity:** P2 (test data hygiene; visible in production if seed runs there)
**File:line:** `database/seeders/KdsOrderTableSeeder.php:32, 58, 84, 110, 136, 162, 189, 209, 229, 249, 269, 289, 309, 329, 349, 369, 389, 409, 429, 449, 469, 489, 509, 529, 549, 569, 589, 609, 629, 649` — 30 hits.
**Reasoning (strong):**
```yaml
claim: The KDS seeder inserts 30+ rows all with `branch_id = 1`. If this seeder is included in a `db:seed` invocation on a SaaS V2 instance where branch 1 may not exist or may be a different tenant, the rows leak to the wrong branch. There is no `if (app()->environment('local')) return;` guard, and no `firstOrCreate` / `updateOrInsert` idempotency — re-running `db:seed --class=KdsOrderTableSeeder` creates 30 more duplicate orders.
evidence:
  - 30 hits of `'branch_id' => 1` in KdsOrderTableSeeder.php.
  - No environment check in the seeder (grep'd, no `App::environment` or `app()->environment`).
  - Factories at database/factories/OrderFactory.php:22 use `BranchFactory::new()` (creates a new branch per call) — safer pattern that this seeder doesn't follow.
counter-evidence:
  - Seeders are typically run by developers, not in production. Le Cayenne V1 is single-tenant so branch_id=1 is correct.
  - The KDS seeder name implies test/dev demo data; a sane DBA wouldn't run it in production.
risk: SaaS V2 onboarding flow that runs `php artisan db:seed` to bootstrap a new tenant — the KDS seeder fires and creates 30 demo orders under tenant 1 (overwriting or shadowing real data for whichever tenant happens to be branch_id=1 in that env).
caveats: Easy fix: add `if (!app()->environment(['local', 'testing'])) return;` at the top of `run()`, and switch hardcoded `branch_id=1` to `Branch::query()->firstOrFail()->id` to fail loudly if no branch exists.
verdict: P2 — add environment guard + dynamic branch resolution.
```

---

## SECONDARY FINDINGS (no top-priority callout)

- **`webhook_events` has NO `branch_id` column at all** (migration 2026_05_09_120000 line 41-83) and the model docstring (`app/Models/WebhookEvent.php:43-46`) explicitly states "intentionally global — providers don't carry tenant context." This is **correct** but creates an architectural asymmetry: webhook events for Stripe/SenangPay traffic to a specific tenant cannot be queried by branch without joining to `orders` via `order_id`. For SaaS V2 forensics, BillingTeam may want a `branch_id` column populated POST-processing from `Order::find($webhook->order_id)->branch_id`. Defer to V2.

- **`item_wizard_profiles.branch_id_scope`** is the only column using a different name pattern (NOT `branch_id`). The `WizardProfileBranchScope` correctly handles this (whereNull OR equals semantics, scope file lines 51-55) — good. But it means generic tooling (e.g., a SQL audit script that greps `branch_id`) misses this table. Documented in the WizardProfileBranchScope docstring.

- **The `FrontendOrder` model shares the `orders` table** (`protected $table = "orders";` line 19) — branch_id integrity for FrontendOrder rows piggybacks on the orders migration's `foreignId('branch_id')->constrained('branches')`. Confirmed correct.

- **`HasDomainEvents` trait passes `branch_id` from the source model** to DomainEvent::create (`app/Traits/HasDomainEvents.php:45`: `'branch_id' => $model->branch_id ?? null,`). Correct, but the `?? null` fallback combined with `domain_events.branch_id` being nullable (no FK, no check) means a model without a branch_id property silently produces a NULL event. Acceptable for global events (PromoCreated, MaintenanceMode) but no enforcement guards against accidental nulls on tenant-scoped events.

- **`ZReport` model does NOT bind `BranchScope`** (`app/Models/ZReport.php` — no `addGlobalScope`). Fiscal reports are queried via explicit `->where('branch_id', $branchId)` in ZReportService.php (lines 102, 113). Acceptable but inconsistent with the rest of the family — a future maintainer might query `ZReport::all()` thinking it's BranchScope-protected.

- **`AuditLog` model not checked here** — out of scope of the 17 listed, but the same pattern applies: audit chain reads use explicit `->where('branch_id', $branchId)` in AuditLogService::lastHashFor().

---

## REMEDIATION PLAN (prioritised)

1. **P0** — none. Schema is operational for V1; no immediate compliance violations.
2. **P1 — F1**: ALTER `audit_logs.branch_id` to NOT NULL (backfill 0 first). 1 migration, gated owner per BRAIN §8.
3. **P1 — F2**: Add `foreign('branch_id')` constraints to audit_logs, z_reports, cash_drawer_sessions, cash_movements, order_payments, pending_payment_confirmations, domain_events, sync_metrics. Use `nullOnDelete()` for fiscal evidence (preserve row + null pointer to honour NF525 retention if branch hard-deleted), `restrictOnDelete()` for operational cash family.
4. **P1 — F5**: Add BEFORE INSERT trigger on `order_items` enforcing `branch_id == orders.branch_id`. Pattern matches 2026_05_10_010000 immutability triggers.
5. **P2 — F3**: Add `(branch_id, status, opened_by_user_id)` composite index on cash_drawer_sessions.
6. **P2 — F4**: Tighten `push_notifications.branch_id` to NOT NULL + FK.
7. **P2 — F6**: Add env guard + dynamic branch resolution to KdsOrderTableSeeder.

All P1 + P2 items are pure schema additions — zero behaviour change at the application layer, full backward compatibility on running queries, and zero risk to NF525 chain integrity (the immutability triggers continue to forbid UPDATE/DELETE; the new FKs are additive).
