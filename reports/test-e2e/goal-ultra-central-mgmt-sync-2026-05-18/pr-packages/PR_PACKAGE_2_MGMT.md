# PR Package 2 — MGMT backbone heal — 2026-05-18

> **Source-of-truth audit dossier:** `reports/test-e2e/goal-ultra-central-mgmt-sync-2026-05-18/round-{1,2}/` + `FINAL_ROUND_1_2_VERDICT.md` §4.
> Every heal commit below cites the agent finding ID — re-read that finding before patching.

## Branch
- name: `heal/mgmt-backbone-2026-05-18`
- base SHA: `5b147f9e7`
- rebased on: `heal/central-backbone-2026-05-18` (PR #1)
- merge order: ships after PR #1.

## Heal commits (in order)

### Commit 1 — `mgmt-heal-cvp0-2` — CVP0-2 Catalog mutator env-gate + audit_log
- **Title:** `fix(mgmt-heal-2026-05-18): env-gate + audit_log on MenuReset/MenuHeal commands (CVP0-2)`
- **Files modified:**
  - `app/Console/Commands/MenuResetLeCayenneCommand.php` lines 25-122 (handle entry + post-transaction)
  - `app/Console/Commands/MenuHealLightV3Command.php` lines 30-60 (handle entry + post-transaction)
- **Patch scope:**
  ```
  At app/Console/Commands/MenuResetLeCayenneCommand.php (handle() entry, before any DB write):
    1. ADD env-gate:
       abort_if(app()->environment('production') && !$this->option('confirm-production'),
         'Production catalog reset requires --confirm-production flag.');
       (Also add to signature: {--confirm-production : Required to run in production})
    2. ADD audit_log write at start AND end of transaction:
       AuditLogService::write([
         'branch_id' => Branch::min('id'),  // or 0 for super-admin context — match existing pattern at FiscalArchiveCommand
         'action' => 'console.menu_reset_le_cayenne.start',
         'payload' => ['operator' => auth()->user()?->id ?? 'console', 'environment' => app()->environment(), 'dry_run' => $this->option('dry-run')],
       ]);
       // ... do work in DB::transaction ...
       AuditLogService::write([
         'branch_id' => $branchId,
         'action' => 'console.menu_reset_le_cayenne.complete',
         'payload' => ['archived' => $archivedCount, 'created' => $createdCount, 'renamed' => $renamedCount],
       ]);

  At app/Console/Commands/MenuHealLightV3Command.php (handle() entry): same env-gate + audit_log pattern with action='console.menu_heal_light.{start,complete}'.

  Why: R1-DBA F1 + R1-Security F-W4-SEC-02 cross-validate this — these commands soft-delete 8 categories + ~35 items + rename 4 with zero env guard and no NF525 audit trail. CVP0-2 in FINAL §1.
  Rollback: revert the abort_if + AuditLogService::write calls.
  ```
- **Tests added:** `tests/Feature/Catalog/MenuResetProductionGuardTest.php` — `(file TO BE CREATED)` — 3 cases: (a) run with `APP_ENV=production` and no `--confirm-production` → AbortException 500, (b) run with both → succeeds + audit_log row written, (c) start + complete events both chained (asserts `audit_logs` count delta = 2).
- **Acceptance evidence command:** `php artisan test --filter=MenuResetProductionGuardTest` → PASS
- **Frozen-zone status:** 0 lines touched.

### Commit 2 — `mgmt-heal-mp0-b` — M-P0-B Ingredient cross-tenant DoS
- **Title:** `fix(mgmt-heal-2026-05-18): branch-overlay table for ingredient availability (M-P0-B)`
- **Files modified:**
  - `database/migrations/2026_05_19_000010_create_ingredient_branch_availability_table.php` — `(file TO BE CREATED)` — new migration ~50 lines
  - `app/Models/IngredientBranchAvailability.php` — `(file TO BE CREATED)` — new model ~30 lines (with BranchScope from creation — sentinel from PR #1 enforces)
  - `app/Services/Ingredients/IngredientAvailabilityService.php` lines 30-67 — refactor toggle path to upsert per branch instead of global UPDATE
  - `app/Http/Controllers/Admin/IngredientController.php` lines 55-78 — add `branch_id` param resolution + authorizeWritableBranchScope() call
  - `app/Events/IngredientAvailabilityChanged.php` — add `actor_id` + `branch_id` to event payload
- **Patch scope:**
  ```
  At database/migrations/...create_ingredient_branch_availability_table.php (new):
    Schema mirroring item_branch_availability:
      - branch_id FK (cascadeOnDelete)
      - type ENUM('extra', 'attribute', 'addon')
      - name varchar(160)
      - group_label varchar(160) nullable (for extras)
      - is_available bool default true
      - unavailable_reason varchar(255) nullable
      - actor_id FK users (nullOnDelete)
      - timestamps
      - UNIQUE (branch_id, type, name, group_label)
      - INDEX (branch_id, is_available)

  At app/Models/IngredientBranchAvailability.php (new):
    class IngredientBranchAvailability extends Model {
      protected $fillable = [...];
      protected static function booted(): void {
        static::addGlobalScope(new BranchScope());  // honors PR #1 sentinel guardrail
      }
    }

  At app/Services/Ingredients/IngredientAvailabilityService.php:
    REPLACE the global UPDATE on item_attributes (line 30-40) AND item_extras (line 50-62) with per-branch upsert on the new overlay table:
      IngredientBranchAvailability::updateOrCreate(
        ['branch_id' => $branchId, 'type' => $type, 'name' => $name, 'group_label' => $groupLabel],
        ['is_available' => $isAvailable, 'unavailable_reason' => $reason, 'actor_id' => Auth::id()]
      );

  At app/Http/Controllers/Admin/IngredientController.php (toggleAvailability ~line 55-78):
    1. Accept branch_id from validated payload OR resolve from Auth::user()->branch_id.
    2. CALL authorizeWritableBranchScope($branchId) (existing helper at AdminController:15-40, R2-Security S-5 pattern).
    3. Pass $branchId to IngredientAvailabilityService::toggle().

  At app/Events/IngredientAvailabilityChanged.php:
    EXTEND public properties / constructor:
      __construct(string $type, int $id, bool $isAvailable, ?string $reason, ?int $actorId, ?int $branchId)
    Update the broadcast payload to include actor_id + branch_id (defense: Kiosk listeners can filter cross-branch fanout properly).

  At app/Listeners/PersistItemAvailabilityChangedToOutbox.php (and the 2 sister listeners):
    Update payload to include actor_id + branch_id.

  At app/Services/Kiosk/KioskMenuService.php:
    UPDATE the availability projection to consult `ingredient_branch_availability` overlay BEFORE consulting the global item_extras.is_available (cascade order: branch-overlay-OUT → cascade; branch-overlay-IN → respect; null → global default).

  Why: R1-Security F-W4-SEC-01 + R2-DBA F2 — Branch Manager A toggling cheddar globally DoS's every kiosk at branch B/C. Fix introduces canonical overlay pattern + per-branch authz + actor audit.
  Rollback: revert all 6 file changes + revert migration (Schema::dropIfExists('ingredient_branch_availability')).
  ```
- **Tests added:** `tests/Feature/Catalog/IngredientBranchAvailabilityIsolationTest.php` — `(file TO BE CREATED)` — 4 cases: (a) Branch A Manager toggles cheddar OFF → Branch B kiosk still has cheddar available, (b) overlay missing → global default applies, (c) cross-branch payload `branch_id` rejected at controller, (d) actor_id captured in event payload + audit trail.
- **Acceptance evidence command:** `php artisan test --filter=IngredientBranchAvailabilityIsolationTest` → PASS
- **Frozen-zone status:** 0 lines touched.

### Commit 3 — `mgmt-heal-mp0-c-d-e-f` — Combined: APP_DEBUG boot guard + env-key allowlist + audit_log on settings
- **Title:** `fix(mgmt-heal-2026-05-18): APP_DEBUG guard + EnvEditor allowlist + audit_log on settings mutations (M-P0-C/D/E/F)`
- **Files modified:**
  - `app/Providers/AppServiceProvider.php` lines 78-141 — add APP_DEBUG boot guard
  - `app/Services/SiteService.php` lines 47-56 — remove APP_DEBUG + MIX_GOOGLE_MAP_KEY from env writes (or gate behind allowlist)
  - `app/Services/MailService.php`, `app/Services/CompanyService.php`, `app/Services/LicenseService.php` — wire `AuditLogService::write()` on `update()` end
  - NEW shared helper `app/Support/Settings/SettingsAuditWriter.php` — `(file TO BE CREATED)` — centralizes the audit-log write pattern (~30 lines)
  - **Override-or-wrap `EnvEditor::addData` via a new gatekeeper service:** `app/Support/EnvFile/SafeEnvEditor.php` — `(file TO BE CREATED)` — wraps the vendor `dipokhalder/laravel-env-editor` package and adds a write allowlist
- **Patch scope:**
  ```
  At app/Providers/AppServiceProvider.php:78-141, ADD a new boot guard mirroring the existing 5 (POS_SIMULATION_HARDWARE, PAYMENT_BYPASS_MODE, etc):
    if (app()->environment('production') && filter_var(env('APP_DEBUG', false), FILTER_VALIDATE_BOOLEAN)) {
        throw new \RuntimeException('APP_DEBUG must be false in production (leaks stack traces + secrets).');
    }
    Also add same for DEMO=true if appropriate.
  Why: R2-Security S-1 Q5 — APP_DEBUG=true admin-writable + no boot guard.

  At app/Support/EnvFile/SafeEnvEditor.php (new):
    Wrapper over dipokhalder/laravel-env-editor EnvEditor::addData($data):
      - DENYLIST (hard-coded, throw on any attempt to write):
          APP_DEBUG, APP_ENV, APP_KEY,
          DB_*, REDIS_*, CACHE_DRIVER, BROADCAST_DRIVER, QUEUE_CONNECTION,
          FISCAL_*, MAIL_PASSWORD, MIX_API_KEY,
          POS_SIMULATION_HARDWARE, PAYMENT_BYPASS_MODE, PRINTING_BYPASS_MODE,
          DEMO, KIOSK_AUTO_LOGIN_TRUSTED_IPS
      - On any disallowed key: throw \DomainException('Refusing to write protected env key: '. $key)
    Then refactor:
      - app/Services/SiteService.php — call SafeEnvEditor::addData() (which throws on APP_DEBUG → forces admin UI to drop the toggle naturally)
      - app/Services/CompanyService.php, MailService.php, LicenseService.php — same wrapper
      - app/Http/Controllers/Installer/InstallerController.php:67-68 — same

  At app/Support/Settings/SettingsAuditWriter.php (new):
    Helper class with static `record(string $group, string $action, array $before, array $after, ?int $branchId = 0)`:
      writes AuditLogService::write([
        'branch_id' => $branchId,
        'action' => "settings.{$group}.{$action}",
        'payload' => ['actor_id' => Auth::id(), 'ip' => Request::ip(), 'before' => $before, 'after' => $after],
      ]);

  Wire SettingsAuditWriter::record() into each of the 12 settings services listed at app/Services/SettingService.php:9-23 (CompanyService, SiteService, MailService, LicenseService, OtpManagerService, KioskSetupService, OrderSetupService, LoyaltySetupService, PaymentGatewayService, ThemeService, NotificationService, SocialService — verify list at file).

  Why combined: R2-Security S-1 Q3 (.env writability paradox), Q4 (admin → APP_DEBUG → leak), Q5 (no APP_DEBUG guard), Q6 (no audit_logs on settings). FINAL §4 PR #2 line "M-P0-D + M-P0-E + M-P0-F combined heal".
  Rollback: revert wrapper class + restore direct EnvEditor calls + drop audit_log writes.
  ```
- **Tests added:**
  - `tests/Feature/Admin/AppDebugProductionGuardTest.php` — `(file TO BE CREATED)` — boot Laravel with APP_DEBUG=true + APP_ENV=production → RuntimeException
  - `tests/Feature/Admin/EnvEditorAllowlistTest.php` — `(file TO BE CREATED)` — attempt to write APP_DEBUG/MAIL_PASSWORD/FISCAL_*/MIX_API_KEY via SafeEnvEditor → DomainException; allowed keys (TIMEZONE, CURRENCY*) pass
  - `tests/Feature/Admin/SettingsAuditLogTest.php` — `(file TO BE CREATED)` — every settings service `update()` call writes an audit_logs row with before/after diff
- **Acceptance evidence command:** `php artisan test --filter=AppDebugProductionGuardTest` + `EnvEditorAllowlistTest` + `SettingsAuditLogTest` → all PASS
- **Frozen-zone status:** 0 lines touched (AppServiceProvider is not frozen; settings services not frozen).

### Commit 4 — `mgmt-heal-mp0-g` — M-P0-G Ansible env-template validation
- **Title:** `fix(mgmt-heal-2026-05-18): Ansible validates .env against PRODUCTION_ENV_TEMPLATE (M-P0-G)`
- **Files modified:**
  - `deploy/ansible/tasks/validate_env_template.yml` — `(file TO BE CREATED)` — ~40 lines
  - `deploy/ansible/site.yml` — include the new task; ~5 lines
  - `scripts/audit-env-against-template.sh` — `(file TO BE CREATED)` — ~30 lines bash helper
- **Patch scope:**
  ```
  At deploy/ansible/tasks/validate_env_template.yml (new):
    1. `slurp` deployed .env file from {{ app_root }}/.env
    2. compare against {{ app_root }}/docs/cloud/PRODUCTION_ENV_TEMPLATE.env.txt keys (extracted via grep '^[A-Z_]\+=')
    3. assert: each required key present in deployed .env; warn on extras; fail on missing
    4. ALSO assert: forbidden values not present (POS_SIMULATION_HARDWARE=true, PAYMENT_BYPASS_MODE=true, PRINTING_BYPASS_MODE=true, APP_DEBUG=true, DEMO=true) — playbook fails

  At deploy/ansible/site.yml:
    - name: Validate production env template alignment
      include_tasks: tasks/validate_env_template.yml
      when: app_env == 'production'
      tags: [preflight]

  At scripts/audit-env-against-template.sh (new):
    Reusable bash that does the same diff for CI hygiene; CI step grep PROHIBITED=true patterns.

  Why: R2-SRE-001 + R2-SRE-007 — operator hand-uploads .env, no validation against template; SRE-006 + SRE-007 in T-2.4.2 SRE report.
  Rollback: revert new files + site.yml include.
  ```
- **Tests added:** Sentinel `tests/Feature/Deploy/EnvTemplateValidationSentinelTest.php` — `(file TO BE CREATED)` — greps the YAML file existence + presence of the forbidden-flag assertions.
- **Acceptance evidence command:** Sentinel passes; manual ops verification by running `ansible-playbook site.yml --check --tags preflight` against staging.
- **Frozen-zone status:** 0 lines touched.

### Commit 5 — `mgmt-heal-mp0-h` — M-P0-H PreflightProductionCommand completeness
- **Title:** `fix(mgmt-heal-2026-05-18): add 3 missing flag checks to PreflightProductionCommand (M-P0-H)`
- **Files modified:**
  - `app/Console/Commands/PreflightProductionCommand.php` lines 40-62 (handle method) + new check methods ~50 lines added
- **Patch scope:**
  ```
  At app/Console/Commands/PreflightProductionCommand.php, ADD after checkBroadcastDriver():
    $this->checkPosSimulationHardware();   // CRITICAL if config('pos.simulation_hardware', false) === true
    $this->checkPaymentBypass();           // CRITICAL if config('payment.bypass_mode', false) === true
    $this->checkPrintingBypass();          // CRITICAL if config('printing.bypass_mode', false) === true
    $this->checkAppDebug();                // CRITICAL if env('APP_DEBUG', false) === true (post-PR #2 redundant but defensive)
    $this->checkDemoFlag();                // CRITICAL if env('DEMO', false) === true

  Each check method mirrors the existing pattern (line 40-62): add a finding via existing $this->addFinding('CRITICAL', ...) helper.

  Why: R2-SRE-002 in T-2.4.2 report — docblock at line 26-28 claims "duplicates the AppServiceProvider boot guards" but the 3 most dangerous flags are absent. The promise of the preflight is broken at exactly the layer ops trusts most.
  Rollback: revert the 5 new check methods + their calls.
  ```
- **Tests added:** `tests/Feature/Console/PreflightProductionCommandTest.php` — `(file TO BE CREATED)` — 5 cases (one per added check); each forces the dangerous flag true and asserts command exits 1 with the correct finding key in output.
- **Acceptance evidence command:** `php artisan test --filter=PreflightProductionCommandTest` → PASS
- **Frozen-zone status:** 0 lines touched (PreflightProductionCommand not in CLAUDE.md §7).

### Commit 6 — `mgmt-heal-mp0-i` — M-P0-I env-drift cron
- **Title:** `feat(mgmt-heal-2026-05-18): daily env-drift detection cron (M-P0-I)`
- **Files modified:**
  - `app/Console/Commands/MonitorEnvDriftCommand.php` — `(file TO BE CREATED)` — ~80 lines new command (signature `monitor:env-drift`)
  - `app/Console/Kernel.php` — add schedule entry; ~4 lines
- **Patch scope:**
  ```
  At app/Console/Commands/MonitorEnvDriftCommand.php (new):
    handle():
      1. Snapshot effective config values for {POS_SIMULATION_HARDWARE, PAYMENT_BYPASS_MODE, PRINTING_BYPASS_MODE, APP_DEBUG, DEMO, QUEUE_CONNECTION, CACHE_DRIVER, BROADCAST_DRIVER, KIOSK_AUTO_LOGIN_TRUSTED_IPS} via config() calls
      2. Compare against a checksum/snapshot stored in cache key 'env_drift_baseline' (refreshed at deploy time)
      3. If any drift detected: BypassAuditLogger::warn + Slack webhook + Log::critical
      4. If any dangerous flag = true in production: exit 1 (forces ops to investigate)

  At app/Console/Kernel.php protected schedule(), ADD:
    $schedule->command('monitor:env-drift')
             ->dailyAt('04:30')
             ->onOneServer()
             ->withoutOverlapping()
             ->emailOutputOnFailure('ops@foodking.test');

  Why: R2-SRE-003 — operator SSH edit + config:cache survives until next worker cycle, no detection. Daily drift check + audit trail.
  Rollback: drop command file + kernel entry.
  ```
- **Tests added:** `tests/Feature/Console/MonitorEnvDriftTest.php` — `(file TO BE CREATED)` — boots with one flag drifted → command exits 1.
- **Acceptance evidence command:** `php artisan test --filter=MonitorEnvDriftTest` → PASS
- **Frozen-zone status:** 0 lines touched.

## PR description template

Title: `feat(mgmt-backbone-2026-05-18): catalog mutator + env-write + settings audit heal — 9 P0 closed`

Body: