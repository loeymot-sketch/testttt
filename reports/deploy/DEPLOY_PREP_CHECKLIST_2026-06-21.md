# DEPLOY-PREP CHECKLIST — Le Cayenne V1 LOCAL cutover (2026-06-21)

> Produced by the FINAL-VALIDATION supervisor wave (WF7). The CODE is validated; this is the
> physical-owner cutover runbook (G-DEPLOY). Single-box LOCAL FR restaurant — no cloud, no multi-tenant.
> Every item below is anchored to a real boot-guard or migration so nothing is hand-wavy.

## 0. Pre-flight (on the build machine)
- [ ] `git pull` the validated PR branch; confirm HEAD == the pushed commit.
- [ ] `composer install --no-dev --optimize-autoloader`
- [ ] **`npm run production`** — REQUIRED: the SPA bundles + `public/js/*` + `mix-manifest.json` must be rebuilt for prod (the campaign's frontend heals live in source; the committed bundles are dev-mode). Verify `mix-manifest.json` hashes changed.
- [ ] `php artisan config:cache && php artisan route:cache && php artisan view:cache`

## 1. `.env` production — REQUIRED values (each maps to a boot guard that REFUSES TO BOOT if wrong)
`app/Providers/AppServiceProvider.php` throws `RuntimeException` at boot in production unless ALL of these hold:
- [ ] `APP_ENV=production`
- [ ] `APP_DEBUG=false`  ← guard :203 (leaks stack/SQL/creds)
- [ ] `APP_URL=https://<your-domain-or-LAN-IP>`  ← guard :261 (CORS allowed_origins / broadcasting/auth)
- [ ] `POS_SIMULATION_HARDWARE=false`  ← guard :166 (NF525 cash-trail; V1 may stay `true` ONLY until the real TPE — see G-HARDWARE)
- [ ] `IDEMPOTENCY_MIDDLEWARE_ENABLED=true`  ← guard :224 (duplicate-POST protection)
- [ ] `LOYALTY_QR_SECRET=<openssl rand -hex 32>`  ← guard :242 (else loyalty QR falls back to forgeable plaintext)
- [ ] `BROADCAST_DRIVER=pusher` (soketi) or `redis`  ← guard :273 (null/log silently swallows real-time sync)
- [ ] `QUEUE_CONNECTION=redis` (or `database`)  ← guard :279 (NOT `sync` — outbox/notifications need a worker)
- [ ] `CACHE_DRIVER=redis`  ← guard :296 (NOT `array`/`null` — NF525 audit-chain `Cache::lock` needs cross-worker coherence; `file`/`database` PASS the guard but redis recommended — see CLAUDE.md §8 UNI-03)
- [ ] If Stripe gateway is ENABLED in `payment_gateways`: `STRIPE_WEBHOOK_SECRET=<whsec_...>`  ← guard :304 (V1 ships Stripe disabled → N/A for Le Cayenne; SumUp is current provider)
> Tip: boot once with `php artisan about` after editing — any missing guard fails fast with the exact var name.

## 2. Database migration + NF525 immutability triggers
- [ ] `php artisan migrate --force` (runs on the prod MySQL).
- [ ] **Verify the 8 anti-DELETE triggers exist on MySQL** (they are driver-guarded — created only on MySQL, SQLite gets parity/skip):
      `SELECT TRIGGER_NAME, EVENT_OBJECT_TABLE FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE();`
      Expect immutability triggers on: `audit_logs` (2026_04_22_000002), `z_reports` (2026_05_09_160000),
      `cash_movements` (2026_05_16_130000), `delivery_boy_cash_*` (2026_05_18_120300),
      `stock_movements` (2026_05_18_140000), fiscal audit trail (2026_05_10_010000),
      `composition_snapshot` (2026_05_24_040211).
- [ ] **GRANT-level defense (CLAUDE.md §8, Ansible CVP0-1):** the app DB user must have DELETE/DROP/TRUNCATE **REVOKED** on `audit_logs` + `z_reports` (triggers block row DELETE but not TRUNCATE/DROP — GRANT closes that). Verify: `SHOW GRANTS FOR '<app_user>'@'<host>';`

## 3. Daemons (single box)
- [ ] `php artisan queue:work redis --queue=high,default` under **supervisor/systemd** (auto-restart) — outbox re-broadcast + notifications + loyalty depend on it.
- [ ] soketi (or pusher creds) running + reachable at the configured `PUSHER_*` host/port; confirm `/broadcasting/auth` returns 200 for a logged-in admin (else sync silently degrades to polling — functional but not live).
- [ ] cron: `* * * * * php artisan schedule:run` (fiscal alloc retry, Z reminders, cleanups).

## 4. Post-boot smoke (proves it's live, not just up)
- [ ] Login → POS loads → create a counter order → encaisser cash → order PAID + fiscal_sequence_no allocated (gap-free) + ticket NF525 prints.
- [ ] `php artisan fiscal:verify-chain --all` → **CHAIN OK** on branch 1.
- [ ] Borne idle → wizard → pay-at-counter → appears on KDS board → OSS wall (live push, not 30s poll).
- [ ] devtools: 0 console errors, money rendered FR (`0,00 €`).

## 5. Rollback
- [ ] DB dump BEFORE migrate (`mysqldump`), keep the prior release dir; rollback = restore dump + `git checkout <prev-tag>` + re-cache. NEVER `migrate:rollback` on fiscal tables (would attempt to drop triggers).

## G — Owner physical gates (cannot be automated)
| Gate | Action | Status |
|---|---|---|
| G-DEPLOY | set `.env` prod (§1), run migrate (§2), start daemons (§3) on the box | PENDING owner |
| G-HARDWARE | install/pair the real TPE; then flip `POS_SIMULATION_HARDWARE=false` | PENDING owner (V1 simulated until then — assumed) |
| G-DB-GRANT | REVOKE DELETE/TRUNCATE/DROP on audit_logs+z_reports for the app user | PENDING owner (DBA) |
