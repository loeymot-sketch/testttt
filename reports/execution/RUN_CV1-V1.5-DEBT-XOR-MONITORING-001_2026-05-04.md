# RUN — CV1-V1.5-DEBT-XOR-MONITORING-001

**TASK_ID:** `CV1-V1.5-DEBT-XOR-MONITORING-001`  
**Date:** 2026-05-04  
**EXECUTE_DELEGATION:** `foodking-routine-implementer`  

## Fichiers créés

| Path | Rôle |
| --- | --- |
| `scripts/xor-violation-check.sh` | Monitoring XOR via `php artisan tinker` (count + listing JSON si violation) |
| `docs/orchestration/V1_XOR_MONITORING_PROCEDURE.md` | Fréquence, cron template, actions incident, désactivation |
| `reports/monitoring/.gitkeep` | Versionne le répertoire des logs de monitoring |

## Référence migration

Contrainte: `item_wizard_profiles_owner_xor_check` — `CHECK ((item_id IS NOT NULL) <> (item_category_id IS NOT NULL))` (`database/migrations/2026_05_05_000020_make_item_wizard_profiles_polymorphic_owner.php`).

## Validation locale

### `bash -n`

```text
(no output — exit 0)
```

### Exécution

```text
$ bash scripts/xor-violation-check.sh 2>&1 | tail -10
[XOR-CHECK] OK: 0 violations at 2026-05-04T13:51:31Z

$ echo "Exit code: $?"
Exit code: 0
```

## Limitations

- **Webhook:** Slack/Discord/PagerDuty (ou autre) doit être configuré côté ops ; aucune URL secrète dans le dépôt. Le script accepte `--alert-webhook` et utilise `curl` avec `--max-time 10` et `|| true` pour ne pas masquer l’exit 1 d’alerte.
- **`post_execute_latest.log`:** réservation parallèle D1 (`CV1-V1.5-DEBT-COMPOSER-RUNTIME-AVAILABILITY-001`) — la trace `EXECUTE_DELEGATION` pour D3 est consignée ici ; fusion manuelle dans `reports/post_execute_latest.log` si le validateur exige une ligne unique par cycle master.

## Cron (extrait doc)

```cron
0 * * * * cd /var/www/foodking && bash scripts/xor-violation-check.sh --quiet --alert-webhook "https://hooks.slack.com/services/XXX" >> /var/log/foodking/xor-monitoring.log 2>&1
```
