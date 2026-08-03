# Axe 3 — Fiscal NF525 (focus technique)

## Chaîne `audit_logs`

- **Écriture** : `AuditLogService` — verrou cache par branche, transaction, `hash_hmac('sha256', …)` sur charge canonique, enchaînement `prev_hash` → `current_hash`, `hash_equals` en vérification.
- **Lecture destructive** : modèle `AuditLog` bloque update/delete Eloquent.
- **Preuves tests :** `AuditLogHashChainTest`, `AuditLogConcurrencyTest`, `AuditLogImmutabilityTest`, triggers SQL éventuels (`Concerns/InstallsAuditLogImmutabilityTriggers`).

## Séquence fiscale commande

- **`FiscalSequenceService::next()`** : `Cache::lock` puis transaction avec `Order` **`lockForUpdate()`** + `max(fiscal_sequence_no)+1`.
- **Pas de table** nommée `fiscal_sequences` — la séquence porte sur **`orders.fiscal_sequence_no`** (index unique branch+sequence attendu en migration).

## Z-report

- **`open()`** : `Cache::lock('z_report_b{n}')`, transaction, vérif pas de Z ouvert, puis `nextSeq = max(sequence_no)+1` **sans** `lockForUpdate` sur l’agrégat MAX — **point de tension** `F-FISC-001` (atténué par lock cache + contrainte `UNIQUE(branch_id, sequence_no)`).
- **`close()`** : sélection Z **OPEN** avec **`lockForUpdate`**, agrégation commandes sur plage temporelle, **signature HMAC** avec `prevHash` dernier Z **CLOSED**.
- **Log :** `Log::channel('fiscal')->info('z_report.open', …)` — bon pour observabilité.
- **Garde prod secrets :** `FiscalSecretProductionGuardTest`.

## X-report

- **`XReportService`** : agrégation **lecture seule** via `ZReportService::aggregate()` — cohérence intraday.

## Limites explicites vs exigence « 10k+ entrées simulées »

- **Non démontré dans le repo** par boucle automatisée 10k+ sur séquences Z (voir `F-FISC-004`).
- La monotonie **DB** + tests d’intégration fournissent une **preuve d’ingénierie**, pas un benchmark de certification.

**Liens tracker :** F-FISC-001 à F-FISC-004, F-FISC-003 (positif).
