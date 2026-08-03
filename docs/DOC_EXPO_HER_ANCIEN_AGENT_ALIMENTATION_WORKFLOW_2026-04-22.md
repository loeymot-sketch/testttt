# FoodKing — Documentation unique : nouvelle session / agent (paths + rôles)

> **Fichier SSOT** (une seule lecture d’amorce pour reprendre le travail, auditer, orchestrer, finir borne + sync).  
> Tous les chemins sont **relatifs à la racine du dépôt** (dossier `testttt` ou nom du clone).

| Meta | Valeur |
|------|--------|
| **Dernière mise à jour** | 2026-04-25 |
| **Branche de référence** | `feat/ton-sujet` |
| **Commits** | `0c31d771a` (audit W9 + Graphiti + gouvernance) · `2ee54d83a` (cette doc passation + lien Primer) |
| **Suite dans le dépôt** : si d’autres sections ont été ajoutées (`SESSION_OPENING_ENFORCEMENT`, etc.), elles **complètent** ce document ; ce fichier reste l’**index** des chemins. |

---

## 1. Par où commencer (3 fichiers, toujours)

Lire **dans cet ordre** avant toute tâche non triviale :

1. `docs/orchestration/GLOBAL_SYSTEM_PRIMER.md` — orchestration, sub-agents, Graphiti, tokens, parfois d’autres rappels (p. ex. `docs/orchestration/SESSION_OPENING_ENFORCEMENT.md` s’il existe = discipline d’ouverture de session).
2. `AGENTS.md` — contrat, MCP, `run-cycle`, invariants, parcours.
3. `.cursor/ACTIVE_CYCLE.md` — reprise d’un cycle en cours ; si vide / CLOSED, revenir ici + choisir une tâche `tasks/`.

**Chemin d’onboarding** (détaillé dans le Primer) : le tableau *Ordre de lecture* dans `docs/orchestration/GLOBAL_SYSTEM_PRIMER.md` prime si une ligne **contradit** un résumé ci-dessus.

---

## 2. Table : utilité de chaque fichier (nouvelle conversation agent)

Colonne **Path** = emplacement **depuis la racine du repo**.

| # | Path | Utilité pour l’agent |
|---|------|------------------------|
| **A. Entrée & contrat** | | |
| 1 | `AGENTS.md` | Contrat global : phases, routing, MCP Graphiti, terminal `claude` / `codex`, non-négociables FoodKing. |
| 2 | `docs/orchestration/GLOBAL_SYSTEM_PRIMER.md` | Manuel d’orchestration : sub-agents Task, MàJ mémoire, `## PRIOR_CONTEXT`, politique tokens *quality-first*. |
| 3 | `docs/DOC_EXPO_HER_ANCIEN_AGENT_ALIMENTATION_WORKFLOW_2026-04-22.md` | **Ce document** : index des paths + passation. |
| **B. Cycle & rôles** | | |
| 4 | `.cursor/routing.md` | `PRIMARY_MODEL` : qui planifie, qui code complexe, qui routine. |
| 5 | `.cursor/commands/run-cycle.md` | Suite **PLAN → EXECUTE → VALIDATE → AUDIT** + Graphiti (Step 0, item 5). |
| 6 | `.cursor/ACTIVE_CYCLE.md` | État du cycle (`TASK_ID`, `PHASE`, prochains steps). |
| 7 | `docs/orchestration/AGENT_ROLES.md` | Roster court (Planner, Implementer, etc.). |
| 8 | `plans/PLAN_TEMPLATE.md` | Gabarit d’un plan ; utiliser `plans/PLAN_[TASK_ID]_[DATE].md` en pratique. |
| 9 | `plans/PLAN_EXECUTION_CLOSEOUT_GRAPHITI_CI_PROD_2026-04-22.md` | Plan d’exécution : mémoire Neo4j complète, smoke, CI, prod. |
| **C. Règles always-on (Cursor)** | | |
| 10 | `.cursor/rules/global.mdc` | Gates, Graphiti, tokens sans réduction d’intelligence. |
| 11 | `.cursor/rules/graphiti-memory.mdc` | Lire/écrire Graphiti, secours `memory/INDEX` si pas de MCP. |
| 12 | `.cursor/rules/context-hygiene.mdc` | Phases : quels fichiers charger ; résumés = handoff uniquement. |
| 13 | `.cursor/rules/human-gates.mdc` | Quand s’arrêter ; format gate. |
| 14 | `.cursor/context/plan-context.md` | `## PRIOR_CONTEXT` ; requêtes Graphiti en phase plan. |
| 15 | `.cursor/context/audit-context.md` | Checklist audit ; `add_memory` / Graphiti en fin de cycle CLOSED. |
| **D. Prod / infra (qualité)** | | |
| 16 | `app/Console/Commands/PreflightProductionCommand.php` | Commande `php artisan app:preflight-production` — gate déploiement. |
| 17 | `.github/workflows/phpunit.yml` | CI MySQL + *Migration drift* avant PHPUnit. |
| 18 | `.env.example` | Rappel variables (TIMEZONE, cache, queue, secrets fiscal, etc.). |
| **E. Mémoire Graphiti (fichier + Neo4j)** | | |
| 19 | `memory/INDEX.md` | Table des 14 domaines → fichiers JSONL. |
| 20 | `memory/README.md` | Ingestion, `verify.py`, mise à jour **continue** obligatoire quand le métier change. |
| 21 | `memory/episodes/01_project_overview.jsonl` → `14_conventions.jsonl` | **SSOT** sémantique (180 épisodes) ; éditer puis ingérer. |
| 22 | `memory/ingest.py` | Client MCP → `add_memory` en lot. |
| 23 | `memory/verify.py` | Fumée : `get_episodes` + `search_memory_facts`. |
| 24 | `memory/ingest.env.example` | Copie locale `memory/ingest.env` (gitignored) pour override LLM. |
| 25 | `bin/graphiti-ingest.sh` | Wrapper shell → `ingest.py`. |
| 26 | `.cursor/mcp/graphiti.json.example` | Modèle de bloc `graphiti` pour `~/.cursor/mcp.json`. |
| 27 | `.cursor/mcp/mcp-graphiti.env.example` | Variables Neo4j / clés (copier vers `~/.cursor/mcp-graphiti.env`, chmod 600). |
| 28 | `.cursor/mcp/start-graphiti-mcp.sh` | Démarre LiteLLM + serveur Graphiti. |
| 29 | `.cursor/mcp/GRAPHITI_TROUBLESHOOTING.md` | Dépannage proxy / embeddings. |
| **F. Décisions & audite** | | |
| 30 | `docs/gates/GATE_W9_AUDIT_KIOSK_RECEIPT_NON_FISCAL_2026-04-21.md` | Décision : ticket Kiosk **non** NF525. |
| 31 | `reports/audit/AUDIT_W1_W9_GLOBAL_2026-04-21.md` | Audit 9 vagues, fixes Stage 1. |
| 32 | `reports/audit/AUDIT_W1_W9_PROD_READY_2026-04-21.md` | PROD-1..4 (lock archive, idempotency, CI, preflight). |
| 33 | `reports/audit/AUDIT_FINAL_GO_PROD_W1_W9_2026-04-21.md` | Go prod + plan phasé J-7…J+7 + sync 3 surfaces. |
| 34 | `reports/audit/AUDIT_GRAPHITI_SYSTEM_DEEP_2026-04-22.md` | Profondeur Graphiti, écart 124/180, pipeline. |
| 35 | `reports/audit/AUDIT_INTEGRATION_FLOW_COMPLETE_2026-04-22.md` | Intégration run-cycle + Graphiti + subagents. |
| 36 | `reports/audit/AUDIT_SECOND_PASS_GLOBAL_GOVERNANCE_REPORT_2026-04-22.md` | 2e passe, Primer, règles tokens. |
| **G. Domaine métier (finition borne + sync) — lire ciblé** | | |
| 37 | `docs/ORDER_FLOW.md` | Cycle de vie commande. |
| 38 | `docs/DEVICE_FLOW.md` | Parcours par appareil (Kiosk, POS, KDS). |
| 39 | `app/Services/FrontendOrderService.php` | Création commande borne. |
| 40 | `app/Services/OrderService.php` | Création commande POS + idempotency tenant-scopée. |
| 41 | `app/Jobs/DispatchDomainEventsJob.php` | Broadcast outbox. |
| 42 | `app/Listeners/PersistOrderCreatedToOutbox.php` | Canal `private-branch.{id}` (voir code). |
| 43 | `resources/js/services/eventContract.js` | Client Echo + dédup `correlation_id`. |
| 44 | `tests/Feature/SyncComprehensiveTest.php` | Tests intégration Kiosk/POSE/KDS. |
| 45 | `tests/Feature/Orders/IdempotencyBranchScoped.php` | Clé d’idempotence par branche. |

*Les lignes 37–45* ne s’ouvrent **en entier** qu’en phase **EXECUTE** / audit ciblé — pas dès l’ouverture de session.

---

## 3. Résumé de ce qui a déjà été livré (ligne de temps)

| Sujet | Où c’est documenté en détail |
|-------|------------------------------|
| Corr. W9 + hardening PROD-1..4, tests receipt/Z/idempotency | `reports/audit/AUDIT_W1_W9_*.md`, `AUDIT_FINAL_*.md` |
| Système Graphiti, JSONL 180, scripts | `memory/README.md`, `AUDIT_GRAPHITI_SYSTEM_DEEP_2026-04-22.md` |
| Gouvernance + 2e passe (Primer, tokens) | `AUDIT_SECOND_PASS_GLOBAL_GOVERNANCE_REPORT_2026-04-22.md` |
| Plan closeout (mémoire, CI, prod) | `plans/PLAN_EXECUTION_CLOSEOUT_GRAPHITI_CI_PROD_2026-04-22.md` |
| Règles d’or mémoire vivante | `GLOBAL_SYSTEM_PRIMER.md` §4, `memory/README.md` §*Mise à jour continue* |

**Neo4j** : peut ne pas afficher 180/180 tant que re-ingest + drain long n’est pas allé au bout (voir `memory/verify.py`).

---

## 4. Fichiers souvent *non* versionnés (local, dev)

- `~/.cursor/mcp.json` — enregistrement du serveur MCP Graphiti (le dépôt fournit seulement l’**exemple** : `.cursor/mcp/graphiti.json.example`).
- `~/.cursor/mcp-graphiti.env` — secrets ; modèle : `.cursor/mcp/mcp-graphiti.env.example`.
- `memory/ingest.env` — override optionnel (voir `.gitignore`).

Dossiers optionnels parfois présents **sans** être au cœur du lot audit : `.cursor/skills/`, `.agents/skills/`, `skills-lock.json` — vérifier avant commit si c’est voulu.

---

## 5. Prompt prêt à coller (nouvelle session agent)

```text
Contexte (à lire dans l’ordre) :
1) docs/orchestration/GLOBAL_SYSTEM_PRIMER.md
2) AGENTS.md
3) docs/DOC_EXPO_HER_ANCIEN_AGENT_ALIMENTATION_WORKFLOW_2026-04-22.md (index paths)
4) .cursor/ACTIVE_CYCLE.md
Objectif : [décrire, ex. finition wizard Kiosk, test sync, gate fiscal]
Contraintes : invariants .cursor/rules/foodking-invariants.mdc, symétrie Order/FrontendOrder, branch_id, after-commit.
```

---

## 6. Lexique du titre (historique)

- **Expo Her** : point de reprise / handoff.  
- **Ancien agent** : le **travail et la doc** figés dans le git + mémoire, pas l’exécuteur.  
- **Alimentation** : mise à jour **mémoire** (JSONL + ingest + `add_memory` fin de cycle).  
- **Workflow** : `PLAN → … → AUDIT` + règles + CI quand c’est dû.

---

## 7. Maintenir **ce** document

- Quand un nouveau fichier « SSOT on boarding » apparaît dans `docs/orchestration/`, ajouter **une ligne** en section **2** (Path + utilité) et, si utile, une note en section 1.  
- Quand un gros audit est produit, ajouter une entrée en **3** (résumé) et une ligne en **2 (F.)** pointant `reports/audit/…`.

---

*Fin. Pour une évolution des **règles d’orchestration** : `memory/episodes/13_agents_roles.jsonl` + `bash bin/graphiti-ingest.sh 13_agents` (ou ciblé) + mise à jour du `GLOBAL_SYSTEM_PRIMER`.*
