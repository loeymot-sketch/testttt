# deploy/local — FoodKing V1 LOCAL Le Cayenne · Mac box daemon + scheduler setup

> **Box reality (verified 2026-06-04).** Single **macOS** box (Darwin 25.4.0),
> Homebrew php@8.2 + nvm node v18.20.7, Homebrew redis. This is NOT the Hetzner
> Linux host that `deploy/ansible/` + `scripts/deploy/CRONTAB_PROD.md` target —
> macOS has no `supervisord` and no `/etc/cron.d/`, so we use **launchd user
> agents** + the **user crontab**. The Ansible/Hetzner artifacts stay valid for
> the eventual cloud cutover; these files are the local equivalent.

This directory ships everything to (a) auto-start + auto-restart the app daemons
and (b) wake the dormant Laravel scheduler. **It does not run anything on its own.**
Nothing here is loaded until you explicitly run the install/start steps below.

---

## 0. Current state this fixes

`crontab -l` → empty ⇒ **all 22 scheduled lanes are DORMANT** (backup, fiscal
close/open, prune, cleanup, chain monitor…). `php artisan schedule:list` shows the
22 lanes that *want* to run but never do. The 3 app daemons are launched **by hand**
and do **not** survive a logout/reboot/crash:

| Daemon            | Live @ 2026-06-04        | Auto-restart today | After this setup |
|-------------------|--------------------------|--------------------|------------------|
| `artisan serve` :8000      | running (manual)  | no  | yes (launchd KeepAlive) |
| `queue:work` default       | running (manual)  | no  | yes |
| `queue:work --queue=high`  | running (manual)  | no  | yes |
| `soketi` :6001             | **NOT running**   | no  | yes |
| `redis` :6379              | running via `brew services` | **yes already** | unchanged |
| Laravel scheduler          | **never runs**    | n/a | yes (crontab) |

---

## 1. Daemon auto-start + auto-restart (launchd)

Four user-agent plists + one wrapper:

| File | Daemon |
|------|--------|
| `fr.lecayenne.serve.plist`       | `artisan serve --host=127.0.0.1 --port=8000` |
| `fr.lecayenne.queue.plist`       | `queue:work redis` (default lane) |
| `fr.lecayenne.queue-high.plist`  | `queue:work redis --queue=high` |
| `fr.lecayenne.soketi.plist`      | Soketi WS `:6001` |
| `dev-stack.sh`                   | install/start/stop/restart/status wrapper |

All plists: `RunAtLoad=true` + `KeepAlive=true` ⇒ they start on login and respawn on
crash. Logs land in `storage/logs/launchd-*.log`. Binaries are **absolute** (launchd
does not inherit shell PATH); soketi invokes nvm node directly against its `server.js`
(see that plist's header for the shebang/PATH trap).

### Install (copy plists — does NOT load)
```bash
deploy/local/dev-stack.sh install      # copies the 4 plists into ~/Library/LaunchAgents/
deploy/local/dev-stack.sh lint         # plutil -lint sanity (all 4 → OK)
```

### Start (load + run) — ⚠️ STOP THE MANUAL DAEMONS FIRST
The manually-launched `serve`/`queue`/`soketi` hold ports 8000/6001 and a parallel
session may be live. `start` will conflict if they are still up. Quiesce first:
```bash
# Identify + kill the hand-launched daemons (NOT redis — leave brew's redis alone):
pkill -f "artisan serve"          # frees :8000
pkill -f "queue:work redis"       # both default + high
pkill -f "soketi"                 # frees :6001 (was not running anyway)

deploy/local/dev-stack.sh start    # launchctl bootstrap the 4 daemons
deploy/local/dev-stack.sh status   # state + listening ports (expect 8000/6001/6379)
```

### Operate
```bash
deploy/local/dev-stack.sh restart   # launchctl kickstart -k all 4
deploy/local/dev-stack.sh stop      # bootout all 4
deploy/local/dev-stack.sh uninstall # bootout + remove plists
```

> **redis is not managed here** — it is a Homebrew service
> (`~/Library/LaunchAgents/homebrew.mxcl.redis.plist`, already auto-restarting). Use
> `brew services restart redis`.

---

## 2. Laravel scheduler (the dormant cron — install this line)

The scheduler needs the OS to poke it every minute. All 22 lanes already live in
`app/Console/Kernel.php::schedule()` — **do NOT add individual lanes to crontab.**
One line is the whole job:

```cron
* * * * * cd /Users/1millnonstop/Downloads/projet/foodking-web/web/testttt && /opt/homebrew/opt/php@8.2/bin/php artisan schedule:run >> storage/logs/schedule.log 2>&1
```

### Install
```bash
# 1. Pre-create the log file:
touch /Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/storage/logs/schedule.log

# 2. Append the line to YOUR user crontab (macOS has no /etc/cron.d):
( crontab -l 2>/dev/null; \
  echo '* * * * * cd /Users/1millnonstop/Downloads/projet/foodking-web/web/testttt && /opt/homebrew/opt/php@8.2/bin/php artisan schedule:run >> storage/logs/schedule.log 2>&1' \
) | crontab -

# 3. Verify it registered:
crontab -l
```

> **macOS gotcha — Full Disk Access.** Under modern macOS, `cron` runs sandboxed.
> If `schedule.log` stays empty after a few minutes, grant **Full Disk Access** to
> `/usr/sbin/cron` in System Settings → Privacy & Security, then `sudo killall cron`.

### Verify lanes (read-only)
```bash
/opt/homebrew/opt/php@8.2/bin/php artisan schedule:list   # expect 22 lanes
tail -f storage/logs/schedule.log                          # watch every-minute lanes fire
```

### Why an absolute php path
`cron`'s PATH is minimal and would not find `/opt/homebrew/opt/php@8.2/bin/php`.
Same reason the plists use absolute binaries.

---

## 3. Relationship to the Hetzner artifacts

| Concern        | Hetzner (cloud, future)                          | This box (local, now)                  |
|----------------|--------------------------------------------------|----------------------------------------|
| Daemon supervisor | `supervisor-foodking.conf.j2` (supervisord)   | launchd plists here                    |
| Scheduler cron | `/etc/cron.d/lecayenne-scheduler`                | user `crontab -e` line (§2)            |
| Backup/prune/Z | Same `Kernel.php` lanes (single source of truth) | Same — woken by the same `schedule:run`|
| Log rotation   | `/etc/logrotate.d/lecayenne`                     | macOS `newsyslog` / manual (out of scope) |

The single source of truth for *what* runs is always `app/Console/Kernel.php`. These
files only decide *how* it is started on this specific box.

---

*Authored 2026-06-04 (OPS-3 supervisor campaign). Deploy/docs only — no app code,
no Kernel edit, no frozen-zone touch. Plists `plutil -lint`-clean; `schedule:list`
confirms 22 lanes; nothing here was loaded/activated.*
