# 🚀 PRODUCTION GO-LIVE Checklist — V1 LOCAL Le Cayenne

**Date** : 2026-05-28
**Branche** : `heal/cms-pr1-quickwins-2026-05-18`

## Pré-requis owner (3 actions ~45 min total)

### 1. Production `.env` flip (10 min)

```bash
# Sur le serveur prod, éditer .env :
APP_ENV=production
APP_DEBUG=false           # AppServiceProvider:204 refuse boot si true
APP_URL=https://lecayenne.fr
POS_SIMULATION_HARDWARE=false  # AppServiceProvider:167 NF525-critical
IDEMPOTENCY_MIDDLEWARE_ENABLED=true
CACHE_DRIVER=redis        # File OK V1 single-box, Redis required cloud
QUEUE_CONNECTION=redis
BROADCAST_DRIVER=pusher

# Apply
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

**Le boot guard refuse production sans ces flags → safe-fail garanti.**

### 2. Ansible CVP0-1 REVOKE (10 min)

```bash
# Sur le serveur prod, déjà préparé dans deploy/ansible/site.yml:59-72
cd deploy/ansible
ansible-playbook site.yml --tags=fiscal-revoke
```

Ce playbook exécute :
```sql
REVOKE DROP, ALTER ON foodking.audit_logs FROM 'foodking'@'%';
REVOKE DROP, ALTER ON foodking.z_reports FROM 'foodking'@'%';
REVOKE DROP, ALTER ON foodking.cash_movements FROM 'foodking'@'%';
REVOKE DROP, ALTER ON foodking.cash_drawer_sessions FROM 'foodking'@'%';
REVOKE DROP, ALTER ON foodking.order_payments FROM 'foodking'@'%';
REVOKE DROP, ALTER ON foodking.domain_events FROM 'foodking'@'%';
REVOKE DROP, ALTER ON foodking.webhook_events FROM 'foodking'@'%';
```

**Bloque l'attaque TRUNCATE empiriquement prouvée nécessaire (dev incident E2E-13).**

### 3. Première seed prod DB (10 min)

```bash
# Sur prod, fresh DB:
php artisan migrate --force --seed

# Vérifier seeders critiques exécutés:
# - PermissionTableSeeder
# - RoleTableSeeder
# - IngredientPermissionSeeder
# - AdminWebGuardPermissionsSyncSeeder (NEW)
# - LeCayenneRoleLandingUrlSeeder
```

### 4. Verify pré-live (5 min)

```bash
# NF525 chain initial state
php artisan fiscal:verify-chain --all     # Expect: CHAIN OK
php artisan fiscal:assert-chain-clean     # Expect: exit 0

# Triggers actifs
php artisan tinker --execute='
$ts = \DB::select("SHOW TRIGGERS");
$crit = ["audit_logs", "z_reports", "order_items"];
foreach ($ts as $t) {
    if (in_array($t->Table, $crit)) echo "  - {$t->Trigger} on {$t->Table} {$t->Event}\n";
}
'
# Expect 4 triggers: no_update, no_delete on audit_logs / z_reports / order_items

# Backup cron
php artisan schedule:list | grep backup-daily
# Expect: 0 3 * * * php artisan foodking:backup-daily

# Queue worker
sudo systemctl status foodking-queue
# OR
php artisan queue:work redis --queue=high,default --daemon &

# Healthz
curl https://lecayenne.fr/api/healthz
# Expect: {"status":"ok","checks":{"db":"ok","redis":"ok","websocket":"ok","fiscal_chain":"ok","queue_pending":0}}
```

## Physical walk lundi matin (60-90 min)

1. **Login admin** sur tablette caisse
2. **Premier vrai Z open** : `php artisan fiscal:open-all-active-branches` (ou via UI quand câblé)
3. **Test borne client** :
   - Place commande complète
   - Paie (test card 4242)
   - Verify ticket imprimé
4. **Test caisse direct sale** :
   - Add items
   - Encaisser Liquide / Carte TPE
   - Verify ticket + receipt
5. **Test KDS chef** :
   - Bump PREPARED
   - Verify OSS shows PRÊT
6. **Test refund** (HEAL-4) :
   - Past PAID order
   - Click 💸 Rembourser
   - Verify mirror order + REMBOURSEMENT ticket
7. **Premier vrai Z close** 23:55 (ou laisser cron 23:59) :
   - Verify Z chain extension
   - audit_logs grows + z_report.closed anchor

## NF525 audit checklist (6 ans rétention)

- [ ] `audit_logs` chain CHAIN OK chaque jour
- [ ] `z_reports` un par jour, gap-free
- [ ] `fiscal_sequence_no` monotonic per branch
- [ ] `composition_snapshot` immutable trigger active
- [ ] Backup daily 03:00 + monthly + quarterly (auto via cron)
- [ ] Restore drill réussi (verified 2026-05-28)
- [ ] Triggers DELETE/UPDATE actifs sur audit_logs + z_reports
- [ ] Ansible REVOKE DROP/ALTER appliqué (CVP0-1)

## Verdict GO-LIVE

✅ **CODE 100% prêt** — 6 heals shippés, NF525 chain OK, frozen-zone 0 LOC

⏳ **Restent les 3 actions owner** ci-dessus + physical walk

Si les 4 sections sont coches → **GO PRODUCTION**.
