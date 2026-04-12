# CYCLE-002b — Local validation command pack (real FoodKing Laravel host)

**Purpose:** Run TASK-04 **outside** limited automation shells (PHP + Composer on `PATH`, `vendor/` present).  
**Scope:** Commands and checks only — **no** application code changes, **no** Playwright, **no** new cycle.

---

## 1. Prerequisites checklist

Run these checks on the **real** machine before `composer install` / tests:

- [ ] **PHP** installed, version **≥ 8.1** (project `composer.json`: `"php": "^8.1.0"`).
  - Verify: `php -v`
- [ ] **Composer** 2.x available.
  - Verify: `composer --version`
- [ ] **Extensions** required by Laravel / this repo (install any missing ones your OS package names differ):
  - `ext-json`, `ext-pdo`, `ext-mbstring`, `ext-xml`, `ext-curl`, `ext-zip`, `ext-bcmath` (common Laravel set)
  - Repo also declares: `ext-exif`, `ext-http` — ensure these are enabled or Composer may fail or warn.
- [ ] **Repository** cloned/checked out at FoodKing root (directory containing `artisan`, `composer.json`).
- [ ] **`.env`** present (copy from `.env.example` if needed) and **`APP_KEY`** set:
  - `php artisan key:generate` if key missing.
- [ ] **Database for tests:** per project convention (often SQLite in-memory for PHPUnit, or MySQL credentials in `.env` matching `phpunit.xml` / `.env.testing` if used).
  - If tests fail on DB: configure `.env` or `phpunit.xml` as documented in repo / `docs/`.
- [ ] **Node/npm** — **not** required for `php artisan test` unless you run frontend asset builds; optional for this pack.
- [ ] **Working directory:** shell opened at repo root (folder containing `artisan`).

---

## 2. Exact commands to run (in order)

From repository root (`FoodKing` / `testttt` root — where `artisan` lives):

```bash
# 1) Install PHP dependencies (required if vendor/ is missing)
composer install

# 2) Optional: clear config cache if you use cached config in dev
# php artisan config:clear

# 3) Primary — order-related tests (CYCLE-002b plan)
php artisan test --filter=Order

# 4) If (3) reports 0 tests or wrong scope, run broader Laravel test suite
php artisan test

# Alternative equivalent (if you prefer PHPUnit entrypoint directly)
# ./vendor/bin/phpunit --filter=Order
# ./vendor/bin/phpunit
```

**Notes:**

- If `composer install` fails on `ext-http` or another extension, install the extension for your PHP build, then re-run `composer install`.
- If `php artisan test` fails before running tests (bootstrap error), fix `.env` / DB / `APP_KEY` first; capture the **full** stderr for the execution report.

---

## 3. Environment assumptions (must be true)

1. **`php` and `composer` are on `PATH`** (or you invoke them via absolute paths — same effect).
2. **`vendor/` exists after `composer install`** — `vendor/autoload.php` and `vendor/bin/phpunit` (or Laravel’s test runner wiring) are available.
3. **Test bootstrap succeeds** — Laravel can load `.env` (or testing env), connect to the test database if configured, and run the framework without fatal errors.
4. **No stale production-only config** blocking tests (e.g. wrong `APP_ENV` if your suite expects `testing`).
5. **Disk and permissions** — project directory is readable/writable for cache, logs, and SQLite file if used.

---

## 4. Paste back into `reports/execution/latest.md` (after real run)

Replace the current **BLOCKED** TASK-04 subsection with the following block **filled in** from your terminal output (keep failure lines verbatim if any):

```markdown
## local-validation results (TASK-04 — completed on real host)

**Host / context:** [e.g. Windows 11 + PHP 8.2 + Composer 2.7 / CI job name / WSL Ubuntu 22.04]

**Commands run (exact):**
\`\`\`text
composer install
php artisan test --filter=Order
[if run:] php artisan test
\`\`\`

**Composer:** [success | failed — one line]

| Metric | Value |
|--------|-------|
| Total tests | [N] |
| Passed | [N] |
| Failed | [N] |

**Failures (if any):**  
For each failure: **test name** | **file** | **line** (if shown) | **message** (first line of stack or assertion)

**Attribution vs CYCLE-002b:**  
[Choose one:]  
- **No failures** — `OrderService::changeStatus` transaction / dispatch ordering change not implicated.  
- **Failures appear pre-existing** — [brief evidence: e.g. unrelated test class / unchanged files in stack].  
- **Failures plausibly caused by CYCLE-002b** — [brief evidence: e.g. stack traces through `OrderService::changeStatus` or order status feature tests only].

**Raw excerpt (optional):** last 30–50 lines of test output pasted below for audit.
```
