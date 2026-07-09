# V3 DEPTH — Legacy /install /payment + Excel Exports

Target: LEGACY `/install`, legacy `/payment`, ~20 Excel `/export`. Live 127.0.0.1:8766 (foodking_e2e). HEAD 61e9ea7b7 + working tree.

Verdict: **BROKEN** — installer post-install re-execution is exploitable (P0 + P1). Payment held green. Exports authz held green; CSV/formula injection CONFIRMED (heal in-flight, verified active).

---

## P0 — Installer runs on an ALREADY-INSTALLED app (constructor guard does not halt) → unauth DB takeover / migrate:fresh wipe

Files:
- `app/Http/Controllers/Installer/InstallerController.php:28-31` — guard is `if (file_exists(storage_path('installed'))) { Redirect::to(env('APP_URL'))->send(); }`. `Response::send()` flushes headers/body but does **not** stop PHP execution; the constructor returns and the action body runs.
- `app/Services/InstallerService.php:26-48` `databaseSetup()` → writes `.env` DB_* + `Artisan::call('migrate:fresh', ['--force'=>true])` + `db:seed`.
- Route `routes/web.php` install group has only `['web']` middleware; `Installed` middleware is NOT applied to /install. Only guard = the non-halting constructor redirect.

Live proof (non-destructive):
- `GET /install` returns HTTP 302 (Location: APP_URL) **and** the body concatenates the redirect page + the FULL `installer.welcome` HTML (`Installateur Le Cayenne`, 2807 bytes). Proves the action body executes after the constructor "guard". Reproduced 2×.
- CSRF bypass: `GET /login` issues `XSRF-TOKEN` + `le_cayenne_session`. Re-submitting the decoded `XSRF-TOKEN` as `X-XSRF-TOKEN` header passes `VerifyCsrfToken` (no auth needed).
- `POST /install/database` with that header + `database_host=127.0.0.1&database_port=1&database_name=..&database_username=..` → body = constructor-redirect(→/) **concatenated with** databaseStore's own redirect(→/install/database). No 419. This proves `databaseStore()` fully executed on the installed app; only `checkDatabaseConnection()` returned false because port 1 refused — BEFORE `migrate:fresh`.
- All `database_*` fields are attacker-supplied (`config/installer.php` rules: required|string|numeric). An attacker supplying a reachable MySQL they control → connection true → `.env` DB_* permanently repointed to attacker DB + `migrate:fresh --force` + `db:seed` → full app/auth takeover on next boot. Supplying the real local creds → `migrate:fresh` DROPS ALL TABLES incl. NF525 `audit_logs`, `z_reports`, `orders` = catastrophic fiscal-data destruction.
- Destructive final step deliberately NOT executed (no-write mandate); the unreachable-host repro proves every gate up to the fully attacker-controlled connection input is passed.

## P1 — `GET /install/final-store` (no CSRF, GET) → unauth `.env` tamper + boot-guard DoS

- `routes/web.php` — `Route::get('/final-store', ...'finalStore')`. GET → not CSRF-protected. Same non-halting constructor.
- `app/Services/InstallerService.php:104-123` `finalSetup()` writes `.env` `APP_ENV=production`, `APP_DEBUG=false`, runs `storage:link` + `optimize:clear`, appends `storage/installed`.
- On this dev box `POS_SIMULATION_HARDWARE=true` → flipping `APP_ENV=production` makes `AppServiceProvider` boot guard throw `RuntimeException` on next request → total app DoS. Triggerable by a single unauthenticated GET (even cross-site via `<img>`). Mechanism proven by the same GET-body-continues evidence; destructive `.env` write not executed.

---

## HELD GREEN

- **Legacy `/payment/*`** — every action calls `guardWebPaymentV1()` → `abort(404)` when `config('payment.web_payment_v1.enabled')` is false (code-owned default, no env override). Live: `GET /payment/1/pay`=404 (2×), `GET/POST /payment/stripe/1/success`=404. IDOR/forge/Stripe-drain unreachable — 404 fires before any Order binding. Stripe additionally gated by `stripe.activation_guard.activation_gate_cleared=false`.
- **Export authz** — customer/transaction/credit-balance/sales/subscriber `export` all carry per-action `permission:*` middleware (`CustomerController:29`, `TransactionController:21`, `CreditBalanceReportController:23`, `SalesReportController:40`, `SubscriberController:26`). Unauth requests are bounced to the SPA login (Content-Type text/html, no spreadsheet MIME, no export rows) — verified no xlsx/PII returned. Initial "200" was the SPA login page, not data (verify-before-report caught the false positive).

## CONFIRMED — heal in-flight (do not double-heal)

- **CSV/formula injection in exports** — `CustomerExport` (and siblings) emit user-controlled `name` verbatim; `name` is attacker-set via public `POST /api/signup/register` (`first_name`/`last_name` → `AppLibrary::name`). Default Maatwebsite binder would bind a leading `= + - @` as FORMULA. **Heal present & runtime-active**: untracked `config/excel.php` sets `value_binder.default => App\Support\Excel\FormulaGuardValueBinder` (neutralizes leading `= + - @ \t \r` → explicit TYPE_STRING with `'` prefix). Verified live: config not cached, `config('excel.value_binder.default')` resolves to the guard binder. Uncommitted — must be committed to persist.
