# Export handoff — POS + KDS — finitions + master plan (nouvelle session / compétition d’agents)

**Date** : 2026-04-26  
**But du document** : transmettre **sans l’historique de chat** tout le nécessaire pour (1) comprendre **ce qui a été planifié audité et pourquoi**, (2) savoir **quels fichiers consulter** (SSOT), (3) lancer l’exécution en **cycles FoodKing** (`run-cycle`) avec le **minimum de contexte** et le **focus maximum** sur le travail utile.  
**Indépendant** de tout “autre plan” product que vous fassiez en parallèle : ici, seulement **la traçabilité** des livrables produits par l’orchestrateur (cursor-claude) + l’avis **Claude terminal** sur l’état de readiness.

---

## 1. Rationale — pourquoi ce plan existe (décision logique)

1. **Objectif métier** : lancer le POS (caisse) et le KDS (cuisine) en **conditions production multi-branches** (opérateur réel), pas un POC. Les risques sont : erreurs de paiement, désynchronisation, régression fiscale/régularité, UX bloquante, i18n partielle, perf non mesurée.
2. **Cadre FoodKing** : les invariants (`.cursor/rules/project-invariants.mdc`) s’imposent — notamment **pricing côté backend (SSOT)**, **OrderStatus** sans entiers magiques, **`branch_id`**, **dispatch après commit**, **symétrie OrderService / FrontendOrderService** si l’un est touché, et **zones frozen** sans brief humain.
3. **Méthode d’audit** : une **master review** a été cadrée par un **brief** (`MASTER_REVIEW_*_BRIEF`) ; le reviewer **Claude en terminal** (`claude -p`, repo `--add-dir`) a produit un **rapport structuré** avec evidence `fichier:ligne`, verdict global, et couverture de buckets (invariants, robustesse, i18n, perf, tests, observabilité, etc.).
4. **Verdict obtenu** : **NOT-READY 4/10** (cf. section 4). Tant qu’il reste des **P0 blockers** (code + **gates humains**), le lancement “large” n’est **pas** recommandé. Le **master plan** découpe donc le travail en **lots** avec dépendances explicites (parallèles dès que possible, chemin critique sur les approbations humaines).
5. **Pourquoi 9 lots** : séparer ce qui est **exécutable immédiatement** (LOT-0, LOT-2 partiel) de ce qui est **bloqué** par `docs/gates/*.md` (LOT-1, certaines parties de LOT-4/5/6/7/8). Évite le scope drift et la violation des invariants.
6. **Contexte d’infrastructure** : la 1ʳᵉ exécution `claude -p` a dû s’exécuter sans **Write** sur le disque (permission Claude Code) ; une 2ᵉ exécution en `--permission-mode acceptEdits` a permis d’**écrire** le rapport complet. La trace d’orchestration reste : brief → génération → (permission) → fichier d’audit final.

---

## 2. Fichiers SSOT — quoi lire d’abord (ordre recommandé)

| # | Fichier | Rôle | Contenu (résumé) |
|---|---------|------|------------------|
| 1 | `docs/orchestration/EXPORT_HANDOFF_POS_KDS_MASTER_FINITIONS_2026-04-26.md` | **Ce document** : manifeste + tâches + compétition d’agents | Vous lisez ici. |
| 2 | `reports/audit/MASTER_REVIEW_POS_KDS_FINITIONS_BRIEF_2026-04-26.md` | Brief cadrage de l’audit (scope, format §3, buckets) | Règles d’examen que le reviewer devait respecter. |
| 3 | `reports/audit/MASTER_REVIEW_POS_KDS_FINITIONS_CLAUDE_2026-04-26.md` | **Rapport d’audit intégral (727 lignes)** | Verdict, **15 findings** avec evidence, §3.3 couverture buckets, §3.4 séquencement, §3.5 conditions minimales. **Source de vérité pour l’analyse de fond.** |
| 4 | `plans/PLAN_MASTER_FINITIONS_POS_KDS_AVANT_LANCEMENT_2026-04-26.md` | **Plan d’exécution par lots** (9 lots) | Tâches par `TASK_ID`, `SUBSYSTEMS_*`, prérequis, calendre indicatif, critères “READY for production”, journal d’avancement. |
| 5 | `docs/gates/GATE_LOG.md` | Registre des **gates** | État PENDING / Approved — bloque toute exécution “frozen” sans humain. |
| 6 | *selon lot* | Briefs ciblés | ex. `docs/gates/GATE_W2_KPI_REVISION_2026-04-26.md`, `docs/gates/GATE_W2_CUTOVER_2026-04-26.md`, `docs/gates/GATE_PAYMENT_PROP_MUTATION_2026-04-26.md`, `docs/gates/GATE_VERIFY_P0_FROZEN_CONSOLIDATED_2026-04-20.md` — cités par les findings. |

**Ne pas coller 727 lignes ici** : le rapport d’audit complet reste dans le fichier 3. Ce handoff en donne la **carte** et les **décisions**.

---

## 3. Manifeste — artefacts préparés pour arriver à ce plan

Ces éléments ont **été créés / utilisés** pour bâtir la review et le master plan (liste exhaustive des fichiers “du paquet”).

| Type | Chemin | Statut |
|------|--------|--------|
| Brief d’audit | `reports/audit/MASTER_REVIEW_POS_KDS_FINITIONS_BRIEF_2026-04-26.md` | **Présent** |
| Rapport d’audit terminal | `reports/audit/MASTER_REVIEW_POS_KDS_FINITIONS_CLAUDE_2026-04-26.md` (727 l.) | **Présent** |
| Plan master lots | `plans/PLAN_MASTER_FINITIONS_POS_KDS_AVANT_LANCEMENT_2026-04-26.md` (≈ 392 l.) | **Présent** |
| Handoff (ce fichier) | `docs/orchestration/EXPORT_HANDOFF_POS_KDS_MASTER_FINITIONS_2026-04-26.md` | **Présent** |
| Règles invariants (réf) | `.cursor/rules/project-invariants.mdc` | **Réf. repo** |
| Gates (réf) | `docs/gates/GATE_LOG.md` + `docs/gates/GATE_*.md` (voir findings) | **Réf. repo** |
| Oui terminal | Sortie succinte `claude` (1ʳᵉ run permission Write bloquée) : résumé table 15 trouvés ; 2ᵉ run `--permission-mode acceptEdits` a écrit le .md | **Hors-dépôt** (transcript te local), **contenu consolidé** dans le rapport §3 |
| Citation transcript parent (si besoin) | *Non requis* pour exécuter le plan : tout est dans les fichiers ci-dessus | — |

---

## 4. Synthèse exécutive (issue du rapport §3.1)

- **Verdict** : **NOT-READY 4/10** (lancement opérateur large non recommandé en l’état).
- **Bloquants P0 (à traiter en tête + gates)** :
  - **FIND-01** : remises POS — `discountReason` **sans** `v-model` dans `PosComponent.vue` (voir rapport pour lignes) → flux remise bloqué si motif requis.
  - **FIND-02** : `GATE_VERIFY_P0_FROZEN_CONSOLIDATED_2026-04-20` **PENDING** sur zones P0 (OrderService, PaymentService, etc.).
  - **FIND-03** : `GATE_PAYMENT_PROP_MUTATION_2026-04-26` **PENDING** — `PaymentComponent.vue` mutations de props (ré-inspection 16+ sites, pas 7 seulement).
- **P1** : kiosk EUR/FR en dur, Bengali KDS 0 clé, focustrap non instancié, couverture tests insuffisante, symétrie services partielle, etc. — **détail chiffré** dans le rapport.
- **P2** : Swiper LTR, purge `sync_metrics`, parked sans `expires_at` (partiel + gate possible), 401 en paiement, tests KDS manquants.
- **P3** : W2-1/2/3, signoff @pricing-allowed-block deadline 2026-05-10.
- **Lecture intégrale** des 15 blocs de findings (format strict) : `reports/audit/MASTER_REVIEW_POS_KDS_FINITIONS_CLAUDE_2026-04-26.md` **§3.2**.

---

## 5. Les 9 lots d’exécution (TASK_ID — ce que l’agent “suivant” fait)

Ces `TASK_ID` et périmètres viennent directement de `plans/PLAN_MASTER_FINITIONS_POS_KDS_AVANT_LANCEMENT_2026-04-26.md` (détails, `SUBSYSTEMS_*`, success criteria sur place).

| Lot | TASK_ID (proposé) | Dépendance principale | Contenu résumé |
|-----|-------------------|------------------------|---------------|
| 0 | `POS_KDS_FINITIONS_LOT0_QUICKWINS_2026-04-26` | Aucun | FIND-01 + FIND-09 |
| 1 | *(humain, pas de TASK_ID dev)* | — | Décider FIND-02, FIND-03 (consigner `GATE_LOG.md`) |
| 2 | `POS_KDS_FINITIONS_LOT2_QUALITY_2026-04-26` | Aucun (attention focustrap Payment = surface limitée) | FIND-04, 05, 06 |
| 3 | `POS_FINITIONS_LOT3_TESTS_FEATURE_2026-04-26` | Après LOT-0 (coherence) | FIND-08 (tests Feature POS) |
| 4 | `POS_FINITIONS_LOT4_SYMETRIE_2026-04-26` | Après FIND-02 (gate) | FIND-07 |
| 5 | `POS_OPS_FINITIONS_LOT5_PERSISTENCE_2026-04-26` | Partiel 5b = gate schéma | FIND-10 + 11 (partie 11 = gate) |
| 6 | `POS_V4_W2_PAYMENT_REFACTOR_2026-04-26` | Après FIND-03 (gate) | FIND-03 exécution + FIND-12 401 |
| 7 | `KDS_FINITIONS_LOT7_TESTS_FEATURE_2026-04-26` | Après FIND-02 (gate) | FIND-13 |
| 8 | LCP + signoff (humain + `POS_V4_LCP_INSTRUMENTATION_2026-04-26` si retenu) | Product / TL | FIND-14, 15, HG W2-3 etc. |

**Chemin critique** : **LOT-1** (humain) débloque LOT-4, LOT-6, LOT-7. LOT-0 et LOT-2/3/5a peuvent avancer en parallèle si l’équipe le permet.

---

## 6. Checklist de démarrage — nouvelle conversation / nouvel agent (5 minutes)

1. Lire `AGENTS.md` § *Parcours obligatoire* (P0) + `docs/orchestration/GLOBAL_SYSTEM_PRIMER.md` (table).
2. Lire `.cursor/ACTIVE_CYCLE.md` (éviter un 2ᵉ cycle fantôme) ; si un autre `TASK_ID` y est en cours, **reprendre** ou le **clôturer** selon procédure.
3. `npm run verify:boucle` (sanity).
4. `bash scripts/agent-activity-log.sh tail 50` (réservations cross-agent).
5. Ouvrir **ce** handoff + le **plan** `plans/PLAN_MASTER_FINITIONS_...` + le **rapport d’audit** (§3) pour toute décision technique.
6. Lancer un cycle : `run-cycle <TASK_ID>` (ex. `run-cycle POS_KDS_FINITIONS_LOT0_QUICKWINS_2026-04-26` une fois le fichier `tasks/<TASK_ID>.md` créé si requis par votre `run-cycle` actuel) — *adapter au repo si la convention exige d’abord le fichier tâche*.

---

## 7. Comparer deux plans / deux agents (fusion par un orchestrateur humain)

Lorsqu’un second agent produit un **autre** rapport (parallèle “compétition”) :

1. **Aligner** sur le même cadre d’invariants (`.cursor/rules/project-invariants.mdc`) et la même preuve (tests, gates).
2. **Dédoublonner** : deux trouvails sur le même `fichier:ligne` → conserver l’**evidence la plus forte** ; si conflit, re-vérifier dans le code.
3. **P0** : union des bloquants (code + gates) ; pas de lancement large tant qu’une union P0 subsiste.
4. **Divergence de priorité** : l’orchestrateur tranche (business risk > perf cosmétique ; frozen > polish).
5. **Traces** : consigner la synthèse dans un fichier unique `reports/audit/MERGE_REVIEW_POS_KDS_YYYY-MM-DD.md` (à créer lors du merge) avec tableau `finding → keep agent A / B / merge / drop` + `raison`.
6. **Graphe de dépendances** : le master plan par lots (section 5) sert de **pivot** : tout nouveau finding doit se mapper sur un `LOT-#` existant ou ouvrir un **nouveau** lot (avec gate si besoin).

---

## 8. Ce document ne remplace pas

- Les **fichiers source** (Vue, PHP) — l’audit pointe `ligne:colonne` dans le rapport .
- L’**approbation humaine** des gates (`docs/gates/`).
- La **validation** (vitest, phpunit) ni l’**AUDIT** de fin de cycle.
- L’histoire de chat (non SSOT) — seulement ce export + le dépôt.

---

## 9. Référence rapide — fichiers clés par finding (pointers, pas relecture intégrale)

Les chemins et numéros de ligne exacts sont dans le rapport d’audit §3.2. Index rapide :

- **FIND-01, FIND-15** → `PosComponent.vue`
- **FIND-02** → `docs/gates/GATE_VERIFY_P0_FROZEN_CONSOLIDATED_2026-04-20.md`, `GATE_LOG.md`
- **FIND-03, FIND-12** → `PaymentComponent.vue` (+ gate)
- **FIND-04** → `resources/js/helpers/kioskFormatPrice.js`
- **FIND-05** → `resources/js/languages/bn.json` + ref EN/FR/AR
- **FIND-06** → modals POS, import focustrap `PosComponent.vue`
- **FIND-07** → `OrderService.php` / `FrontendOrderService.php`
- **FIND-08** → `tests/Feature/Pos/`
- **FIND-09** → `KitchenDisplaySystemComponent.vue` (Swiper)
- **FIND-10** → migration `sync_metrics` + `SyncMetricsRecorder`
- **FIND-11** → `pos_parked_orders` migration + service purge
- **FIND-13** → `tests/Feature/KDS/`
- **FIND-14** → `GATE_LOG.md` (HG-W2-1/2/3) + LCP
- **FIND-15** → `PosComponent.vue` bloc `@pricing-allowed-block` + `BACKLOG_POS_V4_*.md` si suivi

---

*Fin du document d’export — toute tâche nouvelle session doit citer : brief → rapport §3.2 → plan lots → `run-cycle`.*
