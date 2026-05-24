# Runbook — DB Corruption Recovery (FoodKing V1 Le Cayenne, NF525-compliant)

> **Scope.** Procedures for detecting, isolating, and recovering from MySQL data
> corruption on the production single-box Hetzner CX22 deployment.
> Complements (does NOT replace):
> - `docs/runbooks/BACKUP_RESTORE_NF525.md` (backup pipeline + S3 lifecycle)
> - `scripts/db/RESTORE_DRILL_2026-05-21.md` (Q14 owner-signed quarterly drill)
> - `reports/test-e2e/goal-2026-05-23/phase-l/L10.1-dr-drill-findings.json`
>   (full bare-metal-loss 7-phase runbook)
>
> This document focuses on **per-row / per-table / per-column corruption**
> deltas that do NOT require a full server rebuild.

> **Honest framing.** This runbook is a **design**, not an empirically-drilled
> procedure. The constituent primitives are individually proven:
> - `fiscal:verify-chain --all` exit codes 0/1/2/3 (live, V1)
> - `scripts/restore-foodking-from-backup.sh` SHA256 + gzip -t + chain verify
> - InnoDB redo-log replay at crash recovery (MySQL default)
> - 8 NF525 immutability triggers (audit_logs / z_reports / cash_movements /
>   stock_movements / order_payments / cash_drawer_sessions / composition_snapshot)
>
> What has NOT been drilled in V1 LOCAL Le Cayenne:
> - Deliberately corrupting an `audit_logs` row and walking this runbook end-to-end.
>
> V1.0.X follow-up: schedule a **fault-injection drill** that flips one bit
> of an `audit_logs.current_hash` on a staging copy, runs this runbook, and
> attaches the proof to `scripts/db/RESTORE_DRILL_<date>.md`.

---

## 0. The ONE invariant that drives every decision

`audit_logs` and `z_reports` are **INSERT-only by DB trigger**:
- MySQL: `BEFORE UPDATE` / `BEFORE DELETE` raise `SIGNAL SQLSTATE '45000'`
  (migration `2026_04_22_000002_create_audit_logs_table.php` lines 97-115;
  migration `2026_05_09_160000_add_z_reports_delete_trigger_immutability.php`).
- SQLite (tests only): equivalent `RAISE(ABORT, ...)`.

**Consequence:** a corrupted `audit_logs` row **cannot be repaired in place**.
UPDATE is rejected even by DBAs with raw SQL. The only legal recovery paths are:

1. **RESTORE** from backup → live DB reverts to backup state, rows since
   backup are PERMANENTLY LOST (RPO ≤ 24 h per L10.1 / Kernel.php lane #8
   at 03:00 Paris).
2. **ACCEPT chain break** → file NF525 incident report (CGI Article 286 III bis,
   6-year retention upheld in backup). Inspector's chain remains continuous
   from the inspector's viewpoint because the backup file is the legal
   source of truth post-disaster.

**NOT VIABLE** — replay rows from fiscal log channel. `AuditLogService` line 167-176
intentionally logs only `hash_prefix` (first 12 chars), never the full payload
(PII protection). Reconstruction from logs is structurally blocked.

---

## 1. Detection — how corruption surfaces

| Signal | Source | Catches | Latency |
|---|---|---|---|
| `fiscal:verify-chain --all` exit 1 | Daily cron `fiscal-chain-monitor` (Kernel.php) + on-demand operator CLI | `audit_logs` signature mismatch, chain break, `z_reports` chain break / sequence gap / signature mismatch | Up to 24 h (daily) or immediate (on-demand) |
| `FiscalChainCorruptedException` thrown at Z-open | `FiscalChainValidator::assertChainIntegrity` invoked at every Z-report open (see `ZReportService::open`) | Tail 500 rows audit chain + full Z chain — blocks Z-open if corrupted | Immediate on next Z-open attempt |
| Restore script SHA256 mismatch | `scripts/restore-foodking-from-backup.sh:42-44` | Backup file silent disk corruption / mid-stream truncation | Immediate (refuses restore) |
| `gzip -t` exit non-zero | Same restore script implicit via `gunzip -c` | Compressed backup file corruption | Immediate |
| MySQL crash recovery log | `/var/log/mysql/error.log` post-restart | InnoDB redo-log replay failures | Immediate at MySQL boot |
| Boot guards refuse to start | `AppServiceProvider::boot` 10 guards (L12.1) | Mis-configured restore env (wrong DB, missing secrets) | Immediate on first Laravel HTTP/CLI request |
| `BEFORE UPDATE/DELETE` trigger fires | DB-level `SIGNAL SQLSTATE '45000'` | Anyone trying to repair-in-place a fiscal row | Immediate at the offending SQL |

**Primary detection contract:** `fiscal:verify-chain --all` is the canonical
NF525 chain probe. Its exit codes are:

| Exit | Meaning | Operator response |
|---|---|---|
| 0 | `SWEEP COMPLETE — CHAIN OK on every active branch (N total)` | None |
| 1 | `TAMPER detected on X/N branches` + per-row `audit_logs.id=N` / `z_reports.id=N (kind)` | Treat as corruption — proceed to §3 |
| 2 | `Branch ID 0 is reserved` / `Branch ID N not found` | Operator typo, re-run with `--all` or correct `--branch=` |
| 3 | `Verification FAILED to execute: <msg>` | DB outage / missing secret — page ops, NOT a corruption event |

---

## 2. Corruption scenarios — taxonomy

### 2.1 Cosmic-ray bit-flip in `audit_logs.current_hash`
- **Cause.** Silent DRAM bit-flip (un-ECC) OR disk-level bit rot.
- **Detection.** `fiscal:verify-chain --all` reports `signature_mismatch` at the
  flipped row AND `chain_break` at every subsequent row (the prev_hash anchor
  no longer matches the recomputed downstream hash).
- **NF525 implication.** **CRITICAL** — chain is broken from the corrupted row
  forward. Inspector visit would surface the break. Incident report mandatory
  (CGI 286 III bis).
- **Recovery.** §4 decision tree → restore from backup (the corrupted row
  AND every row after it are lost in the live DB; backup preserves the
  pre-corruption state).

### 2.2 `z_reports` HMAC signature mismatch (single Z)
- **Cause.** Bit-flip in `signature` column OR `cash_total_cents` mutated
  post-close (BEFORE DELETE trigger covers DELETE but `z_reports` UPDATE
  trigger DOES NOT exist as of 2026-05-24 — see `find ... z_reports*update*` returns 0).
- **Detection.** `fiscal:verify-chain --branch=N` returns
  `z_reports.id=N (signature_mismatch)` or `chain_break` or `sequence_gap`.
- **NF525 implication.** **CRITICAL** — daily-close evidence corrupted.
  Inspector would refuse the Z as proof of revenue for that day.
- **Recovery.** §4 decision tree → restore from backup. If the corrupted
  Z is the most recent Z and backup is from an earlier Z, owner must
  re-run `fiscal:close --branch=N --date=<lost-day>` on the restored DB
  (cron lane `fiscal:close-all-active-branches`).

### 2.3 `orders` table corruption (non-fiscal row)
- **Cause.** Same as §2.1 but on a non-NF525 table.
- **Detection.** Application-level read failure (Eloquent cast / JSON parse).
  No automated sentinel — surfaces as 500 in admin/POS UI.
- **NF525 implication.** **LOW** if `audit_logs` for that order is intact
  (the audit row IS the legal evidence of revenue, not the order row).
- **Recovery.** `orders` table is NOT INSERT-only → repair-in-place IS legal:
  `UPDATE orders SET <col>=<value> WHERE id=N;` from the most recent backup's
  value. Or full table replace from backup if many rows affected.

### 2.4 `composition_snapshot` malformed JSON
- **Cause.** Bit-flip in JSON column OR (pre-2026-05-24) an attacker UPDATE
  on `order_items.composition_snapshot`.
- **Detection.** `OrderItem::getCompositionSnapshotAttribute` cast throws
  `json_decode` error at runtime. NO sentinel cron.
- **Post-2026-05-24 protection.** Migration `2026_05_24_040211` adds a
  `BEFORE UPDATE` trigger blocking any mutation to `order_items.composition_snapshot`
  (the legally-binding pricing evidence).
- **NF525 implication.** **MEDIUM** — `composition_snapshot` is the fiscal
  evidence that the displayed price matched the audit-logged price.
  Inspector challenge would weaken the defence.
- **Recovery.** Repair-in-place is **BLOCKED by trigger** post-2026-05-24.
  Must restore from backup. Pre-2026-05-24 affected rows on the corrupted
  DB: re-fetch JSON from backup, UPDATE in place (still allowed because
  the trigger only blocks post-2026-05-24 mutations on the live DB).

### 2.5 Full table loss (`audit_logs` / `z_reports` dropped)
- **Cause.** Accidental `DROP TABLE` by a DBA (block by trigger covers
  UPDATE/DELETE on rows, NOT DROP on the table). Disk corruption losing
  the `.ibd` file. Ransomware.
- **Detection.** Every Laravel HTTP/CLI request fails. `fiscal:verify-chain`
  exits 3 (execution error — DB query fails).
- **NF525 implication.** **CRITICAL** — total fiscal evidence loss on the
  live DB. Backup is the only legal evidence remaining.
- **Recovery.** §4 decision tree → restore from backup mandatory. If the
  whole server is lost, follow L10.1 7-phase bare-metal runbook.

### 2.6 mysqldump truncation (backup file corrupted, live DB fine)
- **Cause.** Disk full during backup → partial gzip stream → restore would
  load partial schema → silently broken chain on the restored DB.
- **Detection.** Restore script `scripts/restore-foodking-from-backup.sh`
  refuses BEFORE touching DB:
  - Line 42-44: `sha256sum -c` against sidecar `.sha256` file.
  - `gunzip -c` exits non-zero on stream corruption.
- **NF525 implication.** **LOW** — live DB is intact, backup is the
  problem; fall back to previous-day backup (kept 7 days local +
  weekly/monthly/quarterly rotation per `RunDailyBackup.php`).
- **Recovery.** §4 decision tree → restore from previous-day backup;
  delete the corrupted backup file; investigate disk health.

### 2.7 OOM / disk-failure crash mid-INSERT
- **Cause.** Out-of-memory kill during `INSERT INTO audit_logs ...` OR
  power loss / disk failure mid-write.
- **Behaviour.** **No corruption.** InnoDB redo-log replays at MySQL restart;
  uncommitted transactions roll back atomically. The half-row is either
  fully committed (visible) or fully absent (rolled back).
- **NF525 implication.** **NONE** — same as a normal failed write attempt.
  No manual intervention required.
- **Recovery.** None. Run `fiscal:verify-chain --all` after restart to
  confirm; expected result = `CHAIN OK`.

### 2.8 `cash_movements` / `order_payments` / `stock_movements` corruption
- **NF525 implication.** **MEDIUM** — these tables have BEFORE DELETE
  triggers (cash_movements + order_payments) and BEFORE UPDATE + BEFORE
  DELETE (stock_movements). Same restore-only constraint as audit_logs.
- **Recovery.** Restore from backup, NOT repair-in-place.

---

## 3. Forensic preservation requirements

**MANDATORY BEFORE ANY RECOVERY ACTION** that touches the corrupted DB:

1. **Snapshot the broken DB** (forensic evidence per CGI 286 III bis):
   ```bash
   mysqldump --single-transaction --routines --triggers --events \
       --set-gtid-purged=OFF foodking \
       | gzip > /tmp/foodking-broken-$(date -u +%Y%m%dT%H%M%SZ).sql.gz
   sha256sum /tmp/foodking-broken-*.sql.gz | tee -a /tmp/foodking-incident.log
   ```
2. **Capture the verify-chain output** (exact row IDs of corruption):
   ```bash
   sudo -u www-data php artisan fiscal:verify-chain --all 2>&1 \
       | tee -a /tmp/foodking-incident.log
   ```
3. **Snapshot the error log** (MySQL + Laravel):
   ```bash
   cp /var/log/mysql/error.log /tmp/foodking-incident-mysql.log
   cp /var/www/foodking/storage/logs/laravel.log /tmp/foodking-incident-laravel.log
   cp /var/www/foodking/storage/logs/fiscal-*.log /tmp/foodking-incident-fiscal.log
   ```
4. **Lock the local backup chain** (do NOT let rotation evict the
   pre-corruption backup):
   ```bash
   ls -lh /var/www/foodking/storage/backups/db-daily/ \
       | tee -a /tmp/foodking-incident.log
   cp /var/www/foodking/storage/backups/db-daily/daily-$(date -d yesterday +%Y-%m-%d).sql.gz \
       /tmp/foodking-incident-prev-backup.sql.gz
   ```

These artefacts MUST be retained 6 years alongside the backup (CGI 286 III bis).

---

## 4. Recovery decision tree

```
┌─ fiscal:verify-chain --all exit code? ─────────────────────────────┐
│                                                                    │
├─ 0 (CHAIN OK)                                                      │
│  └─ No action. (False alarm OR corruption healed by §2.7.)         │
│                                                                    │
├─ 1 (TAMPER)                                                        │
│  ├─ §3 forensic preservation (MANDATORY)                           │
│  ├─ Which table?                                                   │
│  │  ├─ audit_logs OR z_reports (§2.1 / §2.2)                       │
│  │  │  └─ Repair impossible (trigger). RESTORE from backup.        │
│  │  │     §5 step-by-step → §6 NF525 incident report MANDATORY.    │
│  │  ├─ composition_snapshot (§2.4)                                 │
│  │  │  └─ Trigger blocks UPDATE post-2026-05-24. RESTORE from      │
│  │  │     backup. §5 step-by-step → §6 incident report.            │
│  │  └─ Non-fiscal table (orders, items, customers...)              │
│  │     └─ Repair-in-place legal. Read good value from backup       │
│  │        SELECT, apply UPDATE on live DB. No incident report.     │
│                                                                    │
├─ 2 (INVALID args)                                                  │
│  └─ Operator typo, not corruption. Re-run with valid args.         │
│                                                                    │
├─ 3 (EXEC ERROR)                                                    │
│  ├─ DB outage? → restart MySQL, verify boot guards (L12.1).        │
│  ├─ Missing fiscal.audit_secret? → restore secret from password    │
│  │  manager, re-verify. NOT a corruption event.                    │
│  └─ Table missing entirely? → §2.5 full table loss. RESTORE +      │
│     L10.1 7-phase bare-metal if disk dead.                         │
│                                                                    │
└─ Backup file SHA256 mismatch OR gunzip -t fail?                    │
   └─ §2.6 — fall back to previous-day backup, investigate disk.     │
```

---

## 5. Step-by-step recovery — partial DB corruption (live DB intact disk)

**Pre-condition:** detection per §1, forensic snapshot per §3 done.

### Step 1 — Stop the app (prevent further writes)
```bash
sudo systemctl stop nginx php8.4-fpm
sudo supervisorctl stop foodking-queue-worker
```

### Step 2 — Isolate the corrupted table (rename, do not drop)
```bash
TS=$(date -u +%Y%m%d_%H%M%S)
MYSQL_PWD="$DB_PASSWORD" mysql -e "
  RENAME TABLE foodking.audit_logs TO foodking.audit_logs_corrupted_${TS};
"
# Repeat for z_reports if affected.
```
Rationale: triggers reject UPDATE/DELETE on rows BUT `RENAME TABLE` is allowed.
The corrupted table is preserved on-disk for forensics; the live name is
empty for the restore.

### Step 3 — Restore the affected table(s) from backup
Two paths, depending on scope:

**Path A — Single table restore** (preferred when only `audit_logs` or
`z_reports` corrupted):
```bash
# Extract only the target table from the backup.
gunzip -c /var/www/foodking/storage/backups/db-daily/daily-YYYY-MM-DD.sql.gz \
  | sed -n '/^-- Table structure for table `audit_logs`/,/^-- Table structure for table `[^a]/p' \
  > /tmp/audit_logs-only.sql

# Apply.
MYSQL_PWD="$DB_PASSWORD" mysql foodking < /tmp/audit_logs-only.sql
```

**Path B — Full DB restore** (preferred when multiple fiscal tables corrupted
OR scope unclear):
```bash
sudo -u www-data /var/www/foodking/scripts/restore-foodking-from-backup.sh \
    /var/www/foodking/storage/backups/db-daily/daily-YYYY-MM-DD.sql.gz \
    foodking_recovered
# Script verifies SHA256 + gunzip -t + post-restore chain verify.
# Restores to foodking_recovered scratch DB by default.
# If chain verify PASSES, promote:
MYSQL_PWD="$DB_PASSWORD" mysql -e "
  RENAME TABLE foodking.audit_logs TO foodking.audit_logs_pre_restore_${TS};
  RENAME TABLE foodking_recovered.audit_logs TO foodking.audit_logs;
  -- Repeat for z_reports + any other tables in scope.
"
```

### Step 4 — Run migrate forward (L10-F-02 mitigation)
```bash
cd /var/www/foodking
sudo -u www-data php artisan migrate --force
```
**NEVER SKIP THIS.** Migrations applied AFTER the backup ran (e.g. today's
`2026_05_24_040211_add_composition_snapshot_immutability_trigger`) will
silently lapse if you skip. The composition_snapshot UPDATE protection
would be gone post-restore until next deploy.sh — opening a window for
the very corruption you just recovered from.

### Step 5 — Verify chain integrity
```bash
sudo -u www-data php artisan fiscal:verify-chain --all
# Expected: SWEEP COMPLETE — CHAIN OK on every active branch (N total)
# Exit code MUST be 0.
```
If exit ≠ 0 → STOP. Do NOT restart app. Escalate — backup itself may be
corrupted (see §2.6, try previous-day backup).

### Step 6 — Re-run boot guards (post-restore env safety check)
```bash
sudo -u www-data php -r "require '/var/www/foodking/vendor/autoload.php'; \
  \$app = require '/var/www/foodking/bootstrap/app.php'; \
  \$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap(); \
  echo 'Boot guards PASSED' . PHP_EOL;"
```
If this throws, one of the 10 L12.1 production boot guards refused —
fix the env mismatch BEFORE restarting the app (typically `FISCAL_HMAC_SECRET`
or `FISCAL_Z_HMAC_SECRET` mismatch between backup-era secrets and current `.env`).

### Step 7 — Restart app + smoke test
```bash
sudo systemctl start php8.4-fpm nginx
sudo supervisorctl start foodking-queue-worker
# Smoke (per CLAUDE.md §6 visual mandate):
curl -fsSL https://lecayenne.fr/api/health | jq '.status'  # → "ok"
# Browser: /admin/pos → can place an order → check audit_logs row appears.
```

### Step 8 — Owner journal entry
Record in the operational journal (next to monthly DR drill log):
- date_utc / incident_id / corrupted_table / corrupted_row_ids
- backup_file_used / backup_sha256
- rows_lost (= live count - restored count, rows since backup permanently gone)
- chain_ok_post_recovery (exit code 0 from §5)
- forensic_artefacts_paths (§3 deliverables)

---

## 6. NF525 compliance checklist (MANDATORY before resuming operations)

**Any time `audit_logs` or `z_reports` was touched by recovery:**

- [ ] §3 forensic snapshot of corrupted DB completed + SHA256 recorded
- [ ] §5 step 5 `fiscal:verify-chain --all` exits 0 on recovered DB
- [ ] Incident report drafted with:
  - Detection timestamp (UTC + Europe/Paris)
  - Affected table + row IDs (from verify-chain output)
  - Root cause hypothesis (cosmic ray / disk error / ransomware / human error)
  - Backup file SHA256 used for restore + age
  - **Rows lost** (= live count at corruption − restored count) — these
    are GONE from the live chain; **document each lost row's business impact**
    (revenue, day, customer if discoverable from POS terminal logs).
  - Boot guard verification result
  - Re-verification result
- [ ] Incident report + forensic artefacts (§3) filed in **owner-controlled
  6-year retention bucket** (CGI Article 286 III bis — NF525 inspector
  may demand both the live chain AND the corruption evidence, OR
  proof the live chain is the legal reference from a specific date).
- [ ] If the corrupted rows represented revenue, **legal counsel notified**
  before the next inspector visit (declared loss vs undeclared loss).

**Source-of-truth shift after corruption recovery:**
- Pre-corruption: live `audit_logs` is the legal evidence.
- Post-corruption: **backup file** + this runbook execution log + forensic
  snapshot together form the legal evidence. The corrupted rows that
  existed on the live DB but not in the backup are LOST evidence — must
  be declared in the incident report.

---

## 7. Cross-references

| Topic | Source |
|---|---|
| Full bare-metal recovery (server lost) | `reports/test-e2e/goal-2026-05-23/phase-l/L10.1-dr-drill-findings.json` § `recovery_runbook_full_bare_metal` (7 phases, 125-180 min RTO) |
| Backup primitives + cron + S3 lifecycle | `docs/runbooks/BACKUP_RESTORE_NF525.md` (§ 1 setup, § 2 cron, § 3 DR drill, § 4 NF525 6y retention) |
| Quarterly restore drill (Q14 baseline) | `scripts/db/RESTORE_DRILL_2026-05-21.md` (PASS proof, 88 tables, 62 audit_logs round-trip) |
| Empirical DR timings | `reports/test-e2e/goal-2026-05-23/phase-l/L10.1-dr-drill-findings.json` § `empirical_restore_drill_l10_1` (1.749s DB-only round-trip) |
| Chain verify CLI contract | `app/Console/Commands/FiscalVerifyChainCommand.php` (exit codes 0/1/2/3, --branch + --all flags) |
| Audit chain HMAC algorithm | `app/Services/Fiscal/AuditLogService.php` lines 237-243 (`computeHash`), lines 199-231 (`verifyChain`) |
| Z chain HMAC algorithm | `app/Services/Fiscal/ZReportService.php::verifyChain` (returns array, three breach kinds) |
| Tail-bounded validator (50ms ceiling) | `app/Services/Fiscal/FiscalChainValidator.php` lines 118-183 (`verifyAuditChainTail`) |
| 8 NF525 immutability triggers in backup | `reports/test-e2e/goal-2026-05-23/phase-l/L10.1-dr-drill-findings.json` § `nf525_triggers_in_backup_observed` |
| L10-F-02 restore-then-migrate hazard | Same L10.1 findings file, finding L10-F-02 |
| Boot guard refusal contract | `reports/test-e2e/goal-2026-05-23/phase-l/L12.1-boot-guards-findings.json` (10 guards) |
| Cron lane catch-up matrix | `reports/test-e2e/goal-2026-05-23/phase-l/L11.1-cron-miss-recovery-findings.json` |
| Daily backup command + rotation | `app/Console/Commands/Backup/RunDailyBackup.php` (4-tier: daily 7d / weekly 4w / monthly 12m / quarterly 8q) |
| Restore script with SHA256 + chain verify | `scripts/restore-foodking-from-backup.sh` (78 lines, lines 42-44 = SHA256, lines 61-74 = chain verify) |
| Audit-trail-immutability migrations | `database/migrations/2026_04_22_000002_create_audit_logs_table.php` + `2026_05_09_160000_add_z_reports_delete_trigger_immutability.php` + `2026_05_10_010000_secure_fiscal_audit_trail_immutability.php` + `2026_05_18_140000_add_stock_movements_immutability_triggers.php` + `2026_05_24_040211_add_composition_snapshot_immutability_trigger.php` |

---

## 8. Known gaps (V1.0.X backlog)

1. **No `z_reports` BEFORE UPDATE trigger** — only DELETE blocked. Adversary
   with raw SQL access could UPDATE `signature` or `cash_total_cents`.
   Verify-chain catches it post-hoc, but no DB-level prevention.
   *Mitigation: add `z_reports_no_update` trigger mirroring `audit_logs_no_update`
   pattern.*
2. **`order_items.composition_snapshot` trigger added 2026-05-24** but
   migration is NOT in the 2026-05-23 backup file (L10-F-02). Recovery
   from pre-2026-05-24 backups silently un-protects this column until
   `migrate --force` runs in Step 4. *Mitigated by mandatory Step 4 in
   this runbook + L10-F-02 disposition.*
3. **No off-host backup as of 2026-05-24** (L10-F-04). All backups live
   on same disk as live DB. Hetzner Backups checkbox + manual offload
   to laptop/USB recommended. Owner gate per `feedback_no_cloud_until_owner_initiates.md`.
4. **No automated fault-injection drill** — this runbook has never been
   executed end-to-end. V1.0.X: add `scripts/db/CORRUPTION_DRILL_<date>.md`
   that deliberately corrupts a staging row and walks §5.
5. **No `--repair-non-fiscal-table` helper** for §2.3 case. Operator must
   hand-craft UPDATE statements from backup SELECT. V1.0.X: ship
   `php artisan db:repair-row --table=orders --id=N --from-backup=<file>`.

---

*Document version: 2026-05-24 (L6.3 Wave L-A initial draft, design-stage).*
