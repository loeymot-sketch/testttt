# SECURITY ULTRA PLAN — FoodKing 2026-05-16
> **Owner consumable** : every section is paste-ready against the codebase HEAD `adf7036e4` on branch `feature/mobile-app-le-cayenne-2026-05-10`. Findings re-verified file-by-file 2026-05-16 ; stale items pruned to §9. Frozen-zone touches : ZERO planned. NF525 invariants : unchanged.

---

## §0 EXECUTIVE SUMMARY

### Current vs target
| Axis | Current | Target | Source |
|---|---|---|---|
| Security score (CTO audit Agent 2) | **28/100** | **75/100** | `reports/audit/cto-global-2026-05-16/agent-2-security-red.md:9` |
| AWS keys leaked + non rotées | 1 (commit `a4a88df06`) | 0 | git log + .env.backup-pre-round2 |
| Wildcard `['*']` tokens still issued | 3 sites (Login, GuestSignup, ForgotPassword) | 0 prod sites (1 documented CI exemption) | grep verified |
| RCE primitive via `LanguageService::edit` | 1 (no `permission:settings`) | 0 | `routes/api.php:486` + `LanguageController.php:23` |
| IDOR cross-branch `withoutGlobalScope(BranchScope)` | 1 illegitimate (`PosOrderController::show`) / 10 documented | 0 illegitimate / 10 documented + assertion-guarded | grep verified |
| FormRequest with permission check | 6 / 93 (6.5%) | 30 / 93 (32%) — 24 endpoints prioritaires | find + grep |
| Composer EOL/CVE packages | Laravel 9.52.21 EOL + phpspreadsheet 1.30.0 | Laravel 10 LTS + phpspreadsheet ≥2.x | composer.lock |
| Secret-scan CI gate | None | gitleaks + composer audit + npm audit bloquants | `.github/workflows/` |

### Hard blockers (must close before Le Cayenne go-live)
1. **AWS keys rotation** (owner action, ≤30 min, AWS console) — Claude cannot do this.
2. **LanguageService RCE quarantine** (Claude-actionable, 30 min code + 1 h tests).
3. **Sanctum `['*']` token detonation** (Claude-actionable, 4 h code + tests + migration).
4. **PosOrderController IDOR removal** (Claude-actionable, 10 min code + 30 min tests).
5. **gitleaks pre-commit + CI gate** (Claude-actionable, 1 h workflow + doc).

### Total effort split (consolidated, brutally honest)
| Actor | Hours | Notes |
|---|---|---|
| Owner (AWS console, S3, Sentry signup, env secrets) | ~6 h | Wall-clock includes AWS rotation propagation + CloudTrail audit |
| Claude (code, tests, migrations, CI workflows, docs) | ~52 h | Pure focused work, no waiting |
| Wall-clock minimum | ~10 working days | If owner + Claude alternate, mostly serialised on owner gates |
| Wall-clock realistic | ~3 weeks | Allowing for review cycles, RED-team rebuttals, regression heals |

### Five domains (executed in this order)
1. **DOMAIN 1 — SECRETS** : current 15/100 → target 80/100 (rotation + gitleaks + composer/npm audit CI + .env.example hardening + secret-scan pre-commit) — ~6 owner-h + ~6 Claude-h
2. **DOMAIN 2 — AUTH & TOKENS** : current 20/100 → target 75/100 (wildcard detonation + role-scoped abilities + revoke migration + CI lint + tokenCan refit) — ~14 Claude-h
3. **DOMAIN 3 — AUTHORIZATION & IDOR** : current 25/100 → target 70/100 (IDOR fix + scope-bypass triage of 11 BranchScope sites + 20 prioritized FormRequest authz) — ~16 Claude-h
4. **DOMAIN 4 — RCE & INPUT VALIDATION** : current 30/100 → target 80/100 (LanguageService quarantine + installer hardening + PaymentController whitelist + upload mime hardening) — ~8 Claude-h
5. **DOMAIN 5 — DEPENDENCIES & SUPPLY CHAIN** : current 35/100 → target 75/100 (PHPSpreadsheet bump + Laravel 10 LTS path + composer audit CI + npm audit CI + abandoned-package report) — ~14 Claude-h (Laravel 10 alone is 8-12 h with regression)

### Composite score projection
After all five domains executed and verified : **75-82/100** (target met). The chain integrity layer (HMAC fiscal, idempotency, OTP CSPRNG) is already strong per Agent 2 §POSITIVES — improving the perimeter from "two layers cosmetic + one layer real" to "three layers real, two layers belts" gets the headline score across 75.

---

## §1 DOMAIN 1 — SECRETS

### Domain summary
| Metric | Current | Target |
|---|---|---|
| Score | 15/100 | 80/100 |
| Live AWS keys leaked in git history | 1 (commit `a4a88df06`, `.env.backup-pre-round2`) | 0 rotated + history scrubbed |
| Secret scan in CI | None | gitleaks-action@v2 + custom JWT/HMAC patterns |
| `.env*` git protection | gitignore updated ✅ (`adf7036e4`) | gitignore + pre-commit hook + CI gate |
| `MIX_API_KEY` cosmetic security | Injected verbatim in HTML (`master.blade.php:109`) | Removed OR redesigned |

**Top 3 priorities** :
1. **AWS rotation** (owner) — `AKIAYJOT77SIZHDXNYOZ` still valid 13 days post-leak (CloudTrail audit mandatory).
2. **gitleaks + composer audit + npm audit CI gate** — block any future regression.
3. **`MIX_API_KEY` redesign** — currently a cargo-cult middleware ; either delete or make non-leakable.

**Total effort** : ~6 owner-h + ~6 Claude-h.

---

### FINDING D1-S-01 — LEAKED AWS PRODUCTION KEY (NOT ROTATED) — P0

**Current state (re-verified 2026-05-16)** :
- Commit `a4a88df06c6fefb73e04c98d559eb54673e195ca` introduced `.env.backup-pre-round2` with `AWS_ACCESS_KEY_ID=AKIAYJOT77SIZHDXNYOZ` and `AWS_SECRET_ACCESS_KEY=oqfWQa5+...` (verified `git log --all -S 'AKIAYJOT77SIZHDXNYOZ'`).
- Follow-up `adf7036e4 chore(security+heal-final): untrack .env backup + gitignore harden` deletes the working-tree file and patches `.gitignore` (`.env.backup`, `.env.backup-*`, `.env.backup.*` — verified).
- Git history retains `a4a88df06`, `57dc6c95b`, `2b9f2effe`, `9b1e741f4`, `1e0611aeb` containing the key. None scrubbed. Branch `backup/pre-menu-heal-v2-2026-05-14` is **still on the remote**.
- **No evidence of AWS rotation**. CloudTrail audit not started.

**Attack scenario** : Trivial. Anyone with clone access to the repo (any past or current collaborator, any leaked SSH key, any GitHub OAuth scope abuse) runs `git log --all -p | grep AKIA` → key found in plain. `aws sts get-caller-identity --access-key-id AKIA... --secret-access-key ...` confirms validity. Pivot : enumerate S3 buckets, dump objects, push poisoned static assets, abuse IAM if any wider grants. Worst case : crypto mining on EC2.

**Root cause** : Manual `.env.backup-pre-round2` snapshot before "round 2" of menu heal, copy-pasted live prod secrets and was `git add`-ed unintentionally because no pre-commit secret scanner existed. The .gitignore at the time didn't have `.env.backup*` — that was added later in `adf7036e4`.

**Fix plan** : owner-action, Claude cannot rotate AWS keys. Three steps :
1. **Owner — AWS console** (≤15 min) :
   - IAM → Users → find user owning `AKIAYJOT77SIZHDXNYOZ` (likely `foodking-prod-deploy` or similar) → Security Credentials tab → Create new access key → record new pair securely (1Password / `op`).
   - Update `.env` on production server with the new pair (`AWS_ACCESS_KEY_ID=<new>`, `AWS_SECRET_ACCESS_KEY=<new>`).
   - Inactivate the old key (do NOT delete yet — keep 24 h for rollback observation).
   - Run `aws cloudtrail lookup-events --lookup-attributes AttributeKey=AccessKeyId,AttributeValue=AKIAYJOT77SIZHDXNYOZ --start-time 2026-05-13T00:00:00Z --max-items 200 > /tmp/cloudtrail-leaked-key.json` ; if any unexpected `eu-west-3` or `us-east-1` API call appears, escalate.
   - After 24 h with no breakage : delete the old key.
2. **Owner — APP_KEY rotation** (≤10 min, schedule for low-traffic window) :
   - `php artisan key:generate --show` on staging first ; copy output.
   - Communicate to ops : "tous les staff seront déconnectés" (encrypted sessions/cookies invalidated).
   - On prod : `php artisan key:generate --force` ; `php artisan cache:clear` ; `php artisan config:cache`.
3. **Owner — FISCAL secrets review** (≤30 min) :
   - Compare leaked `FISCAL_AUDIT_SECRET=local-e2e-fiscal-audit-secret-padding-48chars-ok-20260427` against current `.env` on prod. The string `local-e2e-...` suggests dev sentinel — but verify via `php artisan tinker --execute 'echo md5(config("fiscal.audit_secret"));'` and compare md5 against `md5("local-e2e-fiscal-audit-secret-padding-48chars-ok-20260427")` = `5478d2e7d75ae18a2d3da5e91a9a4715`.
   - If md5 matches : prod is on the leaked dev sentinel → emergency : rotate `FISCAL_AUDIT_SECRET` + `FISCAL_Z_REPORT_SECRET`, run `php artisan foodking:fiscal:rehash --dry-run` then real (`AuditLogService::rotateSecret()` if exists, else stop the chain at next Z + start a new one with the new HMAC). Document NF525-mandated procedure in `docs/runbooks/RUNBOOK_FISCAL_SECRET_ROTATION.md` (currently absent).
   - If md5 doesn't match : prod uses different secret → leaked secret is dev-only, safe to ignore.

**Claude-actionable companion fix** : write `docs/runbooks/RUNBOOK_AWS_KEY_ROTATION.md` (paste-ready) and update `reports/audit/cto-global-2026-05-16/SECRETS_TO_ROTATE.md` (already in dispatch pack scope).

**Git history scrubbing (optional, recommended if repo is/was public)** :
```bash
# Backup before rewrite
git clone --mirror . ../foodking-bare-backup-pre-scrub
# Use git-filter-repo (apt install git-filter-repo or brew install git-filter-repo)
cd ..
git clone --mirror <origin-url> foodking-scrub.git
cd foodking-scrub.git
git filter-repo --invert-paths --path .env.backup-pre-round2 --force
git push --force --all
git push --force --tags
# Communicate to all collaborators : delete local clones, re-clone fresh
```

**Regression test** : N/A — rotation has no automated test, but DOMAIN gating is enforced by gitleaks CI (D1-S-02).

**CI gate** : covered by D1-S-02 (gitleaks).

**Verification commands (owner)** :
```bash
# Owner verifies the leaked key is dead
aws sts get-caller-identity --access-key-id AKIAYJOT77SIZHDXNYOZ --secret-access-key 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 2>&1 | grep -q 'InvalidClientTokenId' && echo "OK: leaked key dead" || echo "FAIL: leaked key still valid"

# Verify APP_KEY changed (md5 of base64-decoded form)
php artisan tinker --execute 'echo "APP_KEY md5: " . md5(config("app.key")) . PHP_EOL;'
```

**Rollback** (if rotation breaks prod) :
1. `aws iam update-access-key --access-key-id AKIAYJOT77SIZHDXNYOZ --status Active` (re-activate the old key)
2. Revert `.env` AWS keys via `cp .env.bak-pre-rotation .env && php artisan config:cache`
3. Restart php-fpm + queue:work : `sudo systemctl restart php8.2-fpm supervisord`
- **Time** : 5 min
- **Data loss risk** : None (rotation is reversible until old key deleted)

**Acceptance criteria** :
- [ ] `aws sts get-caller-identity --access-key-id AKIAYJOT77SIZHDXNYOZ ...` returns `InvalidClientTokenId`
- [ ] `git log --all -S 'AKIAYJOT77SIZHDXNYOZ'` still shows commits (if scrub deferred) BUT new commits do not contain the key
- [ ] `docs/runbooks/RUNBOOK_AWS_KEY_ROTATION.md` exists and is `Status: SIGNED_BY_OWNER_2026-XX-XX`
- [ ] `reports/audit/cto-global-2026-05-16/SECRETS_TO_ROTATE.md` updated with rotation completion checkboxes
- [ ] CloudTrail lookup for the leaked key shows zero unexpected calls 2026-05-13 → today

**Dependencies** : independent ; do FIRST (other domains depend on no fresh leaks).

**Estimated effort** : 30 min Claude (runbook + secrets-to-rotate doc update) + 60 min owner wall-clock (AWS console + CloudTrail review).

---

### FINDING D1-S-02 — NO SECRET-SCAN CI GATE (allows regression) — P0

**Current state (re-verified)** :
- `.github/workflows/` contains : `ci-sync-rupture-harness.yml`, `legacy-guards.yml`, `phpunit.yml`, `playwright.yml`, `vitest.yml` — **no security workflow**.
- No `.githooks/pre-commit` exists.
- `composer audit` not invoked anywhere in CI.
- `npm audit` not invoked anywhere in CI.
- A leak today would only be caught post-merge by manual `git log -S` audit.

**Attack scenario** : Any contributor commits a key/.env file → merged silently → in git history forever. Empirically already happened with `AKIAYJOT77SIZHDXNYOZ`.

**Root cause** : CI pipeline grew organically around tests/playwright/legacy guards ; security gating was never bolted on.

**Fix plan — paste-ready** :

**File** : `.github/workflows/security-scan.yml` (NEW)
```yaml
name: security-scan

on:
  push:
    branches: [main, 'feature/**', 'sprint/**']
  pull_request:
    branches: [main]
  schedule:
    - cron: '0 7 * * 1' # weekly Monday 07:00 UTC

permissions:
  contents: read
  pull-requests: write

jobs:
  gitleaks:
    name: gitleaks (secret scan)
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
        with:
          fetch-depth: 0
      - uses: gitleaks/gitleaks-action@v2
        env:
          GITHUB_TOKEN: ${{ secrets.GITHUB_TOKEN }}
          GITLEAKS_CONFIG: .gitleaks.toml

  composer-audit:
    name: composer audit (PHP CVEs)
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with: { php-version: '8.2', tools: composer:v2 }
      - run: composer install --no-progress --prefer-dist --no-dev
      - run: composer audit --format=json > composer-audit.json || true
      - run: |
          high_count=$(jq '[.advisories[] | select(.severity == "high" or .severity == "critical")] | length' composer-audit.json)
          echo "High/critical advisories: $high_count"
          if [ "$high_count" -gt 0 ]; then
            jq '.advisories[] | select(.severity == "high" or .severity == "critical")' composer-audit.json
            exit 1
          fi

  npm-audit:
    name: npm audit (JS CVEs)
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: actions/setup-node@v4
        with: { node-version: '20' }
      - run: npm ci --no-audit
      - run: npm audit --audit-level=high --omit=dev

  custom-grep-patterns:
    name: custom secret patterns
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
        with: { fetch-depth: 1 }
      - name: AWS Access Key ID
        run: |
          if git grep -nE 'AKIA[0-9A-Z]{16}' -- . ':!*.md' ':!reports/audit/**' ':!.gitleaks.toml'; then
            echo "ERROR: AWS Access Key ID found in tracked files" && exit 1
          fi
      - name: Stripe live keys
        run: |
          if git grep -nE 'sk_live_[0-9a-zA-Z]{24,}' -- . ':!*.md' ':!reports/audit/**' ':!.gitleaks.toml'; then
            echo "ERROR: Stripe live key found" && exit 1
          fi
      - name: Wildcard Sanctum token (regression sentinel D2-S-04)
        run: |
          hits=$(grep -rn "createToken(.*\['\*'\]" app/ --include='*.php' | grep -v 'E2EStressCommand' || true)
          if [ -n "$hits" ]; then
            echo "ERROR: wildcard ['*'] Sanctum token found (CI lint D2-S-04 regression)"
            echo "$hits" && exit 1
          fi
```

**File** : `.gitleaks.toml` (NEW)
```toml
title = "FoodKing gitleaks config"

[extend]
useDefault = true

[[rules]]
id = "foodking-fiscal-secret"
description = "FoodKing fiscal HMAC secrets"
regex = '''FISCAL_(AUDIT|Z_REPORT)_SECRET\s*=\s*[A-Za-z0-9_+\-=]{16,}'''
tags = ["secret", "foodking"]

[[rules]]
id = "foodking-mix-api-key-real"
description = "MIX_API_KEY non-default value"
regex = '''MIX_API_KEY\s*=\s*(?!change-me-long-random-string-local-dev)[A-Za-z0-9_+\-=]{16,}'''
tags = ["secret", "foodking"]

[[rules]]
id = "senangpay-secret"
description = "SenangPay secret key"
regex = '''SENANGPAY_(SECRET|MERCHANT_ID)\s*=\s*[A-Za-z0-9_+\-=]{8,}'''
tags = ["secret", "foodking", "senangpay"]

[allowlist]
description = "Allow audit reports and test fixtures"
paths = [
  '''reports/audit/.*\.md''',
  '''tests/.*Fixture\.php''',
  '''docs/.*\.md''',
  '''\.env\.example''',
]
```

**File** : `.githooks/pre-commit` (NEW, owner installs once)
```bash
#!/usr/bin/env bash
set -euo pipefail
# FoodKing pre-commit secret scanner
# Install: git config core.hooksPath .githooks && chmod +x .githooks/pre-commit
if command -v gitleaks >/dev/null 2>&1; then
  gitleaks protect --staged --config .gitleaks.toml --verbose --redact || {
    echo "ERROR: gitleaks detected secrets in staged files. Commit aborted."
    echo "Run: gitleaks protect --staged --config .gitleaks.toml --verbose"
    exit 1
  }
else
  echo "WARN: gitleaks not installed. Install via 'brew install gitleaks' or 'apt-get install gitleaks'"
fi
# AWS key sentinel (cheap regex)
if git diff --cached --name-only | xargs -I{} grep -lE 'AKIA[0-9A-Z]{16}' {} 2>/dev/null; then
  echo "ERROR: AWS Access Key ID detected in staged files. Commit aborted."
  exit 1
fi
```

**File** : `docs/onboarding/SETUP_PRE_COMMIT.md` (NEW)
```markdown
# Pre-commit secret scanner setup (mandatory)

1. Install gitleaks :
   - macOS: `brew install gitleaks`
   - Linux: `curl -sSfL https://raw.githubusercontent.com/gitleaks/gitleaks/master/install.sh | sh -s -- -b /usr/local/bin`
2. Enable repo hook : `git config core.hooksPath .githooks && chmod +x .githooks/pre-commit`
3. Test : create a fake `.env.test` with `AWS_ACCESS_KEY_ID=AKIA0000FAKE0000TEST` ; `git add .env.test && git commit -m "test"` should be **rejected**.
```

**Regression test (manual + CI)** :
```bash
# RED: write a fake commit
git checkout -b test/gitleaks-red
echo 'AWS_ACCESS_KEY_ID=AKIA0000FAKE0000TEST' > .env.regression-test
git add .env.regression-test
# pre-commit should reject
git commit -m "test gitleaks" 2>&1 | grep -q 'gitleaks detected' && echo OK || echo FAIL
# CI workflow run on push should also FAIL (gitleaks-action returns non-zero)
```

**CI gate** : the workflow above. Add to required-status-checks in GitHub branch protection : `gitleaks`, `composer-audit`, `npm-audit`, `custom-grep-patterns`.

**Verification commands** :
```bash
# Local
gitleaks detect --config .gitleaks.toml --no-banner --redact
composer audit
npm audit --audit-level=high --omit=dev

# CI : push to a feature branch and observe security-scan workflow status
gh workflow run security-scan.yml --ref feature/<branch>
gh run watch
```

**Rollback** :
1. `git rm .github/workflows/security-scan.yml .gitleaks.toml .githooks/pre-commit docs/onboarding/SETUP_PRE_COMMIT.md`
2. `git config --unset core.hooksPath` (each developer locally)
3. `git push`
- **Time** : 2 min
- **Data loss risk** : None (CI infra only)

**Acceptance criteria** :
- [ ] `.github/workflows/security-scan.yml` present, runs on every PR + push
- [ ] gitleaks job RED on fake `AKIA0000FAKE0000TEST` commit
- [ ] gitleaks job GREEN after removing fake file
- [ ] composer audit job RED if introducing `phpoffice/phpspreadsheet@1.30.0` deliberately
- [ ] npm audit job RED on `high|critical` vulnerability
- [ ] `.githooks/pre-commit` documented in `docs/onboarding/SETUP_PRE_COMMIT.md`
- [ ] GitHub branch protection on `main` requires all 4 jobs

**Dependencies** : independent of D1-S-01 (can deploy gate before rotation completes — caught regressions are caught either way).

**Estimated effort** : 2 h Claude + 30 min owner (branch protection + pre-commit local install per dev).

---

### FINDING D1-S-03 — `MIX_API_KEY` LEAKED IN HTML BODY (defense theater) — P1

**Current state (re-verified)** :
- `resources/views/master.blade.php:109` : `apiKey: @json((string) config('app.api_key'))` injects the key into `window.foodkingConfig.apiKey`.
- `resources/views/admin-pos-v4.blade.php:96-98` : same pattern.
- `app/Http/Middleware/ApiKeyMiddleware.php:24` : `$request->header('x-api-key') === $validApiKey` — non-constant-time comparison (timing attack possible but academic given full key already in HTML).
- The middleware appears in the `/api/admin/*` group (`routes/api.php:269`) alongside `auth:sanctum`. **Sanctum is the actual gate**. `apiKey` middleware is theater.

**Attack scenario** : Open `view-source:`, copy `apiKey`, build a curl with `x-api-key: <copied>` → middleware passes. But Sanctum still requires a Bearer token, so attacker needs a token (via login or guest-OTP). No new attack surface — but the deploy documentation (`.env.example:57`) claims this header is `[CRITICAL] ... pour /api/*` which is a documentation-grade lie that misleads ops about defense in depth.

**Root cause** : Upstream legacy pattern from the codebase's pre-Sanctum days. Never reviewed when Sanctum was added.

**Fix plan — two acceptable options ; recommend Option A** :

**Option A — delete the middleware (recommended, ≤30 LOC delete)** :
```php
// File: app/Http/Kernel.php (locate $routeMiddleware array)
// REMOVE: 'apiKey' => \App\Http\Middleware\ApiKeyMiddleware::class,

// File: routes/api.php (apply across all 'apiKey' references)
// Before: ->middleware(['installed', 'apiKey', 'auth:sanctum', ...])
// After:  ->middleware(['installed', 'auth:sanctum', ...])

// File: resources/views/master.blade.php:109 — REMOVE the apiKey line
//   apiKey: @json((string) config('app.api_key')),
// After: (line deleted)

// File: resources/views/admin-pos-v4.blade.php:96-98 — same removal

// File: app/Http/Middleware/ApiKeyMiddleware.php — DELETE the file
```

**Option B — keep the middleware but stop leaking the key (≤60 LOC)** :
```php
// resources/views/master.blade.php:109
// Before:
apiKey: @json((string) config('app.api_key')),
// After: (DELETED — never inject)

// app/Http/Middleware/ApiKeyMiddleware.php:24
// Before:
if ($request->header('x-api-key') === $validApiKey) {
// After:
if (hash_equals((string) $validApiKey, (string) $request->header('x-api-key'))) {

// Optional: derive per-tenant signed token instead of static MIX_API_KEY
//   See docs/security/SANCTUM_ABILITIES_MATRIX.md (to be written in D2)
```

**Regression test** :
```php
// File: tests/Feature/Security/MixApiKeyNotLeakedTest.php (NEW)
<?php
namespace Tests\Feature\Security;
use Tests\TestCase;

class MixApiKeyNotLeakedTest extends TestCase
{
    /** @test */
    public function master_blade_does_not_inject_mix_api_key_into_html()
    {
        $response = $this->get('/');
        $body = (string) $response->getContent();
        $this->assertStringNotContainsString(
            (string) config('app.api_key'),
            $body,
            'MIX_API_KEY leaked into HTML body — DOMAIN 1 D1-S-03 regression'
        );
        $this->assertStringNotContainsString('apiKey:', $body, 'window.foodkingConfig.apiKey should not exist');
    }

    /** @test */
    public function pos_blade_does_not_inject_mix_api_key_into_html()
    {
        $this->actingAs($this->makePosUser());
        $response = $this->get('/admin/pos');
        $body = (string) $response->getContent();
        $this->assertStringNotContainsString(
            (string) config('app.api_key'),
            $body,
            'MIX_API_KEY leaked into POS HTML — D1-S-03 regression'
        );
    }
}
```

**CI gate** :
```yaml
# Add to .github/workflows/security-scan.yml under custom-grep-patterns
- name: MIX_API_KEY injection sentinel
  run: |
    if grep -rn "apiKey:.*config('app.api_key')" resources/views/; then
      echo "ERROR: MIX_API_KEY injection into HTML detected (D1-S-03 regression)"
      exit 1
    fi
```

**Verification commands** :
```bash
# View any rendered page, ensure key absent
php artisan serve --port 8001 &
sleep 2
curl -s http://localhost:8001/ | grep -E 'apiKey|api_key' && echo FAIL || echo OK
pkill -f 'php artisan serve --port 8001'

# Run feature test
./vendor/bin/phpunit --filter MixApiKeyNotLeakedTest
```

**Rollback** :
1. `git revert <commit-removing-apikey-middleware>`
2. `php artisan config:cache && php artisan route:cache`
3. Restart php-fpm
- **Time** : 5 min
- **Data loss risk** : None ; revert restores legacy theater

**Acceptance criteria** :
- [ ] `grep -rn "apiKey:" resources/views/` returns 0 hits
- [ ] `grep -rn "ApiKeyMiddleware" app/Http/Kernel.php` returns 0 hits (Option A)
- [ ] `tests/Feature/Security/MixApiKeyNotLeakedTest.php` GREEN
- [ ] No `/api/admin/*` endpoint becomes openly accessible without Bearer token (manual curl test from §verification)
- [ ] `.env.example:57` claim `[CRITICAL] ... pour /api/*` removed or rewritten

**Dependencies** : D1-S-02 (CI gate must exist to enforce). Best done WITH D2-S-04 (token detonation) since both touch the auth perimeter narrative.

**Estimated effort** : 1.5 h Claude (Option A) ; 4 h Claude (Option B with per-tenant signed tokens).

---

### FINDING D1-S-04 — Untracked .env variants list incomplete in .env.example — P2

**Current state** : `.gitignore` already protects `.env`, `.env.codex`, `.env.cursor`, `.env.backup`, `.env.backup-*`, `.env.backup.*`, `.env.testing`, `.env.anthropic.local`, `.env.orcai`, `.env.local`, `.env.local.*`, `.env.production`, `.env.production.*`, `.env.staging`, `.env.staging.*`. **Good coverage**. `.env.example` is tracked (intentional) but does not document that `.env.*` are all gitignored ; new contributors may not know.

**Fix plan** : add a header comment to `.env.example` :
```bash
# File: .env.example (top of file, after line 1)
# -----------------------------------------------------------------------------
# IMPORTANT: This is the ONLY .env* file tracked in git.
# All other .env.* variants (.env, .env.local, .env.production, etc.) are
# gitignored. NEVER commit real secrets to this file — use placeholders only.
# CI workflow .github/workflows/security-scan.yml will block leaks.
# -----------------------------------------------------------------------------
```

**Regression test** : covered by D1-S-02 gitleaks rule allowlist for `.env.example`.

**Effort** : 5 min Claude.

---

## §2 DOMAIN 2 — AUTH & TOKENS

### Domain summary
| Metric | Current | Target |
|---|---|---|
| Score | 20/100 | 75/100 |
| Wildcard `['*']` token sites in production code | 3 (Login + GuestSignup + ForgotPassword) | 0 |
| Wildcard sites in dev/CI tooling | 1 (E2EStressCommand) | 1 with documented CI lint exemption |
| `tokenCan('kiosk:order')` callers using wildcard | 14 callers all bypassed by `['*']` | 0 bypassable |
| Brute-force protection on login | `LOGIN_LOCKOUT_MAX_ATTEMPTS=10 / 10 min` | unchanged ✅ |
| OTP throttle | `throttle:3,5` on guest-OTP routes | unchanged ✅ |
| Token revocation on relogin | LoginController ✅ (Wave Z 5D Z6-01) ; KioskMachineLoginController ✅ | unchanged |
| Refresh token endpoint | exists (`RefreshTokenController:49`) ; preserves abilities (good) | unchanged |

**Top 3 priorities** :
1. **Detonate `['*']` tokens** at LoginController, GuestSignupController, ForgotPasswordController.
2. **Role→abilities helper** `App\Support\TokenAbilityResolver`.
3. **Migration to revoke existing wildcard tokens** + CI lint to block regression.

**Total effort** : ~14 Claude-h.

---

### FINDING D2-S-04 — SANCTUM `['*']` WILDCARD ABILITIES (3 sites) — P0

**Current state (re-verified)** :
- `app/Http/Controllers/Auth/LoginController.php:96-100` — `createToken('auth_token', ['*'], ...)` — admin/staff login.
- `app/Http/Controllers/Auth/GuestSignupController.php:140` — `createToken('auth_token', ['*'], now()->addDays(30))` — guest customer OTP, 30 days TTL.
- `app/Http/Controllers/Auth/ForgotPasswordController.php:165-169` — `createToken('auth_token', ['*'], ...)` — post-password-reset, 480 min TTL.
- `app/Console/Commands/E2EStressCommand.php:222` — `createToken('stress-pos', ['*'])` — dev/CI only, will be exempted in CI lint.
- `app/Http/Controllers/Auth/RefreshTokenController.php:49` — preserves `$token->abilities ?? []` (good, never widens) ✅
- `app/Http/Controllers/Auth/KioskMachineLoginController.php:98-102` — uses `['kiosk:order']` (correct pattern) ✅

14 callers grep `tokenCan('kiosk:order')` in resources, requests, controllers, services. All currently bypassable by `['*']` tokens.

**Attack scenario** : Guest customer signs up via OTP (phone + 4-digit code, throttle 3/5min). Receives a 30-day `['*']` token. `$user->tokenCan('kiosk:order')` returns true (since `['*']` matches every ability). Now the guest can call any endpoint gated only by `tokenCan('kiosk:order')` (e.g. `OrderRequest::authorize`, `MenuController::syncFor(...)`). Combined with `app/Http/Requests/` 87/93 returning `true` (D3-S-11), the practical permission boundary is "is the route gated by `permission:settings` middleware" — and 16/N routes are not.

**Root cause** : Quick-start Laravel pattern from Sanctum docs. Never refined when role-based abilities became necessary.

**Fix plan — paste-ready** :

**Step 1 — Create `app/Support/TokenAbilityResolver.php`** :
```php
<?php

namespace App\Support;

use App\Models\User;
use App\Enums\EnumRole;

class TokenAbilityResolver
{
    /**
     * Resolve the Sanctum abilities a user's PAT should be granted based
     * on their Spatie roles. Never returns ['*'].
     *
     * Map (canonical, 2026-05-16) :
     *   Admin           => admin:*, pos:order, kds:view, oss:view
     *   Branch Manager  => admin:branch, pos:order, pos:report, kds:view
     *   POS Operator    => pos:order, pos:report, kds:view
     *   Chef            => kds:bump, kds:view
     *   Waiter          => pos:order, kds:view, oss:view
     *   Delivery Boy    => delivery:assign, delivery:complete
     *   Customer        => customer:order, customer:profile
     *   Kiosk Machine   => kiosk:order  (issued by KioskMachineLoginController, untouched)
     */
    public static function for(User $user): array
    {
        $roles = $user->roles()->pluck('name')->map(fn ($n) => (string) $n)->all();

        if (in_array(EnumRole::ADMIN, $roles, true)) {
            return ['admin:*', 'pos:order', 'kds:view', 'oss:view'];
        }
        if (in_array(EnumRole::BRANCH_MANAGER, $roles, true)) {
            return ['admin:branch', 'pos:order', 'pos:report', 'kds:view'];
        }
        if (in_array(EnumRole::POS_OPERATOR, $roles, true)) {
            return ['pos:order', 'pos:report', 'kds:view'];
        }
        if (in_array(EnumRole::CHEF, $roles, true)) {
            return ['kds:bump', 'kds:view'];
        }
        if (in_array(EnumRole::WAITER, $roles, true)) {
            return ['pos:order', 'kds:view', 'oss:view'];
        }
        if (in_array(EnumRole::DELIVERY_BOY, $roles, true)) {
            return ['delivery:assign', 'delivery:complete'];
        }
        if (in_array(EnumRole::CUSTOMER, $roles, true)) {
            return ['customer:order', 'customer:profile'];
        }
        // Default : minimal (matches "user with no role" defensive path)
        return [];
    }
}
```

**Step 2 — Patch `LoginController.php:96-100`** :
```php
// Before
$this->token = $user->createToken(
    'auth_token',
    ['*'],
    now()->addMinutes((int) config('sanctum.expiration', 480))
)->plainTextToken;

// After
$this->token = $user->createToken(
    'auth_token',
    \App\Support\TokenAbilityResolver::for($user),
    now()->addMinutes((int) config('sanctum.expiration', 480))
)->plainTextToken;
```

**Step 3 — Patch `GuestSignupController.php:140`** :
```php
// Before
$this->token = $user->createToken('auth_token', ['*'], now()->addDays(30))->plainTextToken;

// After (guest = customer role)
$this->token = $user->createToken(
    'auth_token',
    ['customer:order', 'customer:profile'],
    now()->addDays(30)
)->plainTextToken;
```

**Step 4 — Patch `ForgotPasswordController.php:165-169`** :
```php
// Before
$this->token = $user->createToken(
    'auth_token',
    ['*'],
    now()->addMinutes((int) config('sanctum.expiration', 480))
)->plainTextToken;

// After
$this->token = $user->createToken(
    'auth_token',
    \App\Support\TokenAbilityResolver::for($user),
    now()->addMinutes((int) config('sanctum.expiration', 480))
)->plainTextToken;
```

**Step 5 — Migration `database/migrations/2026_05_17_000001_revoke_wildcard_tokens.php`** :
```php
<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * D2-S-04 : revoke all personal_access_tokens that carry the wildcard
     * ability ['*']. Forces every user to re-login once post-deploy, at
     * which point LoginController issues a properly role-scoped PAT.
     */
    public function up(): void
    {
        // SQLite test path : abilities stored JSON-encoded as a string
        DB::table('personal_access_tokens')
            ->where('abilities', 'like', '%"*"%')
            ->delete();
    }

    public function down(): void
    {
        // Rollback would re-grant wildcard abilities to nothing in particular;
        // safer to no-op (users re-login to get proper scoped tokens).
    }
};
```

**Step 6 — Update tokenCan() callers to also accept the matching role ability** :
Most callers already use `tokenCan('kiosk:order')`. After D2-S-04, kiosk-machine tokens still carry `['kiosk:order']` (KioskMachineLoginController unchanged), and guest customers carry `['customer:order']`. Add a small helper :
```php
// File: app/Support/TokenAbilityCheck.php (NEW)
<?php
namespace App\Support;

use Illuminate\Contracts\Auth\Authenticatable;

class TokenAbilityCheck
{
    public static function canCustomerOrder(?Authenticatable $user): bool
    {
        if (! $user) return false;
        if (! method_exists($user, 'tokenCan')) return false;
        return $user->tokenCan('kiosk:order') || $user->tokenCan('customer:order');
    }
}
```
Then in 14 callers (grep `tokenCan('kiosk:order')`), replace with `TokenAbilityCheck::canCustomerOrder($user)`. Keep `kiosk:order` literal for kiosk-machine specific gates (e.g. menu cache, machine identity).

**Regression tests** :
```php
// File: tests/Feature/Security/SanctumWildcardAbilityTest.php (NEW)
<?php
namespace Tests\Feature\Security;

use App\Models\User;
use App\Enums\EnumRole;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class SanctumWildcardAbilityTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function login_does_not_grant_wildcard_token(): void
    {
        $user = User::factory()->create(['password' => bcrypt('test1234')]);
        $user->assignRole(EnumRole::POS_OPERATOR);

        $response = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'test1234',
        ]);

        $response->assertStatus(200);
        $token = $user->tokens()->where('name', 'auth_token')->latest()->first();
        $this->assertNotNull($token);
        $this->assertNotContains('*', $token->abilities ?? []);
        $this->assertContains('pos:order', $token->abilities);
    }

    /** @test */
    public function guest_signup_does_not_grant_wildcard(): void
    {
        // Simulate post-OTP guest signup completion
        $user = User::factory()->create();
        $user->assignRole(EnumRole::CUSTOMER);

        $abilities = \App\Support\TokenAbilityResolver::for($user);
        $this->assertNotContains('*', $abilities);
        $this->assertEqualsCanonicalizing(['customer:order', 'customer:profile'], $abilities);
    }

    /** @test */
    public function migration_revokes_existing_wildcard_tokens(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('legacy', ['*'])->accessToken;
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $token->id]);

        $this->artisan('migrate', ['--path' => 'database/migrations/2026_05_17_000001_revoke_wildcard_tokens.php']);

        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $token->id]);
    }

    /** @test */
    public function customer_token_cannot_hit_admin_endpoints(): void
    {
        $user = User::factory()->create();
        $user->assignRole(EnumRole::CUSTOMER);
        Sanctum::actingAs($user, ['customer:order', 'customer:profile']);

        $response = $this->getJson('/api/admin/item');
        $response->assertStatus(403);
    }
}
```

**CI gate** : already in D1-S-02 workflow under `custom-grep-patterns` :
```yaml
- name: Wildcard Sanctum token (regression sentinel D2-S-04)
  run: |
    hits=$(grep -rn "createToken(.*\['\*'\]" app/ --include='*.php' | grep -v 'E2EStressCommand' || true)
    if [ -n "$hits" ]; then
      echo "ERROR: wildcard ['*'] Sanctum token found (CI lint D2-S-04 regression)"
      echo "$hits" && exit 1
    fi
```

**Verification commands** :
```bash
# 1. Tests pass
./vendor/bin/phpunit --filter SanctumWildcardAbilityTest

# 2. CI lint pre-flight
grep -rn "createToken(.*\['\*'\]" app/ --include='*.php' | grep -v 'E2EStressCommand'
# Expected: no output

# 3. Tinker check on existing tokens (post-migration)
php artisan tinker --execute '
  $n = \App\Models\User::join("personal_access_tokens", "users.id", "=", "personal_access_tokens.tokenable_id")
       ->where("personal_access_tokens.abilities", "like", "%\"*\"%")
       ->count();
  echo "Wildcard tokens still alive: $n" . PHP_EOL;
'
# Expected: 0
```

**Rollback** :
1. `git revert <commit-d2-s-04>` (reverts TokenAbilityResolver + 3 controller patches)
2. `php artisan config:cache && php artisan route:cache`
3. The migration `revoke_wildcard_tokens` cannot be undone — users will re-login fresh after rollback ; new tokens will be wildcard again. **Tolerable.**
- **Time** : 5 min
- **Data loss risk** : None ; all token data is recoverable by re-auth

**Acceptance criteria** :
- [ ] `grep -rn "createToken(.*\['\*'\]" app/ --include='*.php' | grep -v 'E2EStressCommand'` returns 0 hits
- [ ] `tests/Feature/Security/SanctumWildcardAbilityTest.php` 4/4 GREEN
- [ ] Migration `2026_05_17_000001_revoke_wildcard_tokens.php` ran successfully (`php artisan migrate:status`)
- [ ] `App\Support\TokenAbilityResolver` exists with all 7 role branches
- [ ] `docs/security/SANCTUM_ABILITIES_MATRIX.md` published with role→abilities table
- [ ] All 14 `tokenCan('kiosk:order')` callers reviewed (some unchanged for kiosk-machine identity ; customer-flow callers updated to `TokenAbilityCheck::canCustomerOrder`)
- [ ] One full staff re-login wave executed post-deploy ; no `personal_access_tokens` with `abilities` containing `"*"` remains

**Dependencies** : Should ship WITH D4-S-05 (RCE patch), since both close the "guest OTP → admin RCE" combined chain. Order : D1-S-01 (rotate) → D4-S-05 (RCE quarantine) → D2-S-04 (token detonation).

**Estimated effort** : ~6 h Claude (resolver + 3 controller patches + migration + 4 tests + 14 caller review) + 30 min owner (deploy + force-relogin comms).

---

### FINDING D2-S-12 — Refresh + revocation already correct (NO ACTION) — ✅

**Current state (re-verified)** :
- `RefreshTokenController.php:49` preserves `$token->abilities ?? []` defensively, never widens to `['*']`. Good.
- `LoginController.php:94` revokes prior `auth_token` rows on relogin (Wave Z 5D Z6-01, commit 56204f052). Good.
- `KioskMachineLoginController.php:96` revokes `kiosk-token` rows on relogin. Good.

**Status** : No new work. Document in §9 stale-findings as **partial coverage already complete**.

---

### FINDING D2-S-13 — Brute force protection adequate (NO NEW ACTION) — ✅

**Current state** :
- `.env.example:47-48` : `LOGIN_LOCKOUT_MAX_ATTEMPTS=10`, `LOGIN_LOCKOUT_DECAY_MINUTES=10` (production override possible).
- OTP routes `routes/api.php:177, 190` carry `throttle:3,5` (3 attempts / 5 min).
- Per Agent 2 §POSITIVES : "Throttle limits on auth/OTP: 3-per-5min on OTP verify is the right defense vs 4-digit OTP brute force." 

**Status** : No new work. Confirm config doc in `docs/security/AUTH_HARDENING.md` (low-priority follow-up).

---

## §3 DOMAIN 3 — AUTHORIZATION & IDOR

### Domain summary
| Metric | Current | Target |
|---|---|---|
| Score | 25/100 | 70/100 |
| `withoutGlobalScope(BranchScope::class)` total | **11** (audit claimed 39 — STALE, the 39 includes legitimate `withoutGlobalScopes()` for User/fiscal/console contexts) | 10 documented & assertion-guarded + 0 illegitimate |
| Illegitimate IDOR | 1 (`PosOrderController::show`) | 0 |
| FormRequests with `authorize() = true;` only | 87/93 (94%) | 63/93 (top-24 hardened) ; 100% via CI lint |
| Cross-branch assertion helpers | 0 | 1 `App\Support\BranchAssertion::sameBranch($model, $user)` |

**Top 3 priorities** :
1. **PosOrderController IDOR fix** + assertion.
2. **Triage 11 BranchScope sites** (most are legitimate ; document each ; add assertion or scope-aware re-fetch).
3. **20 highest-risk FormRequests** → Gate-backed authorize() (covers admin catalog mutations + order destroy + payment confirm + cash drawer).

**Total effort** : ~16 Claude-h.

---

### FINDING D3-S-06 — PosOrderController::show IDOR — P0

**Current state (re-verified)** :
`app/Http/Controllers/Admin/PosOrderController.php:108` :
```php
$order = Order::withoutGlobalScope(BranchScope::class)->findOrFail($order);
```
No `branch_id` assertion follows. `OrderDetailsResource` exposes `customer name, phone, address, total, payment method, composition_snapshot`.

**Attack scenario** : POS user in branch 17 → `GET /api/admin/pos-order/show/<id>` iterating `<id>` — reveals every other branch's orders (PII, fiscal mismatch potential). Auto-incrementing IDs ; trivial scrape.

**Root cause** : Copy-pasted defensive pattern (`withoutGlobalScope` to handle multi-branch admin lookups) without re-asserting the caller's branch.

**Fix plan — paste-ready** :
```php
// File: app/Http/Controllers/Admin/PosOrderController.php:104-115
// Before
public function show(int|string $order): \Illuminate\Http\Response|OrderDetailsResource|\Illuminate\Contracts\Foundation\Application|\Illuminate\Contracts\Routing\ResponseFactory
{
    try {
        $order = Order::withoutGlobalScope(BranchScope::class)->findOrFail($order);
        return new OrderDetailsResource($this->orderService->show($order, false));
    } catch (HttpException $http) {
        throw $http;
    } catch (Exception $exception) {
        return response(['status' => false, 'message' => $exception->getMessage()], 422);
    }
}

// After
public function show(int|string $order): \Illuminate\Http\Response|OrderDetailsResource|\Illuminate\Contracts\Foundation\Application|\Illuminate\Contracts\Routing\ResponseFactory
{
    try {
        // [D3-S-06 CTO audit 2026-05-16] Replace withoutGlobalScope() bypass
        // with branch-aware lookup. Admin (branch_id=0) can read across
        // branches; staff (branch_id>0) is restricted to their own branch.
        $caller = auth()->user();
        $isAdmin = (int) ($caller->branch_id ?? 0) === 0;

        $order = $isAdmin
            ? Order::withoutGlobalScope(BranchScope::class)->findOrFail($order)
            : Order::findOrFail($order);  // BranchScope auto-applies

        // Defense in depth: assert match even for admin path
        if (! $isAdmin && (int) $order->branch_id !== (int) $caller->branch_id) {
            \App\Services\Audit\AuditLogger::log('idor.attempt', [
                'caller_id' => $caller->id,
                'caller_branch' => $caller->branch_id,
                'requested_order' => $order->id,
                'order_branch' => $order->branch_id,
            ]);
            abort(404);  // 404 not 403, avoid id-existence leak
        }

        return new OrderDetailsResource($this->orderService->show($order, false));
    } catch (HttpException $http) {
        throw $http;
    } catch (Exception $exception) {
        return response(['status' => false, 'message' => $exception->getMessage()], 422);
    }
}
```

**Regression test** :
```php
// File: tests/Feature/Security/PosOrderShowIdorTest.php (NEW)
<?php
namespace Tests\Feature\Security;

use App\Models\User;
use App\Models\Order;
use App\Models\Branch;
use App\Enums\EnumRole;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class PosOrderShowIdorTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function pos_user_in_branch_a_cannot_read_order_from_branch_b(): void
    {
        [$branchA, $branchB] = Branch::factory()->count(2)->create();

        $posUser = User::factory()->create(['branch_id' => $branchA->id]);
        $posUser->assignRole(EnumRole::POS_OPERATOR);
        Sanctum::actingAs($posUser, \App\Support\TokenAbilityResolver::for($posUser));

        $foreignOrder = Order::factory()->create(['branch_id' => $branchB->id]);

        $response = $this->getJson('/api/admin/pos-order/show/' . $foreignOrder->id);

        $response->assertStatus(404);  // not 200, not 403 — 404 prevents id-leak
    }

    /** @test */
    public function pos_user_can_read_own_branch_order(): void
    {
        $branch = Branch::factory()->create();
        $posUser = User::factory()->create(['branch_id' => $branch->id]);
        $posUser->assignRole(EnumRole::POS_OPERATOR);
        Sanctum::actingAs($posUser, \App\Support\TokenAbilityResolver::for($posUser));

        $order = Order::factory()->create(['branch_id' => $branch->id]);

        $response = $this->getJson('/api/admin/pos-order/show/' . $order->id);
        $response->assertStatus(200);
        $response->assertJsonPath('id', $order->id);
    }

    /** @test */
    public function admin_can_read_any_branch_order(): void
    {
        $branch = Branch::factory()->create();
        $admin = User::factory()->create(['branch_id' => 0]);
        $admin->assignRole(EnumRole::ADMIN);
        Sanctum::actingAs($admin, \App\Support\TokenAbilityResolver::for($admin));

        $order = Order::factory()->create(['branch_id' => $branch->id]);

        $response = $this->getJson('/api/admin/pos-order/show/' . $order->id);
        $response->assertStatus(200);
    }
}
```

**CI gate** :
```yaml
# Add to .github/workflows/security-scan.yml under custom-grep-patterns
- name: BranchScope bypass without assertion sentinel (D3-S-06)
  run: |
    # Allow only the documented sites in the allowlist
    bypasses=$(grep -rn "withoutGlobalScope(BranchScope::class)" app/ --include='*.php' || true)
    while IFS= read -r line; do
      file=$(echo "$line" | cut -d: -f1)
      lineno=$(echo "$line" | cut -d: -f2)
      # Require a comment on the same or following 3 lines: 'BRANCH ASSERTION' or 'BRANCH BYPASS DOC'
      if ! sed -n "${lineno},+3p" "$file" | grep -qE 'BRANCH ASSERTION|BRANCH BYPASS DOC'; then
        echo "ERROR: $file:$lineno — withoutGlobalScope(BranchScope) without assertion comment"
        exit 1
      fi
    done <<< "$bypasses"
```

**Verification commands** :
```bash
./vendor/bin/phpunit --filter PosOrderShowIdorTest
# Also exercise live IDOR check
curl -H "Authorization: Bearer <pos-branch-A-token>" http://localhost:8000/api/admin/pos-order/show/<branch-B-order-id>
# Expected: HTTP 404
```

**Rollback** :
1. `git revert <commit>`
2. `php artisan route:cache`
3. Restart php-fpm
- **Time** : 5 min
- **Data loss risk** : None ; revert restores the IDOR (and the audit gap)

**Acceptance criteria** :
- [ ] `tests/Feature/Security/PosOrderShowIdorTest.php` 3/3 GREEN
- [ ] PosOrderController has the assertion + `AuditLogger::log('idor.attempt', ...)` call
- [ ] Manual curl test : branch-A POS user → branch-B order ID returns 404
- [ ] Audit log shows IDOR attempt entries when curl test runs
- [ ] `audit_logs` chain integrity preserved (`php artisan foodking:fiscal:audit-verify`)

**Dependencies** : Independent. Best done WITH D3-S-19 (BranchScope triage).

**Estimated effort** : 2 h Claude (code + 3 tests + audit log integration + CI lint update).

---

### FINDING D3-S-19 — 11 `withoutGlobalScope(BranchScope::class)` sites triage — P1

**Current state (re-verified)** : 11 sites total (audit claim of 39 was STALE — see §9). Inventory :

| # | File:line | Purpose | Verdict |
|---|---|---|---|
| 1 | `app/Models/KioskMachine.php:36` | Doc comment only | ✅ N/A |
| 2 | `app/Http/Controllers/Frontend/PaymentReconcileController.php:143` | Pre-auth payment lookup (kiosk machine identity) | ✅ LEGIT (machine-bound) |
| 3 | `PaymentReconcileController.php:194` | Locked re-fetch in transaction | ✅ LEGIT (paired with branch-known kiosk) |
| 4 | `PaymentReconcileController.php:232` | Fresh re-fetch | ✅ LEGIT (idem) |
| 5 | `PaymentReconcileController.php:247` | Failure-state re-fetch | ✅ LEGIT (idem) |
| 6 | `PaymentReconcileController.php:288` | `PendingPaymentConfirmation` lookup | ✅ LEGIT (idempotency key scope) |
| 7 | `app/Http/Controllers/Frontend/OrderController.php:159` | Locked order in payment commit | ✅ LEGIT (FrontendOrder bind) |
| 8 | `OrderController.php:184` | Duplicate-transaction guard | ✅ LEGIT (cross-branch dedupe scope) |
| 9 | `app/Http/Controllers/Admin/PosOrderController.php:108` | **IDOR** | ❌ FIX = D3-S-06 |
| 10 | `app/Jobs/CleanupStalePendingKioskOrders.php:30` | Background sweep job | ✅ LEGIT (no auth context) |
| 11 | `CleanupStalePendingKioskOrders.php:47` | Locked re-fetch in sweep | ✅ LEGIT |

**Fix plan** : annotate each legitimate site with a `BRANCH BYPASS DOC` comment, and the CI gate (D3-S-06 sentinel) enforces that comment exists for every bypass. The single illegitimate site (D3-S-06) is patched separately. Total annotation work : ~30 min Claude.

Example annotation pattern for site #2 (`PaymentReconcileController.php:143`) :
```php
// [BRANCH BYPASS DOC D3-S-19 #2 — 2026-05-16]
// Pre-auth lookup : the payer presents an order_uuid before any auth
// context. Branch resolution comes from the order itself. Verified safe :
// order is then bound to the kiosk machine token's branch in the next
// step (line 187). No cross-branch read possible from the public flow.
$order = FrontendOrder::withoutGlobalScope(BranchScope::class)
    ->where('uuid', $request->order_uuid)
    ->first();
```

Apply the same comment template to all 10 legitimate sites.

**Regression test** : CI lint (already in D3-S-06).

**Acceptance criteria** :
- [ ] All 11 sites annotated (10 LEGIT + 1 fixed)
- [ ] CI lint `BranchScope bypass without assertion sentinel` GREEN on every push

**Effort** : 30 min Claude.

---

### FINDING D3-S-11 — 87/93 FormRequests missing permission check — P1

**Current state (re-verified)** :
- 93 FormRequest classes (`find app/Http/Requests -name "*.php" | wc -l` = 93).
- 87 have `authorize() { return true; }` with no `tokenCan|hasRole|hasPermission|can(|Gate::` keyword (`find ... -exec grep -L ...` = 87).
- The "kiosk-flow" ones that DO check : `OrderRequest`, `OrderStatusRequest`, `PromoValidateRequest`, `PricingPreviewRequest`, `PaymentConfirmRequest`, plus 1 other = 6 total.

**Attack scenario** : A future routes refactor that drops `permission:settings` from any admin group silently widens 20+ endpoints to any authenticated caller. Combined with D2-S-04 (currently fixed) + any future regression that re-introduces wildcard tokens → admin permission bypass.

**Root cause** : FormRequest authorize() pattern was never adopted as a primary authz gate — middleware was relied on instead. Worked until it didn't.

**Fix plan — paste-ready, batched** :
Hardening top-24 endpoints (the ones touching $$ + PII + fiscal). Priority list (verify scope via `grep -l <Name>FormRequest app/Http/Controllers/`) :

| # | FormRequest | Endpoint(s) | Gate ability | Priority |
|---|---|---|---|---|
| 1 | `ItemRequest` | catalog create/update | `admin:catalog` | P0 |
| 2 | `ItemCategoryRequest` | category mutate | `admin:catalog` | P0 |
| 3 | `BranchRequest` | branch mutate | `admin:branch` | P0 |
| 4 | `UserRequest` | staff mutate | `admin:users` | P0 |
| 5 | `RoleRequest` | RBAC mutate | `admin:rbac` | P0 |
| 6 | `PermissionRequest` | RBAC mutate | `admin:rbac` | P0 |
| 7 | `ZReportRequest` | fiscal close | `admin:fiscal` | P0 |
| 8 | `RefundRequest` | refund | `pos:refund` | P0 |
| 9 | `CashDrawerSessionRequest` | cash open/close | `pos:cash` | P0 |
| 10 | `PaymentGatewayRequest` | gateway config | `admin:payment` | P0 |
| 11 | `KioskMachineRequest` | kiosk pair | `admin:kiosk` | P0 |
| 12 | `LanguageRequest` | lang mutate | `admin:settings` | P0 |
| 13 | `PrinterRequest` | printer mutate | `admin:hardware` | P1 |
| 14 | `DiningTableRequest` | table mutate | `admin:hardware` | P1 |
| 15 | `TaxRequest` | tax config | `admin:fiscal` | P0 |
| 16 | `CouponRequest` | discount mutate | `admin:catalog` | P0 |
| 17 | `DeliveryBoyRequest` | driver mutate | `admin:users` | P0 |
| 18 | `SettingsRequest` | global settings | `admin:settings` | P0 |
| 19 | `MediaUploadRequest` | upload | `admin:catalog` | P0 |
| 20 | `OrderDestroyRequest` | order delete | `admin:order:destroy` | P0 |
| 21 | `BackupRequest` (D1-S-02 prereq?) | backup trigger | `admin:ops` | P1 |
| 22 | `MaintenanceModeRequest` | downtime toggle | `admin:ops` | P1 |
| 23 | `ApiTokenRequest` | token mgmt | `admin:rbac` | P1 |
| 24 | `WebhookConfigRequest` | webhook config | `admin:payment` | P1 |

**Template patch (apply to each FormRequest)** :
```php
// Before
public function authorize(): bool
{
    return true;
}

// After (ItemRequest example)
public function authorize(): bool
{
    $user = $this->user();
    if (! $user) return false;

    // Defense in depth: token ability + Spatie permission
    $hasAbility = method_exists($user, 'tokenCan')
        ? ($user->tokenCan('admin:catalog') || $user->tokenCan('admin:*'))
        : false;
    $hasPermission = $user->can('settings');  // existing Spatie permission

    return $hasAbility && $hasPermission;
}
```

**CI gate to prevent regression** :
```yaml
# .github/workflows/security-scan.yml — new job
formrequest-authz-coverage:
  name: FormRequest authz coverage
  runs-on: ubuntu-latest
  steps:
    - uses: actions/checkout@v4
    - name: Count missing authz
      run: |
        total=$(find app/Http/Requests -name "*.php" | wc -l)
        weak=$(find app/Http/Requests -name "*.php" -exec grep -L "tokenCan\|hasRole\|hasPermission\|->can(\|Gate::" {} \; | wc -l)
        echo "FormRequest authz coverage: $((total - weak)) / $total"
        # Ratchet : require at least 24 hardened. Increase the threshold over time.
        if [ "$((total - weak))" -lt 24 ]; then
          echo "ERROR: at least 24 FormRequests must check tokenCan/Gate"
          exit 1
        fi
```

**Regression test (sentinel)** :
```php
// File: tests/Unit/Security/FormRequestAuthzCoverageTest.php (NEW)
<?php
namespace Tests\Unit\Security;

use Tests\TestCase;
use Symfony\Component\Finder\Finder;

class FormRequestAuthzCoverageTest extends TestCase
{
    /** @test */
    public function at_least_24_form_requests_check_token_or_gate(): void
    {
        $finder = (new Finder())->files()->name('*.php')->in(app_path('Http/Requests'));
        $total = 0; $hardened = 0;
        foreach ($finder as $file) {
            $total++;
            $body = file_get_contents($file->getRealPath());
            if (preg_match('/tokenCan|hasRole|hasPermission|->can\(|Gate::/', $body)) {
                $hardened++;
            }
        }
        $this->assertGreaterThanOrEqual(24, $hardened, "At least 24 FormRequests must have authz gates; found $hardened/$total");
    }
}
```

**Verification commands** :
```bash
./vendor/bin/phpunit --filter FormRequestAuthzCoverageTest
find app/Http/Requests -name "*.php" -exec grep -l "tokenCan\|hasRole\|hasPermission\|->can(\|Gate::" {} \; | wc -l
# Expected: ≥30 (24 new + 6 existing kiosk-flow)
```

**Rollback per FormRequest** :
1. `git revert <commit>` (each FormRequest is one commit ideally)
2. CI lint will flag downgrade if total drops below 24
- **Time** : 1 min/file
- **Data loss risk** : None

**Acceptance criteria** :
- [ ] 24 prioritized FormRequests hardened with `tokenCan + ->can` pattern
- [ ] `tests/Unit/Security/FormRequestAuthzCoverageTest.php` GREEN
- [ ] CI job `formrequest-authz-coverage` enforces ≥24
- [ ] `docs/security/FORMREQUEST_AUTHZ_MATRIX.md` published listing each gate

**Dependencies** : Requires D2-S-04 (TokenAbilityResolver) ; `admin:catalog` etc. abilities issued by login.

**Estimated effort** : 8 h Claude (~20 min/FormRequest including test stub) + 1 h owner gate review of authz matrix.

---

## §4 DOMAIN 4 — RCE & INPUT VALIDATION

### Domain summary
| Metric | Current | Target |
|---|---|---|
| Score | 30/100 | 80/100 |
| RCE primitives reachable | 1 (`LanguageService::edit`) | 0 |
| Arbitrary class instantiation | 1 (`PaymentController:75` — gated by `web_payment_v1.enabled=false`) | 0 (whitelist regardless) |
| Installer hardening | partial (Redirect in constructor) | `abort(403)` + IP allowlist + env-gate |
| File upload mime/extension validation | partial (43 upload sites grep — needs audit) | 100% MimeValidationRule applied |
| DB::raw user-controlled | 0 (all literal/computed) | unchanged ✅ |

**Top 3 priorities** :
1. **LanguageService quarantine** — route gate + path whitelist + content sanitize.
2. **PaymentController whitelist** — `match()` switch on validated paymentMethod.
3. **Installer hardening** — `abort(403)` + production env-gate.

**Total effort** : ~8 Claude-h.

---

### FINDING D4-S-05 — LanguageService::edit RCE primitive — P0

**Current state (re-verified 2026-05-16)** :
- `app/Services/LanguageService.php:198-220` : `fopen($request->x_language_file_path, "rw")` + `file_put_contents($request->x_language_file_path, $fileContent)` — fully attacker-controlled path + content.
- `routes/api.php:486` : `Route::post('/file-text/store', [LanguageController::class, 'fileTextStore'])` inside group with middleware `['installed', 'apiKey', 'auth:sanctum', 'localization', 'throttle:admin-mutation']`. **No `permission:*`**.
- `app/Http/Controllers/Admin/LanguageController.php:23` : `permission:settings` applied to `['store', 'update', 'destroy']` only.
- `LanguageController.php:98-106` : `fileTextStore()` calls `$this->languageService->fileTextStore($request)` directly, no auth check inside method body.

Confirmed : any authenticated user (including a guest customer with OTP, post-D2-S-04 holding `['customer:order']`) can hit this endpoint and write arbitrary content to any disk path.

**Attack scenario** : 
1. Attacker signs up via OTP (`POST /api/guest/signup`) — gets a `customer:order` token.
2. POST `/api/admin/language/file-text/store` with body :
   - `x_language_file_path = /var/www/html/public/shell.php`
   - `x_language_file_name = anything`
   - `existing_key = "; system($_GET['c']); //`
3. The endpoint writes the value into the file at the attacker-controlled path.
4. Attacker browses `https://lecayenne.foodking.fr/shell.php?c=id` → PHP webshell active.
5. Leverage : read `.env` → grab `FISCAL_AUDIT_SECRET` → forge audit chain entries → NF525 evidence corruption → potential prison time for owner.

**Exploit complexity** : Trivial (any phone-with-SMS attacker).

**Root cause** : Upstream legacy admin language-editor helper that was never re-secured when Sanctum guest-OTP flow was added. The route group `['installed', 'apiKey', 'auth:sanctum', ...]` provided the appearance of admin gating ; `apiKey` middleware is cosmetic (D1-S-03) ; `auth:sanctum` accepts any authed user ; `permission:settings` was forgotten on this single route.

**Fix plan — paste-ready, two-layer defense** :

**Layer 1 — route middleware** (`routes/api.php:486`) :
```php
// Before
Route::post('/file-text/store', [LanguageController::class, 'fileTextStore']);

// After (adds permission:settings + abilities:admin:settings)
Route::post('/file-text/store', [LanguageController::class, 'fileTextStore'])
    ->middleware(['permission:settings', 'abilities:admin:*,admin:settings']);
```
Note : `abilities` middleware accepts comma-separated abilities and requires the token to have AT LEAST ONE. Use `App\Http\Middleware\AbilitiesMiddleware` (Sanctum default) ; verify it's registered in `Kernel.php`.

**Layer 2 — service hardening** (`app/Services/LanguageService.php:198-220`) :
```php
// Before
public function fileTextStore(Request $request): void
{
    try {
        $file        = fopen($request->x_language_file_path, "rw");
        $fileContent = file_get_contents($request->x_language_file_path);
        foreach ($request->all() as $key => $value) {
            if ($key != 'x_language_file_path' && $key != 'x_language_file_name') {
                $key = str_replace('_', ' ', $key);
                if (strpos($fileContent, "'" . $key . "'") !== false) {
                    $fileContent = str_replace("'" . $key . "'", "\"{$value}\"", $fileContent);
                } elseif (strpos($fileContent, "\"{$key}\"") !== false) {
                    $fileContent = str_replace("\"{$key}\"", "\"{$value}\"", $fileContent);
                }
            }
        }
        file_put_contents($request->x_language_file_path, $fileContent);
        fclose($file);
    } catch (Exception $exception) {
        Log::info($exception->getMessage());
        throw new Exception(QueryExceptionLibrary::message($exception), 422);
    }
}

// After
public function fileTextStore(Request $request): void
{
    // [D4-S-05 CTO audit 2026-05-16] Quarantine the RCE primitive.
    // The legacy code accepted attacker-controlled file path + content,
    // enabling any authenticated user to write PHP to disk.
    try {
        $rawPath = (string) $request->input('x_language_file_path', '');
        $resolved = $this->resolveLanguageFilePath($rawPath);  // throws if outside lang_path()

        $allValues = $request->all();
        foreach ($allValues as $key => $value) {
            if (in_array($key, ['x_language_file_path', 'x_language_file_name'], true)) {
                continue;
            }
            $this->assertSafeValue((string) $value);  // throws if PHP-injection content
        }

        $fileContent = file_get_contents($resolved);
        foreach ($allValues as $key => $value) {
            if (in_array($key, ['x_language_file_path', 'x_language_file_name'], true)) {
                continue;
            }
            $needle = str_replace('_', ' ', (string) $key);
            // value already validated; escape double quote for PHP single-line string
            $escaped = str_replace(['\\', '"'], ['\\\\', '\\"'], (string) $value);

            if (strpos($fileContent, "'" . $needle . "'") !== false) {
                $fileContent = str_replace("'" . $needle . "'", "\"{$escaped}\"", $fileContent);
            } elseif (strpos($fileContent, "\"{$needle}\"") !== false) {
                $fileContent = str_replace("\"{$needle}\"", "\"{$escaped}\"", $fileContent);
            }
        }
        file_put_contents($resolved, $fileContent, LOCK_EX);
    } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
        throw $e;
    } catch (Exception $exception) {
        Log::warning('LanguageService::fileTextStore failed', [
            'message' => $exception->getMessage(),
            'caller' => optional(auth()->user())->id,
        ]);
        throw new Exception(QueryExceptionLibrary::message($exception), 422);
    }
}

private function resolveLanguageFilePath(string $rawPath): string
{
    $base = realpath(lang_path()) ?: realpath(base_path('lang'));
    if (! $base) {
        abort(500, 'language directory not resolvable');
    }
    $resolved = realpath($rawPath);
    if ($resolved === false || strpos($resolved, $base) !== 0) {
        Log::warning('LanguageService path traversal attempt', [
            'caller' => optional(auth()->user())->id,
            'requested_path' => $rawPath,
        ]);
        abort(403, 'language file path outside lang/');
    }
    if (! str_ends_with($resolved, '.php')) {
        abort(403, 'language file must be .php');
    }
    return $resolved;
}

private function assertSafeValue(string $value): void
{
    $patterns = [
        '/<\?(php|=)/i',
        '/`/',           // backtick exec
        '/\beval\s*\(/i',
        '/\bsystem\s*\(/i',
        '/\bexec\s*\(/i',
        '/\bpassthru\s*\(/i',
        '/\bshell_exec\s*\(/i',
        '/\bproc_open\s*\(/i',
        '/\bpopen\s*\(/i',
        '/\bassert\s*\(/i',
        '/\${.+}/',      // PHP variable interpolation
        '/\\\\x[0-9a-f]{2}/i',  // hex escape
    ];
    foreach ($patterns as $p) {
        if (preg_match($p, $value)) {
            Log::warning('LanguageService unsafe content rejected', [
                'caller' => optional(auth()->user())->id,
                'pattern' => $p,
            ]);
            abort(422, 'language value contains forbidden tokens');
        }
    }
}
```

**Regression test** :
```php
// File: tests/Feature/Security/LanguageServiceRceTest.php (NEW)
<?php
namespace Tests\Feature\Security;

use App\Models\User;
use App\Enums\EnumRole;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class LanguageServiceRceTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function customer_token_cannot_reach_file_text_store(): void
    {
        $user = User::factory()->create();
        $user->assignRole(EnumRole::CUSTOMER);
        Sanctum::actingAs($user, ['customer:order', 'customer:profile']);

        $response = $this->postJson('/api/admin/language/file-text/store', [
            'x_language_file_path' => lang_path('en/all.php'),
            'x_language_file_name' => 'all',
            'some_key' => 'harmless',
        ]);

        $response->assertStatus(403);
    }

    /** @test */
    public function admin_cannot_write_outside_lang_directory(): void
    {
        $admin = User::factory()->create(['branch_id' => 0]);
        $admin->assignRole(EnumRole::ADMIN);
        Sanctum::actingAs($admin, \App\Support\TokenAbilityResolver::for($admin));

        $target = storage_path('framework/php-shell-test.php');
        $response = $this->postJson('/api/admin/language/file-text/store', [
            'x_language_file_path' => $target,
            'x_language_file_name' => 'shell',
            'pwned' => 'whatever',
        ]);

        $response->assertStatus(403);
        $this->assertFileDoesNotExist($target);
    }

    /** @test */
    public function admin_cannot_write_php_payload_into_lang_file(): void
    {
        $admin = User::factory()->create(['branch_id' => 0]);
        $admin->assignRole(EnumRole::ADMIN);
        Sanctum::actingAs($admin, \App\Support\TokenAbilityResolver::for($admin));

        $target = lang_path('en/all.php');
        $before = file_get_contents($target);

        $response = $this->postJson('/api/admin/language/file-text/store', [
            'x_language_file_path' => $target,
            'x_language_file_name' => 'all',
            'message' => '"; system($_GET["c"]); //',
        ]);

        $response->assertStatus(422);
        $this->assertSame($before, file_get_contents($target));  // unchanged
    }

    /** @test */
    public function admin_can_edit_legitimate_lang_key(): void
    {
        $admin = User::factory()->create(['branch_id' => 0]);
        $admin->assignRole(EnumRole::ADMIN);
        Sanctum::actingAs($admin, \App\Support\TokenAbilityResolver::for($admin));

        $target = lang_path('en/all.php');
        $response = $this->postJson('/api/admin/language/file-text/store', [
            'x_language_file_path' => $target,
            'x_language_file_name' => 'all',
            'name' => 'FoodKing Updated',
        ]);

        $response->assertStatus(202);
    }
}
```

**CI gate** :
```yaml
# Add to .github/workflows/security-scan.yml under custom-grep-patterns
- name: LanguageService route gate sentinel (D4-S-05)
  run: |
    # Ensure the route still has permission:settings
    line=$(grep -nE "Route::post.*file-text/store" routes/api.php | head -1)
    if ! echo "$line" | grep -q "permission:settings"; then
      echo "ERROR: routes/api.php file-text/store missing permission:settings (D4-S-05 regression)"
      exit 1
    fi
    # Ensure the service still has the realpath check
    if ! grep -q "resolveLanguageFilePath" app/Services/LanguageService.php; then
      echo "ERROR: LanguageService::resolveLanguageFilePath() guard removed (D4-S-05 regression)"
      exit 1
    fi
```

**Verification commands** :
```bash
./vendor/bin/phpunit --filter LanguageServiceRceTest
# Live test (POST 403)
curl -X POST -H "Authorization: Bearer <customer-token>" \
  -H "Content-Type: application/json" \
  -d '{"x_language_file_path":"/tmp/x.php","x_language_file_name":"x","k":"v"}' \
  http://localhost:8000/api/admin/language/file-text/store
# Expected: HTTP 403
```

**Rollback** :
1. `git revert <commit>` (revert service + route)
2. `php artisan route:cache && php artisan config:cache`
3. Restart php-fpm
- **Time** : 3 min
- **Data loss risk** : None ; only restores the vulnerability (do not roll back unless emergency)

**Acceptance criteria** :
- [ ] `tests/Feature/Security/LanguageServiceRceTest.php` 4/4 GREEN
- [ ] `routes/api.php:486` carries `->middleware(['permission:settings', 'abilities:admin:*,admin:settings'])`
- [ ] `LanguageService::resolveLanguageFilePath()` + `assertSafeValue()` present
- [ ] Manual curl from customer-token → HTTP 403
- [ ] CI lint `LanguageService route gate sentinel` GREEN

**Dependencies** : Requires D2-S-04 deployed first (so `admin:*` ability exists on admin tokens). Otherwise admin tokens won't pass the new gate.

**Estimated effort** : 3 h Claude (service + route + 4 tests + CI lint + manual curl).

---

### FINDING D4-S-02 — PaymentController arbitrary class instantiation — P1 (downgraded from P0)

**Current state (re-verified 2026-05-16)** :
- `app/Http/Controllers/Frontend/PaymentController.php:75-76` : `$className = 'App\\Http\\PaymentGateways\\PaymentRequests\\' . ucfirst($request->paymentMethod); $gateway = new $className;`
- `app/Http/Requests/PaymentRequest.php:17` : `authorize() { return true; }`
- `app/Http/Requests/PaymentRequest.php:28` : `'paymentMethod' => ['required', 'string', 'max:190']` — NO whitelist.
- **HOWEVER** : `PaymentController::payment()` line 70 calls `$this->guardWebPaymentV1()` which `abort(404)` if `config('payment.web_payment_v1.enabled', false)` is falsy. **Default is `false`** (verified `config/payment.php:15`). So the route is unreachable in current prod config.

**Downgrade rationale** : The CTO verdict and Agent 2 §P0-S-02 missed the `guardWebPaymentV1()` gate. With the gate closed, the attack surface is zero. If owner ever flips `web_payment_v1.enabled = true`, this becomes P0 immediately. Patch is cheap and should be applied as defense-in-depth.

**Attack scenario (post-config-flip only)** : POST `/payment/{order}/pay` with `paymentMethod=AnyClass\With\Side\Effects` → instantiate arbitrary FormRequest class → DoS via constructor throw, possibly worse if a gadget chain exists.

**Root cause** : Upstream legacy "polymorphic gateway" pattern relying on convention-over-config. Worked when only 2 gateways existed ; never enforced via whitelist.

**Fix plan** :
```php
// File: app/Http/Controllers/Frontend/PaymentController.php:74-78
// Before
if ($this->paymentManagerService->gateway($request->paymentMethod)->status()) {
    $className = 'App\\Http\\PaymentGateways\\PaymentRequests\\' . ucfirst($request->paymentMethod);
    $gateway   = new $className;
    $request->validate($gateway->rules());
    return $this->paymentManagerService->gateway($request->paymentMethod)->payment($order, $request);
}

// After
if ($this->paymentManagerService->gateway($request->paymentMethod)->status()) {
    // [D4-S-02 CTO audit 2026-05-16] Whitelist payment-request classes.
    // Avoid `new $className` with user-controlled input.
    $requestClass = match (strtolower((string) $request->paymentMethod)) {
        'stripe'     => \App\Http\PaymentGateways\PaymentRequests\Stripe::class,
        'senangpay'  => \App\Http\PaymentGateways\PaymentRequests\Senangpay::class,
        'cod'        => \App\Http\PaymentGateways\PaymentRequests\Cod::class,
        'credit'     => \App\Http\PaymentGateways\PaymentRequests\Credit::class,
        'paypal'     => \App\Http\PaymentGateways\PaymentRequests\Paypal::class,
        'paytm'      => \App\Http\PaymentGateways\PaymentRequests\Paytm::class,
        default      => abort(400, 'unsupported payment method'),
    };
    $gateway = app($requestClass);
    $request->validate($gateway->rules());
    return $this->paymentManagerService->gateway($request->paymentMethod)->payment($order, $request);
}
```

Also tighten `PaymentRequest.php:28` :
```php
// Before
'paymentMethod' => ['required', 'string', 'max:190'],

// After
'paymentMethod' => ['required', 'string', \Illuminate\Validation\Rule::in([
    'stripe', 'senangpay', 'cod', 'credit', 'paypal', 'paytm',
])],
```

**Regression test** :
```php
// File: tests/Feature/Security/PaymentMethodWhitelistTest.php (NEW)
<?php
namespace Tests\Feature\Security;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class PaymentMethodWhitelistTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function unknown_payment_method_rejected_when_web_payment_enabled(): void
    {
        config(['payment.web_payment_v1.enabled' => true]);
        $order = \App\Models\Order::factory()->create();

        $response = $this->post('/payment/' . $order->id . '/pay', [
            'paymentMethod' => 'AnyClass\\With\\Side\\Effects',
        ]);

        $response->assertStatus(422);
    }

    /** @test */
    public function whitelisted_payment_method_accepted(): void
    {
        config(['payment.web_payment_v1.enabled' => true]);
        // ... happy path with valid 'stripe' value
        $this->markTestIncomplete('Wire happy path once gateway mock available');
    }
}
```

**Acceptance criteria** :
- [ ] Whitelist `match()` in PaymentController
- [ ] `Rule::in([...])` in PaymentRequest
- [ ] `tests/Feature/Security/PaymentMethodWhitelistTest.php` GREEN
- [ ] No `new $className` pattern remaining in `app/Http/Controllers/Frontend/PaymentController.php`

**Dependencies** : Independent.

**Estimated effort** : 1.5 h Claude.

---

### FINDING D4-S-07 — Installer routes hardening — P2 (downgraded)

**Current state (re-verified)** :
- `routes/web.php:22-34` : `/install/*` group, middleware `['web']` only, no auth.
- `app/Http/Controllers/Installer/InstallerController.php:28-30` : constructor checks `if (file_exists(storage_path('installed'))) { Redirect::to(env('APP_URL'))->send(); }` — short-circuits with HTTP redirect + termination via `->send()`.
- This is NOT P0 as Agent 2 claimed : the constructor guard runs BEFORE the action method, and `->send()` calls `exit()` semantics in Symfony's response chain. The vulnerability would only materialize if `storage/installed` is removed (deploy script bug, malicious rm).
- However, the guard uses `env('APP_URL')` directly — after `php artisan config:cache`, `env()` returns null in production → empty redirect → controller continues. **This is the real issue**.

**Attack scenario** : An ops mistake (config:cache without `env()` audit) → guard short-circuits to empty redirect → controller action runs → if `storage/installed` deleted, attacker can re-run install.

**Fix plan** :
```php
// File: app/Http/Controllers/Installer/InstallerController.php:28-30
// Before
if (file_exists(storage_path('installed'))) {
    Redirect::to(env('APP_URL'))->send();
}

// After
if (file_exists(storage_path('installed'))) {
    abort(403, 'Installer disabled : application already installed.');
}
// [D4-S-07] Defense in depth — refuse in production regardless
if (config('app.env') === 'production' && ! config('installer.allowed_in_production', false)) {
    abort(403, 'Installer disabled in production.');
}
```

**Acceptance criteria** :
- [ ] Constructor uses `abort(403)` not `Redirect::send`
- [ ] Production env check added
- [ ] Test `tests/Feature/Security/InstallerSealedTest.php` GREEN (probes all /install/* routes → 403 when installed flag exists)

**Effort** : 30 min Claude.

---

### FINDING D4-S-MASS — Mass assignment + file-upload audit — P2

**Current state** : 43 `->store(` calls + unknown number of `->fill(` audited via spot check. Not load-bearing for the 75/100 score. Listed here for completeness ; defer to V1.x backlog.

**Effort** : 6 h Claude (full audit + 5 representative fixes) — not in 75/100 scope.

---

## §5 DOMAIN 5 — DEPENDENCIES & SUPPLY CHAIN

### Domain summary
| Metric | Current | Target |
|---|---|---|
| Score | 35/100 | 75/100 |
| Laravel framework | 9.52.21 (EOL Feb 2024) | 10.x LTS (interim) → 11.x (final) |
| PHPSpreadsheet | 1.30.0 (CVE-2024-45048 + family) | ≥2.1.2 (CVE-clean) |
| spatie/laravel-ignition | 1.7.0 (CVE-2022-40127 in debug mode) | Composer `require-dev` only ; verify `composer install --no-dev` on prod |
| stripe-php | 10.21.0 (stale, no CVE) | ≥14.x in same upgrade window |
| `composer audit` in CI | None | RED on high/critical |
| `npm audit` in CI | None | RED on high/critical |

**Top 3 priorities** :
1. **PHPSpreadsheet 1.30 → 2.x** (CVE-2024-45048) — reachable via 2 admin Excel import endpoints.
2. **Laravel 9 → 10 LTS** — separate sprint, ~3-5 days.
3. **`composer audit` + `npm audit` in CI** (covered by D1-S-02 workflow already).

**Total effort** : ~14 Claude-h (PHPSpreadsheet 2 h + Laravel 10 8-12 h + audit reports 2 h).

---

### FINDING D5-S-09a — PHPSpreadsheet 1.30 → ≥2.1.2 — P1

**Current state (re-verified)** :
- `composer.lock` : `phpoffice/phpspreadsheet` v1.30.0.
- Reachable via `app/Http/Controllers/Admin/ItemController.php:188` (`Excel::import(new ItemImport(...))`) and `ItemCategoryController.php:125`.
- CVE-2024-45048 patched in 1.29.5+ / 2.1.2 — but 1.30.0 is on the OLDER 1.30.x branch (parallel to 2.x). Verify against `https://github.com/PHPOffice/PhpSpreadsheet/security/advisories`.
- Compatible upgrade target : `^2.1`.

**Attack scenario** : Admin uploads crafted .xlsx (or a stolen admin token uploads on attacker's behalf) → HTMLWriter / formula parser XSS-to-stored OR formula evaluation gadget → admin-session compromise.

**Root cause** : Composer constraint pinned `^1.30` (likely upstream template). Never bumped.

**Fix plan** :
```bash
# Step 1 : Update composer.json
# Before
"phpoffice/phpspreadsheet": "^1.30"
# After
"phpoffice/phpspreadsheet": "^2.1"

# Step 2 : Update composer.lock + verify
composer update phpoffice/phpspreadsheet --with-all-dependencies
composer audit | grep phpspreadsheet
# Expected : no advisories

# Step 3 : Manual smoke test
php artisan tinker --execute '
  $r = (new \PhpOffice\PhpSpreadsheet\Reader\Xlsx())->load("tests/fixtures/sample.xlsx");
  echo "Sheets: " . $r->getSheetCount() . PHP_EOL;
'
```

**Risk** : Breaking changes in 2.x — `Sheet::setCellValue()` signature ; `Reader\Csv` API. Run full Excel-import test suite.

**Regression test** :
```php
// File: tests/Feature/Hardening/PhpSpreadsheetUpgradeTest.php (NEW)
<?php
namespace Tests\Feature\Hardening;

use Tests\TestCase;

class PhpSpreadsheetUpgradeTest extends TestCase
{
    /** @test */
    public function phpspreadsheet_version_is_at_least_2_1(): void
    {
        $version = \Composer\InstalledVersions::getVersion('phpoffice/phpspreadsheet');
        $this->assertGreaterThanOrEqual(
            0,
            version_compare($version, '2.1.2'),
            "PHPSpreadsheet must be >=2.1.2 (CVE-2024-45048). Current: $version"
        );
    }

    /** @test */
    public function item_import_path_still_loads(): void
    {
        $path = base_path('tests/fixtures/items.xlsx');
        if (! file_exists($path)) $this->markTestSkipped('fixture missing');
        $reader = new \PhpOffice\PhpSpreadsheet\Reader\Xlsx();
        $sheet  = $reader->load($path);
        $this->assertGreaterThan(0, $sheet->getSheetCount());
    }
}
```

**CI gate** : `composer audit` job (already in D1-S-02 workflow) will RED on any advisory.

**Verification commands** :
```bash
composer outdated | grep phpoffice
composer audit
./vendor/bin/phpunit --filter PhpSpreadsheetUpgradeTest
# Manual : admin upload test in staging
```

**Rollback** :
1. `git revert <commit-composer-upgrade>`
2. `composer install`
3. Restart php-fpm
- **Time** : 3 min
- **Data loss risk** : None

**Acceptance criteria** :
- [ ] `composer.lock` : `phpoffice/phpspreadsheet` ≥ 2.1.2
- [ ] `composer audit` returns no `phpspreadsheet` advisory
- [ ] `tests/Feature/Hardening/PhpSpreadsheetUpgradeTest.php` GREEN
- [ ] Full PHPUnit suite GREEN (no regression in import paths)
- [ ] Manual upload smoke test in staging

**Dependencies** : Independent. Can ship before Laravel 10.

**Estimated effort** : 2 h Claude.

---

### FINDING D5-S-09b — Laravel 9.52 (EOL) → 10 LTS — P1

**Current state (re-verified)** :
- `composer.lock` : `laravel/framework` v9.52.21.
- Laravel 9 reached EOL on 2024-02-06. Any post-EOL CVE in framework is unpatched here.
- Laravel 10 LTS receives bug fixes until 2025-02 (security until 2025-08). Laravel 11 receives until later. **Recommend two-step : 9 → 10 first, then 11 in a separate sprint.**

**Plan summary (NO implementation in this ultra plan — too large to scope here ; detailed in §5 of dispatch pack Prompt #12)** :

1. **Pre-flight** : run `vendor/bin/rector` with Laravel 10 set, dry-run.
2. **composer.json bumps** : `"laravel/framework": "^10.0"`, plus likely `"laravel/sanctum": "^3.3"` → `"^4.0"`, `"spatie/laravel-permission": "^5.11"` → `"^6.0"`.
3. **Deprecations** : `Date` facade returns Carbon ; Pulse not yet ; Pail (logs).
4. **Test suite** : full PHPUnit + Vitest + Playwright matrix.
5. **Sanctum 4.x** : ability syntax tightened — coordinate with D2-S-04 deploy.

**Estimated effort** : 8-12 h Claude focused work + 4 h owner gate review + 1 day soak in staging.

**Dependencies** : D5-S-09a (PHPSpreadsheet upgrade compatible with both Laravel 9 and 10 — do first to de-risk). Compatible with D2-S-04 token plan (Sanctum 4 changes ability semantics slightly ; review).

---

### FINDING D5-S-10 — Ignition + Debugbar dev-only verification — P2

**Current state (re-verified)** :
- `composer.json` lists `barryvdh/laravel-debugbar` + `spatie/laravel-ignition` under `require` (NOT `require-dev`). **Bug-prone**.

**Fix plan** :
```json
// composer.json — move from "require" to "require-dev"
"require-dev": {
    ...
    "barryvdh/laravel-debugbar": "^3.8",
    "spatie/laravel-ignition": "^1.7"
}
```
Then : `composer update --no-install --lock`.
Production deploy script must use `composer install --no-dev --optimize-autoloader`.

**Regression test** :
```php
// File: tests/Feature/Hardening/ProductionDependencyTest.php (NEW)
<?php
namespace Tests\Feature\Hardening;

use Tests\TestCase;

class ProductionDependencyTest extends TestCase
{
    /** @test */
    public function debugbar_is_dev_only(): void
    {
        $composer = json_decode(file_get_contents(base_path('composer.json')), true);
        $this->assertArrayNotHasKey('barryvdh/laravel-debugbar', $composer['require'] ?? []);
        $this->assertArrayHasKey('barryvdh/laravel-debugbar', $composer['require-dev'] ?? []);
    }

    /** @test */
    public function ignition_is_dev_only(): void
    {
        $composer = json_decode(file_get_contents(base_path('composer.json')), true);
        $this->assertArrayNotHasKey('spatie/laravel-ignition', $composer['require'] ?? []);
        $this->assertArrayHasKey('spatie/laravel-ignition', $composer['require-dev'] ?? []);
    }
}
```

**CI gate** : the test above + a deploy-script check (`composer install --no-dev` verification).

**Acceptance criteria** :
- [ ] `composer.json` : both packages in `require-dev` only
- [ ] `tests/Feature/Hardening/ProductionDependencyTest.php` 2/2 GREEN
- [ ] Deploy script documented to use `composer install --no-dev`

**Effort** : 30 min Claude.

---

## §6 SEQUENCING DIAGRAM

```
[OWNER GATE]
   |
   v
D1-S-01 (AWS rotation)  —— owner ~60 min
   |
   |   (Claude work below can start in parallel after AWS rotation accepted)
   |
   +---> D1-S-02 (gitleaks CI)        ——  2 h Claude  [SAFE TO DO FIRST]
   |
   +---> D5-S-09a (PHPSpreadsheet)    ——  2 h Claude  [INDEPENDENT]
   |
   +---> D5-S-10 (Ignition dev-only)  ——  30 min      [INDEPENDENT]
   |
   +---> D4-S-05 (LanguageService RCE) — 3 h Claude   [DEPENDS ON D2-S-04 ONLY FOR admin:* ability]
   |          |
   |          v
   |     D2-S-04 (token wildcard)     — 6 h Claude    [REQUIRED BEFORE D4-S-05 deploy + D3-S-11]
   |          |
   |          v
   |     D1-S-03 (MIX_API_KEY)        — 1.5 h Claude  [BEST BUNDLED WITH D2-S-04]
   |          |
   |          v
   |     D3-S-06 (PosOrder IDOR)      — 2 h Claude
   |          |
   |          v
   |     D3-S-19 (BranchScope triage) — 30 min        [BUNDLED WITH D3-S-06 CI lint]
   |          |
   |          v
   |     D3-S-11 (24 FormRequest authz) — 8 h Claude  [REQUIRES D2-S-04 TokenAbilityResolver]
   |
   +---> D4-S-02 (PaymentController whitelist) — 1.5 h Claude
   |
   +---> D4-S-07 (Installer abort) — 30 min
   |
   v
[FULL REGRESSION] ── ~2 h Claude (run all suites) ── 1 h owner gate
   |
   v
[D5-S-09b Laravel 10 LTS — separate sprint] — 8-12 h Claude + 4 h owner
   |
   v
SECURITY SCORE 75-82/100  ← TARGET MET
```

**Critical path (longest serial chain)** : D1-S-01 → D2-S-04 → D4-S-05 → D3-S-11 → full regression = ~6 + 3 + 8 + 2 = **~19 h Claude focused** + 2-3 h owner gates. Realistic wall-clock with reviews : **5-7 working days**.

**Parallel-safe items** (any time after D1-S-02 lands) : D5-S-09a, D5-S-10, D4-S-02, D4-S-07.

---

## §7 ACCEPTANCE CRITERIA — MASTER CHECKLIST (owner sign-off)

Copy this block to a tracking issue. Sign off domain by domain.

### DOMAIN 1 — SECRETS
- [ ] D1-S-01 : AWS key `AKIAYJOT77SIZHDXNYOZ` returns `InvalidClientTokenId` (owner-verified)
- [ ] D1-S-01 : APP_KEY rotated (md5 changed) (owner-verified)
- [ ] D1-S-01 : FISCAL secrets verified differ from leak md5 (owner-verified)
- [ ] D1-S-01 : CloudTrail audited for leaked-key usage 2026-05-13 → today (owner)
- [ ] D1-S-01 : `docs/runbooks/RUNBOOK_AWS_KEY_ROTATION.md` SIGNED
- [ ] D1-S-02 : `.github/workflows/security-scan.yml` present + GREEN
- [ ] D1-S-02 : `.gitleaks.toml` present with foodking custom rules
- [ ] D1-S-02 : `.githooks/pre-commit` documented + installable
- [ ] D1-S-02 : Branch protection on `main` requires all 4 security jobs
- [ ] D1-S-03 : `grep -rn "apiKey:" resources/views/` returns 0 hits
- [ ] D1-S-03 : `tests/Feature/Security/MixApiKeyNotLeakedTest.php` GREEN
- [ ] D1-S-04 : `.env.example` header comment about gitignored variants

### DOMAIN 2 — AUTH & TOKENS
- [ ] D2-S-04 : `grep -rn "createToken(.*\['\*'\]" app/ | grep -v 'E2EStressCommand'` returns 0 hits
- [ ] D2-S-04 : `App\Support\TokenAbilityResolver` covers all 7 roles
- [ ] D2-S-04 : `tests/Feature/Security/SanctumWildcardAbilityTest.php` 4/4 GREEN
- [ ] D2-S-04 : Migration `2026_05_17_000001_revoke_wildcard_tokens.php` ran successfully (`php artisan migrate:status`)
- [ ] D2-S-04 : `docs/security/SANCTUM_ABILITIES_MATRIX.md` published
- [ ] D2-S-04 : All 14 `tokenCan` callers reviewed (kiosk-machine unchanged, customer flow updated)
- [ ] D2-S-04 : Post-deploy : `SELECT COUNT(*) FROM personal_access_tokens WHERE abilities LIKE '%"*"%'` returns 0

### DOMAIN 3 — AUTHORIZATION & IDOR
- [ ] D3-S-06 : `tests/Feature/Security/PosOrderShowIdorTest.php` 3/3 GREEN
- [ ] D3-S-06 : Manual curl: branch-A POS user → branch-B order = HTTP 404
- [ ] D3-S-06 : Audit log shows IDOR attempts logged
- [ ] D3-S-19 : All 11 `withoutGlobalScope(BranchScope::class)` sites annotated with `BRANCH BYPASS DOC` or `BRANCH ASSERTION` comment
- [ ] D3-S-19 : CI lint `BranchScope bypass without assertion sentinel` GREEN
- [ ] D3-S-11 : 24 prioritized FormRequests hardened
- [ ] D3-S-11 : `tests/Unit/Security/FormRequestAuthzCoverageTest.php` GREEN (≥24)
- [ ] D3-S-11 : CI job `formrequest-authz-coverage` enforces ≥24
- [ ] D3-S-11 : `docs/security/FORMREQUEST_AUTHZ_MATRIX.md` published

### DOMAIN 4 — RCE & INPUT VALIDATION
- [ ] D4-S-05 : `tests/Feature/Security/LanguageServiceRceTest.php` 4/4 GREEN
- [ ] D4-S-05 : `routes/api.php:486` carries `permission:settings`
- [ ] D4-S-05 : `LanguageService::resolveLanguageFilePath()` + `assertSafeValue()` present
- [ ] D4-S-05 : Manual curl from customer-token → HTTP 403
- [ ] D4-S-05 : CI lint `LanguageService route gate sentinel` GREEN
- [ ] D4-S-02 : PaymentController uses `match()` whitelist
- [ ] D4-S-02 : `PaymentRequest::rules()` uses `Rule::in([...])`
- [ ] D4-S-02 : `tests/Feature/Security/PaymentMethodWhitelistTest.php` GREEN
- [ ] D4-S-07 : InstallerController uses `abort(403)` not `Redirect::send`
- [ ] D4-S-07 : Production env-check added in InstallerController

### DOMAIN 5 — DEPENDENCIES & SUPPLY CHAIN
- [ ] D5-S-09a : `composer.lock` : phpspreadsheet ≥ 2.1.2
- [ ] D5-S-09a : `composer audit` returns no `phpspreadsheet` advisory
- [ ] D5-S-09a : Full PHPUnit + manual upload smoke GREEN
- [ ] D5-S-09b : (deferred to separate sprint) Laravel 10 LTS migration plan in `plans/`
- [ ] D5-S-10 : `composer.json` : debugbar + ignition in `require-dev`
- [ ] D5-S-10 : `tests/Feature/Hardening/ProductionDependencyTest.php` GREEN

### GLOBAL
- [ ] All PHPUnit suites GREEN post-domain-completion
- [ ] All Vitest + Playwright suites GREEN (no regression)
- [ ] Security score recomputed by re-running Agent 2 prompt → ≥75/100
- [ ] `PROJECT_BRAIN.md` §2 §3 §4 updated with security ultra plan execution log

---

## §8 ROLLBACK MASTER PLAN

| Domain | Trigger | Rollback steps | Time | Data risk |
|---|---|---|---|---|
| D1-S-01 (AWS rotation) | Prod broken post-rotation | `aws iam update-access-key --status Active` (old) → revert `.env` → restart php-fpm | 5 min | None |
| D1-S-02 (gitleaks CI) | False positives blocking merges | `git rm .github/workflows/security-scan.yml` + branch protection → unlist required check | 2 min | None |
| D1-S-03 (MIX_API_KEY) | SPA breakage | `git revert` → `php artisan config:cache` → restart | 5 min | None |
| D2-S-04 (token wildcard) | Auth flows broken | `git revert` 3 commits + revoke-migration is irreversible (users re-login fresh) | 5 min | None (re-auth recovers) |
| D3-S-06 (IDOR fix) | Admin cross-branch read broken | `git revert` → cache → restart | 5 min | None (restores vulnerability — emergency only) |
| D3-S-19 (BranchScope triage) | CI false-positive on legit bypass | Adjust regex in `.github/workflows/security-scan.yml` to widen allowlist | 10 min | None |
| D3-S-11 (FormRequest authz) | Specific endpoint 403 for legit user | Revert that single FormRequest commit (1 file per commit) | 1 min/file | None |
| D4-S-05 (LanguageService) | Lang editor broken for admin | `git revert` route + service patch → cache → restart | 5 min | None (restores RCE — emergency only) |
| D4-S-02 (PaymentController) | Legit gateway slug rejected | Add slug to `match()` whitelist + push | 5 min | None |
| D4-S-07 (Installer) | Owner deliberately re-installing | Touch `storage/installed` to false (delete) + set `config('installer.allowed_in_production', true)` temporarily | 2 min | Catastrophic if attacker pivots — re-seal immediately |
| D5-S-09a (PHPSpreadsheet) | Excel import broken | `git revert composer.json composer.lock` → `composer install` → restart | 3 min | None |
| D5-S-09b (Laravel 10) | Anything broken | `git revert` (cumulative may be 50+ files) → composer install → flush caches | 15 min | None if separate branch |
| D5-S-10 (debugbar dev-only) | Production deploys break with --no-dev | Move back to `require` + redeploy | 5 min | None |

**Master rollback (worst case)** : `git checkout adf7036e4` (pre-ultra-plan baseline) ; `composer install` ; `php artisan migrate:rollback --step=N` (where N = number of new migrations) ; `php artisan optimize:clear` ; restart php-fpm + queue:work. Time : ~10 min. Data loss : zero for code-only changes ; migrations are reversible (revoke-wildcard-tokens is no-op down).

---

## §9 STALE FINDINGS DETECTED (mirror P0-8/P0-9 pattern)

The audit Agent 2 + CTO Verdict §5 carried some claims that DO NOT match the current HEAD `adf7036e4`. These were validated by re-reading the actual files at the times listed.

| Audit claim | Reality (verified 2026-05-16) | Disposition |
|---|---|---|
| **P0-6 Stripe `(int) $total * 100`** | ALREADY FIXED in `Stripe.php:58` via `(int) round((float) $order->total * 100)` + tests `tests/Unit/Payment/StripeCentsCastTest.php` (commit ref in QUICK_WINS_EXECUTED report). | ✅ STALE — closed in QUICK_WINS, not included in this plan |
| **"39 occurrences `withoutGlobalScope(BranchScope::class)`"** (Agent 1 + CTO §5 P0-3 + agent-2 line 11) | ACTUAL : 11 occurrences. The 39 figure comes from grepping `withoutGlobalScope` (any form) — most are `withoutGlobalScopes()` (plural, all scopes) used for legitimate User/fiscal/console contexts (Sanctum recursion, audit chain, CLI commands without auth). | ⚠️ NUMBER CORRECTED — only 1 illegitimate (PosOrderController), 10 documented legit |
| **P0-S-07 Installer routes unauthenticated** | NOT P0 : `InstallerController.php:28-30` has a `Redirect::send()` guard in the constructor that fires for ALL action methods if `storage/installed` exists. Real issue is `env()` post-cache + missing prod env-check. Downgraded to P2. | ⚠️ DOWNGRADED to P2 (D4-S-07) |
| **P0-S-02 PaymentController `new $className`** | NOT P0 in current config : `web_payment_v1.enabled=false` (verified `config/payment.php:15`) → route returns 404 before reaching the vulnerable code. Patch is still recommended as defense in depth. Downgraded to P1. | ⚠️ DOWNGRADED to P1 (D4-S-02) |
| **"87/93 FormRequest weak"** | CONFIRMED EXACTLY : 93 total, 87 missing `tokenCan/hasRole/hasPermission/can(/Gate::` — verified by find+grep. | ✅ ACCURATE |
| **".env.backup-pre-round2 working tree present"** | FIXED in commit `adf7036e4` (untrack + gitignore harden). Working tree clean. Git history still contains the secrets. | ⚠️ PARTIALLY FIXED — D1-S-01 owner-rotation still required |
| **AGENTS.md vs CLAUDE.md contradiction** | FIXED in QUICK_WINS_EXECUTED (P1-26 +8 line header). | ✅ STALE |
| **safety-check.sh frozen list 2→13** | FIXED in QUICK_WINS_EXECUTED (P1-24). | ✅ STALE |
| **P0-8 mobile allergens fabriqués** | FIXED in commit `245e8ab57`. | ✅ STALE (per QUICK_WINS report) |
| **P0-9 mobile promo code stub** | FIXED in commit `245e8ab57`. | ✅ STALE (per QUICK_WINS report) |

**Biggest catch** : the "39 BranchScope sites" number that propagated from Agent 1 to Agent 2 to the CTO verdict. Real number is **11**, of which **10 are legitimate** (documented machine-bound, idempotency-bound, or admin-CLI bypasses) and **1 is the actual IDOR** (`PosOrderController::show`). Owner should not budget hours for "39 sites review" — actual scope is 1 fix + 10 annotations = ~2.5 h total (D3-S-06 + D3-S-19).

**Recommendation for future audits** : every sub-agent prompt must include "RE-VERIFY before flagging" — `git log -p -S '<keyword>'` to check if the finding is already closed.

---

## §10 EXECUTION ORDER (RECOMMENDED CLAUDE SESSIONS)

If owner wants to break this into Claude sessions, this is the ordering :

**Session A — Foundations (4 h Claude + 1 h owner)**
- D1-S-02 (CI gate)
- D1-S-01 (runbook + secrets-to-rotate update ; owner does AWS rotation)
- D1-S-04 (.env.example header)

**Session B — Token detonation (8 h Claude + 30 min owner)**
- D2-S-04 (TokenAbilityResolver + 3 controllers + migration + tests)
- D1-S-03 (MIX_API_KEY removal — bundled)

**Session C — RCE quarantine (4 h Claude)**
- D4-S-05 (LanguageService)
- D4-S-02 (PaymentController whitelist)
- D4-S-07 (Installer abort)

**Session D — Authorization (10 h Claude + 1 h owner)**
- D3-S-06 (PosOrderController IDOR + audit log)
- D3-S-19 (BranchScope annotations)
- D3-S-11 (24 prioritized FormRequests)

**Session E — Dependencies (3 h Claude + dependency sprint for L10)**
- D5-S-09a (PHPSpreadsheet)
- D5-S-10 (dev-only deps)
- D5-S-09b plan written, executed in separate sprint

**Session F — Regression + sign-off (2 h Claude + 1 h owner)**
- Full PHPUnit + Vitest + Playwright
- Re-run Agent 2 security prompt → expect ≥75/100
- Update PROJECT_BRAIN.md + push Graphiti episode

---

**END OF SECURITY ULTRA PLAN** — total Claude effort ~52 h focused work, owner ~6 h gates ; wall-clock 5-7 working days for the headline items, +1 sprint for Laravel 10. Target : security score 28 → 75-82/100.
