# FoodKing Phase D — Ansible playbook (OVH VPS-1 Strasbourg)

Infrastructure-as-code for FoodKing V1 production cloud deploy. Mirrors the
Phase C local actions (commit `72b078682`) as an idempotent, re-runnable playbook.

## Scope (frozen-zone protection)

Deploys infrastructure (apt packages, MySQL/Redis/Nginx/Soketi configs, cron,
TLS certs). NEVER modifies application code — `app/`, `resources/`, `public/`,
`config/` are deployed read-only via `git clone` + `composer install --no-dev`
+ `npm run production`. Frozen-zone files (POS wizard Vanilla JS, fiscal
services, BranchScope, audit_logs triggers) are untouched by Ansible by design.

## Prerequisites

- Ansible 2.14+ on operator workstation (`ansible-galaxy collection install community.general community.crypto`)
- SSH key access to OVH VPS-1 as `deploy` user with passwordless sudo
- `ansible-vault edit group_vars/vault.yml` populated — every `vault_*` secret regenerated via `openssl rand -hex 32`
- `inventory/production.ini` IP placeholder `__OVH_VPS1_IP__` replaced after OVH commande

## First-run

```bash
cd deploy/ansible
ansible-playbook site.yml -i inventory/production.ini --check --diff      # dry-run
ansible-playbook site.yml -i inventory/production.ini --ask-vault-pass    # real run
```

Partial runs via tags: `--tags base,php,mysql,redis,nginx,soketi,app,cron`.

## Rollback

The playbook is idempotent — re-running is the rollback for infra. To revert a
broken playbook change: `git revert <bad-commit>` then re-run. For app-code
rollback: SSH into VPS, `cd /var/www/foodking && git checkout <previous-tag>
&& composer install --no-dev && npm run production && php artisan migrate:rollback --step=1`,
then `ansible-playbook site.yml --tags app` to lock the deployed version.

## DR drill integration

The `cron` task installs the daily backup line. **Production cron activation
MUST be preceded by a successful staging DR drill** per `docs/runbooks/BACKUP_RESTORE_NF525.md`
§3 — restore the latest dump into `foodking_restore` and confirm
`audit_logs.verifyChain: OK` + `z_reports.verifyChain: OK`. Do NOT skip;
an unverified pipeline = NF525 non-compliance.

## Owner physical actions (Phase D kickoff gate)

Run the playbook ONLY after every box below is checked:

- [ ] OVH VPS-1 €8.11/mo Strasbourg commandé, SSH key uploaded, IP captured in `inventory/production.ini`
- [ ] DNS A record `lecayenne.fr` + `www.lecayenne.fr` → VPS IP (TTL ≤300s during cutover)
- [ ] OVH Object Storage bucket `foodking-backups-gra` created with versioning + object-lock (compliance mode, 2200d)
- [ ] `~/.s3cfg` provisioned on VPS for `www-data` (chmod 600) with OVH S3 access/secret keypair scoped bucket-only
- [ ] `BACKUP_ALERT_WEBHOOK` URL (Slack/Discord/PagerDuty) populated in `group_vars/vault.yml` AND `/etc/foodking-backup.env`
- [ ] Staging DR drill GREEN (owner sign-off logged)

## V1 single-resto vs V2 multi-tenant

This playbook assumes single-VPS single-resto (V1 Le Cayenne). V2 SaaS
multi-tenant (out of scope, 6-12mo roadmap per CTO audit 2026-05-16) would
require: managed MySQL/Redis services, HAProxy + N app nodes, dedicated Soketi
host, and per-tenant inventory groups. The current shape is intentional for
V1 shipability and capped infra cost (€8.11 TTC/mo).
