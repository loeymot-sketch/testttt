# W1 — CLOUD-DELTA validation (GOAL_CLOUD_CUTOVER_VALIDATION)

**Date:** 2026-06-05 · **Worktree:** pre-cloud-exec · **HEAD at run:** 8ceb6c36b
**Thesis:** local-green ≠ cloud-ready. This wave exercises the cloud config path (`config:cache`,
multi-worker, boot guards) where genuinely-new findings live — invisible to local validation.

## ⭐ Headline: the catastrophic config:cache × NF525 risk is EMPIRICALLY DISPROVEN
The feared trap (runtime `env()` going null under `config:cache` → fiscal HMAC secret changes →
chain breaks) does **NOT** materialize for V1.

### Empirical test (atomic, with guaranteed restore — running servers untouched)
```
php artisan config:cache      → "Configuration cached successfully"  (no closures-in-config blocker)
php artisan fiscal:verify-chain --all   (UNDER cached config) → CHAIN OK on every active branch (6 total)
php artisan config:clear      → restored
php artisan fiscal:verify-chain --all   → CHAIN OK (6 total)   ;   server:8000 → HTTP 200
```
**Conclusion:** the NF525 audit chain survives `config:cache`. ✅ V1-SAFE.

### Why (root-cause, verified static + empirical)
`AuditLogService::secretFor()` (`:269-292`) tries `env('FISCAL_AUDIT_SECRET_BRANCH_{id}')` (:273, runtime,
config:cache-fragile) FIRST, then falls back to `Config::get('fiscal.audit_secret')` (:279, config-file env
at `config/fiscal.php:31` → **baked into config:cache** → cache-safe).
- `FISCAL_AUDIT_SECRET_BRANCH_*` is **NOT SET** (only commented templates for branch 17 in .env.testing/.env.example).
- → env override returns null cached==uncached → ALWAYS the cache-safe config path → secret identical → chain holds.
- The code ALSO supports per-branch secrets via the cache-safe **config array** (`:281-283`).

## Findings (all triaged per GOAL §0.3 — ZERO V1-LOCAL blockers)

| # | Finding | file:line | Triage | Action |
|---|---|---|---|---|
| CD-1 | NF525 chain × config:cache | `AuditLogService:273` env override | **☁️ CLOUD-PREP** | Unreachable in V1 (override unset). For multi-branch cloud: set per-branch fiscal secrets in `config/fiscal.php` **array** (already supported :281-283), NEVER env overrides. No frozen edit. |
| CD-2 | Boot guards fire in production | `AppServiceProvider:165-298` | ✅ **PASS** | `CACHE_DRIVER=array`→RuntimeException; `POS_SIMULATION_HARDWARE=true`→NF525 refusal (verified live, env-overridden CLI). Production refuses to boot misconfigured. |
| CD-3 | config:cache builds | bootstrap/cache | ✅ **PASS** | "Configuration cached successfully" — no closures-in-config. Deployable. |
| CD-4 | Multi-worker cache coherence (UNI-03) | `AppServiceProvider:295` `['array','null']` | **☁️ CLOUD-PREP** | `file`/`database` pass the guard but break `Cache::lock` cross-worker on ALB/multi-instance. V1 single-box uses **redis** (safe). For multi-instance: keep redis + widen forbidden list. Documented backlog UNI-03. |
| CD-5 | `env('CURRENCY')` tax_type symbol | `OrderItemResource:52` | **📋 V1-UNREACHABLE** | Only the FIXED-tax currency symbol; FR TVA is %-based → branch returns '%', env never read. Real money fmt via `AppLibrary::currencyAmountFormat` (FR-canonical, config-safe). Minor cloud-prep note. |
| CD-6 | `env('DATE_FORMAT'/'TIME_FORMAT')` | `AppLibrary:24/32/40` | **☁️ CLOUD-PREP** | Display formatting → under config:cache falls to defaults. Cosmetic. Set in config or accept defaults on cloud. |
| CD-7 | `env('DEMO')` ×6 + `env('STRIPE_WEBHOOK_SECRET'/MAIL)` | various | **📋 PROD-SAFE / N-A** | DEMO null→off = correct for prod. Stripe/mail not on V1-critical path (SumUp manual, no live Stripe). Verify on cloud smoke. Test-only: E2ESoak/Stress MIX_API_KEY, Installer. |

## Cloud-prep guidance (for the W8 cutover dossier)
1. Deploy with `config:cache` (boot guard CD-2 even instructs it) — chain proven safe (headline).
2. `CACHE_DRIVER=redis`, `QUEUE_CONNECTION=redis`, `BROADCAST_DRIVER=pusher|redis` (guards enforce).
3. If multi-instance later: per-branch fiscal secrets → `config/fiscal.php` array (CD-1); keep redis (CD-4).
4. Single-box Le Cayenne V1: **no cloud-delta blocker** — all findings are forward/multi-tenant prep.

**W1 STATUS: CLOSED — 0 V1-LOCAL blocker, 0 frozen edit needed, NF525 chain config:cache-safe (proven).**
Chain attestation unchanged: branch=1 audit_logs=2697 z_reports=7 last_hash 0db0e8aa…
