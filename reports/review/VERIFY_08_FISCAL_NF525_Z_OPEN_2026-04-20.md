# VERIFY-08 — Fiscal NF525 (Z.open hardening, X/Z, hash chain, audit log)

- **Date** : 2026-04-20
- **Tâche** : `tasks/verify-2026-04-20/08_VERIFY_FISCAL_NF525_Z_OPEN.md`
- **Mode** : AUDIT-ONLY (lecture seule du code applicatif ; seul ce rapport est écrit)
- **Auteur** : sous-agent AUDIT-ONLY (Claude, sous orchestration parent)
- **Sources audits amont** : `reports/review/AUDIT_POS_110_FISCAL_NF525_2026-04-19.md`, `reports/review/AUDIT_POS_110_NF525_READINESS_2026-04-19.md`, `reports/review/AUDIT_POS_110_HIDDEN_RISKS_2026-04-19.md`, finding `F-FISC-001`

---

## 1. Périmètre & méthode

Lecture multi-passe (parallèle) des sources OBLIGATOIRES §2 du brief :

- **Pass A — Services + tests** : `app/Services/Fiscal/{FiscalSequenceService,ZReportService,XReportService,AuditLogService}.php`, `app/Models/{ZReport,AuditLog}.php`, `app/Http/Controllers/Admin/Fiscal/{ZReportController,XReportController}.php`, `app/Console/Commands/FiscalArchiveCommand.php`, `tests/Feature/Fiscal/*` (24 fichiers).
- **Pass B — Migrations + contraintes DB** : `database/migrations/2026_04_22_000001_add_fiscal_sequence_no_to_orders.php`, `..._000002_create_audit_logs_table.php`, `..._000003_create_z_reports_table.php`, `..._100000_add_unique_chain_index_to_audit_logs.php`, `routes/api.php` (lignes 786-806), `app/Services/OrderService.php` (wire-in séquence + call-sites RETURNED / sealed-Z).
- **Pass C — Audits amont** (référence et challenge) : `AUDIT_POS_110_FISCAL_NF525_*`, `AUDIT_POS_110_NF525_READINESS_*`, `AUDIT_POS_110_HIDDEN_RISKS_*`.

Note : aucun modèle dédié `XReport` ni `FiscalSequence` n'existe — la séquence vit sur `orders.fiscal_sequence_no` (cf. migration `000001`) et l'X est purement calculé à la volée. Le brief listait `app/Models/Fiscal/*` ; ce dossier **n'existe pas** : seuls `App\Models\AuditLog` et `App\Models\ZReport` portent les modèles fiscaux. C'est un constat (non-finding), volontairement signalé pour ne pas fabriquer de source.

---

## 2. Hypothèses H1..H7 — verdicts & preuves

| Hyp. | Description | Verdict | Preuve principale (file:line) |
|---|---|---|---|
| H1 | Z.open laisse l'état journalier ouvert si interruption (crash, timeout) → pas de recovery | **PARTIELLEMENT VRAI (mitigé, non explicite)** | `app/Services/Fiscal/ZReportService.php:107-170` (close = `Cache::lock` + `DB::transaction` + `lockForUpdate`) ; aucun état `STATUS_CLOSING` (`app/Models/ZReport.php:15-16`) ; rollback laisse l'état `OPEN` cohérent, lock auto-expire, mais aucun marqueur d'interruption |
| H2 | Hash chain peut être cassée par un RETURNED tardif post-Z fermé | **PARTIELLEMENT VRAI** : la chaîne `audit_logs` reste intacte (insertion seule) et le sceau du Z fermé n'est pas modifié, mais aucun blocage n'empêche `changeStatus → RETURNED` ni la non-ré-agrégation dans le Z courant | `app/Services/OrderService.php:1499-1567` (changeStatus, aucun guard sealed-Z) ; `app/Services/OrderService.php:1736-1755` (seul `destroy()` est gardé) ; `app/Services/Fiscal/ZReportService.php:207-214` (filtre `created_at`, pas `status_changed_at`) |
| H3 | Une commande non signée passe quand même en Z | **FAUX** | `app/Services/Fiscal/ZReportService.php:209` (`whereNotNull('fiscal_sequence_no')`) — exclusion ferme des rangées sans séquence allouée |
| H4 | Pas d'idempotence sur génération Z (double Z possible le même jour) | **FAUX** | `app/Services/Fiscal/ZReportService.php:60-69` (open rejette si OPEN existe), `:118-126` (close rejette si rien d'OPEN) ; `tests/Feature/Fiscal/ZReportCloseTest.php:100-115` |
| H5 | Pas de séparation `branch_id` pour la séquence fiscale | **FAUX** | `database/migrations/2026_04_22_000001_add_fiscal_sequence_no_to_orders.php:36-44` (`orders_branch_fiscal_seq_unique`), `..._000003_create_z_reports_table.php:62` (`z_reports_branch_sequence_unique`), `..._100000_add_unique_chain_index_to_audit_logs.php:33-39` (`audit_logs_branch_prev_unique`) ; `app/Services/Fiscal/AuditLogService.php:88-93` (rejet `branch_id=null`) |
| H6 | Audit log peut être muté par un seed ou une migration | **FAUX** | `database/migrations/2026_04_22_000002_create_audit_logs_table.php:96-136` (triggers MySQL `SIGNAL SQLSTATE '45000'` + SQLite `RAISE(ABORT,...)`) ; `app/Models/AuditLog.php:42-58` (Eloquent `updating`/`deleting` rejetés) ; `..._000002:62-83` (rollback bloqué en prod) |
| H7 | Format export NF525 (JET, archives) absent ou non testé | **PARTIELLEMENT VRAI** : zip propriétaire déterministe en place, **format JET / PIAF officiel non implémenté** | `app/Console/Commands/FiscalArchiveCommand.php:78-148` (manifest schema_version 2 + 6 ans rétention) ; tests `tests/Feature/Fiscal/FiscalArchiveTest.php` (3 cas dont round-trip stable) ; aucun fichier ni test contenant le format JET / PIAF |

---

## 3. Matrice de vérifications V1..V9

| ID | Vérification | Statut | Preuve (file:line) | Observation |
|----|--------------|--------|--------------------|-------------|
| **V1** | Z.open atomique avec recovery (lock + état `OPEN/CLOSING/CLOSED`) | **WARN** | `app/Services/Fiscal/ZReportService.php:50-95` (open) + `:107-170` (close) ; `app/Models/ZReport.php:15-16` | Atomicité OK (Cache::lock TTL 10s + DB::transaction + lockForUpdate). Pas d'état `CLOSING` explicite ; recovery uniquement implicite (rollback laisse OPEN, lock expire). Aucun test crash explicite. → P11 |
| **V2** | Génération Z idempotente (jour × branche unique) | **OK** | `app/Services/Fiscal/ZReportService.php:60-69` (open guard) + `:118-126` (close guard) ; `database/migrations/2026_04_22_000003_create_z_reports_table.php:62` (UNIQUE branch+sequence) ; `tests/Feature/Fiscal/ZReportCloseTest.php:41-115` | Idempotence sur le **cycle open/close** (pas sur le calendrier, voulu — voir commentaire migration `000003:11-14`). |
| **V3** | Hash chain validée à l'ouverture du Z suivant | **WARN** | `app/Services/Fiscal/ZReportService.php:288-314` (`verifySignature` défini) ; seul site d'appel : `app/Http/Controllers/Admin/Fiscal/ZReportController.php:80` (route `pdf`) ; `app/Services/Fiscal/AuditLogService.php:180-212` (`verifyChain` jamais appelé hors tests) | Mécanique présente, **non-bloquante à l'open**. Détection corruption décalée à la prochaine génération PDF. → P11 |
| **V4** | Toute mutation `Order` post-DELIVERED interdite hors RETURNED + audit | **WARN** | `app/Services/OrderService.php:1736-1755` (destroy bloqué sur sealed-Z ✓) ; `:1543-1566` (audit RETURNED/CANCELED/REJECTED ✓) ; `:1628-1643` (audit changePaymentStatus ✓) ; **AUCUN guard** sealed-Z sur `changeStatus → RETURNED` (`:1499-1567`) ni `changePaymentStatus` (`:1592-1653`) ; `app/Services/Fiscal/ZReportService.php:210-214` (filtre `created_at`) | Audit complet, mais le RETURNED tardif n'est jamais ré-agrégé : ni dans le Z scellé (signature figée), ni dans le Z courant (filtre `created_at`). Violation de l'invariant NF525 « tout retour → trace fiscale comptable ». → P11 / P14 |
| **V5** | Séquence fiscale par branche, contrainte unique DB | **OK** | `database/migrations/2026_04_22_000001_add_fiscal_sequence_no_to_orders.php:36-44` ; `app/Services/Fiscal/FiscalSequenceService.php:65-94` (Cache::lock + lockForUpdate + `max+1`) ; wire-in `app/Services/OrderService.php:868-878` ; `tests/Feature/Fiscal/FiscalSequenceTest.php:29-69` ; `tests/Feature/Fiscal/OrderFiscalSequenceSchemaTest.php` | Défense en profondeur cache + DB. Note : SQLite ignore `FOR UPDATE` (commentaire `FiscalSequenceService.php:86-87`) — couvert par `BEGIN IMMEDIATE`. |
| **V6** | Audit log immutable (no UPDATE/DELETE) — policies / triggers | **OK** | `database/migrations/2026_04_22_000002_create_audit_logs_table.php:96-136` (triggers MySQL+SQLite) ; `app/Models/AuditLog.php:42-58` (Eloquent guards) ; `..._000002:62-83` + `..._100000:57-62` (rollback prod bloqué) ; `tests/Feature/Fiscal/AuditLogImmutabilityTest.php` (5 cas, Eloquent + raw SQL) | Triple barrière (modèle + DB + rollback guard). |
| **V7** | Tests : Z normal, Z avec RETURNED, Z double-call, recovery après crash | **WARN** | OK : `tests/Feature/Fiscal/ZReportCloseTest.php` (séquentiel, double-open/close), `AuditLogConcurrencyTest.php` (fork DB), `ZReportBoundaryTest.php` (fenêtre demi-ouverte), `PosOrderBL2AuditCallSitesTest.php` (audit RETURNED), `PosOrderBL3DestroyAfterZTest.php` (sealed-Z). **Manquants** : (a) crash/rollback mid-`close`, (b) RETURNED post-Z fermé, (c) Z après tampering audit_log | Couverture solide nominal+concurrence ; trous fonctionnels ciblés. → P11 |
| **V8** | Export JET (NF525) présent ou WARN explicite + ticket P | **WARN** | `app/Console/Commands/FiscalArchiveCommand.php:46-218` (zip propriétaire `manifest.json`+`z_reports.json`+`orders.json`+`audit_logs.json`, schema_version 2, 6 ans rétention via `config/fiscal.php:45`) ; tests `tests/Feature/Fiscal/FiscalArchiveTest.php` + `FiscalArchiveMemoryBoundedTest.php` | Format **propriétaire**, **ni JET ni PIAF officiel** (art. 286-I-3 bis CGI). Ticket P explicite §8 du brief. → P13 |
| **V9** | Permission `pos-manage-fiscal` correctement appliquée sur toutes les routes fiscales | **OK** | `routes/api.php:229` (groupe admin avec `auth:sanctum` + `apiKey` + `throttle:admin-mutation`) ; `routes/api.php:794-806` (sous-groupe fiscal + `throttle:10,1` sur open/close) ; `app/Http/Controllers/Admin/Fiscal/ZReportController.php:91-96` (authorizeFiscal) + `XReportController.php:24-26` ; tests `tests/Feature/Fiscal/FiscalPermissionTest.php`, `FiscalRateLimitTest.php`, `ZReportControllerTest.php` (cross-branch 403) | Couverture POS-9.4.12 / POS-GA-F-38 complète. Permission jumelle `pos-reopen-z` présente (seeder Spatie) pour wave future. |

---

## 4. Checklist NF525 (item par item)

| Item NF525 (technique) | Statut | Preuve |
|------------------------|--------|--------|
| Signature HMAC Z report | **OK** | `app/Services/Fiscal/ZReportService.php:316-342` (HMAC-SHA256, secret guard prod, canonisation UTC) ; `tests/Feature/Fiscal/FiscalSecretProductionGuardTest.php` (5 cas) |
| Chaînage hash entre Z (`prev_hash`) | **OK** | `app/Services/Fiscal/ZReportService.php:131-145` ; `tests/Feature/Fiscal/ZReportCloseTest.php:141-153` |
| Chaînage hash audit_logs | **OK** | `app/Services/Fiscal/AuditLogService.php:115-173` + `:218-224` ; UNIQUE `(branch_id, prev_hash)` (`migration 100000`) ; `tests/Feature/Fiscal/AuditLogHashChainTest.php` + `AuditLogConcurrencyTest.php` |
| Vérification chaîne automatique à open | **WARN** | Non câblée — voir F-VERIFY-08-01 |
| Archivage 6 ans (rétention) | **OK** | `config/fiscal.php:45` (`FISCAL_ARCHIVE_RETENTION_YEARS=6`) ; `manifest.json:retention_years=6` (`FiscalArchiveCommand.php:109`) ; rollback prod `audit_logs` bloqué |
| Export JET / PIAF officiel | **FAIL (selon V8/H7)** | Format propriétaire JSON+ZIP uniquement (`FiscalArchiveCommand.php:104-120`) — voir F-VERIFY-08-04 |
| Droits / permissions sur routes fiscales | **OK** | `pos-manage-fiscal` sur tous les endpoints (`ZReportController.php:91-96`, `XReportController.php:25-26`) ; `tests/Feature/Fiscal/FiscalPermissionTest.php` |
| Rate-limit endpoints sensibles | **OK** | `throttle:10,1` sur open/close (`routes/api.php:797-800`) ; `tests/Feature/Fiscal/FiscalRateLimitTest.php` (4 cas, dont 11ᵉ → 429) |
| Immutabilité audit log (DB + Eloquent) | **OK** | Triggers MySQL/SQLite + Eloquent guards + rollback prod (V6) |
| Recovery après Z.open interrompu | **WARN** | Pas d'état `STATUS_CLOSING` ni de réconciliation — voir F-VERIFY-08-03 |
| Branch isolation séquence | **OK** | UNIQUE `(branch_id, fiscal_sequence_no)` (V5) |
| Branch isolation audit chain | **OK** | UNIQUE `(branch_id, prev_hash)` (V6) |
| Cohérence X = Z intraday | **OK** | `app/Services/Fiscal/XReportService.php:36-56` délègue à `ZReportService::aggregate` (même algorithme) |
| TVA décomposée par taux | **OK** | `app/Services/Fiscal/ZReportService.php:247-266` (`total_by_tax_rate` depuis `order_items.tax_amount`) ; `tests/Feature/Fiscal/ZReportTaxBreakdownTest.php` |
| Garde secrets prod (sentinelles + longueur) | **OK** | `config/fiscal.php:60-87` ; `app/Services/Fiscal/ZReportService.php:344-374` ; `app/Services/Fiscal/AuditLogService.php:275-308` ; `FiscalSecretProductionGuardTest.php` |
| Observabilité fiscal (channel `fiscal`) | **OK** | `app/Services/Fiscal/ZReportService.php:84-89` + `:151-163` ; `app/Services/Fiscal/AuditLogService.php:148-157` ; tests `FiscalObservabilityTest.php` |

---

## 5. Findings F-VERIFY-08-XX

### F-VERIFY-08-01 — **HIGH** — `Z.open` n'auto-vérifie ni la signature du Z précédent ni la chaîne audit_log
- **V impactée** : V3.
- **Trigger** : `ZReportService::open()` alloue `sequence_no+1` sans appeler `verifySignature(prev_z)` ni `AuditLogService::verifyChain($branchId)`.
- **Impact** : Une corruption (signature manuelle, dump partiel restauré, fork chain) n'est détectée qu'à la prochaine génération PDF (`ZReportController::pdf` → `verifySignature`). NF525 attend une détection à chaque ouverture de période.
- **Preuve** : `app/Services/Fiscal/ZReportService.php:50-95` (open « aveugle ») ; `:288-314` (`verifySignature` défini) ; `app/Http/Controllers/Admin/Fiscal/ZReportController.php:73-89` (seul site d'appel) ; `app/Services/Fiscal/AuditLogService.php:180-212` (jamais appelé hors tests).
- **Recommandation** : cycle **`P11_FISCAL_Z_OPEN_HARDENING`** — appeler `verifySignature(lastClosedZ)` + `verifyChain(branchId)` à `open()`, refuser l'ouverture en cas KO et écrire `audit_logs` action `fiscal.z_open_blocked_chain_corruption`.

### F-VERIFY-08-02 — **HIGH** — Aucun guard NF525 contre `changeStatus → RETURNED` ni `changePaymentStatus` sur order scellé par un Z fermé
- **V impactée** : V4 (et V2 / V7 indirect).
- **Trigger** : `OrderService::changeStatus` (`:1499-1567`) et `OrderService::changePaymentStatus` (`:1592-1653`) acceptent ces transitions même quand l'order est scellé par un `ZReport::STATUS_CLOSED` couvrant `created_at`. Seul `destroy()` est gardé (`:1736-1755`).
- **Impact** : Le RETURNED tardif est tracé dans `audit_logs` mais **jamais ré-agrégé** : `ZReportService::aggregate` filtre par `created_at` (`:210-214`). Ni le `refund_count` du Z scellé (signature figée) ni celui du Z courant ne reflètent la contre-passation. Violation principe NF525 « tout retour produit une trace fiscale comptable ».
- **Preuve** : `app/Services/OrderService.php:1499-1567`, `:1592-1653`, `:1736-1755`, `app/Services/Fiscal/ZReportService.php:207-214`.
- **Recommandation** : cycle **`P11_FISCAL_Z_OPEN_HARDENING`** ou cycle dédié **`P14_FISCAL_CONTREPASSATION`** — soit (a) bloquer ces transitions sur sealed order et exiger un flux `pos-credit-note`, soit (b) modèle `OrderRefund` ré-agrégé via jointure dans `ZReportService::aggregate` par `refunded_at`.

### F-VERIFY-08-03 — **MEDIUM** — État `CLOSING` absent + zéro test crash-recovery
- **V impactée** : V1, V7.
- **Trigger** : `ZReport` n'expose que `STATUS_OPEN` / `STATUS_CLOSED` (`app/Models/ZReport.php:15-16`). Recovery actuel = rollback implicite + expiration de lock cache. Aucun marqueur d'interruption ni test simulant un crash mid-`close`.
- **Impact** : Compatibilité NF525 préservée fonctionnellement (idempotence OK), mais zéro évidence opérationnelle d'une interruption (post-mortem aveugle). Risque P0 identifié dans `AUDIT_POS_110_HIDDEN_RISKS_2026-04-19.md` item 4.
- **Preuve** : `app/Models/ZReport.php:15-16` ; `app/Services/Fiscal/ZReportService.php:107-170` (close — pas de marqueur `CLOSING`) ; `tests/Feature/Fiscal/ZReportCloseTest.php` (5 tests, aucun crash-recovery).
- **Recommandation** : cycle **`P11_FISCAL_Z_OPEN_HARDENING`** — ajouter `STATUS_CLOSING`, marqueur posé en début de transaction `close`, libéré au rollback ; test PHPUnit avec exception simulée mid-`aggregate`; commande de réconciliation `foodking:fiscal:z-recover`.

### F-VERIFY-08-04 — **MEDIUM** — Format d'export NF525 « JET » / PIAF officiel non livré
- **V impactée** : V8.
- **Trigger** : `FiscalArchiveCommand` produit un zip propriétaire (`manifest.json + z_reports.json + orders.json + audit_logs.json`, schema_version 2). Conforme au plan POS-9.4.11, **non conforme** au format JET (Journal d'Événements Techniques) ni PIAF demandé par DGFiP en cas de contrôle.
- **Impact** : En contrôle fiscal, l'export ne peut pas être produit dans le format attendu sans transformation manuelle. Identifié comme suite §8 du brief.
- **Preuve** : `app/Console/Commands/FiscalArchiveCommand.php:104-120` (manifest schema custom) ; aucun fichier au pattern `Jet|Piaf` dans `app/`.
- **Recommandation** : cycle **`P13_FISCAL_EXPORT_JET`** — sous-commande `foodking:fiscal:export-jet` produisant le format JET texte + signature détachée ; fixture DGFiP pour validation contractuelle ; documentation de contrôle fiscal.

### F-VERIFY-08-05 — **LOW** — `verifyChain` non monitoré (pas de cron / health-check)
- **V impactée** : V3 (côté audit_log).
- **Trigger** : Aucun planificateur ni route santé ne lance `AuditLogService::verifyChain` ; aucune métrique exposée.
- **Impact** : Une corruption silencieuse (peu probable vu triggers DB + UNIQUE, mais possible via dump/restore partiel) ne serait détectée qu'à investigation manuelle.
- **Preuve** : `app/Services/Fiscal/AuditLogService.php:180-212` ; aucun usage hors tests dans `app/Console`.
- **Recommandation** : cycle **`P12_FISCAL_AUDIT_LOG_AUTOVERIFY`** — schedule quotidien `foodking:fiscal:verify-chain` (toutes branches), métrique exposée, alerte sur retour ≠ null ; ligne `audit_logs` action `fiscal.chain_verified` à chaque exécution.

### F-VERIFY-08-06 — **INFO** — `Cache::lock` repose sur driver cache (Redis/file) — défense DB en place
- **V impactée** : V5, V6 (informatif).
- **Trigger** : Si le driver cache est `array` en prod (config:cache mal appliquée) ou Redis down, `Cache::lock` devient quasi-noop.
- **Impact** : Risque résiduel mitigé par la défense DB (UNIQUE composites + retry-on-unique dans `AuditLogService::performInsert`). Comportement documenté en commentaires (`FiscalSequenceService.php:30-32`, `AuditLogService.php:48-65`). Cohérent avec `AUDIT_POS_110_HIDDEN_RISKS` item 4 (« P1 infra plus que code »).
- **Preuve** : `app/Services/Fiscal/AuditLogService.php:160-172` (retry on QueryException unique).
- **Recommandation** : aucune action code ; runbook ops + alerte Redis-down (cycle **`P12_FISCAL_AUDIT_LOG_AUTOVERIFY`** peut porter cette assertion).

### F-VERIFY-08-07 — **INFO** — Incohérence audit amont vs code actuel sur F-FISC-001
- **V impactée** : V1, V5.
- **Trigger** : `AUDIT_POS_110_FISCAL_NF525_2026-04-19.md` ligne 16 affirme « pas de `lockForUpdate` sur `nextSeq = max+1` à `open()` ». Vérification du code : `ZReportService::open()` n'ajoute toujours **pas** de `lockForUpdate` sur `MAX(sequence_no)` (`:71-73`), contrairement au `close()` qui le fait sur la sélection du Z OPEN (`:121`). L'audit amont est donc **toujours valide** ; mitigation effective via `Cache::lock('z_report_b{n}')` + UNIQUE `(branch_id, sequence_no)`.
- **Impact** : Faible en pratique (lock cache + UNIQUE + retry implicite par exception). À documenter explicitement ou à corriger si la défense DB doit être renforcée pour multi-instance Redis split-brain.
- **Preuve** : `app/Services/Fiscal/ZReportService.php:71-73` vs `:121`.
- **Recommandation** : intégrer au scope **`P11_FISCAL_Z_OPEN_HARDENING`** — ajouter `lockForUpdate()` sur la sous-requête `max(sequence_no)` à `open()`, miroir de la stratégie `FiscalSequenceService`.

---

## 6. Conformité aux invariants FoodKing

| Invariant | Statut | Note |
|-----------|--------|------|
| 1. Backend pricing SSOT | ✓ | `ZReportService::aggregate` recalcule à partir d'`order_items.tax_amount` côté serveur (`:248-266`) ; aucune logique prix côté Vue. |
| 2. OrderStatus enum authoritative | ✓ | `OrderStatus::CANCELED|RETURNED` utilisés via enum (`ZReportService.php:237-238`, `OrderService.php:1501,1544`). |
| 3. branch_id isolation | ✓ | Toutes requêtes fiscales scopées ; `ZReportController::resolveBranchId` rejette user non-pinné (`:98-109`) ; `AuditLogService::write` rejette `branch_id=null` (`:88-93`) ; UNIQUE composites par branche (V5). |
| 4. Dispatch après DB commit | ✓ | Aucun dispatch dans services fiscaux ; côté `OrderService::changeStatus`, `OrderStatusChanged::dispatch` après transaction (`OrderService.php:1567-1578`). |
| 5. OrderService / FrontendOrderService symétrie | N/A | Cycle audit-only, aucune modification. |
| 6. Frozen zones | ✓ | Aucune édition. |

---

## 7. Critères d'acceptation §6 du brief

- ALL_GREEN si V1–V9 OK → **non atteint** (V1, V3, V4, V7, V8 = WARN).
- WARN si V8 absent (export) mais reste à roadmap → **applicable**, et complété par autres WARN (V1/V3/V4/V7).
- FAIL si V1, V3, V4 ou V6 défaillants → V6 = OK ; V1/V3/V4 sont **WARN** (mécanismes en place mais non-vérifiants/non-bloquants), pas FAIL au sens « rupture d'invariant immédiate ».

Verdict global : **WARN** — la chaîne fiscale est techniquement saine sur le chemin nominal (signatures, immutabilité, séquences, idempotence, permissions), mais trois zones de durcissement bloquent un verdict « ALL_GREEN » :
1. auto-vérification de la chaîne au `Z.open` non câblée (F-01),
2. RETURNED / changePaymentStatus tardif non bordé sur sealed-Z (F-02),
3. format d'export JET / PIAF non livré (F-04).

---

## 8. Cycles P proposés (ordonnancement + routing AGENTS.md)

| Cycle | Périmètre | Findings adressés | Priorité | PRIMARY_MODEL (routing AGENTS.md) |
|-------|-----------|-------------------|----------|------------------------------------|
| **P11_FISCAL_Z_OPEN_HARDENING** | (a) `STATUS_CLOSING` + marqueur début/rollback ; (b) `verifySignature(prev_z)` + `verifyChain(branch)` à `ZReportService::open()` ; (c) `lockForUpdate` sur `max(sequence_no)` à open ; (d) guard sealed-Z sur `OrderService::changeStatus(→RETURNED)` + `changePaymentStatus` ; (e) tests crash-recovery + RETURNED post-Z + Z après tampering | F-01, F-02, F-03, F-07 | **P0** | **GPT-5.4** (foodking-complex-implementer) — touche services fiscaux, lifecycle Order, transactions DB et tests crash : zone complexe sync/state, pricing/fiscal-adjacent. |
| **P12_FISCAL_AUDIT_LOG_AUTOVERIFY** | Schedule quotidien `foodking:fiscal:verify-chain` (toutes branches) ; ligne `audit_logs` `fiscal.chain_verified` ; métrique `fiscal_chain_corruption_total` ; alerte sur retour ≠ null ; runbook ops Redis-down | F-05, F-06 | **P1** | **Composer** (foodking-routine-implementer) — bornée (commande Console + scheduler + métrique), pas de mutation domaine, suit pattern `FiscalArchiveCommand`. |
| **P13_FISCAL_EXPORT_JET** | Sous-commande `foodking:fiscal:export-jet` au format JET / PIAF ; signature détachée (PADES ou détachée HMAC) ; fixture DGFiP de conformité ; documentation contrôle fiscal | F-04 | **P1** | **GPT-5.4** (foodking-complex-implementer) — format DGFiP + cryptographie détachée + fixture conformité = complexité spec/exécution non bornée. |
| **P14_FISCAL_CONTREPASSATION** *(option, alternative à branche (b) du F-02)* | Modèle `OrderRefund` jointable, ré-agrégé dans `ZReportService::aggregate` par `refunded_at`, écrans POS « avoir » dédiés, audit dédié | F-02 (alternative) | **P1 / scope POS-10** | **GPT-5.4** (foodking-complex-implementer) — touche modèle Order + agrégation Z + UI POS, scope cross-module. |

Notes de routing (cf. `AGENTS.md` §Model Roles + `.cursor/routing.md`) : tout ce qui touche `app/Services/Fiscal/*`, `app/Services/OrderService.php` (lifecycle / fiscal sealing) ou la cryptographie de signature relève de **GPT-5.4 (complex implementer)**. Les commandes Console isolées + scheduler + métriques exposées sont éligibles **Composer (routine implementer)**.

---

## 9. Pistes complémentaires (hors scope cycles P)

- Documenter `docs/FISCAL_SECRETS.md` (référencé dans `assertProductionSafe` — `ZReportService.php:362`, `AuditLogService.php:295`) si encore manquant ou incomplet.
- Compléter `docs/AUTHZ_MATRIX.md` avec la ligne `pos-reopen-z` (présente dans le seeder Spatie, pas encore documentée).
- `BUSINESS_RULES.md` : aligner la section « Clôture journalière » avec l'absence d'état `CLOSING` (et le futur, après P11).
- Étendre `FiscalObservabilityTest` pour assertions strictes sur le canal `fiscal` (déjà émis : `z_report.open|close`, `audit_log.write`).
- Pas de modèle `XReport` persistant (volontaire, X = lecture seule) — confirmer dans `docs/CORE_MODULES.md`.

---

GLOBAL: WARN — Chaîne NF525 techniquement saine (signatures, immutabilité, séquences, idempotence, permissions, archivage 6 ans) mais trois durcissements bloquants (auto-vérif chain à `Z.open`, guard RETURNED/payment_status sur sealed-Z, export format JET officiel) à livrer via P11/P12/P13.
