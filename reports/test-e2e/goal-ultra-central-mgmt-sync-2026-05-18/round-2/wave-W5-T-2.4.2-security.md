# Wave W5 — T-2.4.2 Feature Flag Governance — SECURITY AUDIT Round 2

**Specialist** : SECURITY (Round 2, READ-ONLY)
**Threat model** : Attacker holds admin credentials (or stolen `.env`). Goal: enable a dangerous flag in production silently.
**Date** : 2026-05-18
**Format** : Hostile mindset — assume the attacker is inside.

---

## VERDICT

**BLOCK — RED-CRITICAL on production cut-over.**

The feature-flag surface is split across **three governance layers** that don't agree:

1. `config/*.php` — file-system flags consumed at boot (production guards exist for the fiscal-critical ones).
2. `settings` DB table (Smartisan `Settings::group(...)->set()`) — admin-UI mutable, **no audit_log**, **no broadcast revocation gate**.
3. `.env` — runtime-writable by the PHP-FPM user via the `dipokhalder/laravel-env-editor` package, which is wired into **6 admin controllers** (Site, Company, License, Mail, Notification, Installer).

The third layer is the killer. The "MUST be false in production" markers in `docs/cloud/PRODUCTION_ENV_TEMPLATE.env.txt` assume `.env` is immutable. The codebase proves it is not. The production boot-guard in `AppServiceProvider::boot()` is a **defensive shotgun pointed at the wrong threat** — it stops the operator typo, not the compromised admin.

---

## Q1 — Settings table → runtime config (DB poisoning attack)

**Finding** : `MEDIUM-MITIGATED`. The Smartisan `settings` table is NOT bound to Laravel's `config()` repository. Anchor anchors:

- `app/Services/SiteService.php:31` — `Settings::group('site')->all()` (reads DB).
- `config/pos.php:37` — `'simulation_hardware' => filter_var(env('POS_SIMULATION_HARDWARE', false), FILTER_VALIDATE_BOOLEAN)` (reads .env, **not** DB).

Critical observation: **none of the boot-guarded flags** (`POS_SIMULATION_HARDWARE`, `PAYMENT_BYPASS_MODE`, `PRINTING_BYPASS_MODE`, `CACHE_DRIVER`, `BROADCAST_DRIVER`, `QUEUE_CONNECTION`) have a `settings` table mirror. An attacker who poisons the `settings` table cannot enable fiscal-bypass or audit-chain-break flags through that vector. This is the only piece of explicit defense-in-depth I credit.

**However** : the `settings` table DOES control business-critical behavior — `site_default_branch` (`app/Traits/DefaultAccessModelTrait.php:28-46`), `pos_dine_in_enabled` (`app/Http/Requests/PosOrderRequest.php:60`), `order_setup_delivery`, `order_setup_takeaway` (full order-type gate), `otp_*` (auth gate). DB write = silent toggle, no audit, no gate (see Q6).

---

## Q2 — Cache pollution attack

**Finding** : `LOW-RISK conditional`. The Smartisan cache layer is **disabled by default** (`vendor/smartisan/laravel-settings/config/settings.php:44` — `SETTINGS_CACHE_ENABLED=false`). Production template does NOT enable it.

If ops flip it on for performance later, the cache keys are **deterministic and predictable** :

```
${prefix}settings.keys=${keys}&group=${group}&excepts=${excepts}&for=$for
```

(`vendor/smartisan/laravel-settings/src/Settings.php:175-186`)

An attacker with LAN-Redis access could `SET fk_cache:settings.keys=&group=order_setup&excepts=&for=` to inject `{order_setup_delivery: "enabled"}` after-the-fact — bypassing the controller's permission gate entirely. **Mitigation already partial** : `forgetCacheIfEnabled()` (`Settings.php:232-241`) invalidates on writes — but does not prevent direct Redis SET. Depends entirely on Redis network isolation (`REDIS_HOST=127.0.0.1` per template). **Flag for ops runbook**, not RED-blocker.

---

## Q3 — .env file write access (CRITICAL — deploy doc contradicts code)

**Finding** : `P0-CRITICAL`. The `.env` file MUST be writable by the PHP-FPM user for **six production-path admin features to function**.

Anchor anchor : `vendor/dipokhalder/laravel-env-editor/src/EnvEditor.php:414` — `file_put_contents($this->env, $newArray);` — bare write, no permission check, no file-ownership audit.

Call sites in production code paths :

| Service | File:Line | What it writes to .env |
|---|---|---|
| `SiteService::update` | `app/Services/SiteService.php:47-56` | `APP_DEBUG`, `TIMEZONE`, `CURRENCY*`, `DATE_FORMAT`, `TIME_FORMAT`, optionally `MIX_GOOGLE_MAP_KEY` |
| `CompanyService::update` | `app/Services/CompanyService.php:44` | `APP_NAME` |
| `LicenseService::update` | `app/Services/LicenseService.php:45` | `MIX_API_KEY` |
| `MailService::update` | `app/Services/MailService.php:42` | `MAIL_HOST`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_ENCRYPTION`, ... |
| `InstallerService` | `app/Services/InstallerService.php:19,31,118` | DB credentials + site config |
| `InstallerController::licenseStore` | `app/Http/Controllers/Installer/InstallerController.php:67-68` | `MIX_API_KEY` (no auth) |

**Deploy doc contradiction** : `docs/cloud/PRODUCTION_ENV_TEMPLATE.env.txt` lines 102-189 sprinkle "MUST be false in production" markers as if .env is operator-controlled. Nowhere does it say "chmod 400 .env to www-data" or "switch admin Settings/Company/Mail/License pages to read-only in production". The natural ops interpretation is to harden the file → the admin UI then 500s on every Settings save → ops reverts to 644.

**Net effect** : in any compliance-realistic prod deployment with the admin UI functional, the PHP user can write to .env, and a compromised admin session = full env-tampering reach. Combine with Q5 below.

---

## Q4 — Compromised admin → dangerous flag enable (Blast radius)

**Finding** : `P0`. Mapped reach for an attacker with `permission:settings` (the Spatie gate on `CompanyController:19`, `SiteController:19`, `KioskSetupController`, `MailController`, `LicenseController`, etc.) :

### Via Admin UI (PUT /api/admin/setting/site, /company, /mail, /license, /otp, /kiosk-setup, /order-setup, /loyalty-setup, /payment-gateway):

1. **`APP_DEBUG=true`** — `SiteRequest:38` accepts `numeric` with no enum constraint, `SiteService:48` writes it. NO production boot guard (see Q5). Result: stack-traces, SQL, file paths leak on every uncaught exception. From there, leaked secrets in stack traces (DB creds, Pusher keys, Fiscal HMAC seeds if a query erroring out includes them in context).
2. **`MAIL_HOST` / `MAIL_USERNAME` / `MAIL_PASSWORD`** — `MailService.php:42` writes them straight to .env. Attacker pivots SMTP to their server → exfiltrates password-reset OTPs, kiosk machine notifications, fiscal close emails.
3. **`MIX_API_KEY`** — `LicenseService.php:45`. License/identity tampering — affects `config('installer.buildPayload.license_code')` and any code that re-checks at boot.
4. **`order_setup_delivery=ENABLE` / `order_setup_takeaway=ENABLE`** — bypasses Le Cayenne V1 channel scoping. Enables order types the operator did not intend.
5. **`pos_dine_in_enabled=true`** (via `OrderSetupService` or DB direct) — `app/Http/Requests/PosOrderRequest.php:60` is the only gate. Enabling unlocks dine-in flow that V1 has explicitly disabled per BRAIN §V1 Dine-in désactivé.
6. **`otp_expire_time`** raised to absurd value, `otp_digit_limit` lowered — brute-force OTP horizon expanded. Combined with `MAIL_HOST` swap = phone+email account takeover.

### NF525 / fiscal blast radius (good news, partial)

The fiscal-critical flags (`POS_SIMULATION_HARDWARE`, `PAYMENT_BYPASS_MODE`, `PRINTING_BYPASS_MODE`) have **no admin-UI write path** (no `addData([... "POS_SIMULATION_HARDWARE" ... ])` anywhere — `grep -rn "POS_SIMULATION_HARDWARE" app/` returns only readers). They live in env-only. **Combined with** the `AppServiceProvider:78-141` boot guards, a compromised admin CANNOT enable simulation_hardware via UI. The fiscal sequence + audit chain integrity is preserved by this defense layer. This is the strongest single mitigation in the codebase.

But: the env-editor `addData` API accepts an arbitrary key. If a future PR adds a control on `SiteService` that lets the admin set "any env key", the whole NF525 perimeter falls. The guard is **conventional, not enforced** — no allowlist on which keys `addData` accepts.

---

## Q5 — Hardcoded production defaults that should be false

**Finding** : `P0`. `APP_DEBUG` is the canonical failure.

- `AppServiceProvider::boot()` (`app/Providers/AppServiceProvider.php:78-141`) refuses to boot if `POS_SIMULATION_HARDWARE=true`, `PAYMENT_BYPASS_MODE=true`, `PRINTING_BYPASS_MODE=true`, `CACHE_DRIVER=array|null`, `BROADCAST_DRIVER=null`, `QUEUE_CONNECTION=sync`. **`APP_DEBUG=true` is NOT in this list.**
- `SiteService.php:48` : `'APP_DEBUG' => $request->site_app_debug == Activity::ENABLE ? 'true' : 'false'`.
- `SiteController::update:34-38` : duplicate branch on `env('DEMO')`, both arms write the same way — the DEMO gate is theatre.
- `PRODUCTION_ENV_TEMPLATE.env.txt:9` lists `APP_DEBUG=false` without a CRITICAL marker.

Exploit chain : compromised admin posts `{ site_app_debug: 1, ... }` → `EnvEditor::addData(["APP_DEBUG" => "true"])` writes .env → `Artisan::call('optimize:clear')` (`SiteService.php:64`) reloads config → next 500 leaks the database connection string and Fiscal HMAC context.

**Recommendation** : add APP_DEBUG=true boot guard in `AppServiceProvider`, OR remove `site_app_debug` from `SiteRequest` rules + drop `addData(['APP_DEBUG' => ...])` from `SiteService::update`.

Secondary instance : the `DEMO` flag. `MIX_DEMO="${DEMO}"` is exposed to the frontend (`.env:60-61`), `SettingResource:95` echoes `env('DEMO')` to the API response. Setting it true short-circuits OTP delivery (`app/Services/OtpManagerService.php:76`) and bypasses Google Map key writes (`SiteService.php:58`). A "DEMO=true" admin flip = silent OTP defeat. No boot guard.

---

## Q6 — Flag toggle audit trail (CRITICAL — no forensics)

**Finding** : `P0`. `grep -n "audit_log\|AuditLogService" app/Services/*Service.php | grep -i "setting\|otp\|company\|license\|site\|mail\|kiosk\|order\|theme\|notification\|social\|cookies\|loyalty"` returns **ZERO matches** across the 12 settings services listed in `app/Services/SettingService.php:9-23`.

The only reaction to a settings change is `SettingsUpdated::dispatch(...)` (e.g. `CompanyController::update:36`, `SiteController::update:39`), which the `PersistSettingsUpdatedToOutbox` listener turns into a **broadcast event** (`domain_events` outbox + Pusher fan-out). This is **for live propagation, not forensics** :

- `app/Listeners/PersistSettingsUpdatedToOutbox.php:60-72` writes `event_type = SETTINGS_UPDATED` + `payload = {changed_keys: [...]}`. It does NOT record the actor, IP, before/after values, branch (it FANS OUT to all branches), or chain into `audit_logs`.
- `audit_logs` is NF525-protected — append-only, HMAC chain (`AuditLogService::write`). No settings mutation calls it.
- `optimize:clear` is invoked **inside** `CompanyService::update` and `SiteService::update` — a config-cache wipe that's invisible in any log.

**Forensic impact** : an attacker who PUTs `/api/admin/setting/site` with `site_app_debug=1`, ships exploits while debug is on, then PUTs `site_app_debug=0` will leave **zero evidence** beyond the broadcast event (which only contains `changed_keys: ['site']`, not the values, the user, or the IP).

**Recommendation** : require every Settings::group(*)->set() call to also write `audit_logs` (actor, IP, group, key-level diff). This is the single highest-leverage fix because it bounds the blast radius — it doesn't stop the attack, but it makes recovery + prosecution possible.

---

## Q7 — PRODUCTION_ENV_TEMPLATE completeness

**Finding** : `P1`. The template is **partial**.

Missing CRITICAL markers / discussion :

- `APP_DEBUG` — no boot guard; should be `# CRITICAL: any value other than false leaks stack traces & secrets`.
- `MAIL_PASSWORD` — writable via admin UI (`MailService.php:42`). Template lists it unmarked.
- `MIX_API_KEY` — license-validation pivot vector (`LicenseService.php:45`). Unmarked.
- `KIOSK_AUTO_LOGIN_TRUSTED_IPS` — controls kiosk machine cred auto-injection (`config/kiosk.php:177` + `master.blade.php` gate). MUST be set in prod or the gate falls open via `APP_ENV=local` bypass. Template doesn't even mention this var.
- `SETTINGS_CACHE_ENABLED` — defaults false. Not in template. If flipped on without Redis isolation review, Q2 cache pollution becomes exploitable.
- `DEMO` — listed at line 189 but no warning about its OTP / Google-key / SMS short-circuit semantics.
- Missing **operational note** : "`.env` MUST be writable by the PHP-FPM user — admin pages Site/Company/Mail/License/OTP rewrite it via `dipokhalder/laravel-env-editor`. If you chmod 400 these pages will 500." Either the doc accepts the risk explicitly or the package gets surgically gated.
- Missing **artisan note** : `optimize:clear` is invoked from request context (`SiteService.php:64`, `CompanyService.php:45`, `LicenseService.php:46`). On a php-fpm-only host without artisan exec capability, this can silently fail and leave the running PHP processes on stale config until next request cycle. No "verify post-save" guidance.

---

## Defense-in-depth credits (don't go one-sided)

- Boot guards in `AppServiceProvider:78-141` are **effective and well-placed** for the three bypass flags + CACHE_DRIVER + BROADCAST_DRIVER + QUEUE_CONNECTION. They fail-loud, not fail-silent.
- `permission:settings` Spatie gate is applied consistently on the 12 admin settings controllers (verified on `CompanyController:19`, `SiteController:19`).
- `Settings::set()` correctly invalidates the cache key on write (`vendor/smartisan/laravel-settings/src/Settings.php:53,232`) — legitimate writes don't fall stale.
- `SettingsUpdated` outbox event (Wave 5G R9 heal, `app/Listeners/PersistSettingsUpdatedToOutbox.php`) closes the live-propagation gap that RED-team flagged 2026-05-17. Partial mitigation: it makes silent admin-DB-poke detectable on the broadcaster side, even though it isn't a forensic audit chain.

---

## Round 2 BLOCK conditions (must close before prod cut-over)

1. **Add APP_DEBUG=true production boot guard** in `AppServiceProvider::boot()` (matching the existing pattern).
2. **Remove `site_app_debug` and `MIX_GOOGLE_MAP_KEY` from `SiteService::update` env writes**, OR add an env-key allowlist to `EnvEditor::addData()` that excludes APP_DEBUG, MAIL_*, DB_*, FISCAL_*, MIX_API_KEY, POS_SIMULATION_HARDWARE, PAYMENT_BYPASS_MODE, PRINTING_BYPASS_MODE.
3. **Settings mutations must call `AuditLogService::write`** with actor, IP, group, key-diff. Wave 5G outbox stays as live-propagation; this is the forensic counterpart.
4. **Resolve the .env writability paradox** in PRODUCTION_ENV_TEMPLATE — either document the chmod 644 requirement + accept the blast radius explicitly, or move env-writing admin features behind a Tenant-Admin-only gate.
5. **Add CRITICAL markers** to APP_DEBUG, MAIL_PASSWORD, MIX_API_KEY, KIOSK_AUTO_LOGIN_TRUSTED_IPS in the production template.

Until these five are addressed, **the feature-flag governance layer is one stolen admin session away from a NF525-orthogonal but operationally catastrophic compromise.**

— SECURITY Round 2 / READ-ONLY / hostile framing applied
