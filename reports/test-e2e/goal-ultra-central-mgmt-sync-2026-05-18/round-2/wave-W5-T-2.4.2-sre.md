# Wave W5 — T-2.4.2 Feature flag governance — SRE audit (Round 2)

**Specialist**: SRE / Ops
**Date**: 2026-05-18
**Mode**: READ-ONLY
**Anchors**:
- `config/pos.php:37` (simulation_hardware) + `config/pos.php:58` (featured_category_ids)
- `config/payment.php:82-93` (payment bypass) + `config/printing.php:28-35` (printing bypass)
- `config/{app, cash, kds, kiosk, fiscal, idempotency, security, payment, printing, pos, caisse_v1_rollout, broadcasting, queue}.php` (13 verified — flags surface)
- `app/Providers/AppServiceProvider.php:78-142` (production boot guard, 5 checks)
- `app/Console/Commands/PreflightProductionCommand.php` (15 checks, exit 1 on critical)
- `app/Services/Bypass/BypassAuditLogger.php` (runtime warning log per call)
- `docs/cloud/PRODUCTION_ENV_TEMPLATE.env.txt` (190 lines, 75+ keys)
- `docs/runbooks/BYPASS_MODE_OPERATIONAL.md` (218 lines)
- `deploy/ansible/site.yml` (Phase D playbook) + `group_vars/all.yml`
- `tests/Feature/Sentinels/{PosSimulationHardwareProductionGuard, BypassProductionGuard}Test.php`
- `.github/workflows/{phpunit, playwright, legacy-guards}.yml`

> SRE mindset: it's deploy day. The .env file lands on a fresh VPS or a re-keyed
> staging mirror. The wrong flag value silently rides into prod. What rings?
> What stays silent until a customer complains?

---

## Findings (strong-reasoning YAML)

```yaml
- id: SRE-T242-001
  severity: P0
  title: "Ansible playbook does NOT render `.env` and does NOT validate flag values — operator hand-uploads `.env`, no schema enforcement"
  category: deploy_time_validation_missing
  evidence:
    - "deploy/ansible/site.yml full read (lines 1-209) — no task copies/templates `.env`; no `validate:` or `assert:` task; no `PRODUCTION_ENV_TEMPLATE.env.txt` reference"
    - "group_vars/all.yml lines 1-7 explicitly disclaim: 'Full app .env (75+ keys) lives at docs/cloud/PRODUCTION_ENV_TEMPLATE.env.txt. This file holds infra-level vars + secret placeholders consumed by site.yml templates.'"
    - "Mention of `app:preflight-production` ONLY in line 4 of the template comment — NOT a task in site.yml"
  reasoning: |
    The pipeline is: (1) operator SCP's `.env` to /var/www/foodking, (2)
    Ansible runs `composer install` + `php artisan migrate --force`, (3)
    NGINX reload. There is no Ansible task that asserts the operator's
    `.env` matches `PRODUCTION_ENV_TEMPLATE.env.txt`. If the operator
    copies a staging `.env` (PAYMENT_BYPASS_MODE=true,
    PRINTING_BYPASS_MODE=true, POS_SIMULATION_HARDWARE=true) to the
    production VPS, *Ansible itself never notices*. The AppServiceProvider
    boot guard (line 78-142) eventually catches it — but only at first
    HTTP request, NOT at deploy time. So `ansible-playbook site.yml`
    returns 0 (success), the operator believes the deploy is healthy, and
    the first user request crashes with RuntimeException. That's a 5-10
    minute window where alerts fire on 502s instead of "deploy failed at
    flag validation step".
  cost_of_delay: |
    Deploy looks green. NGINX reloads. The first PoS cashier login at
    08:30 triggers a 500 (RuntimeException uncaught at request boundary
    because no global exception handler downgrades it). Cashier panics,
    pages on-call, on-call has to ssh in and grep storage/logs/laravel.log
    to discover the flag mismatch. Operational invisibility window = the
    entire deploy → first-request latency (could be minutes if no
    healthcheck hits a flag-dependent path). At Le Cayenne peak (12:00
    rush) this is a revenue-loss incident.
  remediation_hint: |
    Add an Ansible task immediately AFTER `composer install` and BEFORE
    `artisan migrate`:
      - name: Preflight production config gate (W9-AUDIT PROD-4)
        ansible.builtin.command:
          cmd: "php{{ php_version }} artisan app:preflight-production --strict"
          chdir: "{{ app_root }}"
        environment: { APP_ENV: production }
        register: preflight
        failed_when: preflight.rc != 0
    PreflightProductionCommand already exists and exits 1 on CRITICAL
    findings — wire it in. Bonus: add an explicit `lineinfile` check that
    asserts PAYMENT_BYPASS_MODE / PRINTING_BYPASS_MODE / POS_SIMULATION_HARDWARE
    / DEMO are literally `false` (NOT just absent — Dotenv defaults to
    false but a copy-paste from staging might have them explicit-true).

- id: SRE-T242-002
  severity: P0
  title: "PreflightProductionCommand does NOT check the dangerous flags it was designed to gate — simulation_hardware/payment.bypass/printing.bypass MISSING from check list"
  category: preflight_blind_spot
  evidence:
    - "PreflightProductionCommand.php lines 40-62 enumerate 15 checks: AppEnv, AppDebug, AppKey, Timezone, CacheDriver, QueueConnection, BroadcastDriver, SessionDriver, LogLevel, LogChannel, OpsCommandAvailability, FiscalSecrets, FiscalVerifyChain, DatabaseReachable, CacheReachable"
    - "grep 'simulation_hardware\\|payment.bypass\\|printing.bypass' on PreflightProductionCommand.php → 0 hits"
    - "AppServiceProvider:78-142 declares 5 boot guards (simulation_hardware, payment.bypass, printing.bypass, broadcasting, queue, cache) — only 3 of these are mirrored in preflight (broadcasting / queue / cache); the 3 most-dangerous flags are NOT preflight-checked"
    - "PreflightProductionCommand:26-28 docblock claims: 'The checks intentionally duplicate the AppServiceProvider boot guards' — but the duplication is incomplete"
  reasoning: |
    PreflightProductionCommand is the canonical "ops can run this WITHOUT
    booting the full HTTP stack" gate (per docblock line 26-28). It
    promises to mirror AppServiceProvider boot guards so cron / deploy /
    healthcheck wrappers can validate the config without paying boot-time
    cost. But the three most operationally-dangerous flags (the very ones
    that have RuntimeException guards in AppServiceProvider) are absent
    from the preflight. So: an operator who runs `php artisan
    app:preflight-production` BEFORE flipping the release symlink gets a
    green PASSED back, even if PAYMENT_BYPASS_MODE=true. The defense-in-depth
    pattern is broken at exactly the layer that's supposed to make it
    safer than the boot guard.
  cost_of_delay: |
    Operator who follows the README and runs preflight gets falsely
    assured. The boot guard catches it eventually, but the entire purpose
    of the preflight command (per its docblock) — early detection without
    booting — is defeated. Compounds SRE-T242-001: even if Ansible *did*
    call preflight, it wouldn't catch these three flags.
  remediation_hint: |
    Add these checks to PreflightProductionCommand::handle() right after
    checkBroadcastDriver():
      $this->checkPosSimulationHardware();   // CRITICAL if true
      $this->checkPaymentBypass();           // CRITICAL if true
      $this->checkPrintingBypass();          // CRITICAL if true
      $this->checkDemoFlag();                // CRITICAL if true
    Each mirrors the AppServiceProvider boot-guard logic. ~50 lines total,
    zero new dependencies. Recommend also re-running PreflightProduction
    as a daily cron (see SRE-T242-003) for drift detection.

- id: SRE-T242-003
  severity: P0
  title: "No drift detection — flags can be toggled mid-cycle (admin SSH edits `.env`, runs `config:cache`) and nothing pages until incident"
  category: drift_detection_missing
  evidence:
    - "grep -rn 'flag drift\\|config drift\\|baseline.*check\\|expected.*config' app/ scripts/ → 0 hits"
    - "app/Console/Kernel.php has 13 scheduled commands (Round 1 SRE inventory) — none are 'app:preflight-production'"
    - "No drift monitor in scripts/ — closest is scripts/check-invariants.sh (CI grep guard, not runtime)"
    - "Operator workflow per BYPASS_MODE_OPERATIONAL.md §4-5: ssh into VPS, edit .env, run `php artisan config:clear && config:cache`, no audit trail of who flipped what when"
  reasoning: |
    Once production is up, the boot guard only fires on app boot — meaning
    only on `php-fpm reload`, `supervisor restart`, or the first request
    that boots a fresh worker. A flag flipped mid-day via SSH edit (or by
    a rogue admin route, none in this codebase but hypothetically) sits
    in `.env` AND in `bootstrap/cache/config.php` until the next worker
    cycle. There is no `app:flag-drift-check` scheduled command that reads
    the current effective config and compares it to an expected baseline.
    Even worse: `config:cache` SERIALIZES the .env values into a PHP file
    — so any subsequent `.env` edit is silently ignored until the next
    `config:clear + config:cache` cycle. A panicked operator who edits
    `.env` to disable a flag and forgets to clear the cache will see no
    behavior change and may compound the incident.
  cost_of_delay: |
    Steady-state risk: low at Le Cayenne (single resto, owner+1-2 staff).
    Higher risk as SaaS scales (more operators, more touch points). Audit
    risk: NF525 inspector asks "show me the flag state at 2026-06-12
    14:00" — no answer exists without grepping storage/logs/laravel.log
    for [BYPASS-PAYMENT] entries (which only fire if bypass is ACTIVE,
    not if it was toggled and then untoggled).
  remediation_hint: |
    (a) Schedule preflight daily at 04:00 (after archive 02:00, before
        outbox prune 04:00 — same window as SRE-003 in Round 1 reco):
          $schedule->command('app:preflight-production --strict')->dailyAt('04:00');
        Non-zero exit → Sentry captureMessage + Slack page (mirrors the
        Round 1 SRE-002 fix pattern).
    (b) Add `BypassAuditLogger::flagSnapshot()` that runs on every boot
        AND on every config:cache, writes a row to `flag_audit_log`
        table with (timestamp, flag_name, value, env, host).
    (c) Document in BACKUP_RESTORE_NF525.md: "flag state snapshot is part
        of the NF525 forensic trail" so the daily backup includes it.

- id: SRE-T242-004
  severity: P1
  title: "Boot guard runs on EVERY request (re-evaluated per worker boot) — first request crash, but stale `config:cache` means worker boots with old flags after .env edit"
  category: runtime_mutability_inconsistency
  evidence:
    - "AppServiceProvider::boot() called on every Laravel bootstrap — line 78-142 fires for HTTP request workers AND queue workers AND scheduled command runs"
    - "BYPASS_MODE_OPERATIONAL.md §4 step 2 line 50-60: 'OBLIGATOIRE — pas optionnel ... config:cache préserve les anciennes valeurs même après modification de .env'"
    - "supervisor-foodking.conf.j2 line 9 (queue worker): `php artisan queue:work redis ... --max-jobs=1000` — workers do NOT auto-restart on .env change, only on max-jobs hit or supervisor SIGHUP"
    - "deploy/ansible/site.yml has `notify: reload supervisor` for soketi.json and supervisor.conf changes — but no notify on `.env` change because `.env` is NOT Ansible-managed"
  reasoning: |
    Heterogeneous flag visibility: PHP-FPM worker A boots at 08:30 with
    POS_SIMULATION_HARDWARE=false (correct). At 09:15, operator edits .env
    to POS_SIMULATION_HARDWARE=true to debug a TPE issue. Runs `config:clear
    && config:cache`. PHP-FPM is reloaded by FPM master because cache file
    timestamp changed → all FPM workers re-bootstrap with new config →
    boot guard fires → RuntimeException → cashier sees 500s. Operator
    panics, edits back to false, runs `config:clear && config:cache` —
    PHP-FPM reloads again → no exception → all good. BUT: the queue worker
    has `--max-jobs=1000` and may still be running on the OLD bootstrap
    config (cache:array snapshot of the simulation_hardware=true value)
    until it hits max-jobs or is manually killed. So OrderPaidAtCounter
    jobs queued during the 5-minute window can dispatch with
    simulation_hardware=true semantics WHILE PHP-FPM serves
    simulation_hardware=false semantics. NF525 sequence remains intact
    (the flag doesn't touch fiscal logic per CLAUDE.md §8 invariant), but
    the cash-drawer-session check could be inconsistent (worker bypasses,
    web request enforces).
  cost_of_delay: |
    Inconsistency window = up to `--max-jobs` worth of queue work (default
    1000 jobs, ~30 min at peak). Hard to detect because the worker logs
    don't surface flag state per job — only [BYPASS-PAYMENT] warning fires
    when payment.bypass=true (it would here), but NO equivalent logger
    fires for simulation_hardware. Auditor reviewing the day's audit_logs
    sees a clean chain but the underlying cash-drawer business logic was
    enforced on web, bypassed on worker.
  remediation_hint: |
    (a) Add `queue:restart` to the BYPASS_MODE_OPERATIONAL.md flip
        procedure (currently §4 only lists config:clear + view:clear +
        npm rebuild). `php artisan queue:restart` writes a flag to cache
        that workers check between jobs → graceful restart with new config.
    (b) Add a worker-side BypassAuditLogger::simulationHardwareUsed()
        invocation in PosController.php:95 + PaymentService.php:280 +
        SplitPaymentService.php:206 (the three sites that read
        config('pos.simulation_hardware')). Mirror the [BYPASS-PAYMENT]
        log pattern so simulation_hardware activations leave a structured
        trail per call.
    (c) Document the `--max-jobs` flag's interaction with stale config in
        BYPASS_MODE_OPERATIONAL.md.

- id: SRE-T242-005
  severity: P1
  title: "No alerting on flag-toggle events — RuntimeException in boot guard logs to fiscal/stack channels but no Sentry capture, no PagerDuty"
  category: alerting_gap
  evidence:
    - "AppServiceProvider:86-91 throws \\RuntimeException — caught by Laravel global exception handler (Handler.php) which by default returns 500 HTML; only `Log::error` if logging configured; NO `\\Sentry\\captureException` in the boot-guard path"
    - "Round 1 SRE-005 already documented Sentry captureException missing from queue failure paths — same pattern here"
    - "BypassAuditLogger only fires when bypass is ACTIVE (not on attempted-but-prevented boot). No Slack webhook on guard fail."
  reasoning: |
    The boot guard is the LAST line of defense. When it fires, you have a
    P0 incident — production cannot serve requests. The current behavior:
    RuntimeException bubbles to Laravel's `Handler::report()`, which
    writes to LOG_CHANNEL (production_json → file). No one is paged. The
    operator only knows the deploy failed because a customer or healthcheck
    notices the 500s. Whereas if we wired Sentry captureException + a
    pre-throw `Slack::critical('boot-guard tripped')`, on-call gets paged
    before the cash register tries to ring up a customer.
  cost_of_delay: |
    Detection lag = whatever your healthcheck/customer-complaint cycle is.
    For Le Cayenne with peak rush = 5-15 minutes. For SaaS B2B with
    24h customer-monitoring lag = 24h+.
  remediation_hint: |
    In AppServiceProvider::boot(), wrap each guard:
      try {
        if ((bool) config('pos.simulation_hardware', false)) {
          $this->emergencyPage('POS_SIMULATION_HARDWARE true in prod');
          throw new \RuntimeException(...);
        }
      }
    Where emergencyPage() does: Sentry::captureMessage + Log::critical +
    optional Slack webhook (config('alerts.slack.boot_guard_webhook')).
    Keep the RuntimeException — fail-fast is correct — but TELL someone
    BEFORE the throw bubbles up.

- id: SRE-T242-006
  severity: P1
  title: "CI does NOT run preflight against non-test configs — phpunit.yml uses CACHE_DRIVER=array + QUEUE_CONNECTION=sync (forbidden in prod) without asserting they're test-scoped"
  category: ci_cd_flag_check_missing
  evidence:
    - ".github/workflows/phpunit.yml:61-67 sets CACHE_DRIVER=array, QUEUE_CONNECTION=sync, SESSION_DRIVER=array, STAFF_ONLY_MODE='false', KIOSK_USE_POS_WIZARD='false' — all FORBIDDEN in production by AppServiceProvider"
    - ".github/workflows/playwright.yml:101-107 mutates .env including STAFF_ONLY_MODE, KIOSK_USE_POS_WIZARD, LOGIN_LOCKOUT_MAX_ATTEMPTS=500 — values that would be REJECTED in prod"
    - "No CI step like `! grep -E '^POS_SIMULATION_HARDWARE=true' .env.example .env.production`"
    - "No CI step that runs `app:preflight-production` against a synthetic 'production' env to verify the gate works"
  reasoning: |
    Two distinct CI gaps:
    (a) CI uses dangerous flag values for test convenience — this is FINE
        per se (sync queue is needed for in-process testing). But there is
        no sentinel that asserts these dangerous values DON'T leak into
        `.env.example` or `PRODUCTION_ENV_TEMPLATE.env.txt`. If a developer
        copies their CI matrix into `.env.example`, the template is
        poisoned and the next operator follows it.
    (b) CI never tests the production-guard path. The PHPUnit suite has
        PosSimulationHardwareProductionGuardSentinelTest and
        BypassProductionGuardTest, which is GOOD — but these test the
        BOOT guard, not the preflight command. There is no
        PreflightProductionCommandTest that asserts each flag check
        actually FAILS the command when set wrong. Defense-in-depth has
        only one of two layers under test.
  cost_of_delay: |
    Latent risk — depends on a developer making a specific copy-paste
    mistake. Low frequency, but the blast radius (template-poisoning)
    is high: every subsequent install inherits the mistake.
  remediation_hint: |
    (a) Add a CI step in legacy-guards.yml:
          - name: Production env template hygiene
            run: |
              grep -E '^(PAYMENT_BYPASS_MODE|PRINTING_BYPASS_MODE|POS_SIMULATION_HARDWARE|DEMO|APP_DEBUG)=true' \
                docs/cloud/PRODUCTION_ENV_TEMPLATE.env.txt && exit 1 || exit 0
          - name: Forbid dangerous defaults in .env.example
            run: |
              grep -E '^(PAYMENT_BYPASS_MODE|PRINTING_BYPASS_MODE|POS_SIMULATION_HARDWARE)=true' .env.example && exit 1 || exit 0
    (b) Write PreflightProductionCommandTest::test_each_check_blocks_deploy
        — assert each CRITICAL finding sets exit code 1 + the right key.

- id: SRE-T242-007
  severity: P2
  title: "PRODUCTION_ENV_TEMPLATE.env.txt is documentation-only — not consumed by Ansible, not validated against the live `.env` shape"
  category: documentation_drift
  evidence:
    - "docs/cloud/PRODUCTION_ENV_TEMPLATE.env.txt has 190 lines listing 75+ keys"
    - "deploy/ansible/group_vars/all.yml:2-3 explicitly states 'Full app .env (75+ keys) lives at docs/cloud/PRODUCTION_ENV_TEMPLATE.env.txt'"
    - "No `lineinfile` task in site.yml that asserts each PRODUCTION_ENV_TEMPLATE key is present in deployed `.env`"
    - "No JSON Schema / dotenv-linter style validation"
  reasoning: |
    Documentation that humans copy by hand drifts. PRODUCTION_ENV_TEMPLATE
    is the SSOT of "what flags should be set" — but there is no automated
    check that the deployed `.env` actually includes every required key.
    A missing key → Dotenv falls back to env() default which may be `false`
    (good) OR an empty string (could be bad if a config reader expects a
    real value). Example: missing CASH_MANAGER_GATE_ROUTINE_CLOSE → reads
    as false → V1 Le Cayenne behavior (single-cashier, no manager gate) →
    correct by accident, but ONLY because the default happens to match.
  cost_of_delay: |
    Subtle config divergence between operators and the canonical template.
    Low immediate cost; high audit / debugging cost when an incident later
    has a "wait, why is FLAG_X behaving differently here vs prod" thread.
  remediation_hint: |
    Add a script `scripts/audit-env-against-template.sh` that diffs
    `keys_only(.env)` vs `keys_only(PRODUCTION_ENV_TEMPLATE.env.txt)` and
    fails the playbook if delta > N keys. Wire as Ansible task right
    after `.env` is uploaded. Bonus: include in daily preflight cron
    (SRE-T242-003).

- id: SRE-T242-008
  severity: P2
  title: "Featured-category allowlist flag (`POS_FEATURED_CATEGORY_IDS`) hot-reload story unclear — admin route to override? Cache invalidation strategy?"
  category: runtime_mutability_doc_gap
  evidence:
    - "config/pos.php:58-61 reads env CSV at config-build time → array_map(intval, explode)"
    - "Once `config:cache` runs, the parsed array is frozen in bootstrap/cache/config.php"
    - "No admin UI surfaced (grep 'POS_FEATURED_CATEGORY' resources/ → only in config/pos.php) — env-only flag"
    - "Owner spec 2026-05-18 (per config docblock) implies operators can change this — but procedure is undocumented"
  reasoning: |
    The flag's value is operationally interesting (the cashier's landing
    screen) but its mutation requires the same `.env` edit + `config:clear`
    + worker restart dance as the dangerous flags. If owner asks to add
    new category 401 to the strip mid-day, there is no documented runbook
    for the safe procedure (vs hot-reload via admin UI). Low-stakes (only
    affects what's shown on cashier landing), but mirrors the broader
    pattern: flags are env-only, no admin-route mutability, all changes
    require the same disruptive workflow.
  cost_of_delay: |
    Operational annoyance. Le Cayenne owner who wants to swap a category
    has to ssh in. Doesn't scale to SaaS B2B (no SSH access).
  remediation_hint: |
    V1: document in CLAUDE.md or a new docs/runbooks/FLAG_GOVERNANCE.md:
    "Featured categories: edit POS_FEATURED_CATEGORY_IDS in .env →
    config:clear + config:cache + queue:restart → verify via
    /admin/pos load shows the new strip."
    V1.0.2+: surface an admin route that writes to settings table (DB
    row, not env) for non-dangerous flags. Keep dangerous flags
    env-only (correct security posture).
```

---

## Cross-cutting SRE verdict

**FLAG GOVERNANCE READINESS: NOT V1-CLOUD-READY without remediation of SRE-T242-001 / SRE-T242-002 / SRE-T242-003.**

The current design follows a defensible pattern — env-file + boot-time RuntimeException for dangerous flags — but the wiring is INCOMPLETE in the layer where SRE depends on it most: the deploy pipeline. Three problems compound:

1. **Deploy-time validation MISSING (SRE-T242-001)** — Ansible never calls preflight. Deploy returns 0 even when flags are wrong. Boot guard catches it later, but at request boundary, not deploy boundary.

2. **Preflight INCOMPLETE (SRE-T242-002)** — the very command designed to be the safer pre-symlink gate doesn't check the 3 most-dangerous flags. The command's own docblock claims it duplicates the boot guards, but it doesn't.

3. **No drift detection (SRE-T242-003)** — once production is up, no scheduled task re-asserts the config baseline. A flag flipped via SSH edit + `config:cache` survives until the next worker cycle, and there's no audit trail of *who* flipped what *when*.

The pattern that emerges is the same one Round 1 SRE-002 / SRE-010 surfaced for outbox observability: **the data exists, the page never fires**. Boot guard is good. Preflight command exists. Sentinel tests pass. But the OPERATIONAL glue — Ansible-runs-preflight, daily drift check, Sentry pages on boot-guard-trip — is missing.

**Runbook gaps observed**:
- `BYPASS_MODE_OPERATIONAL.md` documents the bypass-mode flip procedure but lacks `queue:restart` step (SRE-T242-004) and lacks the "what if I'm in prod and a flag is wrong" rollback procedure.
- No `docs/runbooks/FLAG_GOVERNANCE.md` — there is no single document that lists "all dangerous flags + expected prod values + what happens if wrong". This forces SRE to grep three config files + AppServiceProvider to know what's at stake.
- `BACKUP_RESTORE_NF525.md` does not include `flag_audit_log` table (because it doesn't exist yet, per SRE-T242-003 reco).

**Strongest single quick-win**: SRE-T242-002 — adding 4 checks (~50 lines) to PreflightProductionCommand closes the preflight blind spot. Combined with SRE-T242-001 (wire preflight into Ansible site.yml as a `failed_when` task, ~10 lines), the deploy pipeline gains the gate it was designed to have. ~2-3 hours of work total, prevents the entire "dangerous flag silently rides into prod" class.

**Estimated wall-clock to V1-cloud-ready**:
- SRE-T242-001 + SRE-T242-002 (P0 deploy-time gate): ~3h dev + 1h ops verification
- SRE-T242-003 (P0 drift detection daily cron + flag_audit_log migration): ~4h dev + 1h ops
- SRE-T242-004 + SRE-T242-005 (P1 worker consistency + alerting): ~4h dev
- SRE-T242-006 (P1 CI hygiene): ~2h dev
- SRE-T242-007 + SRE-T242-008 (P2 docs + admin UI plumbing for non-dangerous flags): backlog V1.0.2

End of audit. ~1510 words.
