# DISK PRESSURE — OWNER ACTION REQUIRED

**Date:** 2026-05-25
**Severity:** P0 — system at 100% disk capacity
**Source:** bad-mood AUDIT-5 P0 (FIX-4 heal agent investigation)
**Scope:** SYSTEM-LEVEL, not project-side fixable

---

## TL;DR

The disk is **systemically full**: `/dev/disk3s5` reports `460Gi used / 3.3Gi free / 100% capacity`. The FoodKing project occupies ~3GB total. **No amount of project-side cleanup will materially fix this.** Owner action is required at the filesystem / OS / disk-provision level.

---

## State BEFORE conservative cleanup

```
/dev/disk3s5   460Gi   426Gi   3,1Gi   100%   /System/Volumes/Data
```

## State AFTER conservative cleanup

```
/dev/disk3s5   460Gi   426Gi   3,3Gi   100%   /System/Volumes/Data
```

**Net freed by project-side actions: ~200MB** (cosmetic — still at 100% capacity).

---

## Project-side breakdown (3GB total)

| Path                           | Size   |
|--------------------------------|--------|
| `.git/`                        | 1.1G   |
| `node_modules/`                | 671M   |
| `tests/e2e/__screenshots__/`   | 644M   |
| `vendor/`                      | 482M   |
| `storage/`                     | ~1.1G  |
|   `storage/logs/`              | 294M   |
| `public/`                      | 177M   |
| `reports/test-e2e/`            | 27M    |

**Verdict:** the project is **already lean**. Big consumers like `node_modules`, `vendor`, and `.git` cannot be safely reduced without breaking the dev environment. Test screenshots are 100% within 14-day window (current cycles) — not safe to delete.

---

## Cleanup actually performed (conservative, regenerable only)

1. **`git gc`** — packed loose objects, repacked deltas.
2. **`composer clear-cache`** — cleared user Composer cache `~/Library/Caches/composer/` (NOT project files; regenerable on next install).
3. **`php artisan cache:clear`** — Laravel app cache (regenerable on next request).
4. **`php artisan view:clear`** — compiled Blade views (regenerable on next render).
5. **`php artisan config:clear`** — config cache (regenerable on next boot).

All five operations are **idempotent and reversible** — caches rebuild automatically when needed.

---

## NOT touched (intentional safety)

- `storage/framework/sessions/` — active user sessions
- `storage/app/` — production user uploads (NF525 receipts, item images)
- `audit_logs` / `z_reports` DB tables — NF525 6-year retention legal requirement
- `tests/e2e/__screenshots__/` — all entries within 14-day window (active cycles + current `gap-hunt-2026-05-25` + `post-restore-2026-05-25`)
- `storage/logs/laravel-*.log` — keeping >14 day window in case orchestrator post-mortem needed
- `/tmp/foodking-*` — soak/test artifacts orchestrator may need
- `reports/test-e2e/goal-2026-05-23/` and `post-restore-2026-05-25/` — current cycle reports
- `reports/audits/` — bad-mood cycle artifacts (current investigation)
- `.playwright-mcp/page-2026-05-25T*.yml` — recent traces

---

## Why project cleanup is the wrong layer

The disk is 460GB and 426GB is used. Even if we deleted **the entire FoodKing project** (~3GB), the disk would still be at 92% capacity. The pressure source is elsewhere on the system — likely:

1. **Other macOS user data** — `~/Downloads`, `~/Desktop`, `~/Documents`, Photos library, iCloud cache.
2. **Time Machine local snapshots** — APFS local snapshots can occupy tens of GB invisibly.
3. **Xcode caches** — `~/Library/Developer/Xcode/DerivedData` and iOS device support files.
4. **Other application caches** — Chrome, Slack, Spotify, Docker disk images.
5. **System logs / OS swap** — under `/private/var/`.

---

## Owner-actionable next steps (recommended order)

### Step 1 — Free 30-50GB fast via macOS native tools
```bash
# Purge Time Machine local snapshots (often massive, invisible)
tmutil listlocalsnapshots /
sudo tmutil thinlocalsnapshots / 50000000000 4  # frees up to 50GB

# Empty Trash
osascript -e 'tell application "Finder" to empty trash'

# Clear Xcode DerivedData (if Xcode installed)
rm -rf ~/Library/Developer/Xcode/DerivedData/*

# Docker disk image (if Docker Desktop installed)
docker system prune -a --volumes
```

### Step 2 — Use Apple "About This Mac → Storage" tool
- Apple menu → About This Mac → More Info → Storage Settings
- Visualizes biggest consumers, lets you offload to iCloud or delete safely
- Categories: Applications, Music, Photos, Documents, System Data

### Step 3 — Check user Downloads / Desktop
```bash
du -sh ~/Downloads ~/Desktop ~/Documents 2>&1 | sort -rh
du -sh ~/Library/Caches/* 2>&1 | sort -rh | head -20
```

### Step 4 — If still tight, expand disk or move project
- External SSD for backups / screenshots
- Migrate FoodKing project to dedicated dev partition
- Cloud project workspace (later — owner mandate "no cloud until owner initiates")

---

## Why this is logged as owner-action (not heal-loop)

- AUDIT-5 P0 was correctly raised — disk pressure WILL cause log writes, image uploads, and backups to fail within hours.
- FIX-4 cannot fix system-level disk capacity from inside the project.
- Project-side cleanup is already at lean state; further reduction risks deleting recoverable cycle data the orchestrator may reference.
- **Owner has root / Finder / Storage Settings access** that Claude does not.

---

## NF525 / safety impact

- **NF525 chain bit-identical** — no audit_logs or z_reports touched.
- **Frozen-zone diff = 0** — no frozen files modified.
- **Branch isolation intact** — no BranchScope-protected models touched.
- **No DB operations performed.**

---

## Verdict

**FIX-4 status:** PARTIAL — project-side cleanup performed (regenerable caches only, ~200MB cosmetic gain). **System-level disk pressure requires owner action** — documented above with concrete next steps.

**Recommendation:** Owner runs Step 1 (Time Machine snapshot purge) within 24h to restore safe operating margin. Defer FIX-4 commit pending owner decision on which directories to prune.

---

*Generated by HEAL AGENT FIX-4, bad-mood Le Cayenne V1 2026-05-25*
