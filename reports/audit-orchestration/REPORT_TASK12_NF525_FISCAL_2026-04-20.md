# REPORT T12 — NF525 / Fiscal POS readiness

- **Date** : 2026-04-20
- **Tâche** : `tasks/audit-orchestration/12_TASK_NF525_FISCAL_POS_2026-04-20.md`
- **Mode** : audit READ-ONLY (aucune modification, aucun test exécuté)
- **Sources d'entrée obligatoires** :
  - `reports/review/AUDIT_POS_110_FISCAL_NF525_2026-04-19.md`
  - `reports/review/AUDIT_POS_110_NF525_READINESS_2026-04-19.md`
  - `reports/review/VERIFY_08_FISCAL_NF525_Z_OPEN_2026-04-20.md` (audit profond le plus récent)
  - `tasks/phase9-sync/BLOCKER_POS_9_4_10_destroy_after_Z_2026-04-18.md` (CLOSED)
- **Code lu** : `app/Services/Fiscal/{AuditLogService,ZReportService,XReportService,FiscalSequenceService}.php`, `app/Models/{AuditLog,ZReport}.php`, `app/Console/Commands/FiscalArchiveCommand.php`, `app/Http/Controllers/Admin/Fiscal/{ZReportController,XReportController}.php`, `app/Console/Kernel.php`, `routes/api.php` (786-806), `config/fiscal.php`, migrations `2026_04_22_*`, `tests/Feature/Fiscal/*` (24 fichiers).

---

## 0. Verdict global

**PARTIAL (WARN)** — Les 4 piliers NF525 (Inaltérabilité / Sécurisation / Conservation / Archivage) sont **couverts au niveau MVP**, avec preuves de signature HMAC chaînée, immutabilité DB+Eloquent, séquence monotone par branche, archive 6 ans déterministe et permission `pos-manage-fiscal`. Aucun pilier n'est manquant au sens « POS non déployable », **mais** la conformité « certification organisme » n'est pas atteinte : 5 gaps techniques + 4 gaps légaux (cf. §5/§6) bloquent un PASS strict. Roadmap remédiation (P11–P14) déjà cadrée par VERIFY-08.

> Conformément aux critères PASS/FAIL du brief : pas de pilier critique manquant ⇒ pas de FAIL ; mais V1/V3/V4/V7 = WARN ⇒ pas de PASS strict ⇒ **PARTIAL**.

---

## 1. État des 4 piliers NF525

### Pilier 1 — Inaltérabilité (signature chaînée + hash)

| Aspect | Statut | Composant code | Preuve |
|---|---|---|---|
| Hash chain `audit_logs` (HMAC-SHA256) | **OK** | `AuditLogService::write/computeHash` | `app/Services/Fiscal/AuditLogService.php:70-173, 218-224` |
| Lock chaîne (cache + DB UNIQUE) | **OK** | `Cache::lock('audit_chain_b{n}')` + UNIQUE`(branch_id, prev_hash)` | `AuditLogService.php:95-112` ; migration `2026_04_22_100000_add_unique_chain_index_to_audit_logs.php:33-39` |
| Vérification chaîne (`verifyChain`) | **PRÉSENTE** mais **NON CÂBLÉE** auto | `AuditLogService::verifyChain` | `AuditLogService.php:180-212` (jamais appelé hors tests) |
| Signature Z (HMAC chaînée prev_hash) | **OK** | `ZReportService::sign/close` | `app/Services/Fiscal/ZReportService.php:131-145, 316-342` |
| `verifySignature(Z)` | **PRÉSENTE** mais appelée seulement à la génération PDF | `ZReportController::pdf` | `ZReportController.php:73-89` |
| Séquence fiscale monotone par branche | **OK** | `FiscalSequenceService::next` + UNIQUE`(branch_id, fiscal_sequence_no)` | `app/Services/Fiscal/FiscalSequenceService.php:57-104` ; migration `2026_04_22_000001_add_fiscal_sequence_no_to_orders.php:36-44` |
| `lockForUpdate` sur `MAX(sequence_no)` à `Z.open` | **GAP** (`F-FISC-001`, `F-VERIFY-08-07`) | `ZReportService::open` n'utilise que cache lock + UNIQUE | `ZReportService.php:71-73` (vs `:121` close = OK) |
| Guard sealed-Z sur `OrderService::changeStatus(→RETURNED)` / `changePaymentStatus` | **GAP** (`F-VERIFY-08-02`) | Seul `destroy()` est gardé | `OrderService.php:1499-1567, 1592-1653, 1736-1755` ; `ZReportService::aggregate` filtre par `created_at` (`:207-214`) |

**Couverture actuelle** : signature HMAC chaînée multi-niveaux (audit_logs + Z reports), séquence monotone gap-free, immutabilité Eloquent + DB triggers (MySQL `SIGNAL SQLSTATE '45000'`, SQLite `RAISE(ABORT,…)`), rollback migration bloqué en production.
**Gaps + risque** :
1. **`verifyChain`/`verifySignature` non appelés à `Z.open`** — corruption détectée seulement à la prochaine PDF (`F-VERIFY-08-01`, HIGH).
2. **`changeStatus → RETURNED` ou `changePaymentStatus` post-Z** non bordés — le retour n'est ré-agrégé nulle part : ni dans le Z scellé (signature figée), ni dans le Z courant (filtre `created_at`). Violation principe « tout retour produit une trace fiscale » (`F-VERIFY-08-02`, HIGH).
3. **Pas de `STATUS_CLOSING`** — recovery seulement implicite (rollback + lock TTL). Aucun test crash mid-`close` (`F-VERIFY-08-03`, MEDIUM).

### Pilier 2 — Sécurisation (utilisateur / audit log / RBAC)

| Aspect | Statut | Composant code | Preuve |
|---|---|---|---|
| Permission `pos-manage-fiscal` sur toutes routes fiscales | **OK** | `ZReportController::authorizeFiscal`, `XReportController` | `ZReportController.php:91-96` ; `routes/api.php:794-806` |
| Resolve `branch_id` depuis user pinné (refus paylaod-side) | **OK** | `ZReportController::resolveBranchId` | `ZReportController.php:98-109` |
| Refus `branch_id=null` à l'écriture audit | **OK** (`F-C5`) | `AuditLogService::write` | `AuditLogService.php:88-93` |
| Rate-limit `throttle:10,1` sur open/close | **OK** | `routes/api.php:797,800` | `tests/Feature/Fiscal/FiscalRateLimitTest.php` (4 cas, 11ᵉ → 429) |
| Garde secrets prod (sentinelles + min 32 chars) | **OK** (`F-C1`) | `assertProductionSafe` | `AuditLogService.php:284-308` ; `ZReportService.php:344-374` ; `config/fiscal.php:60-87` ; `tests/Feature/Fiscal/FiscalSecretProductionGuardTest.php` |
| Audit user_id + ip + user_agent + session_id | **OK** | `AuditLogService::performInsert` | `AuditLogService.php:117-141` |
| Observabilité fiscale (channel `fiscal`) | **OK** (`F-C7`) | `Log::channel('fiscal')` | `AuditLogService.php:148-157` ; `ZReportService.php:84-89, 151-163` ; `tests/Feature/Fiscal/FiscalObservabilityTest.php` |
| `pos-reopen-z` (réouverture contrôlée) | **PRÉSENT (seeder)** mais sans contrôleur | seeder Spatie | mentionné dans `AUDIT_POS_110_NF525_READINESS_*` ; non câblé en route |
| Cross-branch 403 (V9) | **OK** | tests | `tests/Feature/Fiscal/ZReportControllerTest.php` |

**Couverture actuelle** : RBAC fort (sanctum + apiKey + Spatie permission + branch pinning), rate-limit serré, audit complet user/ip/UA/session, observabilité loggable SIEM (sans PII).
**Gaps + risque** :
4. **`pos-reopen-z` documenté mais non câblé** — pas de procédure formelle de réouverture (clôture annulée par erreur). LOW : flux opérationnel manuel possible via DB ; à formaliser pour audit.
5. **Pas de durée max session pour rôles fiscaux** ni de 2FA obligatoire sur opérations Z — non exigé NF525 stricto sensu mais attendu par les schémas anti-fraude récents.

### Pilier 3 — Conservation (durée 6 ans, exportable)

| Aspect | Statut | Composant code | Preuve |
|---|---|---|---|
| Rétention 6 ans configurée | **OK** | `config/fiscal.php:45` (`FISCAL_ARCHIVE_RETENTION_YEARS=6`) | `FiscalArchiveCommand.php:109` (`retention_years`) |
| Rollback `audit_logs` bloqué en prod | **OK** (`F-B7`) | migration `down()` | `2026_04_22_000002_create_audit_logs_table.php:62-83` |
| Stockage configurable (disk + path) | **OK** | `config/fiscal.php:55-56` | — |
| Snapshot Z dénormalisé (résiste à archivage Order) | **OK** | colonnes `total_*`, `total_by_*`, `*_count` figées à `close` | `ZReportService.php:139-145` ; `ZReport.php:18-37` |
| Pas de purge automatique avant 6 ans | **OK (par absence)** | `Console\Kernel` n'a aucune commande de purge `audit_logs` ou `z_reports` | `app/Console/Kernel.php:17-38` |
| Pas de scheduler `foodking:fiscal:archive` automatique | **GAP** | aucune entrée `$schedule->command('foodking:fiscal:archive')` | `app/Console/Kernel.php:17-38` |

**Couverture actuelle** : durée 6 ans déclarée, immutabilité technique des évidences (triggers DB + Eloquent guards + rollback prod refusé), rétention configurable.
**Gaps + risque** :
6. **Aucun schedule `foodking:fiscal:archive`** — l'export d'archive est manuel ; un opérateur qui oublie de l'exécuter n'a pas d'archive scellée disponible en cas de contrôle. MEDIUM. Conséquence opérationnelle, pas une rupture conceptuelle (les données restent dans la DB).
7. **Pas de cron `foodking:fiscal:verify-chain`** — corruption silencieuse non détectée (`F-VERIFY-08-05`, LOW). Roadmap → cycle P12.

### Pilier 4 — Archivage (clôture périodique, archive scellée)

| Aspect | Statut | Composant code | Preuve |
|---|---|---|---|
| Archive zip déterministe (manifest + 3 JSONL) | **OK** (`F-C8`) | `FiscalArchiveCommand::build` | `FiscalArchiveCommand.php:78-148` |
| Streaming mémoire bornée (`->lazy(500)`) | **OK** | `streamToJson` | `FiscalArchiveCommand.php:150-183` ; `tests/Feature/Fiscal/FiscalArchiveMemoryBoundedTest.php` (1k orders + 500 audit_log) |
| Round-trip stable (sort par id / sequence_no) | **OK** | `zReportQuery / orderQuery / auditLogQuery` orderBy | `FiscalArchiveCommand.php:194, 205, 216` |
| Schema_version dans manifest | **OK** | `manifest['schema_version'] = 2` | `FiscalArchiveCommand.php:110` |
| Tests | **OK** | round-trip + memory-bounded + content | `tests/Feature/Fiscal/FiscalArchiveTest.php` ; `FiscalArchiveMemoryBoundedTest.php` |
| Format **JET / PIAF officiel** (DGFiP) | **GAP** (`F-VERIFY-08-04`, MEDIUM) | format propriétaire JSON+ZIP uniquement | aucun fichier `*Jet*` ni `*Piaf*` dans `app/` |
| Chiffrement / signature détachée GPG de l'archive | **PRÉPARÉ** mais non implémenté | commentaire `config/fiscal.php:53` (« gpg-encrypted in prod when the gpg binary is available ») | aucun `gpg` invoqué dans `FiscalArchiveCommand` |

**Couverture actuelle** : archive technique solide (déterministe, mémoire bornée, retention 6 ans), tous les artefacts fiscaux inclus (Z reports + orders + audit_logs).
**Gaps + risque** :
8. **Pas de format JET / PIAF officiel** — non recevable tel quel par DGFiP en cas de contrôle (Art. 286-I-3 bis CGI). MEDIUM. Roadmap → cycle P13.
9. **Pas de signature détachée / chiffrement GPG** — l'archive zip est signable a posteriori mais aucun lien cryptographique entre l'archive et les sceaux fiscaux internes (les hash sont dans les JSON, pas signés au niveau bundle).

---

## 2. Vérifications spécifiques demandées

| Vérif | Résultat | Preuve |
|---|---|---|
| `app/Services/Fiscal/` existe | **OUI** — 4 services (`AuditLogService`, `ZReportService`, `XReportService`, `FiscalSequenceService`) | `Glob app/Services/Fiscal/**/*.php` (4 fichiers) |
| Signature SHA-256 chaînée | **OUI** — HMAC-SHA256 chaînée à 2 niveaux (audit_logs + Z reports) | `AuditLogService.php:218-224` ; `ZReportService.php:316-342` |
| `app/Models/Receipt.php` | **ABSENT** — pas de modèle Receipt dédié ; les tickets sont des `Order` augmentés (`pos_payment_method`, `total*`) | `Glob app/Models/Receipt.php` ⇒ 0 résultat |
| `app/Models/OrderTicket.php` | **ABSENT** | `Glob` ⇒ 0 résultat |
| `app/Jobs/Fiscal/ZTicketArchiveJob.php` | **ABSENT** — l'archivage est une commande Console synchrone, pas un Job async | `Glob app/Jobs/Fiscal/**/*.php` ⇒ 0 résultat |
| `app/Models/JournalEvent.php` (JET) | **ABSENT** — pas de modèle `JournalEvent` ; le journal d'événements est `audit_logs` (rôle équivalent JET non normé) | `Grep "class JournalEvent"` ⇒ 0 résultat |
| `tasks/phase9-sync/BLOCKER_POS_9_4_10_destroy_after_Z` | **CLOSED 2026-04-18** par commits `2d4d2c84` / `a7036f6e` / `c3c0593e` (branche `feat/pos-phase-9-2-3`) | fichier lu ; 93/93 tests Fiscal+PosOrder OK |
| `tests/Feature/Fiscal/*` | **24 fichiers** — couverture solide (hash chain, immutabilité, concurrence, séquence, Z close, X, archive, secrets, observabilité, permissions, rate-limit, schemas, sealed-Z destroy) | `Glob tests/Feature/Fiscal/**/*.php` (24) |
| `tests/Feature/POS/ZTicketTest.php` | **ABSENT** — pas de `ZTicketTest` dédié ; tests Z présents sous `tests/Feature/Fiscal/ZReport*Test.php` (6 fichiers) | `Glob` ⇒ 0 résultat |

---

## 3. Vérifications supplémentaires (ré-impression / duplicata)

| Aspect | Résultat | Preuve |
|---|---|---|
| Mention « duplicata » sur ticket réimprimé | **GAP** | `Grep "duplicata|reprint"` (case-insensitive) sur `app/`+`resources/` ⇒ aucune occurrence métier ; seuls 2 commentaires (`KioskConfirmationComponent.vue:304`, `kioskReceiptPersistence.js:28`) |
| Journal des ré-impressions | **GAP** | `printReceipt` côté front uniquement ; aucun `audit_logs.action = 'order.reprint'` (`Grep "printReceipt|print_receipt" app/` ⇒ 1 ressource lecture seule, 0 endpoint dédié) |
| API `pos-reopen-z` (permission seedée) | **PRÉSENT** mais sans route/controller | mentionné `VERIFY_08:171` ; aucun `Route::*reopen*` dans `routes/api.php` |

**Risque** : NF525 exige (a) un marquage explicite « DUPLICATA » sur tout ticket réimprimé, (b) un journal d'événement de chaque ré-impression. **Aucun des deux n'est implémenté**. Cycle dédié recommandé (P-REPRINT, MEDIUM).

---

## 4. Checklist du brief (V1..V7)

- [x] **V1. Inaltérabilité (signature chaînée + hash)** — couverte (audit_logs + Z reports HMAC-SHA256 chaînés ; immutabilité DB + Eloquent), avec gap chiffré : `F-VERIFY-08-01` (verifyChain/verifySignature non câblés à open) et `F-VERIFY-08-02` (RETURNED/payment_status post-Z non bordés).
- [x] **V2. Sécurisation (utilisateur, audit log, RBAC)** — couverte : `pos-manage-fiscal`, branch pinning, throttle 10/min, garde secrets prod, observabilité fiscale.
- [x] **V3. Conservation 6 ans exportable** — couverte : `FISCAL_ARCHIVE_RETENTION_YEARS=6`, rollback prod bloqué sur `audit_logs`, dénormalisation à `close`. Gap : pas de schedule auto.
- [x] **V4. Archivage scellé** — couverte techniquement (zip déterministe streamé + manifest + tests round-trip + memory-bounded). Gap : format JET/PIAF officiel (`F-VERIFY-08-04`).
- [x] **V5. Ticket Z (clôture journalière)** — implémenté + testé (`ZReportService` + `ZReportController` + 6 tests `ZReport*Test.php`). Gap mineur : pas de scheduler auto pour close de fin de journée — opération manuelle.
- [ ] **V6. Ré-impression contrôlée (mention « duplicata » + journal)** — **GAP COMPLET** : ni marquage UI ni audit log dédié.
- [x] **V7. Liste des gaps légaux + recommandation** — fournie ci-dessous (§5/§6).

---

## 5. Gaps techniques (consolidé)

| # | Sévérité | Gap | Source | Cycle |
|---|---|---|---|---|
| G1 | **HIGH** | `Z.open` n'auto-vérifie ni `verifySignature(prev_z)` ni `verifyChain(branch)` | `F-VERIFY-08-01` | P11 |
| G2 | **HIGH** | Aucun guard sealed-Z sur `OrderService::changeStatus(→RETURNED)` / `changePaymentStatus` ; RETURNED tardif jamais ré-agrégé | `F-VERIFY-08-02` | P11 / P14 |
| G3 | **MEDIUM** | Pas de `STATUS_CLOSING` ; zéro test crash-recovery mid-`close` | `F-VERIFY-08-03` | P11 |
| G4 | **MEDIUM** | Pas de format JET/PIAF officiel ; archive propriétaire JSON+ZIP | `F-VERIFY-08-04` | P13 |
| G5 | **LOW** | `verifyChain` non monitoré (pas de cron/health-check) | `F-VERIFY-08-05` | P12 |
| G6 | **MEDIUM** | Pas de schedule `foodking:fiscal:archive` ni `foodking:fiscal:z-close` automatique de fin de journée | constat T12 (`Console/Kernel.php`) | P12 / P-OPS |
| G7 | **MEDIUM** | Pas de marquage « DUPLICATA » ni journal de ré-impression de ticket | constat T12 (`Grep duplicata|reprint`) | P-REPRINT |
| G8 | **LOW** | Pas de signature détachée GPG / sceau cryptographique du bundle archive (les hash sont dans les JSON, pas au niveau zip) | constat T12 (`config/fiscal.php:53` annonce « gpg-encrypted » non implémenté) | P13 |
| G9 | **LOW** | `pos-reopen-z` (permission seedée) sans contrôleur ni procédure | constat T12 | backlog |
| G10 | **INFO** | Bench 10k+ séquences/charge non automatisé en CI | `F-FISC-004` (audit amont) | backlog perf |
| G11 | **INFO** | `Z.open` n'utilise pas `lockForUpdate` sur `MAX(sequence_no)` (mitigation cache lock + UNIQUE) | `F-FISC-001`, `F-VERIFY-08-07` | P11 |

---

## 6. Obligations légales NF525 encore non couvertes

| Obligation NF525 (technique + juridique) | État | Notes |
|---|---|---|
| Auto-tests obligatoires logiciel (NF525 §4.4) | **NON COUVERT** | Aucun endpoint admin `/api/admin/fiscal/self-test` ni commande `foodking:fiscal:self-test` qui valide simultanément (1) verifyChain par branche, (2) verifySignature derniers Z, (3) intégrité unique indexes, (4) présence des triggers DB. |
| Certificat éditeur / attestation individuelle (Art. 286-I-3 bis CGI) | **NON FOURNI** | Aucune attestation/certification organisme (`AFNOR Certification` ou `LNE`) référencée. Engagement organisme tiers requis (cf. roadmap `AUDIT_POS_110_NF525_READINESS_*` §5). |
| Format export JET / PIAF (DGFiP) | **NON COUVERT** (G4) | Cycle P13. |
| Marquage « DUPLICATA » sur ré-impression | **NON COUVERT** (G7) | Cycle P-REPRINT. |
| Journal des ré-impressions (audit dédié) | **NON COUVERT** (G7) | Cycle P-REPRINT. |
| Procédure de réouverture Z contrôlée + traçabilité | **NON COUVERT** (G9) | Permission seedée mais pas câblée. |
| Documentation utilisateur (mode opératoire fiscal, runbook clôture, contrôle DGFiP) | **PARTIEL** | `docs/FISCAL_SECRETS.md` présent ; `docs/BUSINESS_RULES.md` à aligner sur §1.2 NF525 (cf. roadmap `AUDIT_POS_110_NF525_READINESS_*` étape 1) ; pas de runbook dédié contrôle fiscal. |
| Test 10k+ séquences sous charge (preuve perf) | **NON COUVERT** (G10) | `F-FISC-004`. |
| Conservation 6 ans démontrée par procédure | **OK techniquement**, **manuel opérationnellement** (G6) | Schedule auto manquant. |

---

## 7. Conformité aux invariants FoodKing

| Invariant | État | Note |
|---|---|---|
| 1. Backend pricing SSOT | ✓ | `ZReportService::aggregate` recompute via `order_items.tax_amount` côté serveur (`:248-266`). |
| 2. OrderStatus enum authoritative | ✓ | `OrderStatus::CANCELED|RETURNED` utilisés via enum. |
| 3. branch_id isolation | ✓ | Toutes requêtes scopées ; `AuditLogService` rejette `branch_id=null` ; UNIQUE composites par branche. |
| 4. Dispatch après DB commit | ✓ | Aucun dispatch dans services fiscaux ; `OrderStatusChanged::dispatch` après transaction (OrderService). |
| 5. Symétrie OrderService / FrontendOrderService | N/A | Audit only. |
| 6. Zones gelées | ✓ | Aucune édition. |

---

## 8. Roadmap remédiation priorisée

| Cycle | Périmètre | Findings | Priorité | Routing AGENTS.md |
|---|---|---|---|---|
| **P11_FISCAL_Z_OPEN_HARDENING** | (a) `verifySignature(prev_z)` + `verifyChain(branch)` à `Z.open` ; (b) `STATUS_CLOSING` + recovery ; (c) `lockForUpdate` sur `MAX(sequence_no)` ; (d) guard sealed-Z sur `changeStatus → RETURNED` + `changePaymentStatus` ; (e) tests crash-recovery + RETURNED post-Z | G1, G2, G3, G11 | **P0** | foodking-complex-implementer (GPT-5.4) |
| **P12_FISCAL_AUDIT_LOG_AUTOVERIFY** | Schedule quotidien `foodking:fiscal:verify-chain` + métrique `fiscal_chain_corruption_total` + audit_log `fiscal.chain_verified` ; runbook ops | G5, G6 (verify), Redis-down | **P1** | foodking-routine-implementer (Composer) |
| **P13_FISCAL_EXPORT_JET** | `foodking:fiscal:export-jet` au format DGFiP + signature détachée + fixture conformité + doc contrôle fiscal | G4, G8 | **P1** | foodking-complex-implementer (GPT-5.4) |
| **P14_FISCAL_CONTREPASSATION** *(option, alternative à G2.b)* | Modèle `OrderRefund` + ré-agrégation Z par `refunded_at` | G2 (alt) | **P1 / scope POS-10** | foodking-complex-implementer (GPT-5.4) |
| **P-REPRINT** *(nouveau)* | (a) marquage UI « DUPLICATA » sur reprint kiosk/POS, (b) endpoint `POST /api/admin/order/{id}/reprint` qui écrit `audit_logs.action = 'order.reprint'` (compteur), (c) compteur `reprint_count` sur `Order` | G7 | **P1** | foodking-routine-implementer (Composer) |
| **P-OPS-SCHEDULE** *(nouveau)* | (a) `$schedule->command('foodking:fiscal:archive --branch=*')->dailyAt('03:00')` ; (b) optionnel : `foodking:fiscal:z-close` auto fin de service avec garde-fou | G6 | **P1** | foodking-routine-implementer (Composer) |
| **P-CERTIFICATION** | Engagement organisme (AFNOR / LNE), attestation individuelle, dossier de preuves, procédure exploitation | obligations légales | **P0 (legal)** | escalade humaine (domaine juridique) |
| **P-SELFTEST** | Endpoint admin `/fiscal/self-test` + commande `foodking:fiscal:self-test` (verifyChain × branches + verifySignature derniers Z + intégrité indexes + présence triggers DB + statut secrets) | obligation NF525 §4.4 | **P1** | foodking-routine-implementer (Composer) |

---

## 9. Verdict synthétique

- **PILIERS NF525** : 4/4 couverts au niveau **MVP_INTERNE** (audit + Z + séquence + archive + permissions + observabilité).
- **READY_TECHNIQUE_INTERNE** : **OUI** — la chaîne tient sur le chemin nominal.
- **READY_CERTIFICATION_NF525_ORGANISME** : **NON** — manque (a) auto-vérif active à open (G1), (b) guard contre ré-écritures post-Z (G2), (c) format JET officiel (G4), (d) duplicata marqué + journal (G7), (e) self-test (P-SELFTEST), (f) attestation organisme (P-CERTIFICATION).
- **VERDICT FINAL T12** : **PARTIAL (WARN)** — pas de FAIL (aucun pilier critique manquant, BLOCKER 9.4.10 CLOSED) ; pas de PASS (5 gaps techniques HIGH/MEDIUM + 4 obligations légales non couvertes). Roadmap claire P11/P12/P13/P-REPRINT/P-OPS-SCHEDULE/P-SELFTEST déjà cadrée.

---

## 10. Action si FAIL → Non applicable (verdict PARTIAL)

Le critère « POS non déployable » n'est pas atteint. Le sous-cycle de remédiation reste néanmoins **P0** sur P11 (auto-vérif + sealed-Z guard + crash recovery) car ce sont les 2 gaps HIGH qui rapprochent la chaîne d'un FAIL si une contrepassation post-Z arrive en production sans bordure.

> Top 3 actions prioritaires (si l'orchestrateur veut traiter la dette) :
> 1. **P11** — câbler `verifyChain` + `verifySignature` à `Z.open`, ajouter guard sealed-Z sur `changeStatus`/`changePaymentStatus`, ajouter `STATUS_CLOSING` + tests crash.
> 2. **P-OPS-SCHEDULE** — schedule `foodking:fiscal:archive` quotidien (sans quoi la conservation 6 ans est opérationnellement fragile).
> 3. **P13 + P-REPRINT** — format JET + marquage DUPLICATA + journal de ré-impression (préparation contrôle DGFiP).

— Fin du rapport T12 —
