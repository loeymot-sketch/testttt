# MÉGA-PLAN — Orchestration FoodKing (Sync & surfaces critiques)

**Date** : 2026-04-24  
**Statut** : `APPROVED_WITH_CHANGES` (revue Claude Passe B : `reports/audit/CLAUDE_REVIEW_MEGA_PLAN_ORCHESTRATION_2026-04-24.md` — intégrée ci-dessous)  
**Sources fusionnées** :  
- `plans/MEGA_PLAN_SYNC_HARDENING_v3_2026-04-23.md` (SSOT technique lots + Vagues)  
- `plans/PLAN_POS_KIOSK_KDS_SYNC_REPAIR_2026-04-23.md` (phases 0–3, findings)  
- `reports/audit/AUDIT_MASSIF_POS_KIOSK_KDS_SYNC_2026-04-23.md` (P0/P1)  
- Passe 1 terminal : `reports/execution/CLAUDE_ORCH_MEGA_PLAN_PASS1_2026-04-24.md`  

**Traçabilité v3** : MEGA v3 (2026-04-23) indiquait `GPT-5.4-pro` comme implémenteur via proxy — **mise à jour** : l’orchestrateur utilise désormais **`gpt-5.5-high`** (AGENTS.md / routing) + missions **atomiques** ; c’est un amendement explicite, pas une incohérence.  

**Amendement vs v3 §4.3 (regroupement missions)** : v3 proposait `T-LOT-2-PAYMENT-RECOVERY` (2.A+2.B+2.D) et `T-LOT-2-KDS-POS-PERSISTENCE` (2.F+2.G+2.H) — **abandonné ici** à cause du **plafond ~8–10k tokens sortie** sur le proxy (troncature JSON). Remplacement : `T-LOT-2A-*`, `T-LOT-2B-*`, etc. (une mission = un objectif).  

**Amendement vs v3 (audits fin Phase 2)** : v3 = « un seul » audit Claude en fin de batch — **ce plan ajoute** un audit Claude **après chaque lot** (rituel §5) *en plus* de l’audit transversal P5. Escalade de rigueur volontaire.  

---

## Açık — Ouverture (pourquoi ce plan existe)

L’orchestration FoodKing n’est **pas** une course à la vitesse : c’est un **pipeline reproductible** qui maximise l’intelligence **sans** brûler le budget inutilement. Avec l’**EXÉCUTE** via le **CLI** `codex` (hors *runner* HTTP, retiré du dépôt), le fournisseur peut en pratique plafonner de longues générations côté routeur ; d’où la règle : **une mission = un objectif ciblé** (1 fichier, ou 1 paire `service + test`) plutôt qu’un mega-prompt multi-écrans.

Ce document remplace l’idée d’« un seul gros appel GPT » par **10 étapes (P1..P10)** exécutées sur plusieurs heures ou jours, avec **audit Claude Code en terminal (coût abonnement, pas quota proxy)** à chaque jalons, et **GPT-5.5** uniquement pour les **patchs atomiques** documentés.

**Principes non négociables** (AGENTS.md + `project-invariants.mdc`) :

- Pricing = backend SSOT.  
- `OrderStatus` = enum / pas de strings magiques (refacto D-04/05 = gate).  
- `branch_id` = isolation stricte.  
- Événements / jobs = **after commit** (invariant 4/6 + KI V8/V9).  
- Symétrie `OrderService` / `FrontendOrderService` si l’un des deux est touché.  
- Chaque lot : `check-invariants.sh` + tests + entrée `memory/episodes/12_decisions_log.jsonl`.

---

## 1. Rôles d’exécution (qui fait quoi)

| Rôle | Outil | Rôle concret |
|------|--------|----------------|
| **Orchestrateur** | Session Cursor (ce fil) | Découpage missions, application patches, tests, rapports, mémoire JSONL. |
| **Plan / audit indépendant** | `bash scripts/foodking-claude-orchestrate.sh` (`context`, `audit`, `audit-brief`) | Lecture repo complète, pas de troncature proxy ; verdicts structurés. |
| **Implémentation ciblée** | `npm run codex:complex -- <TASK_ID>` + `missions/<TASK>/input.json` | JSON court & **1 objectif** ; `output_codex.json` vérifié intégrité JSON avant application. |
| **Fallback** | `foodking-complex-implementer` (Cursor) | Si proxy HS ≥3x, JSON vide, ou mission **cross-fichiers** > risque troncature. Tracer `EXECUTE_DELEGATION: foodking-complex-implementer` + `FALLBACK_REASON`. |

**Règle budget token (entrée + sortie)** : viser **< 4k tokens** de prompt mission (instruction + 1 extrait ciblé) + **< 8k** sortie attendue ; si le sujet est plus gros → **découper** (P1a, P1b) plutôt qu’augmenter la plafond (impossible côté fournisseur).

**Environnement recommandé** (`.env.codex` — ne pas commiter de secrets) :

```bash
CODEX_LOG_USAGE=1
CODEX_DISABLE_STREAM=1
CODEX_MODEL_COMPLEX=gpt-5.5-high
# Optionnel : plafond explicite restant < cap proxy
CODEX_MAX_COMPLETION_TOKENS=8000
```

`CODEX_DISABLE_STREAM=1` : permet d’obtenir le bloc `usage` fiable (completion_tokens) pour détecter les troncatures.

---

## 2. État d’avancement (au 2026-04-24)

| Zone | Statut | Notes |
|------|--------|--------|
| Vague 1 (1.A–1.G) | Fait | F-01, F-02, F-04bis, F-12, F-21, etc. |
| 1.C F-03 | Fait | KDS adaptive poll + `KdsSyncService` + tests |
| NEW-01..04 | Fait | Outbox, reconnect storm, queues, observability |
| **2.I (G-4 + G-5)** | **Code livré, clôture lot** = audit double + rapport + JSONL | Tests PHPUnit + Vitest verts ; manquent audits formels + entrée mémoire finalisée |
| 2.A..2.J (Phase 2 P1) | **Non terminé** | Voir section 3 |
| D-01..D-09 (Phase 3) | **Non terminé** | D-04/D-05 = **gate humain** obligatoire |

**Baseline tests (à figer dans `reports/execution/baseline_2026-04-24.txt` avant P2)** : ne pas mélanger totaux. Noter **séparément** le résultat de `npx vitest run` (compte Vitest) et `php artisan test` ou `phpunit` (compte PHP). *MEGA v3 citait 869 en contexte de vague historique — non comparable 1:1.* Reconfirmer après chaque lot. **6/6 invariants** : `check-invariants.sh` à chaque clôture.

---

## 3. Carte des lots P1 restants (alignement `MEGA v3` §4.1)

| Lot | Id audit | Sujet | Contrainte |
|-----|----------|--------|------------|
| 2.A | F-05 | Idempotence / retry flux paiement POS | Ne pas dupliquer encaissement |
| 2.B | F-06 | Récupération UI kiosk « bloqué » / hung | |
| 2.C | F-07 | Throttle son KDS | **Owner** : orchestrateur (UI mince) ou mission atomique unique si logique > 1 fichier — *trancher en début de P4* |
| 2.D | F-08 | Re-print reçu après 409 / conflit | |
| 2.E | F-09 | Timeout / UX scanner QR kiosk | |
| 2.F | F-10 | Persistance filtre station KDS (par user) | |
| 2.G | F-11 | TTL / nettoyage parked orders POS | |
| 2.H | F-13 | Course redemption fidélité kiosk | |
| **2.I** | **F-14 + F-15** | **Allergènes badge + split lignes** | **G-4, G-5** |
| 2.J | F-16 | Floorplan : libération table à paiement complété | cohérence `DiningTableService` |

*Ordre d’attaque recommandé après 2.I :* **2.A+2.B+2.D** (triade paiement), puis **2.F+2.G+2.H** (persistance & parked), puis **2.C+2.E+2.J** (perception + floorplan), ou l’ordre du v3 si dépendance découverte en audit.

---

## 4. Les 10 étapes d’exécution (P1..P10)

Chaque étape = **bloc de travail** avec fin claire. Entre les étapes : **audit Claude terminal** + correction findings + re-run tests.

### P1 — Clôture Lot 2.I (sécurité alimentaire)

- **Livrable** : audits (GPT second avis optionnel + **Claude obligatoire**), rapport `reports/audit/AUDIT_LOT_2I_2026-04-24.md`, entrée JSONL, gate findings résolus.  
- **Missions GPT** : déjà exécutées en atomique ; pas de re-run sauf finding.  
- **Vérif** : `phpunit` ciblé + `vitest` `kdsAllergens.spec.js` + invariants.  
- **Test stratégie** (plan) : `local-validation` + **`human-verification`** — validation visuelle KDS (badge + modale, non-bloquant) en complément des tests auto (G-4/G-5 food safety).
- **Smoke** : 1 scénario manuel documenté (ou capture) : commande avec deux lignes même produit, allergènes distincts → **2 lignes** KDS + badge si allergènes.

### P2 — Triade paiement 2.A / 2.B / 2.D

- **Missions** : `T-LOT-2A-*`, `T-LOT-2B-*`, `T-LOT-2D-*` (1 fichier / mission).  
- **Vérif** : tests feature + js existants + nouveaux si plan le prescrit.  
- **Audit** : Claude `audit` ciblé chemins `PosComponent`, `Kiosk*`, reçu (lecture seule **frozen** si applicable).

### P3 — 2.F / 2.G / 2.H (KDS + parked + loyalty)

- Même discipline atomique.  
- **Risque** : `OrderService` vs `FrontendOrderService` — symétrie documentée.

### P4 — 2.C / 2.E / 2.J (son, QR, floorplan paiement)

- **2.J** peut toucher `DiningTableService` : vérifier **dispatch after commit** + `branch_id`.

### P5 — Point d’audit intermédiaire « Phase 2 complète »

- **Claude** : audit transversal `reports/audit/AUDIT_PHASE2_P1_BATCH_2026-04-24.md`.  
- **Pas d’implémentation** : uniquement findings + triage P0.  
- Critère de sortie : 0 P0 ouvert, ou gate documentée ; chaque lot P2–P4 a une entrée `12_decisions_log.jsonl` ; si JSONL modifié : rappel `bin/graphiti-ingest.sh` (poste autorisé) + manifeste `after-execute-memory.sh`.

### P6 — Dette « safe » D-01, D-02, D-07, D-08 (+ D-09 bump Vue — voir P6bis)

- Docs, cleanup = orchestrateur ; **migrations = gate humain** (cf. `human-gates.mdc`).

### P6bis — D-09 (MEGA v3 §5) — bump Vue 3.4 → 3.5

- **Status** : `BLOCKED_UNTIL_HUMAN` / **soft gate** — vérifier breaking changes Options API + suite Vitest complète.  
- Ne pas fusionner sans QA signoff + rapport dans `reports/execution/`.

### P7 — D-03 migration index (si gate vert)

- Une mission GPT = **fichier migration seul** + test perf ou test régression requêtes.

### P8 — D-04 + D-05 (lourd) — **gate obligatoire**

- **Avant toute exécution** : créer le plan borné SSOT `plans/PLAN_D04_D05_ENUM_STATE_MACHINE_<DATE>.md` avec `TASK_ID`, `SUBSYSTEMS_TOUCHED`, `GATE_CONDITIONS`, `INVARIANTS_AT_RISK` (conforme `AGENTS.md` + `run-cycle.md`).  
- Approbation humaine + entrée `docs/gates/GATE_LOG.md` si requis.  
- Découpage exécution : enum seul → machine d’état → appels (par module).

### P9 — D-06 E2E Playwright KDS (si plan l’autorise)

- Playwright = déclaré explicitement dans le plan de cycle (`playwright.mdc`).  
- Sinon : smoke manuel documenté + rapports `reports/execution/`.

### P10 — Audit final + rapport consolidé

- `bash scripts/foodking-claude-orchestrate.sh audit` (prompt global : invariants + zones touchées P1–P9).  
- Rédaction `reports/MEGA_PLAN_v3_ORCHESTRATION_REPORT_2026-04-24.md` (before/after, métriques, findings résiduels P2).

---

## 5. Rituel par lot (checklist copiable)

0. **Mémoire (si Graphiti dispo session)** : `search_memory_facts` ciblant le lot + secours `memory/INDEX.md`.  
0b. `bash .cursor/hooks/safety-check.sh` (pré-EXECUTE).  
1. `bash scripts/agent-activity-log.sh start cursor-composer T-<LOT> execute "<fichiers>" "mega-plan P#"`  
2. `missions/T-<...>/input.json` **court** (objectif unique).  
3. `npm run codex:complex -- T-<...>` — valider `output_codex.json` = JSON **valide** et **complet**.  
3b. Tracer `EXECUTE_DELEGATION: codex-terminal` (et `FALLBACK_*` si repli) dans `reports/post_execute_latest.log` + `REPORT_FILE` du cycle actif.  
4. Appliquer / ajuster, lancer tests + `check-invariants.sh`.  
5. `bash scripts/foodking-claude-orchestrate.sh audit "…lot <id>…"`  
6. Corriger findings, re-test.  
7. `bash scripts/after-execute-memory.sh` si JSONL / épisodes modifiés ; rappel ingest Graphiti.  
8. Append JSONL + `agent-activity-log.sh done`.  

---

## 6. Gestion des erreurs token / troncature

| Symptôme | Action |
|----------|--------|
| `output_codex.json` tronqué (pas de `}` final) | **Ne pas appliquer** ; subdiviser la mission. |
| `completion_tokens` ~ plateau ~700–1200 (mesuré) | Sujet trop verbeux ; modèle s’arrête : réduire la demande. |
| HTTP 429 / 502 / 503 | `codex.runner` retry ; au-delà → fallback implementer. |
| Erreur « exceeds response size limit » côté modèle | Simplifier la sortie attendue (diff, pas fichier entier). |

---

## 7. Double audit du *plan* (avant toute exécution des étapes P2+)

| Passe | Outil | Sortie |
|-------|--------|--------|
| **A** | Claude terminal (pass 1) | `reports/execution/CLAUDE_ORCH_MEGA_PLAN_PASS1_2026-04-24.md` |
| **B** | Claude terminal (pass 2) sur **ce fichier** | `reports/audit/CLAUDE_REVIEW_MEGA_PLAN_ORCHESTRATION_2026-04-24.md` (intégré) |

Résultat Passe B : **APPROVED_WITH_CHANGES** — écarts ci-dessus (traçabilité v3, baseline tests, D-09, rituel, P8 `PLAN_FILE`, human-verification 2.I) **fusionnés** dans ce document. Tout **P0 restant** sur la faisabilité d’une phase → **bloquer** avant exécution.

---

## 8. Risques P0 (sur toute la suite)

1. **Commit avant dispatch** sur tout nouveau listener/job.  
2. **Fuite `branch_id`** sur endpoints admin/observabilité.  
3. **D-04/05** : régression comparaison statut string ↔ enum côté JS/Laravel.  

---

**Fin — MÉGA-PLAN ORCHESTRATION 2026-04-24**  
*Prochaine action orchestrateur : exécuter Passe B (Claude) sur ce fichier, intégrer les corrections, verrouiller `REVIEW → APPROVED`, puis P1 clôture 2.I ou enchaîner P2 selon priorité produit.*
