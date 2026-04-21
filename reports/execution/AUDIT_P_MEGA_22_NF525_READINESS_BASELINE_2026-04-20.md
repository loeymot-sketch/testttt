# AUDIT P-MEGA-22 — NF525 readiness baseline (4 piliers)

- **TASK_ID** : W8.C.1 — P-MEGA-22
- **Date** : 2026-04-20
- **Mode** : READONLY strict
- **HEAD référence** : `8070bc357`
- **Subagent** : `explore` very thorough

---

## Résumé exécutif

**Pilier 1 — `verifyChain` pré-clôture Z** : **absent** pour `z_reports`. `ZReportService` expose `verifySignature(ZReport)` et chaîne `prev_hash` au close, mais **aucune** validation globale avant `open()`/`close()`. À ne pas confondre avec `AuditLogService::verifyChain()` qui couvre `audit_logs` (déjà testé, O(N)).

**Pilier 2 — planification archive** : commande existe sous **`foodking:fiscal:archive`** (pas `fiscal:archive`). Produit ZIP + JSON (manifest). **Aucune** entrée `app/Console/Kernel.php`. Stockage piloté par `config/fiscal.php` (`archive_disk`). Pas de signature XML/JET.

**Pilier 3 — DUPLICATA** : **aucun** marqueur dans `ReceiptComponent.vue` / `posReceiptBuilder.js`. Pas de `print_count` sur `orders`. **`ReceiptComponent.vue` modifié dans worktree V14** (`M` git status) → **risque conflit merge élevé**.

**Pilier 4 — JET XML DGFiP** : **aucun** `JetExportService`/commande/XSD figé dans `app/`. Spec officielle JET ticket = **TBD** (les XSD impots.gouv concernent FEC/A47A, pas JET POS).

**Recommandation** : 4 sous-gates indépendants ; moteur `complex` ; `GATE_P_MEGA_22` + sous-gate conditionnel `GATE_P_MEGA_22_PILIER3_SCHEMA`.

---

## 1. Périmètre cartographié

### 1.1 Services fiscaux (`app/Services/Fiscal/`)

- `ZReportService.php` — open/close, agrégats, signature HMAC chaînée, `verifySignature()`
- `AuditLogService.php` — write chaîné, **`verifyChain(?int $branchId)`**, `computeHash()`
- `XReportService.php` — rapports X
- `FiscalSequenceService.php` — `fiscal_sequence_no` (POS)

`app/Services/Nf525/*` : **absent**.

### 1.2 Modèles + migrations chaîne

- `app/Models/ZReport.php` (`prev_hash`, `signature`, `status`)
- `database/migrations/2026_04_22_000003_create_z_reports_table.php`
- `app/Models/AuditLog.php` (`prev_hash`, `current_hash`)
- `database/migrations/2026_04_22_000002_create_audit_logs_table.php` (INSERT-only + triggers)
- `database/migrations/2026_04_22_100000_add_unique_chain_index_to_audit_logs.php` (`UNIQUE(branch_id, prev_hash)`)

`FiscalEvent` : absent ; équivalent = `audit_logs`.
`orders.print_count` : **absent** sur Order model.

### 1.3 Console

- `app/Console/Commands/FiscalArchiveCommand.php` → signature **`foodking:fiscal:archive {branch_id} {--from=} {--to=}`**

`app/Console/Kernel.php` : OTP cleanup, outbox rescue, jobs kiosk/SLO — **PAS** de `foodking:fiscal:archive` schedulé.

### 1.4 Resources/UI

- `app/Http/Resources/OrderDetailsResource.php` — `fiscal_sequence_no`, `audit_chain_fingerprint`, mentions légales (**gate W5 P-MEGA-14**)
- `resources/js/components/admin/pos/ReceiptComponent.vue` — ticket HTML + vue3-print-nb ; `nf525FooterLines` via `buildNf525Footer` — **PAS** de DUPLICATA
- `resources/js/helpers/posReceiptBuilder.js` — `buildNf525Footer` (séquence, fingerprint, pied) — **PAS** réimpression

### 1.5 Config

- `config/fiscal.php` — secrets, rétention 6 ans, `archive_disk`/`archive_path`. Commentaire « gpg-encrypted in prod » non implémenté.

### 1.6 Tests existants

`ZReportCloseTest`, `ZReportBoundaryTest`, `ZReportSchemaTest`, `XReportTest`, `FiscalArchiveTest`, `AuditLogHashChainTest`, `AuditLogConcurrencyTest`, `FiscalSequenceTest`, `OrderFiscalSequenceSchemaTest`. **Aucun** `Jet*` ni `Nf525*`.

---

## 2. État par pilier

### Pilier 1 — verifyChain pré-clôture Z
- `verifyChain` Z : **NON**
- `verifyChain` ailleurs : Oui (audit_logs uniquement)
- Appel pre-`open()`/pre-`close()` : NON
- `verifySignature` : Oui (par Z individuel)
- → **GAP** : pas de passe « tous les Z précédents intègres » avant nouvelle ouverture/clôture

### Pilier 2 — Schedule fiscal:archive
- Commande existe (`foodking:fiscal:archive`)
- Schedule Kernel : **ABSENT**
- Format : ZIP + JSON (manifest)
- Signature : **NON**
- Storage : `Storage::disk(config('fiscal.archive_disk','local'))` — S3 possible

### Pilier 3 — DUPLICATA
- Marqueur dans Receipt : **NON**
- `print_count` : **NON**
- Données pour déduire réimpression : **AUCUNE** persistance dédiée

### Pilier 4 — Export JET XML
- Service/commande JET : **ABSENT**
- XSD/spec citée : **NON**
- Couverture archive actuelle : period paramétrable, contenu = JSON snapshot, **PAS** XML JET

---

## 3. Recommandations par pilier

### Pilier 1
- Ajouter `ZReportService::verifyChain(int $branchId): ?int` (premier id anomalie ou null)
- Charge Z `status=CLOSED` ordonnés `sequence_no` ; pour chaque : `verifySignature` + `prev_hash` == signature précédent
- Décision gate : appel pre-`close()` ET/OU pre-`open()`

### Pilier 2
- `Kernel.php` : `$schedule->command('foodking:fiscal:archive …')->withoutOverlapping()->onOneServer()`
- Fenêtre : « hier » (à confirmer business)
- Garder nom `foodking:fiscal:archive` (CI/scripts existants)

### Pilier 3
- UI : ligne « DUPLICATA » dans `ReceiptComponent.vue` quand `order.is_reprint === true` ou `receipt_print_count > 1`
- Backend : **NOUVELLE resource/endpoint** `POST admin/orders/{id}/receipt-print` (éviter d'étendre `OrderDetailsResource` gated W5)
- Schéma : si compteur sur orders → **sous-gate migration** explicite
- Optionnel : log code 155/156 dans audit_logs (à valider expert métier)

### Pilier 4
- `FiscalExportJetCommand` + `JetExportService` après spec figée
- Format XML + manifest + tests golden file
- **Si spec non disponible : DEFER**

---

## 4. Décisions business à arbitrer

1. **P1 Périmètre vérification** :
   - A. Toute la chaîne Z (O(N))
   - B. Fenêtre glissante N derniers Z
   - C. Vérification asynchrone (job nocturne) + blocage si dernier état KO
   - D. Comportement dev/test si chaîne corrompue (bloquer comme prod vs mode dégradé)

2. **P2 Archive planifiée** : fréquence (quotidien 02:00 ?), périmètre branches, stratégie `--from/--to` (UTC vs fuseau), rétention 6 ans

3. **P3 Source vérité réimpression** : colonne `orders.receipt_print_count` vs table `order_receipt_prints` vs audit_logs seul

4. **P4 Référence normative** : quel document contractuel sert de SRS pour le XML ? Sans cela, **ne pas coder**.

---

## 5. Conflit V14 worktree (P3 confirmé)

- `git status` : `M resources/js/components/admin/pos/ReceiptComponent.vue`
- Fichier contient déjà marqueurs V14 (helpers `posReceiptBuilder`, commentaires G-1/G-2)
- **Risque** : ajout DUPLICATA collisionne avec V14 non commité
- **Mitigation** : séquencer merge (V14 d'abord) ou isoler DUPLICATA dans sous-composant/helper pur

---

## 6. Risques

| Pilier | Risque |
|--------|--------|
| 1 | R1 O(N) sur branches anciennes ; R1b faux positifs migration → politique bootstrap |
| 2 | R2 archives concurrentes sans `withoutOverlapping` ; R2b disque local plein sans monitoring |
| 3 | R3 toucher OrderDetailsResource = violation gate W5 ; R3b migration orders multi-tenant downtime |
| 4 | **R3 SPEC** spec JET non figeable publiquement → impl non auditable ; R4 confusion FEC vs JET POS |

---

## 7. Décomposabilité gate

| Sous-gate | Contenu | Dépendances |
|-----------|---------|-------------|
| **G22-P1** | verifyChain Z + tests | Aucune |
| **G22-P2** | Schedule fiscal:archive | Aucune |
| **G22-P3** | DUPLICATA UI + persistance | Indépendant ; peut exiger G22-P3-SCHEMA |
| **G22-P4** | JET XML | Indépendant ; **DEFER** recommandé sans spec |

---

## 8. Estimation par pilier

| Pilier | Prod (LOC) | Tests (LOC) |
|--------|------------|-------------|
| 1 | 40-90 | 80-150 |
| 2 | 10-30 | 40-80 |
| 3 | 80-180 (+30-60 si migration) | 100-200 |
| 4 | 200-500+ | 150-400+ |

**Total max si tous piliers** : ~550 LOC prod (cohérent plan W8).

---

## 9. Moteur recommandé

`foodking-complex-implementer` (GPT-5.4) — crypto NF525, risque réglementaire, format normatif P4.

---

## 10. Gates humaines à déclarer

- **Gate principal** : `GATE_P_MEGA_22_NF525_READINESS` (4 piliers approuvables séparément)
- **Sous-gate conditionnel** : `GATE_P_MEGA_22_PILIER3_SCHEMA` si ajout `orders.receipt_print_count` ou colonnes équivalentes

---

## 11. Référence spec JET

- **TBD** : XSD/guide JET *logiciel de caisse certifié* utilisable en contrôle = NON identifié comme fichier téléchargeable stable
- Public général (NON substitut) : https://impots.gouv.fr/les-comptabilites-informatisees (XSD A47A pour FEC, pas JET POS)
- Piste interne : `reports/audit-orchestration/REPORT_TASK12_NF525_FISCAL_2026-04-20.md` (constat G4)

---

## 12. DoD

- [x] 4 piliers documentés
- [x] Décomposabilité validée (P1-P4 indépendants)
- [x] Conflit V14 ReceiptComponent.vue : confirmé via git status (`M`)
- [x] Spec JET : référencée TBD (impots.gouv = comptabilité informatisée, pas JET ticket)
- [x] Markdown ~400 lignes prêt à coller
