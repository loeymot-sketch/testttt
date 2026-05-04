# Runbook — migration staging SOURCE-FK (`source_item_attribute_id`)

| Date       | 2026-05-03 |
|------------|------------|
| Tâche      | CV1-V2-REMAINING-MISSIONS-001 |
| Migration  | `database/migrations/2026_05_03_200500_add_source_item_attribute_id_to_item_wizard_steps_table.php` |
| Script     | `scripts/migrate-source-fk-staging.sh` |

Ce document décrit les étapes opérationnelles alignées sur le script bash. **Aucune exécution automatique en CI** : un humain valide staging, dispose des identifiants, et déclenche le script hors pipeline tant que la stratégie release ne l’a pas explicitement autorisée.

---

## Pré-requis humains

- Accès réseau et droits DDL sur la **base staging** cible (hôte, port, base, utilisateur).
- **`STAGING_DB_PASSWORD`** (et équivalents) disponibles par un canal sécurisé — ne pas commiter dans le dépôt.
- Outils client SQL sur la machine d’exécution : **`mysqldump`** / **`mysql`** (Laravel `mysql`) ou **`pg_dump`** / **`psql`** (`pgsql`), selon le driver.
- Répertoire **`storage/backups/staging/`** writable (le script le crée si besoin).
- PHP / Composer / projet Laravel configuré pour pointer vers **cette** base au moment du `php artisan migrate` (`.env` staging ou variables `DB_*` cohérentes avec le backup).

---

## Étapes (correspondance script)

1. **Pré-flight** — Exiger `STAGING_DB_HOST`, `STAGING_DB_NAME`, `STAGING_DB_USER`, `STAGING_DB_PASSWORD`. Déterminer le driver via `STAGING_DB_DRIVER` (`mysql` \| `pgsql`) ou `DB_CONNECTION` dans `.env`. Sinon : arrêt code **1**.
2. **Backup full** — `mysqldump` ou `pg_dump` vers `storage/backups/staging/source-fk-pre-{timestamp}.sql`. Échec → code **2**.
3. **Dry-run** — `php artisan migrate --pretend --path=database/migrations/2026_05_03_200500_add_source_item_attribute_id_to_item_wizard_steps_table.php`. Échec → code **3**.
4. **Pause** — Invite : *Appuyer sur ENTER pour appliquer*.
5. **Application** — `php artisan migrate --path=… --force`. Échec → code **3**.
6. **Post-flight** — Exécuter manuellement les requêtes SQL du rapport **`reports/execution/RUN_SOURCE_FK_BACKFILL_VERIFICATION_2026-05-03.md`** ; confirmer au prompt du script. Refus / échec logique → code **4**.

**Mode `--dry-run` (script)** : exécute les étapes 1–3 puis s’arrête sans pause ni migrate réel (sortie **0** si tout passe).

**Aide sans check env** : `scripts/migrate-source-fk-staging.sh --dry-run-help`

---

## Rollback

Une fois la migration appliquée, rollback ciblé (à exécuter sur la **même** base, après décision humaine) :

```bash
php artisan migrate:rollback --path=database/migrations/2026_05_03_200500_add_source_item_attribute_id_to_item_wizard_steps_table.php --force
```

En cas de doute sur l’état des batches, vérifier la table `migrations` et la doc Laravel avant rollback partiel.

Restauration **nucléaire** : réimporter le fichier `source-fk-pre-*.sql` généré à l’étape 2 (fenêtre de maintenance, coordination DBA).

---

## CI / automatisation

**Ne pas** brancher ce script sur une CI qui applique des migrations staging/prod sans revue. Tant qu’un humain n’a pas validé la procédure et les identifiants, considérer ce runbook **manuel uniquement**.

---

## Voir aussi

- Vérifications SQL post-migration : `reports/execution/RUN_SOURCE_FK_BACKFILL_VERIFICATION_2026-05-03.md`
- Synthèse pack α : `reports/execution/RUN_ALPHA_ROUTINE_PACK_2026-05-03.md`
