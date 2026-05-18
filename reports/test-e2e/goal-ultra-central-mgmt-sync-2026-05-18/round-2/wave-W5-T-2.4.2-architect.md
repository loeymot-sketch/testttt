# T-2.4.2 Feature Flag Governance — ARCHITECT Audit Report — Round 2

## Verdict (one line): GO-CONDITIONAL

The committed `AppServiceProvider` boot guard for `POS_SIMULATION_HARDWARE` is correct, well-defended, and well-tested (`PosSimulationHardwareProductionGuardSentinelTest`). However it is a **point fix on a fragmented governance surface**: there is no central feature-flag registry, no enforcement that "every dangerous flag has a boot guard," and the storage hierarchy is split across three classes (`.env`/`config/*.php`, DB-backed `Settings::group()`, and hardcoded constants) with **inverted visibility characteristics** — env flags are partially guarded but invisible to operators; DB flags are admin-UI-visible but structurally exempt from boot-guard protection. The pattern `caisse_v1_rollout.php` already declares per-flag metadata (`owner`, `rollback_order`, `invariant`) but nothing consumes it. This is a half-built registry waiting to be wired. Five concrete architectural risks below — none P0 today (the simulation_hardware sentinel anchors the highest-impact one), but together they form the V2 SaaS blocker for "trust the flag governance layer."

## Top findings

### [P1] config/payment.php:85 + config/printing.php:31 — `forbidden_environments` array declared but never consulted

trigger:
  load_mode: "A deployment that uses `APP_ENV=prod` or `APP_ENV=live` (common in legacy ops / Kubernetes manifests / Heroku-style platforms that distinguish 'production' from 'prod' / 'live'). The bypass mode flags ship with `'forbidden_environments' => ['production', 'prod', 'live']` documenting intent — but `AppServiceProvider::boot()` lines 78, 97, 104 check ONLY `app()->environment('production')`. `BypassAuditLogger.php:28-46` (the only other consumer of these configs) reads `bypass.enabled` and `bypass.gate` — never `forbidden_environments`. The declarative array is dead config."
  failure_mode: "An operator sets `APP_ENV=live PAYMENT_BYPASS_MODE=true`, the boot guard is silent, TPE-bypass mode activates against a live customer base, fake `approved` responses get persisted, no real transaction flows through the gateway. NF525 fiscal sequence is still allocated (`PaymentService::processPayment` uses bypass response to compose a fiscal entry — confirmed lines 207-231) so receipts are signed pointing to phantom auth codes. DGFiP-attestable trail is internally consistent, but no money moved at the bank — discovered when the daily settlement reconciliation fails."

v2_saas_impact:
  blocks: "SaaS multi-region deploy will inevitably need APP_ENV values beyond the literal string 'production' (staging-1, prod-eu, prod-us, blue/green). One slip and the bypass guard goes silent."
  enables: "Centralizing the production-check as `isDangerousEnv(): in_array($env, config('flags.production_aliases'))` lets the same predicate gate every boot guard."

cost_of_delay_if_v1_ships:
  customer: "None for Le Cayenne V1 (APP_ENV=production hardcoded in `docs/cloud/PRODUCTION_ENV_TEMPLATE.env.txt:7`). The risk is V1.0.2+ when a second tenant or staging-mirror deploy is built."
  fiscal: "Worst case: phantom-approved receipts signed into the audit chain. NF525 contestable in court."
  business: "Latent landmine for any future devops who runs `APP_ENV=prod-eu` thinking they're being explicit."

recommendation:
  scope: "Either (a) read the `forbidden_environments` array in the boot guard — `if (in_array(env('APP_ENV'), config('payment.bypass.forbidden_environments', ['production'])))` — making the declarative config authoritative, OR (b) delete the dead array so the static analyzer can flag the drift. Option (a) is the more architecturally honest fix; ~10 lines in AppServiceProvider, 3 line-of-test additions to `PosSimulationHardwareProductionGuardSentinelTest`."
  rollback: "Trivial — additive predicate behind an env override `STRICT_ENV_GUARD=true`. Default OFF preserves current behaviour."
  owner_gate: "N — config + AppServiceProvider boot block (already touched in commit `2477a2d05`)."

### [P1] config/*.php (cross-file) — Safety-invariant flags lack the production boot-guard symmetry that bypass flags enjoy

trigger:
  load_mode: "Operator panic-rollback after a perceived incident. Production `.env` flipped: any of `PRICING_USE_SSOT=false`, `PRICING_TAX_INCLUSIVE=false`, `SEALED_Z_GUARD_ENABLED=false`, `FISCAL_CHAIN_VALIDATION_ENABLED=false`, `FISCAL_VERIFY_CHAIN_BEFORE_ARCHIVE=false`, `FISCAL_KIOSK_AUTO_ALLOCATE_SEQUENCE=false`, `IDEMPOTENCY_MIDDLEWARE_ENABLED=false`."
  failure_mode: "Each of these flags is a safety invariant whose ON state preserves NF525 / SSOT / idempotency. The boot guard pattern in `AppServiceProvider::boot()` defends the *bypass-mode-on-in-prod* direction (refuse boot if `PAYMENT_BYPASS_MODE=true`) but is silent on *safety-mode-off-in-prod*. Concretely: `IDEMPOTENCY_MIDDLEWARE_ENABLED=false` in production silently re-opens the double-charge surface; `PRICING_TAX_INCLUSIVE=false` silently restores the pre-iter15 HT-add NF525 bug (the comment at `config/pricing.php:25-29` literally documents this as the reason the default was flipped); `SEALED_Z_GUARD_ENABLED=false` re-allows mutations on sealed Z windows. None of these triggers a refuse-to-boot."
  asymmetry: "Bypass flags = guarded if TRUE in prod. Safety flags = unguarded if FALSE in prod. Architecturally inconsistent — both are dangerous, both should be boot-checked."

v2_saas_impact:
  blocks: "V2 SaaS = N tenants with N operators with N panic-rollback events. The probability of one of them disabling a safety flag without owner counter-sign approaches 1 per quarter."
  enables: "Symmetric guard pattern (`refuse_boot_if_dangerous_state(prod_env, flag_table)`) lets the GOAL Wave W2 fiscal hardening (T-1.2.2) reuse the same machinery for `FISCAL_AUDIT_SECRET` rotation."

cost_of_delay_if_v1_ships:
  customer: "Le Cayenne V1 single-resto with disciplined .env management = low. But the .env file is owner-editable on the OVH VPS; a 3am stress-fix that flips PRICING_USE_SSOT to false is exactly the kind of incident this guard would catch."
  fiscal: "Each safety flag corresponds to a published NF525 invariant. Disabling one in production is a DGFiP-attestable violation."
  business: "Recovery = restore the flag + replay the affected orders' fiscal sequence. Owner-blocking event."

recommendation:
  scope: "Define `config/flags.php` with a `dangerous_defaults` map: `[ 'pricing.use_ssot' => true, 'pricing.tax_inclusive_prices' => true, 'fiscal.sealed_z_guard_enabled' => true, … ]`. Add a single boot-guard loop in `AppServiceProvider::boot()` that iterates the map and throws if any production value diverges from the dangerous-default. ~40 lines + ~80 lines of sentinel coverage. Reuses existing `RuntimeException` exit pattern."
  rollback: "Each entry overridable via `FLAGS_ALLOW_<KEY>_DIVERGENCE=true` env (last-resort escape valve, audit-log-on-boot)."
  owner_gate: "Y — touches AppServiceProvider production-critical boot path. LOCK_PRODUCTION_BOOT_GUARDS doc required."

### [P1] AppServiceProvider::boot() — boot guard is structurally blind to DB-backed flags (`Settings::group()`)

trigger:
  load_mode: "Operator-driven runtime toggle. `Settings::group('pos')->set('pos_dine_in_enabled', true)` invoked via the admin UI (e.g. `SettingsComponent.vue` → `appService` → admin settings POST). The flag flips in the DB; no boot occurs; the application picks up the new value on the next request via `PosOrderRequest::authorize()` line 60 + `OrderRequest::rules` line 212 + `SettingResource.php:124`."
  failure_mode: "The committed boot guard at `AppServiceProvider:78-141` runs ONLY at framework boot — before DB access is reliable, and before any admin UI mutation can have occurred. Any flag stored in the `settings` table is structurally exempt from the boot-guard pattern. Admin UI flag visibility is the inverse: DB flags ARE surfaced (intentional product feature), but `.env` flags (the dangerous ones — POS_SIMULATION_HARDWARE, PAYMENT_BYPASS_MODE, KIOSK_LOCALE_SWITCH_ALLOWED) are invisible to the operator. Net architecture: dangerous-flag guarding and dangerous-flag visibility are on opposite storage classes."

v2_saas_impact:
  blocks: "Multi-tenant: each tenant has its own DB-backed settings row. A tenant-admin role could flip `pos_dine_in_enabled=true` without org-admin awareness. The current single-tenant V1 design doesn't expose this surface, but the schema (`settings.settingable_morphs` line 20 of `2022_05_24_204620_create_settings_table.php`) already supports per-model scoping — the only thing keeping V2 tenant-managers from flipping arbitrary flags is the absence of a flag-permission matrix."
  enables: "A unified flag registry where each entry declares `storage_class` (env / db / hybrid) + `dangerous` (bool) + `mutation_audit` (bool) lets boot-guard apply to env, while a runtime middleware applies to DB mutations (refuse Settings::set() in production for any flag tagged `boot_only=true`)."

cost_of_delay_if_v1_ships:
  customer: "Low V1 — Le Cayenne UI has the dine-in flag hidden because V1 disables dine-in per BRAIN feedback. But the underlying mechanism is intact: a future settings page rendering pos_dine_in_enabled toggle would silently re-enable the dine-in surface."
  fiscal: "None directly — pos_dine_in_enabled doesn't touch NF525. But the architectural gap is broader: nothing prevents adding a DB-backed flag named `pricing_use_ssot` in the future that mirrors `config('pricing.use_ssot')` with no guard."
  business: "Latent — depends on what future flags get added to the `settings` table."

recommendation:
  scope: "Document the storage-class boundary explicitly in `config/flags.php` (per finding #2 above): each flag declares `storage: 'env' | 'db'`. Then add a `Settings` model observer that, in production, refuses `set()` on any key tagged `dangerous_in_prod=true` without an attached `audit_logs` row carrying the operator's user_id + reason. ~60 lines + 4 sentinel tests."
  rollback: "Observer can be disabled via `FLAGS_DB_MUTATION_GUARD=false` env."
  owner_gate: "Y — touches the production `Settings` mutation path."

### [P2] config/idempotency.php:20 — `IDEMPOTENCY_MIDDLEWARE_ENABLED` defaults to FALSE; production template overrides to TRUE — drift surface

trigger:
  load_mode: "Two deploys: (a) Le Cayenne V1 follows `docs/cloud/PRODUCTION_ENV_TEMPLATE.env.txt:115` and sets `IDEMPOTENCY_MIDDLEWARE_ENABLED=true` (correct). (b) A future SaaS tenant deploys without that line in .env. The fallback default `env('IDEMPOTENCY_MIDDLEWARE_ENABLED', false)` returns false — middleware no-ops — double-charge protection is silently off."
  failure_mode: "Dual-layer protection collapses to single-layer (DB UNIQUE only). Concurrent POS retries during a TPE-stuck-spinner scenario can race past the cache layer; the DB UNIQUE backstop catches it but throws a QueryException visible to the cashier as 'order create failed' — not 'duplicate prevented'. Customer card may be charged twice if the gateway responded between the duplicate POST and the UNIQUE rejection."

v2_saas_impact:
  blocks: "Every new SaaS tenant onboarding inherits the wrong default. The architectural smell is: the config DEFAULT disagrees with the production TEMPLATE. The template is documentation; the default is code. When they disagree, code wins for any deploy that skips the documentation."
  enables: "Flipping the default to TRUE in `config/idempotency.php:20` and treating the docs override as 'set to false ONLY for emergency rollback' inverts the failure mode to safe-by-default."

cost_of_delay_if_v1_ships:
  customer: "Cards double-charged during TPE-spinner race window — rare but real. Le Cayenne V1 deploy template includes the override line so this is masked for the V1 single-deploy scenario."
  fiscal: "If a duplicate gets through, two fiscal_sequence_no allocated for one customer payment — observable but recovery requires owner-gated refund-with-counter-entry."
  business: "Reputation hit if cashier denies the double-charge during reconciliation."

recommendation:
  scope: "Flip the default at `config/idempotency.php:20` from `false` to `true` to match the production template. One line. Same pattern as `pricing.tax_inclusive_prices` (default flipped 2026-05-10 per iter15-BUG-NF525). Add a sentinel test asserting `config('idempotency.enabled')` defaults to true when env is unset, mirroring `PosSimulationHardwareProductionGuardSentinelTest::test_config_default_is_false_when_env_unset`."
  rollback: "Trivial — operator can still set `IDEMPOTENCY_MIDDLEWARE_ENABLED=false` for emergency."
  owner_gate: "N — config default flip, no business logic change."

### [P2] No meta-sentinel — adding a new dangerous flag requires manual sentinel addition

trigger:
  load_mode: "Future cycle: developer adds a new flag (say `FISCAL_NEW_HARDENING=false`) intended to default-on in prod. They add the config entry, they add the consumer logic, they MIGHT remember to add a boot guard, they MIGHT remember to add a sentinel — but nothing in the test suite or CI ENFORCES that the new flag has either."
  failure_mode: "The flag inventory and the guard inventory drift. The two existing sentinels (`PosSimulationHardwareProductionGuardSentinelTest` for simulation_hardware, `FiscalSecretProductionGuardTest` for audit secret + Z secret) are flag-specific. There is no test that says 'enumerate every flag in `config/flags.php::dangerous_defaults` and verify each appears in the AppServiceProvider boot-guard block by reflection.' Result: governance hardens against the flag-of-the-month, not against the pattern."

v2_saas_impact:
  blocks: "V2 SaaS = more flags (feature rollout, per-tenant overrides, regional toggles). Without a meta-sentinel, the test suite cannot tell you which flags lack guards."
  enables: "The meta-sentinel becomes the architectural enforcement layer that `caisse_v1_rollout.php`'s declarative metadata was always supposed to power."

cost_of_delay_if_v1_ships:
  customer: "None V1."
  fiscal: "None V1."
  business: "V1.0.2+ developer velocity tax — each new flag review requires a manual checklist instead of `phpunit --filter FlagGovernanceMetaSentinel`."

recommendation:
  scope: "Add `tests/Feature/Sentinels/FlagGovernanceMetaSentinelTest.php` that loads `config('flags.dangerous_defaults')`, reflects on `AppServiceProvider::boot()` source via `(new \ReflectionClass(AppServiceProvider::class))->getMethod('boot')->getFileName()` + token scan, and asserts each dangerous flag key appears in the boot-guard text. ~80 lines."
  rollback: "Pure additive test."
  owner_gate: "N — pure test code."

## Coverage map

Storage classes traced (3 → 60+ flags, sampled):

| Flag | Storage | Default | Boot guard | Admin UI | Dangerous-direction |
| --- | --- | --- | --- | --- | --- |
| `POS_SIMULATION_HARDWARE` | env→config/pos.php:37 | false | YES (line 85-91) | NO | true-in-prod |
| `PAYMENT_BYPASS_MODE` | env→config/payment.php:83 | false | YES (line 97-103) | NO | true-in-prod |
| `PRINTING_BYPASS_MODE` | env→config/printing.php:29 | false | YES (line 104-110) | NO | true-in-prod |
| `BROADCAST_DRIVER` | env→config/broadcasting.php | (null) | YES (line 112-117) | NO | null-in-prod |
| `QUEUE_CONNECTION` | env→config/queue.php | sync | YES (line 118-123) | NO | sync-in-prod |
| `CACHE_DRIVER` | env→config/cache.php:18 | file | YES (line 133-141) | NO | array/null-in-prod |
| `PRICING_USE_SSOT` | env→config/pricing.php:9 | true | NO | NO | false-in-prod |
| `PRICING_TAX_INCLUSIVE` | env→config/pricing.php:31 | true | NO | NO | false-in-prod |
| `SEALED_Z_GUARD_ENABLED` | env→config/fiscal.php:117 | true | NO | NO | false-in-prod |
| `FISCAL_CHAIN_VALIDATION_ENABLED` | env→config/fiscal.php:95 | true | NO | NO | false-in-prod |
| `FISCAL_VERIFY_CHAIN_BEFORE_ARCHIVE` | env→config/fiscal.php:74 | true | NO | NO | false-in-prod |
| `FISCAL_KIOSK_AUTO_ALLOCATE_SEQUENCE` | env→config/fiscal.php:137 | true | NO | NO | false-in-prod |
| `IDEMPOTENCY_MIDDLEWARE_ENABLED` | env→config/idempotency.php:20 | **false** | NO | NO | false-in-prod (def wrong) |
| `SPLIT_PAYMENT_ENABLED` | env→config/split_payment.php:19 | true | NO | NO | (low-risk) |
| `KIOSK_LOCALE_SWITCH_ALLOWED` | env→config/kiosk.php:31 | false | NO | NO | (low-risk) |
| `KDS_V2_DEFAULT_ENABLED` | env→config/kds.php:24 | true | NO | NO | (low-risk) |
| `CASH_VARIANCE_MANAGER_APPROVAL_REQUIRED` | env→config/cash.php:43 | true | NO | NO | false-in-prod (NF525) |
| `CASH_MANAGER_GATE_ROUTINE_CLOSE` | env→config/cash.php:85 | false | NO | NO | (multi-cashier hardening) |
| `FK_CATALOG_*` (12 flags) | env→config/catalog_v15.php | mostly false | NO | NO | rollout-stage |
| `caisse_v1_rollout.flags.*` (6) | code constants | false | NO | NO | declarative-only |
| `payment.web_payment_v1.enabled` | hardcoded (no env) | false | N/A | NO | (locked by design) |
| `payment.stripe.activation_guard.*` | hardcoded (no env) | guard-on | N/A | NO | (locked by design) |
| `pos_dine_in_enabled` | DB Settings::group('pos') | 0 | N/A (db) | YES | true-in-V1 (dine-in disabled) |
| `order_setup_delivery` | DB Settings::group('order_setup') | 1 | N/A (db) | YES | (UX) |

Findings summary by call-graph:
- Guard-on patterns (6 flags, all in AppServiceProvider:78-141): bypass-mode-on-in-prod direction only.
- Safety-default flags (~8 fiscal + pricing + idempotency): no boot guard, env-overridable, dangerous-direction = OFF.
- DB-backed flags (~3 critical Settings::group rows): admin-UI-mutable, no boot guard by architecture.
- Half-built registry (caisse_v1_rollout.php): declarative metadata exists but no consumer reads `owner` / `rollback_order` / `invariant`.
- Dangerous default disagreement: idempotency.enabled defaults FALSE but production template overrides TRUE — wrong-way default.

## Architecture verdict

The committed `POS_SIMULATION_HARDWARE` production boot guard is good engineering. It is also **the only one of its kind on a flag surface that contains ~30 production-sensitive switches**. The architecture is not flag-governance, it is hand-curated guards on three flags (simulation, bypass, printing) plus two infrastructure (broadcast, queue, cache). Every other dangerous flag lives on the safety-default-but-env-overridable surface with no boot enforcement and no admin visibility.

Three structural recommendations, in priority order:

1. **Wire the half-built registry**: `config/caisse_v1_rollout.php` already declares per-flag `owner`/`rollback_order`/`invariant`. Extend it (or create `config/flags.php`) with `dangerous_defaults` map. Make `AppServiceProvider::boot()` iterate the map. Single guard pattern.

2. **Symmetric guard semantics**: bypass-flags-true-in-prod AND safety-flags-false-in-prod both refuse boot. The asymmetry is unprincipled.

3. **Meta-sentinel as enforcement**: `FlagGovernanceMetaSentinelTest` enumerates the registry, asserts each entry has a boot guard, fails CI for any new flag without one.

T-2.4.2 as scoped (production boot guard for simulation_hardware) is **delivered correctly**. The wider governance gap is V1.0.2+ work — name it explicitly in the GOAL synthesis so the next cycle attacks the architecture, not just the next single flag.
