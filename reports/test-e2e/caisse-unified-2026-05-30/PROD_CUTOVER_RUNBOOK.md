# Le Cayenne — Production Cutover Runbook

**Date**: 2026-05-30 · **Scope**: the exact box steps to go live, after the code fixes (VAT 10% + offers-off) are merged. Excludes cloud/domain/CB-terminal (owner's separate track).

> Order matters. Do these on the production box, in sequence, BEFORE the first real customer.

## 1. Clean fiscal start (blocker B2) — PRESERVE the canonical menu

The dev DB carries issued fiscal numbers + test/soak orders. The production NF525
chain must start clean. **⚠️ DO NOT `migrate:fresh --seed` to get the menu** — the
committed `config/menu.php` items array is STALE (old/fictional groups: nos-tacos,
ojja, omelettes, salades…) and does NOT match the current category slugs, so a
fresh seed produces only ~8 non-canonical items. The **DB items table is the SSOT**
(45 validated Le Cayenne items) — preserve it.

**Correct procedure — choose ONE:**

**(A) Same box, clear only test fiscal/order data (recommended — keeps the validated menu):**
Run BEFORE the `.env`→production flip and BEFORE the Ansible CVP0-1 GRANT-REVOKE
(so the order/fiscal test rows can be cleared; the NF525 DELETE triggers are
MySQL-prod-only). Clear: orders, order_items, order_payments, transactions,
order_quotes, pos_parked_orders, cash_drawer_sessions, cash_movements,
domain_events, order_status_transitions, z_reports, audit_logs, and reset the
fiscal sequence. Keep: items, categories, menu, wizard profiles, users, settings.

**(B) Fresh box: restore the validated DB backup** (`storage/backups/db-daily/` —
the daily backup contains the canonical menu), then apply (A) to clear any test
orders in it.

Then, regardless of path:
```bash
php artisan fiscal:assign-menu-vat     # idempotent: confirm/repair 45/45 items on VAT 10% TTC
php artisan fiscal:verify-chain --all  # expect: fresh chain, CHAIN OK
php artisan app:preflight-production    # GO/NO-GO GATE — see step 7
```
Verify the menu:
```bash
php artisan tinker --execute="echo \App\Models\Item::where('status',5)->whereHas('tax', fn(\$q)=>\$q->where('tax_rate',10))->count().' / '.\App\Models\Item::where('status',5)->count();"
# expect: 45 / 45  (anything < 40 means the menu did not preserve/seed completely — app:preflight-production WARNS on this)
```

## 2. The three .env flips (blocker B3 — boot guard enforces)
Edit `.env` on the box:
```ini
APP_ENV=production              # was local
APP_URL=http://<box-ip-or-host> # was http://localhost:8000 — the real address the kiosk/POS browsers hit
POS_SIMULATION_HARDWARE=false   # was true — NF525-CRITICAL: re-enables the cash-drawer-open requirement
PRICING_TAX_INCLUSIVE=true      # confirm present (10% extracted from TTC price, not added)
FEATURE_OFFERS_ENABLED=false    # confirm absent/false — Offers module stays disabled (S1)
```
Already correct (verified): `IDEMPOTENCY_MIDDLEWARE_ENABLED=true`, `CACHE_DRIVER=redis`, `QUEUE_CONNECTION=redis`.
Then:
```bash
php artisan config:clear && php artisan config:cache
php artisan route:cache
# AppServiceProvider boot guard will REFUSE to boot if any prod invariant is wrong — that is the safety net.
```

## 3. OS-level cron (so the daily Z + backups actually fire)
The app schedule is fully wired (verified `schedule:list`: fiscal close 23:59 / open 00:01 / verify-z-membership 06:05 / backup-daily 03:00 / outbox rescue+monitor+retry / stock scan). **They only run if Laravel's scheduler is invoked every minute by the OS.** Add to the box crontab (`crontab -e`):
```cron
* * * * * cd /path/to/app && php artisan schedule:run >> /dev/null 2>&1
```
Verify the next day: a `z_reports` row exists for the prior day, a backup file exists in `storage/backups/`.

## 4. Supervised queue worker (so live sync doesn't silently degrade)
`QUEUE_CONNECTION=redis` — the worker must run as a supervised, auto-restarting service (systemd or supervisor), e.g.:
```ini
# /etc/supervisor/conf.d/foodking-worker.conf
[program:foodking-worker]
command=php /path/to/app/artisan queue:work redis --queue=high,default --sleep=1 --tries=3
autostart=true
autorestart=true
numprocs=1
```
If the worker dies, KDS/OSS push degrades to a 60s poll (no data loss — the poll reads `orders` directly — but slower). `outbox:monitor` + `/health/ready` surface the degradation.

## 5. Cash-drawer discipline (now enforced once sim=false)
With `POS_SIMULATION_HARDWARE=false`, every CASH sale requires an OPEN `CashDrawerSession`. Train the cashier:
- **Shift start**: open a drawer session (POS UI).
- **Shift end**: close + reconcile the drawer; run the EOD PDF clôture.
A cash sale with no open session is blocked at the controller (NF525 cash trail).

## 6. Receipt printer (strong-recommend S2 — not a hard blocker)
`Printer::count()=0`. French law (2023 anti-gaspillage): ticket **on request**, not systematic — so you can open without one. To hand paper tickets, configure one ESC/POS thermal printer in admin (`EscPosPrinterService` is wired). The fiscal ticket DATA + EOD PDF already generate.

## 7. GO/NO-GO GATE — `app:preflight-production`
The single command that decides safe-to-open. Run it AFTER steps 1–4:
```bash
php artisan app:preflight-production
# Exit 0 = safe. Exit 1 = at least one CRITICAL — DO NOT open.
```
It hard-checks (CRITICAL): APP_ENV=production, APP_DEBUG=false, app key, cache/queue
drivers, fiscal secrets, fiscal verify-chain, **MENU_VAT** (every active item on a
non-zero VAT — catches a 0%/stale menu), **POS_SIMULATION_HARDWARE=false** (cash
trail). WARNINGs: **MENU_COUNT** (< 40 active items → menu incomplete vs canonical
45), **POS_MANUAL_DISCOUNT** enabled while F1 unfixed, log channel. On dev today it
correctly FAILS on POS_SIMULATION_HARDWARE=true — that is the flip you make in step 2.

## 8. Pre-open smoke (5 min, on the box)
- Place ONE real test order on the kiosk → it appears on KDS → collect at the caisse → **receipt shows 10% TVA, total unchanged** → it lands in `/admin/historique` with a fiscal number.
- `php artisan fiscal:verify-chain --all` → CHAIN OK.
- If that smoke order should not be the first real fiscal record, re-run the step-1 (A) order/fiscal clear (NOT `migrate:fresh --seed`, which would wipe the canonical menu). Or keep it — it is a real, correct order.

## Deferred / owner decisions (not go-live blockers if handled)
- **F1** (TVA/HT split on DISCOUNTED orders, frozen) — now ENFORCED dormant: offers disabled (S1) AND manual POS discounts disabled (`pos.manual_discount_enabled=false`, this cycle). A discount cannot be applied → F1 cannot fire. **Do not set `POS_MANUAL_DISCOUNT_ENABLED=true` until F1 is fixed under a lock-plan** (preflight WARNS if you do).
- **delta-B** (walk-in unified collection) — built, default OFF; activate only after the cross-Z-window settlement decision.
- **changePaymentStatus escape-Z** — pre-existing, detect-only via the `fiscal:verify-z-membership` cron (now firing 06:05).
