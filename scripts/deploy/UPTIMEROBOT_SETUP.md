# UPTIMEROBOT_SETUP.md — FoodKing Le Cayenne V1

> External uptime monitor setup for the V1 LOCAL production host.
> Phase A.1 of Gap-Hunt 2026-05-25 — ops gate #1 (OPS-GATE-1).
> Tag: 2026-05-25. Branch: `heal/cms-pr1-quickwins-2026-05-18`.
>
> **Scope.** A4 print-ready runbook for the owner. Pair this doc with
> `scripts/deploy/CRONTAB_PROD.md` (system cron + Laravel scheduler) and
> with the on-host `healthz:check` heartbeat lane (5-min, writes to
> `storage/logs/heartbeat.log`).

---

## Part 1 — Why an external monitor?

The Laravel scheduler runs `healthz:check` every 5 minutes locally and
appends a heartbeat line to `storage/logs/heartbeat.log`. That trail
is **on-host only**. If the host itself goes down (power loss, OVH /
Hetzner network blackout, kernel panic, disk-full), the cron does not
fire, the log stops growing, and **nobody knows until the next time
someone walks past the borne and sees a black screen**.

An external monitor solves that: a third party pings the public URL
`https://lecayenne.fr/api/healthz` every 5 minutes from the open
internet. If the URL stops answering with HTTP 200, the monitor pages
the owner by email + SMS within 60 seconds.

**This is the single cheapest layer of defense against a silent
production outage.** Free tier is enough for V1 LOCAL (one host, one
probe).

---

## Part 2 — UptimeRobot setup (recommended, free)

### 2.1 Create the account

1. Open <https://uptimerobot.com/> in a browser.
2. Click "Register for FREE" (top-right).
3. Enter the **owner email** (the one that should receive alerts).
4. Confirm via the activation email UptimeRobot sends.
5. Log in to the dashboard.

### 2.2 Add the monitor

1. From the dashboard, click "+ New monitor" (top-left green button).
2. Fill the form:

   | Field                  | Value                                       |
   | ---------------------- | ------------------------------------------- |
   | **Monitor Type**       | HTTP(s)                                     |
   | **Friendly Name**      | `Le Cayenne — /api/healthz`                 |
   | **URL (or IP)**        | `https://lecayenne.fr/api/healthz`          |
   | **Monitoring Interval**| 5 minutes (free tier minimum)               |
   | **Monitor Timeout**    | 30 seconds                                  |
   | **HTTP Method**        | GET                                         |
   | **Alert when down for**| 1 occurrence (= alert on first failure)     |

3. Scroll down to "Select alert contacts to notify". Tick at least
   the default email contact.
4. Click "Create monitor".

### 2.3 Add SMS alerts (recommended, free tier = 20 SMS / month)

1. Go to "My Settings" → "Alert Contacts" (top menu).
2. Click "+ Add Alert Contact".
3. Type = "SMS". Enter the **owner phone number in E.164 format**
   (e.g. `+33612345678`).
4. UptimeRobot sends a verification SMS. Enter the 4-digit code.
5. Back in the monitor (Part 2.2), tick the new SMS contact alongside
   email. Save.

### 2.4 Test the alert path

1. From a terminal on the production host:
   ```bash
   sudo systemctl stop nginx       # simulate outage
   ```
2. Wait 5-10 minutes (one monitoring cycle).
3. The owner should receive an email + SMS: "Monitor is DOWN — Le
   Cayenne — /api/healthz".
4. Restart nginx:
   ```bash
   sudo systemctl start nginx
   ```
5. Within 5-10 minutes a recovery alert lands: "Monitor is UP".

**If the test fails**, troubleshoot in this order:
- Email landed in spam? Move to inbox + mark "Not spam".
- SMS never arrived? Confirm the phone number in My Settings →
  Alert Contacts is verified (status = "Active", not "Pending").
- Monitor never flipped to DOWN? Confirm `https://lecayenne.fr/api/healthz`
  is publicly reachable (curl from outside the office).

---

## Part 3 — What the monitor sees

The `/api/healthz` endpoint returns a compact JSON body:

```json
{
  "status": "ok",
  "checks": {
    "db": "ok",
    "redis": "ok",
    "websocket": "ok",
    "fiscal_chain": "ok",
    "queue_pending": 3
  },
  "timestamp": "2026-05-25T14:30:00+02:00"
}
```

**HTTP status policy:**
- All subsystems OK → HTTP 200, `status=ok`. Monitor stays green.
- Mixed (some ok, some fail) → HTTP 200, `status=degraded`. Monitor
  stays green (V1 lenient), but the heartbeat log captures the
  degradation for forensic review.
- All subsystems FAIL → HTTP 503. Monitor flips RED → email + SMS.

V1 LOCAL Le Cayenne accepts the "lenient degraded" stance because the
borne has 30s polling fallbacks for realtime sync. V2 SaaS will tighten
this (degraded → 503) when multi-tenant SLA contracts are in place.

---

## Part 4 — Fallback monitors (if UptimeRobot rejects free tier)

If for any reason UptimeRobot is unavailable, the following providers
expose the same `HTTP GET + alert` shape and all offer a free or
cheap-paid tier sized for V1 LOCAL:

| Provider          | Free tier              | URL                                  |
| ----------------- | ---------------------- | ------------------------------------ |
| **Cronitor**      | 5 monitors / 60s       | <https://cronitor.io/>               |
| **Better Stack**  | 10 monitors / 30s      | <https://betterstack.com/>           |
| **HetrixTools**   | 1 monitor / 1-min      | <https://hetrixtools.com/>           |
| **Pingdom**       | 14-day trial then paid | <https://pingdom.com/>               |
| **Healthchecks.io** | 20 checks / free     | <https://healthchecks.io/>           |

**Recommendation order:** UptimeRobot → Cronitor → Better Stack →
HetrixTools. The setup steps for each are nearly identical: register,
add HTTP monitor on `https://lecayenne.fr/api/healthz`, configure
email + SMS contact, run the nginx-stop test from Part 2.4.

---

## Part 5 — Operational runbook on alert

When the owner receives a DOWN alert from UptimeRobot:

1. **First action — verify the alert is real**:
   ```bash
   curl -sS -o /dev/null -w "%{http_code}\n" https://lecayenne.fr/api/healthz
   ```
   If 200 → false positive (DNS / monitor blip). Acknowledge in
   UptimeRobot and watch the next cycle.

2. **If non-200**, SSH to the host and check the heartbeat log:
   ```bash
   tail -50 /var/www/lecayenne/storage/logs/heartbeat.log
   ```
   The most recent line tells you which subsystem failed
   (`db=fail`, `redis=fail`, `fiscal=fail`, `ws=fail`).

3. **Per-subsystem playbook:**

   - **db=fail** → `sudo systemctl status mysql`; if down, `restart`.
   - **redis=fail** → `sudo systemctl status redis-server`; if down, `restart`.
     **WARNING:** restarting Redis flushes the cache including
     idempotency-key locks. Inform the on-shift cashier first.
   - **fiscal_chain=fail** → DO NOT TOUCH. NF525 chain corruption is a
     legal emergency. Page the dev team immediately. Run
     `php artisan fiscal:verify-chain --branch=1` to surface the
     offending row id.
   - **websocket=fail** → `sudo systemctl status soketi`; if down,
     `restart`. Kiosk borne keep working (30s polling fallback) but
     realtime sync between POS / KDS / OSS will lag.

4. **If you are unable to recover within 15 minutes**, switch all
   POS terminals to the manual fallback receipt book (see the binder
   on the cashier desk) and call the dev team.

---

## Part 6 — Cost summary

| Layer                       | Monthly cost | Notes                              |
| --------------------------- | ------------ | ---------------------------------- |
| UptimeRobot Free            | 0 €          | 50 monitors / 5-min / email        |
| UptimeRobot Pro (optional)  | 7 € / month  | 60s interval + 50 SMS / month      |
| Local heartbeat cron        | 0 €          | already on the host                |

V1 LOCAL Le Cayenne runs entirely on the free tier. Upgrade to Pro
only once V2 SaaS multi-tenant contracts require sub-5-min RTO.

---

## Part 7 — Acceptance checklist (owner, after first install)

Print this page and tick each box when verified:

- [ ] UptimeRobot account created with the owner email
- [ ] Monitor "Le Cayenne — /api/healthz" exists in the dashboard
- [ ] Monitor interval = 5 minutes
- [ ] Monitor type = HTTP(s) GET
- [ ] At least one email alert contact attached
- [ ] At least one SMS alert contact attached (E.164 verified)
- [ ] Test alert from Part 2.4 received both email + SMS
- [ ] Recovery alert from Part 2.4 received both email + SMS
- [ ] Heartbeat log on the host shows lines every 5 minutes
      (`tail -f storage/logs/heartbeat.log`)
- [ ] This page filed in the on-site operations binder

---

_End of UPTIMEROBOT_SETUP.md — Phase A.1 OPS-GATE-1 — 2026-05-25._
