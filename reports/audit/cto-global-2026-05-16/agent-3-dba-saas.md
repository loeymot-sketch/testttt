# Agent 3 — DBA + Multi-Tenant SaaS-Readiness Audit
**Date**: 2026-05-16
**Scope**: 158 migrations, 60+ Eloquent models, `BranchScope`, billing/onboarding infra
**Working tree**: `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt`
**Verdict (TL;DR)**: V1 single-tenant DB is **production-grade for one branch**. Multi-restaurant SaaS readiness is **non-existent** — fundamental schema, billing, onboarding, and tenancy primitives are absent. Cannot be sold as multi-restaurant SaaS in the current shape.

---

## SCORES

| Dimension | Score |
|---|---|
| **DB Quality (V1, single tenant)** | **72/100** |
| **Multi-Restaurant SaaS Readiness** | **8/100** |

DB quality is solid for one restaurant — NF525 invariants, immutability triggers, HMAC chains, idempotency uniqueness are all correctly engineered. SaaS readiness is essentially zero — there is no subscription, no billing, no plan, no super-admin segregation, no onboarding pipeline, and worst of all the **catalog is schema-shared globally** with no `branch_id`.

---

## PART A — DATABASE QUALITY

### A.1 Schema review (158 migrations)

- **First migration**: `2014_10_12_000000_create_users_table.php` (Laravel default).
- **Branch table created**: `database/migrations/2022_11_17_110125_create_branches_table.php:16-33` — minimal columns (name, contact, geo, status). No subscription/billing fields.
- **Fiscal/NF525 additions (Q1 2026)**: `audit_logs`, `z_reports`, `cash_drawer_sessions`, `cash_movements`, `order_payments`, `webhook_events`, `fiscal_alloc_error_at`, `domain_events`, `composition_snapshot`, etc. — all well-structured with FK + index discipline.
- **Tech-debt smell**: 50+ `add_<col>_to_<table>` migrations (`2023_07_20_*`, `2024_*`, `2026_*`) accumulated over 4 years. Branches table received 5 separate ALTERs (`add_zone`, `add_available_locales`, `add_fiscal_identity`) — readable but the next major version should consolidate.
- **Wizard family** (`item_wizard_profiles`, `item_wizard_steps`, `item_wizard_step_versions`) underwent 3 reshape migrations (`make_polymorphic_owner`, `add_source_item_attribute_id`, `add_wizard_profile_id_to_item_categories`) within 4 weeks — indicates active design churn but downs are clean.

### A.2 Index coverage

**Strong**:
- `2026_03_12_130000_add_performance_indexes.php:21-61` adds `idx_orders_branch_status`, `idx_orders_user_id`, `idx_orders_datetime`, `idx_items_status_category`, `idx_users_email`, `idx_order_items_order_id`.
- `2026_04_22_200000_add_composite_index_branch_created_to_action_logs.php` for log queries.
- Unique chain integrity: `audit_logs_branch_prev_unique` (`2026_04_22_100000`), `z_reports_branch_sequence_unique` (`2026_04_22_000003:62`), `orders_branch_id_idempotency_key_unique` (`2026_04_18_140003:35`), `orders_branch_queue_number_unique` (`2026_04_26_213800`).
- `audit_logs` has `(branch_id, created_at)` + `(resource, resource_id)` composite (`2026_04_22_000002:54-55`).

**Gaps**:
- `order_items.item_id` indexed only via FK (no covering composite `(branch_id, item_id)` for cross-branch popularity queries — fine at V1 scale, will need it for SaaS analytics).
- `users.phone` not indexed despite NEW NOT NULL constraint (`2026_05_16_140100`) — phone lookups for delivery and loyalty (NFC + branch) need it.
- `users.branch_id` not standalone indexed — `users_branch_nfc_uid_unique` covers the prefix but a per-branch staff listing query has no dedicated path.
- `composition_snapshot` is JSON column (`2026_04_22_000020:12`) with no functional indexes — fine at V1 (read-only), but cross-branch analytics ("how many add-ons selected per item") will require either generated columns or extraction at ingest.

### A.3 N+1 risks

**Mitigated**:
- `OrderDetailsResource.php:55` does `$this->orderItems->load('orderItem')` — eager load.
- `OrderResource.php:41-42` eager-loads `user.roles`, `user.media`, `transaction.order`.
- `ItemCategoryMenuResource.php:26`, `OfferResource.php:32` eager-load via `->load(...)`.

**Risks**:
- `NormalItemResource.php:108` chains `addonItem.variations`, `addonItem.offer`, `item` — 3-level deep eager load on potentially large addon collections (per-item rendering in catalog responses). On 50+ items × 5 addons × variations this is fine, but at SaaS scale (1000+ menus) it pre-materializes a large blob.
- `ItemResource.php:52-62` (`$this->variations->each(...)`) operates on already-loaded collections — not N+1, but performs `choiceAvailability` lookup per row. Should be batched at the resource boundary.
- Several `->each(function($x))` patterns in `ItemResource` and `NormalItemResource` perform availability decisions per element — acceptable, but should be measured under load.

### A.4 `composition_snapshot` JSON

- Column added at `2026_04_22_000020_add_composition_snapshot_to_order_items.php:12` as nullable JSON.
- Cast at `OrderItem.php:71` (`'composition_snapshot' => 'array'`).
- **V1 fine** — frozen-at-order-creation, read-only, NF525 evidence. Not indexable, but doesn't need to be at this scale.
- **SaaS concern**: cross-tenant analytics ("most-customized item" across all chains) will require either a projection to relational rows, or MySQL JSON virtual columns + functional indexes. Not blocking, but plan for it.

### A.5 Audit chain HMAC schema

`2026_04_22_000002_create_audit_logs_table.php:34-56` is **correct and severe**:
- `prev_hash CHAR(64)`, `current_hash CHAR(64)` columns present.
- `BEFORE UPDATE` + `BEFORE DELETE` triggers raise `SQLSTATE '45000'` on MySQL/MariaDB (`audit_logs_no_update`, `audit_logs_no_delete` at lines 98-115).
- SQLite parallel triggers (lines 121-135) for PHPUnit coverage.
- Production rollback blocked: migration `down()` throws if `APP_ENV=production` (lines 70-76).
- `2026_05_10_010000_secure_fiscal_audit_trail_immutability.php` extends the same pattern to `cash_movements`, `cash_drawer_sessions`, `order_payments` with `restrictOnDelete` FK + BEFORE DELETE triggers.

**This is best-in-class for the fiscal subset.** The same engineer should harden the rest of the schema with the same discipline before SaaS.

### A.6 Migration discipline

- All migrations checked have `up()` + `down()`.
- `down()` is guarded against production rollback on fiscal tables (`audit_logs`, `z_reports`).
- One stub trait: `app/Traits/MultiTenantModelTrait.php:14-18` — body is an empty `if (!App::runningInConsole() && Auth::check()) { }` (no-op). Vestigial from a multi-tenant ambition that was never finished. Used by `User.php:29`. **Dead code that should be removed or implemented.**
- No breaking migrations detected in recent series.

### A.7 Backups

- **No automated DB backup detected.**
  - `app/Console/Kernel.php:21-100` schedules OTP purge, outbox rescue, fiscal-alloc retry, kiosk cleanup — no `backup:run`, no `mysqldump`, no `spatie/laravel-backup` invocation.
  - `storage/backups/` contains application-level menu/heal snapshots only (`menu-heal-v2-*`, `menu-reset-2026-05-13`, `ultra-review-heal-2026-05-16`), produced by domain-specific commands. Not DB dumps.
  - **This is a P0 SaaS blocker.** A single restaurant can survive with weekly hosting-provider snapshots; a paying tenant cannot.
- Audit / Z report retention: 6 years configured (`config/fiscal.php:148`), but no scheduled archive verification.

---

## PART B — MULTI-RESTAURANT SAAS READINESS

### B.1 BranchScope coverage (verified)

**17 models with `addGlobalScope(new BranchScope)` (verified by grep)**:
`CashDrawerSession`, `CashMovement`, `DiningTable`, `FrontendOrder`, `KioskMachine`, `Order`, `OrderItem`, `OrderPayment`, `OrderQuote`, `PaymentTerminal`, `PendingPaymentConfirmation`, `Printer`, `PosParkedOrder`, `PushNotification`, `StockLevel`, `StockMovement`, `User` (scope attached but `apply()` early-returns at `BranchScope.php:21-23` to avoid Sanctum recursion).

**Models WITHOUT BranchScope but referenced cross-branch** (= V1 data leak between tenants):
- `Item`, `ItemCategory`, `ItemAttribute`, `ItemVariation`, `ItemExtra`, `ItemAddon` — **no `branch_id` column at all** in their migrations. Catalog is globally shared. Verified: `grep branch_id database/migrations/*create_items_table.php` → none.
- `Tax`, `Coupon`, `Menu`, `MenuSection`, `MenuTemplate` — same, no `branch_id`.
- `Allergen`, `ItemBranchAvailability` — `ItemBranchAvailability` is a junction table providing per-branch availability toggles on the otherwise-global catalog.
- `ActionLog` — has `branch_id` (added by `2026_04_19_000000_add_branch_id_to_action_logs.php`) but no `BranchScope` global filter — admin-only read but still a potential leak.
- `DomainEvent`, `Transaction`, `ZReport`, `Address` — schema has `branch_id` references but no global scope; `ZReport` is admin-read; `Transaction` accessed via Order so transitively scoped.

### B.2 Branch table model

`database/migrations/2022_11_17_110125_create_branches_table.php`:
- Columns: `id, name, email, phone, lat/lng, city, state, zip_code, address, status, creator_*, editor_*, timestamps`.
- ALTERs added: `zone` (2025_02_12), `available_locales` (2026_04_18), `siret/vat_intra/register_id/legal_footer` (2026_04_20).
- **Missing for SaaS**: `owner_user_id`, `plan_id`, `subscription_id`, `subscription_status`, `trial_ends_at`, `billing_email`, `mrr`, `currency`, `country`, `timezone`, `feature_flags JSON`, `quotas JSON`, `created_via` (signup vs manual), `is_demo`, `suspended_at`, `archived_at`.
- `Branch.php` model has no `subscription()`, `plan()`, `owner()` relations. None exist because the underlying tables don't exist.

### B.3 Tenant isolation strategy

- **Currently**: row-based + `BranchScope`. Single DB, single schema, all tenants share every table.
- **Problem 1**: Catalog tables (Item, Tax, Coupon, etc.) are globally shared. There is no per-tenant menu — the architecture assumes one menu, used by everyone. `ItemBranchAvailability` lets a branch hide an item, not customize it. For "Le Cayenne" alone this is fine; for "Pizza Hut + Burger King in the same DB" this is a fatal flaw.
- **Problem 2**: No noisy-neighbor protection. Heavy queries from one tenant (Z-report rebuild, KDS poll storm) affect all.
- **Problem 3**: GDPR data export per tenant requires walking 17 scoped tables + 10 shared-catalog tables (which don't have `branch_id`) — there is no per-tenant `.zip` export command.

### B.4 Cross-branch admin (`branch_id=0`)

`BranchScope.php:33-36`:
```php
if ($userBranch === 0) {
    // Admin: no filter applied — sees all branches including branch_id=0 rows
    return;
}
```

**Severe SaaS concern**: any user with `branch_id=0` sees **every tenant's data**. There is no distinction between:
- Platform super-admin (FoodKing staff seeing all tenants)
- Chain owner (sees only their 3 restaurants)
- Branch manager (sees only 1 restaurant)

`grep super_admin` across `app/` and `database/seeders/` returns **zero hits**. Spatie roles exist (`Admin`, `Branch Manager`, `POS Operator`, `Chef`, etc. per CLAUDE.md §9) but there is no `super_admin` vs `chain_owner` separation. Selling to two competing restaurant chains today would let each chain's admin (if they get `branch_id=0`) read the other chain's orders and Z-reports.

### B.5 Data export per tenant

- No `php artisan tenant:export {branch_id}` command in `app/Console/Commands/` (verified by `find`). Closest commands are `FiscalArchiveCommand` (Z-report archive only, branch-scoped) and `MenuResetLeCayenneCommand` (hard-coded to one branch).
- GDPR Article 20 (portability) and Article 17 (erasure) cannot be served without manual SQL.

### B.6 Per-tenant configuration

- `Branch.available_locales` (JSON) is the only per-tenant config column. SIRET/VAT/register_id added recently (`2026_04_20_210000_add_fiscal_identity_to_branches.php:13-22`) — France-only.
- No per-tenant currency (system-wide `config/payment.php` + `currencies` table).
- No per-tenant tax-rate isolation (`taxes` table is global).
- No per-tenant fiscal regime (NF525 is hardcoded — selling to non-FR countries means rewriting `FiscalSequenceService`, `ZReportService`, `AuditLogService`).
- HMAC secret can be branch-scoped (`config/fiscal.php:23-29` comment mentions `FISCAL_AUDIT_SECRET_BRANCH_N` env override) — that's the only existing per-tenant key rotation primitive.

### B.7 Billing readiness

- **No `subscriptions` / `plans` / `invoices` / `billing_*` tables.**
- `app/Http/PaymentGateways/Gateways/Stripe.php` is a **customer-order** payment gateway (Stripe Checkout for end-customers paying for food), not a SaaS billing integration for restaurants paying FoodKing.
- No usage metering (orders/month, KDS minutes, kiosk transactions).
- No Stripe Billing / Paddle / Chargebee SDK installed (verified via grep).

### B.8 Onboarding new restaurant

- No CLI command: `find app/Console/Commands -name "*Onboard*"` returns nothing. Closest: `EnsureKioskMachineCommand`, `EnsureAdminLoginCommand` (developer fixtures).
- No web wizard route (`grep onboard routes/*.php` empty).
- Provisioning today = manual: `php artisan db:seed --class=BranchTableSeeder` + manual SQL + `EnsureKioskMachineCommand`. Time-to-onboard ≈ developer-hours. SaaS needs minutes.

### B.9 Per-tenant feature flags

- `config/catalog_v15.php` defines `FK_*` env-based feature flags (`FK_POS_WIZARD_COMPOSER_AWARE_ENABLED`, `FK_CATALOG_UNIFIED_PROJECTION_ENABLED`, etc.) — **global env scope, not per-tenant**. Toggling them affects every restaurant simultaneously.
- No `feature_flags JSON` on `branches` table.
- No `Laravel\Pennant` / `flagsmith` / `launchdarkly` package detected.

### B.10 Branch.status drift (production bug)

`app/Listeners/PersistCatalogChangedToOutbox.php:38-41` documents:
> *"Production DB seeded long before the enum migration still has status=1 for active branches. Listener filter was silently dropping all branchId=null fan-out."*

Code workaround: `whereIn('status', [Status::ACTIVE, 1])` (line 39).
Root cause: `Status::ACTIVE = 5` (`app/Enums/Status.php:7`) but legacy seed inserted `status=1`. The migration default uses `\App\Enums\Status::ACTIVE` (=5), so seeders applied **before** the enum existed never were back-fixed. **Owner action documented but not executed** — a `UPDATE branches SET status=5 WHERE status=1` data migration is pending. Until then, two code paths (BranchService:132,189 use strict `Status::ACTIVE`; Listener uses lenient `whereIn`) interpret reality differently. Selling SaaS while this drift is unresolved would multiply the bug across N tenants.

---

## TOP FINDINGS

### P0-1 — Catalog has no `branch_id`; multi-restaurant menus impossible without rewrite
- Files: `database/migrations/2022_11_17_110514_create_items_table.php:19-39`, `app/Models/Item.php` (no `BranchScope`), `app/Models/ItemCategory.php`, `app/Models/Tax.php`, `app/Models/Coupon.php`.
- Verified: 10 catalog tables (`items`, `item_categories`, `taxes`, `coupons`, `item_attributes`, `item_variations`, `item_extras`, `menus`, `menu_sections`, `menu_templates`) all have NO `branch_id` column.
- Impact: catalog is **architecturally global**. `ItemBranchAvailability` is a junction table for on/off toggling, not for distinct menus. Two restaurants cannot have different items, prices, or taxes in the current schema.
- Severity: **fundamental schema rewrite required for SaaS**.

### P0-2 — No billing / subscription / plan / onboarding infrastructure
- Verified: no `subscriptions`, `plans`, `invoices`, `tenants`, `billing_*` tables in 158 migrations.
- `app/Console/Commands/` contains no `Onboard*` / `Provision*` / `Tenant*` command.
- `MultiTenantModelTrait` (`app/Traits/MultiTenantModelTrait.php:14-18`) is a **no-op stub** — empty `if` block.
- Impact: cannot bill, cannot trial, cannot self-serve onboard. Today selling a restaurant = manual SQL + developer time.

### P0-3 — `branch_id=0` super-admin = all-tenant data read; no role separation
- `BranchScope.php:33-36` — admin sees every branch's records.
- No `super_admin` vs `chain_owner` vs `branch_manager` separation in Spatie roles seeders/code.
- Impact: legal/competitive disaster the moment two restaurants share the same instance. A single misconfigured user can read every tenant's NF525 audit trail.

### P0-4 — No scheduled DB backup
- `app/Console/Kernel.php` schedules OTP purge, outbox rescue, fiscal retry, etc. — **no DB dump/backup command**.
- `storage/backups/` contains domain heal snapshots, not full DB.
- Impact: a paying SaaS tenant losing their menu/orders to a deploy mistake has no recovery path. Non-negotiable for SaaS GA.

### P0-5 — `Branch.status` integer drift (1 vs 5) silently active in production
- `app/Enums/Status.php:7` → `ACTIVE=5`.
- `app/Listeners/PersistCatalogChangedToOutbox.php:38-41` documents legacy `status=1` rows; uses `whereIn('status', [5, 1])` workaround.
- `app/Services/BranchService.php:132,189` use strict `Status::ACTIVE` (only matches 5).
- Two code paths interpret the same column differently. Live production bug.

### P1-1 — `MultiTenantModelTrait` is a dead stub
- `app/Traits/MultiTenantModelTrait.php:13-18` — empty `if` body. Used by `User.php:29`.
- Either implement it as a true scope (complementing BranchScope) or delete it. Currently it's a misleading signal in the codebase.

### P1-2 — `withoutGlobalScope(BranchScope)` widely used; needs audit pass
- Verified 22 occurrences across `app/Http/Controllers/`, `app/Jobs/`, `app/Services/`, `app/Console/Commands/`.
- Each is a justified bypass (pre-auth kiosk lookup, fiscal close cross-branch admin), but each is also a **potential leak vector** that must be re-audited under multi-tenant invariants.
- File:line examples: `app/Http/Controllers/Frontend/PaymentReconcileController.php:143,194,232,247,288`, `app/Http/Controllers/Frontend/OrderController.php:159,184`, `app/Services/Fiscal/ZReportService.php:337,589`, `app/Services/Fiscal/ZReportCashEnrichmentService.php:54,77,154,181`.

### P1-3 — No per-tenant currency / tax / fiscal regime
- `config/fiscal.php` is NF525-only (FR). `taxes` table is global. `currencies` table not per-branch.
- Selling FoodKing to a Belgian or Moroccan restaurant requires either deploying a separate instance or rewriting fiscal services + adding `tax_regime_id` to branches.

### P1-4 — Feature flags are global env vars, not per-tenant
- `config/catalog_v15.php:96` — `FK_POS_WIZARD_COMPOSER_AWARE_ENABLED` toggles for all branches simultaneously.
- No `branches.feature_flags JSON` column. No `Laravel\Pennant`.
- A gradual SaaS rollout (10% of tenants on new wizard) is currently impossible.

### P2-1 — `users.phone` not indexed despite NOT NULL constraint
- `2026_05_16_140100_make_user_phone_required.php` makes phone NOT NULL.
- No standalone index. Phone lookups for delivery validation, NFC-loyalty, and SMS gateway routing pay full table scan.
- Easy fix: add `$table->index('phone')` migration.

### P2-2 — `composition_snapshot` JSON non-queryable for analytics
- Column at `2026_04_22_000020:12`. Stored as full JSON blob.
- V1 OK (read-only fiscal evidence). SaaS analytics ("which add-ons drive revenue across all chains") require either a projection table or MySQL JSON generated columns.

---

## MULTI-RESTAURANT MIGRATION PATH (3 phases)

### Phase 1 — Foundations (4-6 weeks, blocks SaaS GA)
1. Add SaaS columns to `branches`: `owner_user_id`, `plan_id`, `subscription_status`, `trial_ends_at`, `currency`, `country`, `timezone`, `tax_regime_id`, `feature_flags JSON`, `quotas JSON`, `suspended_at`.
2. Create `plans`, `subscriptions`, `invoices`, `usage_meters` tables. Wire Stripe Billing / Paddle.
3. Fix `branches.status` drift: data migration `UPDATE branches SET status=5 WHERE status=1`, then enforce enum at write.
4. Implement `php artisan tenant:provision --name=... --owner-email=...` CLI; create web onboarding wizard route.
5. Split Spatie roles: `super_admin` (FoodKing staff), `chain_owner`, `branch_manager`, `staff`. Refactor `BranchScope` to filter by **chain** not just branch when chain-owner queries.
6. Schedule **DB backups** via `spatie/laravel-backup` daily + retention.

### Phase 2 — Catalog per-tenant (8-12 weeks, hardest work)
1. Add `branch_id` (or `tenant_id`) to `items`, `item_categories`, `taxes`, `coupons`, `item_attributes`, `item_variations`, `item_extras`, `menus`, `menu_sections`, `menu_templates`.
2. **Migrate the existing single-tenant menu** to branch 1 (Le Cayenne) — keep `ItemBranchAvailability` as the cross-tenant inventory-sharing primitive only.
3. Add `BranchScope` (or `TenantScope`) to all 10 catalog models. Validate every controller path with the multi-tenant test harness.
4. Replicate global-scope discipline applied to `Order`/`OrderItem` to catalog. Add migration `add_branch_id_to_*` for each.
5. Per-tenant feature flags via `Laravel\Pennant` or `branches.feature_flags JSON`.
6. Per-tenant currency/tax/fiscal regime — extract `FiscalEngine` interface; implement `Nf525FiscalEngine` (FR), keep door open for `BeFiscalEngine`, `EsFiscalEngine`.

### Phase 3 — Hardening (6-8 weeks, can run in parallel late in Phase 2)
1. GDPR `tenant:export {branch_id}` CLI producing portable ZIP (orders + items + audit chain + Z reports + customers).
2. Tenant-suspend / tenant-archive flows.
3. Cross-tenant analytics warehouse (Snowflake/BigQuery sync via outbox events).
4. Per-tenant query budgets / rate-limit middleware (noisy-neighbor protection).
5. Cost attribution per tenant (orders, KDS minutes, kiosk transactions) feeding usage metering.
6. Public API + webhooks per tenant; OAuth client credentials per tenant.

**Realistic effort estimate**: 18-26 weeks of focused engineering for an experienced 2-3 person backend team. Anything faster is wishful.

---

## TOP 3 RECOMMENDATIONS

1. **Owner decision needed: single-tenant per-instance vs true multi-tenant.** The cheapest SaaS path for FoodKing today is **one instance per restaurant** (each restaurant gets their own DB + their own deploy). The schema is already production-grade for one tenant; replication via Terraform/Ansible per customer is a 2-week investment. Multi-tenant-in-one-DB is the harder, 18-26-week path described above. Pick now before another quarter of code lands.

2. **Fix the `branch.status` 1-vs-5 drift before adding ANY new branch.** Run the documented `UPDATE branches SET status=5 WHERE status=1` migration today. Remove the `whereIn([5, 1])` workaround. Add a CI assertion that `branches.status IN (5, 10)`. Until this is fixed, every multi-tenant scenario inherits the bug at multiplied scale.

3. **Schedule DB backups now, before V1 GA.** Install `spatie/laravel-backup`, schedule daily + weekly retention, encrypt to S3/B2. No SaaS sale should happen on infrastructure that has only application-level menu snapshots and no full-DB recovery point.

---

## MULTI-TENANT READINESS VERDICT

**Cannot be sold as multi-restaurant SaaS in current shape.** The transactional half of the schema (Orders/Payments/Cash/Fiscal/KDS) is well-engineered for one tenant. The catalog half (Items/Taxes/Coupons/Menus) is structurally single-tenant — no `branch_id`, no per-tenant scope, no per-tenant pricing or tax. Add to that: no billing tables, no subscription model, no onboarding pipeline, no super-admin role separation, no per-tenant feature flags, no DB backups, and a known status-drift bug already in production. Selling SaaS today exposes FoodKing to legal (NF525 cross-tenant leak), commercial (chain admins reading competitor data), and operational (no recovery point) liability.

The pragmatic alternative is **one-instance-per-restaurant SaaS** — each customer gets their own dedicated FoodKing stack. The codebase is ready for that within ~2 weeks of provisioning automation. The true multi-tenant rewrite is 18-26 weeks minimum.
