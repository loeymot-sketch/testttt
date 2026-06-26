# Vérification adversaire — Immutabilité NF525 (triggers DELETE) sur foodking_e2e

**Rôle** : vérificateur adversaire (défaut = REFUTED si doute).
**Finding candidate** : [P1] `database/migrations/2026_05_10_010000_secure_fiscal_audit_trail_immutability.php:101-137` — Immutabilité NF525 cash/audit non appliquée sur `foodking_e2e` : 0 trigger DELETE alors que la migration est marquée run.

**VERDICT : CONFIRMÉE — P1** (NF525, base opérationnelle, registre migration ment).

---

## Tentative de réfutation (et pourquoi elle a ÉCHOUÉ)

Premier réflexe : une requête `information_schema.TRIGGERS WHERE EVENT_OBJECT_TABLE IN ('cash_movements',...)` a listé 5 triggers → semblait réfuter (triggers présents).

**Cette piste de réfutation est un ARTEFACT auto-induit.** Cette requête NE filtre PAS par schéma : `EVENT_OBJECT_TABLE IN (...)` matche les noms de tables sur TOUTES les bases du serveur. Re-exécutée avec la colonne schéma :

```
mysql -u root foodking_e2e -N -e "SELECT TRIGGER_NAME, EVENT_OBJECT_SCHEMA FROM information_schema.TRIGGERS WHERE EVENT_OBJECT_TABLE IN ('cash_movements','cash_drawer_sessions','order_payments','audit_logs','z_reports');"
=> les triggers retournés appartiennent à foodking_test et foodking_dash_e2e — AUCUN EVENT_OBJECT_SCHEMA='foodking_e2e'.
```

La réfutation tombe. La repro du finder (0 trigger sur foodking_e2e) est correcte.

---

## Repro confirmée (reproductible, isolée)

```
# 1) 0 trigger sur foodking_e2e (deux méthodes indépendantes)
mysql -u root -N -e "SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA='foodking_e2e';"  => 0
mysql -u root foodking_e2e -N -e "SHOW TRIGGERS;"  => 0 ligne

# 2) Comparaison schéma-correcte par base
mysql -u root -N -e "SELECT TRIGGER_SCHEMA, COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA IN ('foodking','foodking_e2e','foodking_test','foodking_dash_e2e') GROUP BY TRIGGER_SCHEMA;"
  => foodking_dash_e2e  9
  => foodking_test      9
  => (foodking_e2e : AUCUNE ligne = 0)   (foodking : AUCUNE ligne = 0)

# 3) Migration marquée RUN (le registre prétend que c'est appliqué)
mysql -u root foodking_e2e -e "SELECT migration,batch FROM migrations WHERE migration LIKE '%secure_fiscal%';"
  => 2026_05_10_010000_secure_fiscal_audit_trail_immutability | 1
  (sœurs aussi run batch 1 : 2026_04_22_000002_create_audit_logs_table, 2026_05_09_160000_add_z_reports_delete_trigger_immutability)

# 4) Les 5 tables fiscales EXISTENT dans foodking_e2e (absence = drift réel, pas table absente)
mysql -u root foodking_e2e -N -e "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA='foodking_e2e' AND TABLE_NAME IN ('cash_movements','cash_drawer_sessions','order_payments','audit_logs','z_reports');"
  => audit_logs, cash_drawer_sessions, cash_movements, order_payments, z_reports

# 5) Et elles contiennent de la VRAIE donnée fiscale à protéger
  => cash_movements 364 | cash_drawer_sessions 31 | order_payments 259 | audit_logs 4635 | z_reports 24
```

## Evidence : c'est bien la base opérationnelle

`foodking_e2e` correspond à l'empreinte live de la CONTEXTE : `MAX(fiscal_sequence_no)=2573`, `orders=2864`, audit head id=4639 (chaîne avancée depuis le snapshot CONTEXTE, même chaîne). Le serveur :8766 tourne sur `.env` (`DB_DATABASE=foodking`) mais la base où l'équipe teste tout le fiscal et où vivent les 2864 ordres + 2573 fiscal + chaîne audit 4639 rows EST `foodking_e2e` (référencée par `.env.e2e`). L'invariant NF525 (CLAUDE.md §8 : `audit_logs`/`z_reports` HMAC chain + DB trigger `BEFORE DELETE SIGNAL 45000`, 6 ans rétention ineffaçable) ne tient PAS structurellement sur cette base.

## Code de la migration (vérifié par Read, lignes 101-141)

Lignes 108-141 : `CREATE TRIGGER cash_movements_no_delete / cash_drawer_sessions_no_delete / order_payments_no_delete BEFORE DELETE ... SIGNAL SQLSTATE '45000'`. Garde MySQL-only (l.101 `return` si non-MySQL). Les FK passent en `restrictOnDelete` (l.72-95) — mais RESTRICT ne bloque QUE le cascade parent ; un `DELETE FROM audit_logs` / `cash_movements` / `z_reports` DIRECT n'a aucun trigger pour SIGNAL 45000 → réussit. (DELETE NON exécuté : règle READ-ONLY + un DELETE réussirait réellement faute de garde = mutation fiscale interdite. Preuve structurelle = trigger absent + table+data présentes + FK restrict-only ≠ delete-row-guard.)

## Mécanisme « sentinels restent verts » — confirmé (avec une précision)

Le finder dit que `CashMovementsDeleteForbiddenTest` + `SqliteMysqlParitySentinel` tournent sur la base de test (RefreshDatabase recrée les triggers) → restent verts. Précision vérifiée :
- `phpunit.xml:53` => `DB_DATABASE=:memory:` (SQLite), PAS `foodking_test`. Sur SQLite, la migration **skip** carrément le SQL trigger (l.101). Les tests `tests/Feature/Cash/CashMovementsDeleteForbiddenTest.php`, `tests/Feature/Fiscal/AuditLogImmutabilityTest.php` s'appuient sur le trait `tests/Feature/Fiscal/Concerns/InstallsAuditLogImmutabilityTriggers.php` pour réinstaller les triggers DANS la base de test.
- Conclusion identique : AUCUN sentinel n'observe `foodking_e2e` (base op MySQL). Le drift n'est jamais capté. (Le détail « foodking_test recrée via RefreshDatabase » est imprécis mais immatériel.)

## Reco #2 vise un vrai trou (vérifié)

`fiscal:verify-triggers` n'existe PAS. Commandes existantes : `FiscalVerifyChainCommand` (chaîne HMAC uniquement — `grep "TRIGGER\|information_schema"` => 0 match structurel), `FiscalAssertChainCleanCommand`, `VerifyZMembershipCommand`. Aucune ne SELECT `information_schema.TRIGGERS`. Le `/brain ship` ne vérifie donc jamais la présence physique des 5 triggers sur la base op.

## Lentille

**Drift-registre-vs-réalité (provisioning) + jumeau base-op-non-couverte-par-sentinel.** La validation NF525 « active » (migration run, sentinels verts) tourne contre une base où l'immutabilité est silencieusement absente. Signature d'un restore `mysqldump` sans `--triggers` (ou `schema:dump`/load SQL) puis `migrate` marquant run sans recréer. PAS un bug de code de la migration : `foodking_test`=9 et `foodking_dash_e2e`=9 prouvent qu'un `migrate:fresh` MySQL propre crée bien les triggers.

## Sévérité (lens V1-LOCAL)

P1 (pas P0) : preuve fiscale (cash-trail + chaîne audit append-only `audit_logs`/`z_reports`) physiquement effaçable sur la base opérationnelle, et le registre migration prétend faussement que la garde est en place. NF525/argent/intégrité-fiscale = P0/P1 par doctrine. Atténuants qui retiennent à P1 plutôt que P0 : (a) défaut environnemental de provisioning, pas un défaut de code ; (b) une prod issue d'un `migrate:fresh` propre aurait les triggers ; (c) la chaîne HMAC reste vérifiable (suppression détectable a posteriori), donc ce n'est pas une corruption silencieuse indétectable. Reste P1 car l'effacement direct est possible MAINTENANT et la validation ment.

## Reco (NON-frozen, hors-code — racine = provisioning, pas la migration)

1. Réinstaller les 5 triggers sur `foodking_e2e` (artisan de réparation DROP-IF-EXISTS + recreate ciblé `up()` des 3 migrations trigger, OU `migrate:fresh` sur une base MySQL propre re-seedée). NE PAS toucher la migration (elle est correcte).
2. **Nouveau** `app/Console/Commands/FiscalVerifyTriggersCommand.php` (`fiscal:verify-triggers`) — TDD : `SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DB-courante` ; échec (exit≠0) si l'un des 5 (`cash_movements_no_delete`, `cash_drawer_sessions_no_delete`, `order_payments_no_delete`, `audit_logs_no_delete`, `z_reports_no_delete`) manque. Câbler dans `/brain ship`. Un sentinel PHPUnit teste une base recréée — pas la base op ; cette commande comble exactement ce trou.
3. Runbook deploy : `mysqldump` DOIT inclure `--routines --triggers` ; documenter dans `docs/` que tout restore/clone vérifie `fiscal:verify-triggers` avant mise en service.

Aucun fichier frozen touché : migration intacte, FK intactes, nouvelle commande hors-frozen, ré-installation = opération DB hors-code. `heal_safe_nonfrozen = true`.
