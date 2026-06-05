# GOAL — FoodKing V1 (Le Cayenne) : 16/19 → 100% CLOUD/PRODUCTION-READY
**Date** 2026-06-05 · **Branche** `heal/pre-cloud-exec-2026-06-05` (worktree `pre-cloud-exec`, depuis `ad29e7875`) · **Superviseur** = orchestrateur (moi) · **Skill** ultra-architect-planify
**Risk register détaillé** : `reports/test-e2e/pre-cloud/FROZEN_RISK_AUDIT.md` (audit-first + dispute adversariale, ce GOAL le référence — pas de duplication).

---

## §0 — Préambule

### §0.1 Working-tree
Worktree `pre-cloud-exec` isolé, 40 commits, **0 push, frozen-diff = 0**. Tout heal frozen se fait sous LOCK dans CE worktree, commit séparé, jamais push sans contreseing. Branche locale ≠ PR remote (cf §0.5).

### §0.2 Critère de convergence (DONE = 100% cloud-ready)
- **19/19 P1** clos (16 déjà + M6-002, M3-02, G-H résolus OU explicitement acceptés par owner).
- Triple-vert : PHPUnit (2857+/0 réel, hors 4 sentinelles plan-path cross-worktree connues) · Vitest 1895+/0 · sentinelles.
- **NF525** : `fiscal:verify-chain --all` OK **avant + après** chaque wave fiscale ; identités `Σ total_by_method == total_ttc` et `total_tva == Σ total_by_tax_rate` prouvées.
- **2 cycles consécutifs** P0+P1=0 avec findings identiques (règle test-e2e).
- Frozen-diff = 0 SAUF lignes couvertes par un LOCK contresigné.
- E2E cross-surface (borne→KDS→OSS→caisse) + SYNC live re-prouvé.

### §0.3 Pipeline par tâche
Chaque tâche d'implémentation suit `ultra-audit-profond` (5 spécialistes read-only → implémente → RED → test → visual → dispute). Non redécrit ici.

### §0.4 Frozen §7 + NF525
Liste canonique : `memory/reference_frozen_zones.md`. Tout edit frozen = `lock-plan` skill → LOCK doc → contreseing owner → commit séparé. NF525 invariants : CLAUDE.md §8.

### §0.5 Anti-duplication (contrainte owner explicite)
Un **ultraplan remote** s'exécute en PR sur ces mêmes fichiers frozen. **Ce GOAL = la spec autoritaire (audit + risk + acceptance gates).** La PR remote = **une exécution validée contre ce GOAL avant merge** (la checklist de revue PR §G-REVIEW = les critères de convergence). Pas deux plans concurrents.

---

## §1 — Map principal (6 systèmes) — état CERTIFIÉ

| Système | Maturité | Anchor vérifié | Statut pré-cloud |
|---|---|---|---|
| S1 CAISSE/POS | 95% | `Admin/PosController.php`, `public/js/pos-*.js`, `PosComponent.vue`, 13 fonctionnalités sondées | ✅ sauf M3-02 (frites) + G-H (feature) |
| S2 BORNE/kiosk | 100% | `FrontendOrderService.php`, `KioskWizardComponent.vue`, 13 fonctionnalités | ✅ certifié (0 défaut) |
| S3 KDS | 100% | `KitchenDisplaySystemOrderService.php`, 11 fonctionnalités | ✅ certifié (1 P3 opt) |
| S4 OSS | 100% | `OrderStatusScreenController.php:88`, 6 fonctionnalités | ✅ certifié (1 P2 multi-branche) |
| S5 CENTRAL | 100% | dashboard/historique/gestion, `LicenseController.php:18`, 8 fonctionnalités | ✅ certifié (1 P3) |
| S6 SYNC | 100% | `routes/channels.php`, outbox `DispatchDomainEventsJob`, 12 fonctionnalités | ✅ certifié + live-prouvé |
| S7 FISCAL/NF525 | 90% | `ZReportService.php` (787 l.), chaîne HMAC | ⚠️ M6-002 (frozen) + S13-02 (non-frozen) |

**Preuve "systèmes conformes" (NE PAS re-run)** : sweep adversarial décomposé `wf_91714cef-e4d` — **63 fonctionnalités / 6 systèmes / 0 nouveau P0/P1 non-frozen** (`TOTAL-VALIDATION-SWEEP.md`). PHPUnit 2857/0 réel, Vitest 1895/0, NF525 chain OK, SYNC live (`SUPERVISOR-REPORT-FINAL.md`).

---

## §2 — Systèmes séparés (hors scope cloud-cutover central)
`mobile/` (RN standalone) + `/Users/1millnonstop/Downloads/web` (web standalone) : **NO API wireup V1** (mandat owner). Hors périmètre de ce GOAL (le cutover concerne le backend testttt). Réf `CONSTITUTION.md`.

---

## §3 — Système 1 : CAISSE/POS
### Contract
Caisse fast-food : commande (wizard) + paiement/encaissement + tiroir + reçu NF525.
### Frozen zones (re-list DRY) : `public/js/pos-wizard.js`, `PaymentComponent.vue`, `admin-pos-v4.blade.php`.

### Sub 3.1 — M3-02 : sous-facturation frites (POS-only) — **P1, MEDIUM**
**Anchors** : `pos-wizard.js:4153` (item_extras vide), `:4159` (menu_extras texte), `:90-91`/`:1325-1326` (prix client). Kiosk OK (`KioskWizardComponent.vue` fritesStyleExtraId + items #402/#403). Détail : `FROZEN_RISK_AUDIT.md §M3-02`.
**Découverte clé** : POS replie **2** upgrades (Grande=taille + Cheddar=topping) dans un seul groupe `frites_style max_select=1` → seed ItemExtra récupère Cheddar mais **pas Grande** (taille à modéliser).
**Tasks** :
- T-3.1.1 Décision de modélisation Grande : (a) item-variant taille comme kiosk #402/#403 ; (b) variation prix ; (c) extra groupe séparé. → choisir avec owner.
- T-3.1.2 Seed `ItemExtra` `frites_style` (Cheddar 1,00 / Cheddar+Oignons 2,00) sur l'item addon (NON-frozen seeder, idempotent ; miroir `MenuResetLeCayenneCommand`).
- T-3.1.3 Émettre la sélection en `item_extras{id}` structuré (frozen `pos-wizard.js:4153` → **LOCK** ; OU translate non-frozen `PosController::normalizePosRuntimePayload:217-241`).
- T-3.1.4 Garde anti-double-charge : `menu_extras` reste display-only.
**Acceptance** :
- `FritesWizardComposerTest.php:211-228` (existant) reste vert.
- `tests/Feature/Pos/FritesWizardComposerTest.php::test_frites_addon_with_grande_and_cheddar_upgrades` (**TO CREATE**) → POST `/api/admin/pos` frites+Grande+Cheddar → `grand_total` inclut **+2,00 €**.
- E2E live : 1 commande frites+Grande+Cheddar → reçu + Z reflètent +2,00 €.
- Kiosk #361/#402/#403 pricing inchangé (régression).

### Sub 3.2 — G-H : encaissement unifié — **P1 (FEATURE, owner objectif #1)**
**Anchors** : `PaymentComponent.vue` 66KB FROZEN · `PosCounterCollectModal.vue` 29KB NON-frozen (sibling, préserve le frozen) · `EncaissementComponent.vue` (admin/encaissement) · `PaymentService::confirmCounterPayment:193`. Détail : `FROZEN_RISK_AUDIT.md §G-H`.
**Tasks** (selon path choisi au gate G3) :
- Path B (reco, non-frozen) : T-3.2.B1 router caisse + borne-collect via `PosCounterCollectModal`/`EncaissementComponent` unifié ; T-3.2.B2 modes Espèces/TR/Terminal-manuel(+réf) ; T-3.2.B3 chaque méthode → 1 `OrderPayment` (card/TR ref-only, no CashMovement) ; T-3.2.B4 préserver split-payment.
- Path A (frozen) : idem mais dans `PaymentComponent.vue` → **LOCK**.
**Acceptance** :
- `tests/Feature/Pos/PosCashTrailTest.php` (existant) vert.
- `SplitPaymentEndToEndTest.php` (existant) vert.
- TO CREATE : `tests/Feature/Pos/UnifiedEncaissementTest.php` (1 surface, 3 modes, OrderPayment correct par mode, card/TR sans CashMovement).
- Visual : surface unifiée (`:8765 /admin/pos`) — capturée + analysée, 0 raw label.

---

## §4 — Système FISCAL/NF525
### Contract
Z-reports signés HMAC, séquence gap-free, chaîne append-only 6 ans.
### Frozen : `ZReportService.php`, `FiscalSequenceService.php`, `AuditLogService.php`.

### Sub 4.1 — M6-002 : bucketing Z split-payment — **P1, MEDIUM, FROZEN LOCK**
**Anchor** : `ZReportService::applyOrderToTotals:661-668` (total complet sous 1 `pos_payment_method`). Détail + résolution adversariale (verifyChain lit le champ STOCKÉ → fix **forward-only**, historique immuable) : `FROZEN_RISK_AUDIT.md §M6-002`.
**Tasks** :
- T-4.1.1 `lock-plan` LOCK_ZREPORT_SPLIT_BUCKETING (frozen) → contreseing owner (gate G1).
- T-4.1.2 Garde forward-only : si `order->payments` non-vide → ventiler le total par mode ; sinon → chemin `pos_payment_method` **byte-identique**.
- T-4.1.3 Attestation `fiscal:verify-chain --all` avant + après ; quantifier les Z historiques split (`SELECT count(*)…`).
**Acceptance (MUST-NOT-BREAK, cf risk audit)** :
- `tests/Feature/Fiscal/ZReportSplitPaymentBucketingTest.php` (**TO CREATE**) — split 30€cash+20€card → buckets 30/20 (pas 50) ; legacy single-tender fallback byte-identique ; split+remise (ratio).
- `ZReportDiscountNettingTest.php` (existant, étendre split) · `RefundMirrorSplitPaymentTest.php` (existant) · `FiscalSealingHmacTest.php` (existant — déterminisme sig).
- `verifyChain` historique vert AVANT == APRÈS (immuabilité prouvée).
- Identités `Σ total_by_method == total_ttc` + `total_tva == Σ total_by_tax_rate` tenues.

### Sub 4.2 — S13-02 : TVA par-commande — **P1, MEDIUM — CORRIGÉ : le fix propre est FROZEN**
**Anchor (corrigé exec-audit 2026-06-05)** : la racine est `PricingService::calculateOrder` `$totalTax` pré-remise (`:317/323`), retourné non-netté dans `PricingResult` (`:364`) → TOUS les chemins (SSOT `OrderService:1043/1578` + legacy `:562/1048/1583`) stockent un `total_tax` pré-remise. `PricingService.php` est **FROZEN §7**. TTC mode confirmé → netter est sûr (total indépendant de total_tax). Détail : `FROZEN_RISK_AUDIT.md §S13-02 CORRECTED`.
**Options (gate G4)** : (1) netter dans `PricingService` (propre, **FROZEN → LOCK_PRICINGSERVICE_TVA_NETTING**) ; (2) override `total_tax` aux 5 sites `OrderService` (non-frozen mais re-dérive ce que le SSOT possède → risque de divergence vs Z) ; (3) documenter l'asymétrie (Z déjà correct).
**Acceptance** : reçu commande remisée TVA == Z TVA ; `OrderTotalHtDecompositionTest` + `PosReceiptTaxLinesTest` + `ZReportDiscountNettingTest` verts (identité TTC=HT+TVA).

---

## §5 — Systèmes déjà verts (confirmation) + backlog non-bloquant
S2/S3/S4/S5/S6 = certifiés (sweep 63-fonctionnalités, 0 défaut). **Confirmation, pas de re-run.**
**Backlog non-P1 (loggé, hors gate cloud)** : OSS zéro-branche (P2, multi-branche `OrderStatusScreenController.php:88`) · KDS release-guard (P3 `KitchenDisplaySystemOrderService.php:386`) · License index gate (P3 `LicenseController.php:18`). À traiter post-cutover ou en V1.0.X.

---

## §A — Agent Army Map + Fan-Out Matrix
| Rôle | Subagent | Tools | Template |
|---|---|---|---|
| Architect | Plan/general | RO | superpower-gstack/agents/architect-prompt.md |
| Security | general | RO | qa-red-team-prompt.md (SECURITY) |
| DBA | general | RO | inline (schema/FK/BranchScope/order_payments) |
| Fiscal | general | RO | inline (NF525 chain/HMAC/ksort/F1) — **wave fiscale** |
| Implementer | general | Edit+Bash | implementer-prompt.md (TDD-first) |
| RED-team | general | RO | qa-red-team-prompt.md (RED) |
| QA Visual | general | RO+Playwright | inline (capture+analyse) |
| RED Visual | general | RO | inline (re-dispute screenshots) |

**Fan-out par type** : Fiscal NF525 (M6-002) = Architect+Security+DBA+Fiscal+Implementer+RED ; Frontend (M3-02/G-H) = Architect+Security+UX+Implementer+RED+QA Vis+RED Vis ; les 5 RO en **1 seul message** (parallèle). Implementer **jamais** parallèle. RED **après** commit, avant DONE. Rapports persistés disque (`reports/test-e2e/pre-cloud/goal-exec/<wave>-<role>.json`).

---

## §X — Waves de convergence

| Wave | Scope | Parallélisme | Dépend gate | Checkpoint |
|---|---|---|---|---|
| **W0 Pre-flight** | backup branch + DB dump ; baselines PHPUnit/Vitest count + `audit_logs` count+last_hash ; `verify-chain --all` baseline | séquentiel | — | baselines capturés |
| **W1 S13-02** (non-frozen) | net per-order TVA OU doc-accept | séquentiel | — | reçu↔Z TVA OK ; PHPUnit vert |
| **W2 M3-02** (frozen pos-wizard) | seed + structured extras + size-modeling | séquentiel (audit RO //) | **G2** LOCK pos-wizard | grand_total +2,00 ; kiosk régression OK ; visual |
| **W3 M6-002** (frozen ZReport) | forward-only per-tranche bucketing | séquentiel (audit RO //) | **G1** LOCK ZReport | split buckets OK ; chain AVANT==APRÈS ; identités tenues |
| **W4 G-H** (path owner) | encaissement unifié | séquentiel | **G3** path A/B | 1 surface 3 modes ; OrderPayment/mode ; visual |
| **W5 Convergence finale** | smoke complet + E2E cross-surface + SYNC live + frozen-diff (LOCK-only) + chain attestation | RO fan-out | toutes | 2 cycles P0+P1=0 identiques |
| **W6 Cutover sign-off** | tag `v1.0.X-production-ready` + owner sign-off + (push gated) | séquentiel | owner | §10 human gate |

**Checkpoint (6 points, fin de wave)** : tasks PASS/doc · frozen-diff = LOCK-only · NF525 chain (si fiscale) · visual gate (si frontend) · RED dispute 0 P0 NEW · BRAIN §2/§3. Si un "no" → wave non close.
**Interrupt-resume** : commit WIP `wip(<wave>):…` + manifest `reports/test-e2e/pre-cloud/goal-exec/INTERRUPT_<wave>.md` + BRAIN §2. Reprise : read manifest → smoke last task → continue.
**Convergence-failure (3 loops)** : STOP → Plan subagent root-cause → `STUCK_<wave>.md` → owner choice (accept-doc / pivot / defer / human).
**Parallélisme** : W1 (non-frozen) peut tourner pendant l'attente des gates G1/G2/G3. W2/W3/W4 séquentiels (frozen, risque d'état partagé).

---

## §G — Owner Gates (WHO / WHAT / WHERE)

| Gate | Description | WHO | WHAT (artefact débloquant) | WHERE (preuve) | Status |
|---|---|---|---|---|---|
| **G1** | LOCK `ZReportService` (M6-002 forward-only bucketing) | Owner physique | Contreseing `LOCK_ZREPORT_SPLIT_BUCKETING.md §10` | LOCK doc + commit tag | **PENDING** |
| **G2** | LOCK `pos-wizard.js` (M3-02) **+ décision modélisation Grande** (variant taille vs extra) | Owner physique | Contreseing LOCK + choix taille | `LOCK_POS_WIZARD_FRITES.md §10` | **PENDING** |
| **G3** | G-H **path A (fusion frozen, LOCK) vs path B (sibling non-frozen, reco)** | Owner physique | Choix A/B (+ LOCK si A) | BRAIN §2 + (LOCK si A) | **PENDING** |
| **G4** | S13-02 : netter vs documenter-accepter | Owner (sign-off léger) | Décision | BRAIN §2 | PENDING |
| **G5** | Push / merge PR remote + cutover | Owner physique | Sign-off §10 + review PR vs ce GOAL | commit/PR + deploy log | PENDING |

**Owner-gate-waiting** : pendant G1/G2/G3 PENDING, j'exécute W0 + W1 (non-frozen) ; W2/W3/W4 bloquées. BRAIN §2 liste bloqué vs runnable.

### §G-REVIEW — Checklist de revue de la PR remote (= critères de merge, anti-duplication)
- [ ] LOCK doc présent + contresigné par fichier frozen modifié.
- [ ] `verify-chain --all` AVANT+APRÈS dans la PR (M6-002).
- [ ] M6-002 = forward-only (legacy byte-identique ; historique immuable).
- [ ] M3-02 = preuve grand_total +2,00 (Grande **et** Cheddar) ; kiosk inchangé.
- [ ] Frozen-diff = lignes lockées uniquement.
- [ ] Triple-vert + 2 cycles convergence.
> Si la PR diverge de ce GOAL → la PR s'aligne sur le GOAL (spec autoritaire), pas l'inverse.

---

## §RISK — Registre de risque (synthèse ; détail `FROZEN_RISK_AUDIT.md`)
| Item | Risk | Touche | Blast radius | Rollback |
|---|---|---|---|---|
| M6-002 | **MEDIUM** | `ZReportService:661-668` (frozen) | Z futurs uniquement (forward-only ; historique immuable car verifyChain lit le stocké) | revert patch ; Z historiques intacts |
| S13-02 | MEDIUM | `OrderService:551` (non-frozen) + Resource | reçu/affichage TVA | revert ; Z déjà correct |
| M3-02 | MEDIUM | `pos-wizard.js` (frozen) + seeder | POS only (kiosk OK) ; hazard double-charge | revert + reseed |
| G-H | LOW-MED | path B non-frozen / path A frozen | surface encaissement | revert composant |
**Risque transverse** : duplication PR remote → maîtrisé par §0.5 + §G-REVIEW.

---

## §R — Références
`FROZEN_RISK_AUDIT.md` · `TOTAL-VALIDATION-SWEEP.md` · `SUPERVISOR-REPORT-FINAL.md` · `VALIDATION-MATRIX-6x6.md` · `GATE-G-LOCK-REQUEST.md` · CLAUDE.md §7/§8 · `memory/reference_frozen_zones.md` · skills `ultra-audit-profond`, `lock-plan`, `superpower-gstack`, `test-e2e`.

---

## §F — Règle finale (DONE)
100% cloud-ready = **19/19** P1 résolus-ou-accepté-owner · triple-vert + 2 cycles convergence · NF525 chain attestée avant/après · frozen-diff = LOCK-only · E2E cross-surface + SYNC live · tag `v1.0.X-production-ready` · owner sign-off §10. **Production-perfect, pas "presque".** Aucun edit frozen sans LOCK contresigné. Aucun push sans owner. Le GOAL prime sur la PR remote en cas de divergence.
