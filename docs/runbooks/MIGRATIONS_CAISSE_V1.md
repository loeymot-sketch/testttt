# FoodKing Caisse V1 Migration Safety Runbook

## Scope

This runbook covers Caisse V1 schema changes only. It does not authorize new
product migrations. The approved schema gate is:

- `GATE_SCHEMA_MIGRATIONS_CAISSE_V1_2026-04-25`: Option A, all migrations with
  rehearsal plus backup.

Every migration that ships under Caisse V1 must have a filled copy of
`docs/runbooks/MIGRATIONS_RUNBOOK_TEMPLATE.md` attached to the release evidence
before production execution.

## Fail-Closed Rule

The migration scripts are non-destructive by default:

- `scripts/db/dry-run.sh` refuses to contact a database unless `--run` or
  `--print-command` is present.
- `scripts/db/backup.sh` refuses to create a backup unless
  `--i-understand-backup` is present.
- `scripts/db/rehearsal.sh` refuses to mutate a staging database unless
  `--apply --i-understand-rehearsal-mutates-db` are both present.
- Production use is blocked unless a script has a documented production guard
  flag. `rehearsal.sh` is staging-only.

## Required Order

1. Fill one per-migration runbook from `MIGRATIONS_RUNBOOK_TEMPLATE.md`.
2. Create a backup manifest:

   ```bash
   bash scripts/db/backup.sh \
     --env=staging \
     --driver=sqlite \
     --database=/absolute/path/to/staging.sqlite \
     --output-dir=storage/app/migration-backups \
     --i-understand-backup
   ```

3. Run a dry-run and store the transcript:

   ```bash
   bash scripts/db/dry-run.sh \
     --env=staging \
     --connection=sqlite \
     --output=storage/logs/migration-dry-run.txt \
     --run
   ```

4. Rehearse Up, Down, then Up again on staging after the backup exists:

   ```bash
   bash scripts/db/rehearsal.sh \
     --env=staging \
     --connection=sqlite \
     --backup-manifest=storage/app/migration-backups/backup-staging-YYYYmmddTHHMMSSZ.manifest.json \
     --step=1 \
     --apply \
     --i-understand-rehearsal-mutates-db \
     --run
   ```

5. Validate branch-sensitive tables with exact `branch_id` checks from the
   migration runbook. Never use prefix, `LIKE`, or cross-branch aggregate checks
   for branch isolation evidence.
6. Attach the backup manifest, dry-run transcript, and rehearsal transcript to
   the release report.

## Per-Migration Ledger

Each row below must be completed before a migration is allowed into a release
batch. Add one row per migration file; keep the migration file path exact.

| Migration file | Branch impact | Backup manifest | Dry-run transcript | Rehearsal transcript | Rollback step | Owner |
| --- | --- | --- | --- | --- | --- | --- |
| `database/migrations/2026_04_22_000001_add_fiscal_sequence_no_to_orders.php` | orders are branch-scoped, verify exact `branch_id` retention | pending | pending | pending | 1 | fiscal |
| `database/migrations/2026_04_22_000002_create_audit_logs_table.php` | audit logs must preserve exact branch references when present | pending | pending | pending | 1 | fiscal |
| `database/migrations/2026_04_22_000003_create_z_reports_table.php` | Z reports are branch-scoped evidence | pending | pending | pending | 1 | fiscal |
| `database/migrations/2026_04_25_190000_create_order_quotes_table.php` | quotes are branch-scoped and must not be reusable cross-branch | pending | pending | pending | 1 | order quote |

## Production Checklist

- Gate Option A is referenced in the release report.
- Backup manifest exists and includes SHA-256 for the backup artifact.
- Dry-run transcript contains `php artisan migrate --pretend --force`.
- Rehearsal transcript contains Up, Down, Up commands.
- Rollback step count matches the migration batch size.
- Branch-sensitive tables have exact `branch_id` verification evidence.
- No new migration file was created by this safety mission.
