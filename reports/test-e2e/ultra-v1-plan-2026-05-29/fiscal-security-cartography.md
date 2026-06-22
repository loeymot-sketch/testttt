# Fiscal + Security + Tenant — Verified Cartography
*Read-only ultra-audit · 2026-05-29 · Security+Fiscal+DBA specialist*
*Every cited path = a file opened with Read, or a grep hit shown verbatim. Unverified internals marked NOT-RE-READ.*

## Verified Cartography (file:line proof)

### 1. Fiscal services (frozen §7) — ALL EXIST, match description
- **`app/Services/Fiscal/FiscalSequenceService.php`** (116 lines, fully read).
  - `next(int $branchId)` returns `MAX(fiscal_sequence_no)+1` per branch (`:97-103`).
  - Concurrency triple-defense exactly as documented: `Cache::lock('fiscal_seq_b{id}', 5)` + `->block(3s)` (`:65-69`); inside, `DB::transaction` with `->lockForUpdate()` on the MAX query (`:76-101`); DB UNIQUE `orders_branch_fiscal_seq_unique` as ultimate gate (doc `:22`).
  - Soft-delete correctness: `withoutGlobalScope(BranchScope)->withTrashed()` so an allocated-then-soft-deleted order cannot cause seq re-use (`:97-98`, Z6-P1-WGS comment `:88-96`).
  - `$branchId <= 0` rejected (`:59-63`).
- **`app/Services/Fiscal/ZReportService.php`** (727 lines, fully read). HMAC chain confirmed: on close, `prev_hash` = previous CLOSED Z `signature` (`:233-237`), new `signature` via `FiscalSealingService::signZReport` (`:618-627`). `verifyChain()` re-walks chain detecting `chain_break` / `sequence_gap` / `signature_mismatch` and throws in strict/production mode (`:463-572`). `close()` is `Cache::lock('z_report_b{id}',10)` + `->block(4)` + `DB::transaction` with `lockForUpdate()` on the OPEN row (`:191-208`). Half-open `(from,to]` window prevents boundary double-count (`:343-347`). `withTrashed()` on aggregate for NF525 continuity (`:337-338`).
- **`app/Services/Fiscal/AuditLogService.php`** (375 lines, fully read). Only writer for `audit_logs`. `current_hash = HMAC-SHA256(prev_hash || canonical(action,payload))` (`:237-243`); canonical = recursive ksort + `json_encode(UNESCAPED_SLASHES|UNICODE)` (`:335-352`). Per-branch serialization `Cache::lock('audit_chain_b{id}',10)`+`block(5)` (`:100-109`) + `DB::transaction` (`:112`) + UNIQUE(branch_id,prev_hash) with single retry on collision (`:179-191`). Rejects null branch_id (`:93-98`). Refuses unsigned write if no secret (`:288-291`); production weak-secret guard (`:303-327`).
- Adjacent (exist, not in scope-detail): `FiscalChainValidator.php`, `FiscalSealingService.php`, `XReportService.php`, `ZReportCashEnrichmentService.php`.

### 2. Audit chain integrity / DB triggers
- Chain build mechanism: **confirmed Y** (AuditLogService `:237-243` HMAC; ZReportService `:233-239` Z-chain). Cross-chain anchor: `ZReport::updated` hook writes a `z_report.closed` audit_logs row binding the two chains (`AppServiceProvider.php:107-152`).
- Trigger migrations EXIST (grep-verified paths):
  - `database/migrations/2026_04_22_000002_create_audit_logs_table.php`
  - `database/migrations/2026_05_10_010000_secure_fiscal_audit_trail_immutability.php` (audit_logs immutability)
  - `database/migrations/2026_05_09_160000_add_z_reports_delete_trigger_immutability.php` (z_reports BEFORE DELETE)
  - `database/migrations/2026_04_22_100000_add_unique_chain_index_to_audit_logs.php` (UNIQUE chain index referenced by AuditLogService `:60-62`)
  - Sibling immutability triggers: cash_movements, delivery_boy_cash, stock_movements, composition_snapshot, z_reports/order_payments sqlite parity.
- Trigger bodies VERIFIED (fully read):
  - **audit_logs**: BEFORE UPDATE + BEFORE DELETE on MySQL/MariaDB via `SIGNAL SQLSTATE '45000'` (`2026_04_22_000002:96-118`); SQLite parity via `RAISE(ABORT,...)` (`:120-136`) — so immutability is covered end-to-end in PHPUnit. pgsql/sqlsrv = app-layer only. `down()` refuses in production (`:70-76`, 6y retention).
  - **z_reports**: BEFORE DELETE `SIGNAL '45000'`, MySQL/MariaDB (`2026_05_09_160000:44-58`); SQLite parity `z_reports_no_delete` RAISE(ABORT) under sqlite driver-guard (`2026_05_24_050000:75-83`, fully read) → CI now exercises DELETE-forbidden on both drivers. **SQLite gap RESOLVED.** UPDATE intentionally allowed (open→closed→archived state machine + cash enrichment).
  - **cash_movements / cash_drawer_sessions / order_payments**: FK cascade→RESTRICT + BEFORE DELETE `SIGNAL '45000'`, MySQL/MariaDB (`2026_05_10_010000:107-141`). order_payments SQLite parity `order_payments_no_delete` (`2026_05_24_050000:85-93`, read). cash_movements + cash_drawer_sessions SQLite parity via `2026_05_16_130000` + `2026_05_18_120300` (delivery_boy cash) per the parity-migration docblock (`2026_05_24_050000:19-26`, read).
  - TRUNCATE bypasses MySQL triggers everywhere → mitigated by GRANT-level REVOKE on prod DB user (Ansible CVP0-1 deploy doc, per CLAUDE.md §8 — not migration scope).

### 3. Multi-tenant (BranchScope + sentinel)
- **`app/Models/Scopes/BranchScope.php`** (42 lines, fully read). Admin `branch_id===0` → no filter (`:33-36`); staff → `where(table.branch_id = userBranch)` (`:38-39`); `User` model never filtered to avoid Sanctum recursion (`:21-23`); applies in testing env (`:27`).
- **`app/Models/Scopes/WizardProfileBranchScope.php`** (58 lines, fully read). Nullable variant for `item_wizard_profiles.branch_id_scope`: admin (`branch_id=0`) no filter (`:47-49`); staff → `WHERE branch_id_scope IS NULL OR branch_id_scope = userBranch` (`:51-55`) i.e. global-published OR own-branch; User never filtered (`:39-41`). Correctly handles the "global profile" semantics the strict BranchScope would have hidden.
- **`tests/Feature/Branch/BranchScopeCoverageSentinelTest.php`** (132 lines, fully read). Reflection-walks `app/Models`, flags any model with a `branch_id` column lacking `addGlobalScope(new BranchScope)` unless in `EXEMPTED_MODELS` (`:48-66`). Real exempted models (verbatim): **Branch**, **Customer** (architectural); **FrontendDiningTable, ZReport, AuditLog, OrderDiscountLog, Message, DiningTableAuditLog, KioskPromo, UpsellRule, ActionLog, DomainEvent** (V1.0.2 BASELINE backlog = 10). Sentinel asserts offenders list empty (`:115-123`). Companion `tests/Feature/Sentinels/ClaudeMdBranchScopeCountSentinelTest.php` locks the count documented in CLAUDE.md.

### 4. Idempotency
- **`app/Http/Middleware/IdempotencyKeyMiddleware.php`** (244 lines, fully read). Gated by `config('idempotency.enabled')` (`:41`). Scope key = `idempotency:v1:{branch}:{user}:sha256(key)` (`:77-82`) — exactly (branch_id,user_id,hash(key)). Payload hash compared on replay; mismatch → **409 IDEMPOTENCY_KEY_CONFLICT** (`:88-93`). Dual-layer: middleware cache (`acquire`/`waitForCompletion`/`complete`) + app-level UNIQUE(branch_id,idempotency_key) noted as defense-in-depth (`:22-25`). Only caches 2xx (`:145-151`); non-2xx releases the slot. Race in-flight → 425 (`:119-123`). `fail_open` config can bypass on storage outage (`:127-129`).

### 5. Production boot guards — `app/Providers/AppServiceProvider.php` (505 lines, fully read)
All inside `if (app()->environment('production'))` block `:158-454`:
- `POS_SIMULATION_HARDWARE` → throw `:165-171`
- `PAYMENT_BYPASS` `:177-183`, `PRINTING_BYPASS` `:184-190`
- `APP_DEBUG=true` → throw `:202-210`
- `IDEMPOTENCY_MIDDLEWARE_ENABLED` false → throw `:223-231`
- `LOYALTY_QR_SECRET` empty → throw `:241-251`
- `APP_URL` empty → throw `:261-271`
- `BROADCAST_DRIVER` null → throw `:273-278`; `QUEUE=sync` → throw `:279-284`
- `CACHE_DRIVER in ['array','null']` → throw `:294-302`
- Conditional: Stripe/SenangPay webhook secrets `:323-417`; MAIL_HOST SSRF range `:441-453`.
- Sentinel `tests/Feature/Boot/ProductionBootGuardsCompletenessSentinelTest.php` exists.

### 6. Authz drift
- **`tests/Feature/Sentinels/FormRequestAuthzDriftSentinelTest.php`** EXISTS (114 lines, fully read). **`RETURN_TRUE_BASELINE = 66`** (`:65`, ratcheted 2026-05-29 SUP-2 from prior 69 ceiling). Asserts `assertLessThanOrEqual(66, count)` (`:100-104`); if count < baseline it prints a hint to lower the constant (`:108-111`).
- **Counting predicate (`:83-84`):** matches ONLY the exact `authorize(){ return true; }` / `authorize(): bool { return true; }` body shape via PCRE, NOT any `return true;`.
- **VERIFIED GREEN (no drift):** I replicated the sentinel's exact regex in PHP over `app/Http/Requests` → **count = 66 = baseline** → PASS. (My initial raw `grep -rl "return true;"` = 71 over-counts — it matches `return true;` in other methods/comments and is NOT comparable to the sentinel. CLAUDE.md §9's "66 observed" reconciles exactly.) **No P0/P1 here.** Total FormRequests = 99.

### 7. Controllers inventory
- `find app/Http/Controllers -name '*.php'` = **142** files. Order/payment/fiscal-critical: `Frontend/OrderController`, `Frontend/PaymentController`, `Frontend/PaymentReconcileController`, `Admin/PosOrderController`, `Admin/OnlineOrderController`, `Admin/Fiscal/ZReportController`, `Admin/Fiscal/XReportController`, `Admin/Pos/CashDrawerController`, `Admin/Pos/CashDrawerSessionController`, `Admin/Pos/ParkedOrderController`, `Auth/KioskMachineLoginController`.

## Maturity score
**Fiscal core: 9.0/10.** Triple-lock sequence (cache+FOR UPDATE+DB UNIQUE), HMAC dual-chain with independent re-walk verifier, immutability triggers (MySQL `SIGNAL '45000'` + full SQLite `RAISE(ABORT)` parity, CI-exercised), GRANT-level TRUNCATE REVOKE, soft-delete-aware aggregation, production secret guards, ~40 fiscal feature tests. Deductions: cross-chain anchor is intentionally best-effort/forensic-only (a swallowed exception leaves a signed Z with no audit_logs anchor row — the Z-chain stays valid via its own HMAC, but no reconciliation cron exists to detect a missing anchor). TRUNCATE mitigation lives in deploy tooling, not the schema.
**Tenant isolation: 8.0/10.** Global scope applied per the locked baseline (CLAUDE.md §9 claims 20 models — NOT independently re-counted this session; the sentinel walks models dynamically rather than asserting a fixed 20) + CI coverage sentinel preventing drift. Deductions: 10 V1.0.2-backlog models (incl. **ZReport, AuditLog** — fiscal tables) still exempt → cross-branch read leak latent (V1 single-tenant safe, V2 hard-fail); BranchScope only applies when `Auth::check()` (queue/CLI contexts rely on F010 sentinel).
**Backend security (authz/idempotency/boot): 8.0/10.** Strong boot guards + scoped idempotency + authz drift sentinel verified GREEN at 66=baseline (no drift). Deductions: 66 FormRequests still defer authz to route middleware via `return true;` (V1.0.2 chip-away backlog — each is a latent over-permissive write surface if its route loses `permission:*`); `fail_open` idempotency bypass exists but defaults false (`config/idempotency.php:13`, verified); `file`/`database` cache drivers pass the boot guard (UNI-03 backlog).

## Findings (adversarial)

**[P2] app/Http/Requests (66 files) — broad authz delegation to route middleware via `authorize(){return true;}`.**
Repro: sentinel-exact count = 66 (verified GREEN, not drift). Evidence: PHP replication of FormRequestAuthzDriftSentinelTest:83-84 regex = 66. Risk: defense-in-depth is single-layer for these 66 mutating requests — if a route ever loses its `permission:*` middleware, the FormRequest authorizes unconditionally. Largest blast-radius remaining (sentinel docblock `:57-63`): EmployeeRequest/ChefRequest/WaiterRequest (staff create), SignupRequest/OtpRequest (customer authn), Company/Site/Theme/Language (settings). Fix: continue the documented V1.0.2 chip-away to `$this->user()?->can('xxx')` and lower the baseline each wave (ratchet tight). Not a V1 ship blocker — routes are middleware-guarded today.

**[P1] app/Models/Scopes/BranchScope.php:27 + sentinel EXEMPTED_MODELS — ZReport & AuditLog exempt from BranchScope.**
Repro: both fiscal models in `EXEMPTED_MODELS` (BranchScopeCoverageSentinelTest:57-58). Evidence: file read. Risk: any query path on ZReport/AuditLog not manually `where('branch_id')`-scoped returns cross-branch fiscal rows. Services scope manually today (verified in ZReportService/AuditLogService), so V1 single-tenant is safe, but a new admin controller listing Z reports could leak. Fix: keep V1.0.2 heal; add a per-model regression test asserting controller-layer scoping until the global scope lands.

**[P2] app/Providers/AppServiceProvider.php:294-302 — CACHE_DRIVER boot guard forbids only array/null; `file`/`database` pass.**
Repro: `$forbiddenCacheDrivers = ['array','null']`. Evidence: read `:295`. Risk: `file`/`database` cache drivers are not cross-worker coherent → `Cache::lock('audit_chain_b{n}')` becomes per-process under multi-FPM, weakening chain-fork protection (UNIQUE index still catches). CLAUDE.md §8 documents this as UNI-03. V1 single-box file driver safe; ALB multi-instance unsafe. Fix: widen list to require redis/memcached before cloud cutover.

**[P2] app/Http/Middleware/IdempotencyKeyMiddleware.php:127-129 — `idempotency.fail_open` bypasses at-most-once on storage outage.**
Repro: when storage unavailable + `fail_open=true`, request proceeds relying solely on app-layer UNIQUE. Evidence: middleware read `:127-129`; **default VERIFIED safe** — `config/idempotency.php:13` = `env('IDEMPOTENCY_FAIL_OPEN', false)`, so the bypass is OFF unless explicitly enabled. Residual risk: an operator who sets `IDEMPOTENCY_FAIL_OPEN=true` during a Redis incident loses duplicate protection on routes lacking a DB UNIQUE (status changes, drawer ops) → double-execute. Fix: ensure all 23 required routes have a DB-level idempotency anchor; consider removing the flag entirely for V1 (low value, real foot-gun). Severity stays P2 (safe-by-default).

**[P3] app/Providers/AppServiceProvider.php:107-152 — Z-close cross-chain audit anchor is best-effort (no detector for a missing anchor).**
Repro: anchor write wrapped in try/catch that only logs on failure (AppServiceProvider:139-151). Evidence: the code's own comment at `:139-143` states the design intent — "Cross-chain anchor missing = forensic limitation, NOT a fiscal break. The Z-chain (z_reports.signature) remains valid via its own HMAC." So this is documented-intentional, not a defect; the gap is the absence of a *detector*. Risk: a cache outage at close time leaves the Z signed in `z_reports` but with NO `audit_logs` anchor row → an audit_logs-only forensic walk cannot prove the Z-close happened. Fix: a reconciliation cron asserting every CLOSED ZReport has a matching `z_report.closed` audit row.

## Existing tests (verified-real paths)
- **tests/Feature/Fiscal/** (~40 files incl.) AuditLogHashChainTest, AuditLogImmutabilityTest, AuditLogConcurrencyTest, AuditLogBranchRequiredTest, ZReportCloseTest, ZReportBoundaryTest, ZReportControllerTest, FiscalVerifyChainCommandTest, FiscalSealingHmacTest, OrderFiscalSequenceSchemaTest, NF525ComplianceE2ETest, RefundPreZTest/RefundPostZTest, VoidPreZTest, SealedOrderMutationGuardTest, FiscalSecretProductionGuardTest, AuditTruncateProtectionDeployDocTest.
- **tests/Feature/Branch/** BranchScopeCoverageSentinelTest.
- **tests/Feature/Sentinels/** FormRequestAuthzDriftSentinelTest, IdempotencyMiddlewareProductionGuardSentinelTest, PosSimulationHardwareProductionGuardSentinelTest, CorsAppUrlProductionGuardSentinelTest, FiscalZBranchExactnessSentinelTest, FiscalSealedZSentinelTest, ClaudeMdBranchScopeCountSentinelTest, WithoutGlobalScopesAuditSentinelTest, AdministratorBranchZeroMintBypassSentinelTest, + many branch-exactness/payment-confirm sentinels.
- **tests/Feature/Boot/** ProductionBootGuardsCompletenessSentinelTest.
- **tests/Feature/Security/** IdempotencyCrossUserLeakSentinelTest, KioskTokenAdminBlockSentinelTest, CustomerTokenHmacHardenedSentinelTest, MailHostAllowlistSentinelTest.
- **tests/Feature/Idempotency/** (dir exists).
