# AUDIT MANIFEST — Global Cross-System (Auth + Multi-Tenant + NF525 + Idempotency)
**Date** : 2026-05-09
**Scope** : Couches transverses — BranchScope global, Sanctum, Spatie permissions, NF525 fiscal chain, Idempotency middleware, Production guards
**Branch** : `review/audit-global-cross-system`
**Cible** : `/ultrareview <this-PR>` doit auditer tout le périmètre listé ci-dessous

---

## §1 — Files in scope

### Multi-Tenant + BranchScope
- `app/Models/Scopes/BranchScope.php` (global scope logic)
- `app/Models/User.php` (exempt for Sanctum recursion)
- `app/Models/Branch.php`
- 11 models scoped post iter11+12 :
  - `app/Models/Order.php`
  - `app/Models/OrderItem.php` (iter11)
  - `app/Models/OrderPayment.php` (iter12)
  - `app/Models/FrontendOrder.php`
  - `app/Models/KioskMachine.php` (iter12 + 2 withoutGlobalScope sites)
  - `app/Models/StockLevel.php`
  - `app/Models/StockMovement.php`
  - `app/Models/CashDrawerSession.php`
  - `app/Models/CashMovement.php`
  - `app/Models/PendingPaymentConfirmation.php`
  - `app/Models/DiningTable.php`
  - `app/Models/Printer.php`
  - `app/Models/PushNotification.php`

### Auth Sanctum
- `app/Http/Controllers/Auth/KioskMachineLoginController.php` (kiosk:order ability single, withoutGlobalScope explicit)
- `app/Http/Controllers/Auth/AuthController.php`
- `config/sanctum.php` (TTL 480 min, stateful domains)
- `app/Providers/AppServiceProvider.php` (focus boot() guards)
- `app/Http/Kernel.php` (middleware auth:sanctum)

### Authorization Spatie
- `database/seeders/PermissionTableSeeder.php`
- `database/seeders/RolesAndPermissionsSeeder.php` (si existe)
- `config/permission.php`
- 88+ FormRequests (la plupart `authorize() = true` blanket — V1.0.1 refactor backlog) :
  - `app/Http/Requests/*.php` — focus mutations critiques

### Idempotency
- `app/Http/Middleware/IdempotencyKeyMiddleware.php` (focus resolveBranchId + Cache::lock 5s)
- `app/Models/AppIdempotencyRecord.php` (DB UNIQUE defense-in-depth)
- `database/migrations/2026_*_scope_idempotency_key_to_branch.php`
- `app/Models/WebhookEvent.php` (iter11 unifié Stripe + SenangPay)

### NF525 Fiscal Chain (cross-system)
- `app/Services/Fiscal/FiscalSequenceService.php` (Cache::lock 5s + DB FOR UPDATE)
- `app/Services/Fiscal/FiscalChainValidator.php`
- `app/Services/Fiscal/AuditLogService.php` (HMAC SHA-256 chain-signed)
- `app/Services/Fiscal/ZReportService.php` (Z close + chain HMAC)
- `app/Services/Fiscal/SealedOrderGuard.php`
- `app/Models/FiscalSequence.php`
- `app/Models/AuditLog.php`
- `app/Models/ZReport.php`
- `database/migrations/2026_04_22_000002_create_audit_logs_table.php` (DELETE trigger MySQL)
- `database/migrations/2026_04_22_000003_create_z_reports_table.php`
- `database/migrations/2026_05_09_160000_add_z_reports_delete_trigger_immutability.php` (iter11)

### Production Guards + Observability
- `app/Providers/AppServiceProvider.php` (focus throws si BROADCAST_DRIVER null, QUEUE_CONNECTION sync, CACHE_DRIVER array)
- `app/Console/Kernel.php` (cron schedule)
- `app/Jobs/Observability/SloEvaluatorJob.php`
- `routes/api.php` (rate limiting buckets)
- `routes/channels.php` (broadcasting auth)

### Tests sentinels critiques
- `tests/Feature/BranchScopeTest.php`
- `tests/Feature/BranchIsolationTest.php`
- `tests/Feature/Orders/IdempotencyBranchScopedTest.php`
- `tests/Feature/Idempotency/IdempotencyMiddlewareTest.php`
- `tests/Feature/KioskScopeIsolationTest.php`
- `tests/Feature/ActionLogBranchIsolationTest.php`
- `tests/Feature/Fiscal/FiscalChainValidatorTest.php` (7 unit tests)
- `tests/Feature/Sentinels/F001KioskFiscalSequenceTest.php`
- `tests/Feature/Sentinels/F004CancelReasonEnforceSentinelTest.php`
- `tests/Feature/Sentinels/F006PosIdempotencyParitySentinelTest.php`
- `tests/Feature/Sentinels/F013FinalizeStateGuardTest.php`
- `tests/Feature/Webhooks/WebhookEventIdempotencyTest.php` (iter11)

---

## §2 — Invariants à vérifier

### Multi-tenant + Branch isolation
1. **BranchScope global** sur 11 models — admin (branch_id=0) bypass, staff (branch_id>0) scoped
2. **User exempt** — pas de BranchScope sur User pour éviter Sanctum recursion
3. **Cross-tenant queries blocked** — no leak via direct Model::query() bypass
4. **forcePosRuntimeBranchScope** — POS runtime explicit branch filter
5. **withoutGlobalScope explicit** — pre-auth contexts (KioskMachineLoginController:48,80, EnsureKioskMachineCommand:76)

### Sanctum auth
6. **kiosk:order single-ability** — token créé avec `['kiosk:order']` UNIQUEMENT
7. **TTL 480 min** — config sanctum.expiration adaptable env
8. **Old tokens revoked** sur relogin (prevent token sprawl)
9. **Stateful domains** — cookies + bearer support
10. **No mixed abilities** — admin token n'a PAS kiosk:order, kiosk machine n'a PAS admin

### Authorization Spatie
11. **Permission seeders** — Admin / Branch Manager / POS Operator / Chef roles
12. **`permission:settings`** gate routes admin sensibles
13. **FormRequest authz** — actuellement scattered controller-level (V1.0.1 refactor 88 endpoints backlog)

### Idempotency
14. **HTTP X-Idempotency-Key** mandatory sur POST mutating critical
15. **Scope (branch_id, user_id, hash(key))** — branch isolation
16. **2xx-only replay** cache, 409 si payload diff, 425 in-flight
17. **DB UNIQUE constraint** dual-layer defense (AppIdempotencyRecord)
18. **webhook_events UNIQUE (provider, webhook_id)** — iter11 unifié

### NF525 Fiscal Chain
19. **fiscal_sequence_no monotonic gap-free** per branch — Cache::lock 5s + DB FOR UPDATE
20. **audit_logs HMAC SHA-256** chain (prev_hash → current_hash)
21. **z_reports chain-signed** daily clôture
22. **DELETE triggers MySQL** sur audit_logs (2026_04_22_000002) + z_reports (2026_05_09_160000)
23. **6 ans rétention** post-close (deploy doc)
24. **TRUNCATE bypass mitigated** via GRANT level
25. **Pricing SSOT** — composition_snapshot frozen NEVER overwritten

### Production guards
26. **AppServiceProvider boot() throws** si :
    - `PAYMENT_BYPASS_MODE=true`
    - `PRINTING_BYPASS_MODE=true`
    - `BROADCAST_DRIVER` unset/null
    - `QUEUE_CONNECTION=sync`
    - `CACHE_DRIVER` ∈ {array, null} (NF525 chain breaks)
27. **Rate limiting** — login-lockout 10/10min, throttle:3/60 auth, throttle:60,1 admin

---

## §3 — Questions critiques

### Branch isolation completeness
- 11 models post iter11+12 — il en reste ? (audit grep `branch_id` column sans BranchScope)
- BranchScope::apply skip si !Auth::check() — couvre pre-auth + console correctement ?
- forcePosRuntimeBranchScope : où invoqué ? Tests régression cross-branch POS bloqué ?
- ActionLog branch isolation tested ?

### Auth + RBAC
- Sanctum kiosk:order ability strict : tokenCan('kiosk:order') checks dans 6+ controllers ?
- Old token revocation iter12 : KioskMachineLoginController revoke avant create ?
- Spatie permission seeder : 4 roles + permissions correctement seeded ?
- FormRequest authz scattered V1.0.1 refactor : 88 endpoints listés ?

### Idempotency robustness
- IdempotencyKeyMiddleware:67-74 fail-closed/open : corrects ?
- Cross-branch same key : results in distinct executions ?
- Replay after TTL expired : executes anew ?
- 8 scenarios IdempotencyMiddlewareTest tous verts ?

### NF525 invariants
- Pricing SSOT : composition_snapshot frozen — pas de overwrite via update ?
- fiscal_sequence_no allocation race : Cache::lock 5s + DB FOR UPDATE — race window minimal ?
- audit_logs DELETE trigger MySQL prod : test SIGNAL SQLSTATE '45000' ?
- z_reports DELETE trigger : pareil iter11 ?
- TRUNCATE bypass : revoke TRUNCATE permission DB user prod ?

### Production guards
- AppServiceProvider throws verified ? Tests environment guards ?
- BROADCAST_DRIVER prod = pusher/soketi ? CACHE_DRIVER = redis ?
- Queue worker `queue:work --queue=high` HA 2+ instances ?
- Cron daemon scheduled + monitored ?

---

## §4 — Acceptance criteria

CLEAN si :
- ✅ BranchScope global sur 11 models (verified)
- ✅ Sanctum kiosk:order single-ability strict
- ✅ Spatie permission seeders complets
- ✅ Idempotency dual-layer (middleware + DB UNIQUE)
- ✅ NF525 fiscal chain HMAC + DB DELETE triggers
- ✅ Production guards AppServiceProvider throws
- ✅ Tests sentinels F001/F004/F006/F013 + BranchScope + Idempotency + FiscalChainValidator (7 unit) + WebhookEventIdempotency (7 iter11) verts

HEAL si :
- ⚠️ FormRequest authz blanket 88/90 (V1.0.1 refactor backlog)
- ⚠️ Password policy min:6 weak (V1.0.1)
- ⚠️ Sanctum TTL 8h trop long sensitive ops (V1.0.1)
- ⚠️ API key versioning manquant (V1.0.1)
- ⚠️ 6 listeners idempotency restants (V1.0.1)

BLOCK si :
- ❌ Cross-tenant leak via Model directe non-scopé
- ❌ Sanctum ability mixed (kiosk admin)
- ❌ NF525 chain broken ou DELETE triggers manquants
- ❌ Idempotency bypass possible (UNIQUE constraint absent)
- ❌ Pricing SSOT violated (composition_snapshot overwritten)

---

## §5 — Out of scope

- POS Caisse business logic — cf `AUDIT_CAISSE_POS_2026-05-09.md`
- Kiosk Borne business logic — cf `AUDIT_BORNE_KIOSK_2026-05-09.md`
- Order tracking + stock — cf `AUDIT_TRACKING_STOCK_2026-05-09.md`
- Sync events infra — cf `AUDIT_SYNC_EVENTS_2026-05-09.md`
- UI / a11y / i18n
- Admin dashboards UI

---

## §6 — Reference

CLAUDE.md §7 (Frozen Zones), §8 (NF525), §9 (Multi-Tenant + Auth)
PROJECT_BRAIN.md §7 #2-4-5-7 (BranchScope, Fiscal NF525, Idempotency, Sanctum)
ULTRA-AUDIT-SECURITY iter13 + ULTRA-INVARIANTS iter10 (cf masters)

— *Manifest pour `/ultrareview review/audit-global-cross-system`. Audit transverse global.*
