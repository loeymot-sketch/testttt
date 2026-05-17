# Runbook — Backup & Restore NF525 (OVH VPS-1 + Object Storage Cold)

Daily backup of MySQL `foodking` with NF525-mandatory triggers/routines,
uploaded to OVH Object Storage Cold (D2: GRA primary + SBG cross-AZ secondary).
Scripts: `scripts/backup-foodking-daily.sh`, `scripts/restore-foodking-from-backup.sh`.

> Document explicitly: these scripts MUST be tested in a staging environment
> BEFORE production cron activation (DR drill mandatory per Master Plan
> condition C3 — no production cron without a green staging dry-run).
>
> Document explicitly: the scripts assume the OVH `s3cmd` CLI is installed and
> credentials are configured at `~/.s3cfg` for the user running the cron.
>
> Document explicitly: the restore script's `php artisan tinker --execute=...`
> invocation requires the `laravel/tinker` package (already in `composer require`).

---

## 1. Setup (one-shot)

```bash
sudo apt-get install -y s3cmd mysql-client
sudo s3cmd --configure   # OVH endpoint: s3.gra.io.cloud.ovh.net (or sbg)
# OR write ~/.s3cfg manually with:
#   host_base = s3.gra.io.cloud.ovh.net
#   host_bucket = %(bucket)s.s3.gra.io.cloud.ovh.net
#   access_key = <OVH S3 access key>
#   secret_key = <OVH S3 secret key>
#   use_https = True

sudo mkdir -p /var/backups/foodking /var/log
sudo chown www-data:www-data /var/backups/foodking
sudo chmod 750 /var/backups/foodking

# Export in cron env (cron has NO .env by default):
cat <<'ENV' | sudo tee /etc/foodking-backup.env
FOODKING_ROOT=/var/www/foodking
BACKUP_S3_BUCKET=s3://foodking-backups-gra
BACKUP_LOCAL_DIR=/var/backups/foodking
BACKUP_LOCAL_RETENTION_DAYS=7
BACKUP_ALERT_WEBHOOK=https://hooks.example/foodking-ops
ENV
sudo chmod 640 /etc/foodking-backup.env
```

The script sources `${FOODKING_ROOT}/.env` for DB credentials, so the cron
shell does NOT need `DB_*` exports — only the wrapper vars above.

---

## 2. Daily cron (production)

```cron
# /etc/cron.d/foodking-backup   (root)
0 3 * * * www-data . /etc/foodking-backup.env && /var/www/foodking/scripts/backup-foodking-daily.sh >> /var/log/foodking-backup.log 2>&1
```

Runs nightly at 03:00 UTC. Exit non-zero triggers webhook alert + non-zero
cron mail. Local copies rotated >7 days; remote retention enforced via
Object Storage lifecycle (see §4).

---

## 3. DR drill procedure (MONTHLY — owner-gate)

Mandatory monthly to prove backups are restorable AND that the NF525 HMAC
chain survives the round-trip (a backup without verified triggers is
non-compliant — the chain breaks silently on restore).

```bash
# Pull yesterday's dump from S3 and restore into foodking_restore on staging:
sudo -u www-data /var/www/foodking/scripts/restore-foodking-from-backup.sh \
    s3://foodking-backups-gra/foodking-YYYYMMDDTHHMMSSZ.sql.gz

# Cross-check row counts vs production (optional):
mysql -e "SELECT COUNT(*) FROM audit_logs;" foodking_restore
mysql -e "SELECT COUNT(*) FROM audit_logs;" foodking
```

PASS criteria: `audit_logs.verifyChain: OK` AND `z_reports.verifyChain: OK`
printed by the restore script (exit 0). Any FAIL = backup pipeline broken,
escalate immediately and do NOT trust the chain on the live DB until
investigated.

---

## 4. NF525 6-year retention enforcement (Object Storage lifecycle)

NF525 requires `audit_logs` + `z_reports` to be retained 6 years post-close.
Enforced via OVH Object Storage lifecycle policy on the bucket (NOT in the
script — the script only uploads, never deletes remote objects).

Apply once via OVH manager OR `s3cmd setlifecycle`:

```xml
<LifecycleConfiguration>
  <Rule>
    <ID>nf525-6y-retention</ID>
    <Status>Enabled</Status>
    <Prefix>foodking-</Prefix>
    <Expiration><Days>2200</Days></Expiration>  <!-- 6 ans + marge -->
  </Rule>
</LifecycleConfiguration>
```

Also enable **versioning + object lock** (compliance mode) on the bucket so
backups cannot be deleted before 2200 days even by an admin.

---

## 5. Emergency restore (production DB corrupted)

1. STOP the app: `sudo systemctl stop nginx php8.2-fpm laravel-worker`.
2. Snapshot the broken DB: `mysqldump --single-transaction foodking > /tmp/foodking-broken.sql` (forensics).
3. Restore the latest backup into `foodking_restore`:
   ```bash
   /var/www/foodking/scripts/restore-foodking-from-backup.sh s3://foodking-backups-gra/foodking-<latest>.sql.gz
   ```
   NOTE: OVH Object Storage Cold has retrieval latency (minutes, sometimes
   tens of minutes for first-byte). Plan downtime accordingly. The script
   logs `cold storage may take several minutes` while waiting.
4. If `verifyChain` OK on `foodking_restore`, promote:
   ```bash
   mysql -e "RENAME TABLE ... ;"   # or full DB swap with downtime window
   ```
   Set `ALLOW_RESTORE_PROD=1` only for the final cutover step.
5. Restart services, smoke-test `/admin/pos` + `/kiosk/idle` + a Z-report close.
6. File an incident report referencing the dump SHA-256 and chain-verify output.

---

## 6. Owner physical actions (one-time, before activating cron)

- [ ] Create OVH Object Storage account, region GRA (primary).
- [ ] Create bucket `foodking-backups-gra` with versioning + object lock (compliance mode, 2200d).
- [ ] Enable cross-region replication GRA → SBG (D2: cross-AZ secondary).
- [ ] Generate S3 access/secret key pair scoped to this bucket only.
- [ ] Provision `~/.s3cfg` on the VPS for the `www-data` user (chmod 600).
- [ ] Configure `BACKUP_ALERT_WEBHOOK` (Slack/Discord/PagerDuty inbound URL).
- [ ] Run staging DR drill once before enabling the production cron line.
- [ ] Calendar a recurring monthly DR drill (cron + owner sign-off log).
