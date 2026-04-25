# Passation — Expo Her / nouvelle session (`ancien agent` + alimentation mémoire + workflow)

**Date** : 2026-04-22  
**Usage** : lire **ce fichier en premier** quand on ouvre une **nouvelle conversation** Cursor (ou on reprend le travail) pour **auditer**, **orchestrer** et **finition borne + synchronisation**, sans reparcourir tout l’historique de chat.  
**Commit de référence (branche `feat/ton-sujet`)** : `0c31d771a` — *chore(audit): W9 global closeout, Graphiti memory, prod hardening, governance*.

---

## 1. Résumé exécutif de ce qui a été fait (côté code + docs + mémoire)

| Domaine | Livré |
|---------|--------|
| **Audit global W1→W9** | 10 correctifs (timezone Paris, cache prod, i18n DUPLICATA, archive fiscal `endOfDay`, job kiosk, SLO/scheduler, tests audit/Z-chain/Vitest, gate Kiosk non-fiscal) + **4 durcissements PROD-1..4** (lock TOCTOU archive, idempotency `branch_id`, CI drift migrations, `app:preflight-production`) |
| **Borne + sync (vision)** | Documenté : une table `orders`, outbox `domain_events`, broadcast `private-branch.{id}`, EventContract, tests `SyncComprehensiveTest`, etc. (voir rapports d’audit) |
| **Mémoire Graphiti** | 14 × fichiers `memory/episodes/*.jsonl` = **180 épisodes** ; scripts `memory/ingest.py`, `memory/verify.py`, `bin/graphiti-ingest.sh` ; **Neo4j** peut être incomplet (ex. ~124/180) tant que re-ingest + drain n’est pas finalisé |
| **Gouvernance multi-agents** | **`docs/orchestration/GLOBAL_SYSTEM_PRIMER.md`** (ordre de lecture, sub-agents Task, terminal `claude`/`codex`, MàJ Graphiti, politique tokens *quality-first*) + liens dans **`AGENTS.md`** + règles `.cursor/rules/*.mdc` |
| **Workflow cycle** | `.cursor/commands/run-cycle.md` (Graphiti step 0.5), `plan-context` / `audit-context` avec `## PRIOR_CONTEXT` et écriture mémoire post-CLOSED |
| **Rapports d’audit** | Dossier **`reports/audit/`** (plusieurs `AUDIT_*_2026-04-21/22.md`) |

---

## 2. Fichiers « utiles tout de suite » (ordre de lecture recommandé)

| Priorité | Fichier | Rôle |
|----------|---------|------|
| 0 | **`AGENTS.md`** | Contrat global : phases, routing, MCP Graphiti, terminal allies |
| 0 | **`docs/orchestration/GLOBAL_SYSTEM_PRIMER.md`** | Sub-agents, mémoire vivante, tokens, checklists |
| 1 | **`.cursor/routing.md`** | `PRIMARY_MODEL`, qui implémente quoi |
| 1 | **`.cursor/commands/run-cycle.md`** | Déroulé d’un `TASK_ID` (dont Graphiti) |
| 1 | **`.cursor/ACTIVE_CYCLE.md`** | Où en est le cycle (W9 / W10 closeout) |
| 2 | **`docs/ORDER_FLOW.md`** + **`docs/DEVICE_FLOW.md`** | Borne, POS, KDS — flux commande |
| 2 | **`memory/INDEX.md`** | Quel JSONL lire par domaine (kiosk, sync, fiscal) |
| 2 | **`memory/README.md`** | Ingestion, vérification, *mise à jour continue* |
| 3 | **Plans d’exécution** | `plans/PLAN_EXECUTION_CLOSEOUT_GRAPHITI_CI_PROD_2026-04-22.md` (mémoire + commit + CI + prod) |
| 3 | **Gates** | `docs/gates/GATE_W9_AUDIT_KIOSK_RECEIPT_NON_FISCAL_2026-04-21.md` (ticket Kiosk = non fiscale NF525) |
| 4 | **Rapports audit (profondeur)** | `reports/audit/AUDIT_W1_W9_PROD_READY_2026-04-21.md`, `AUDIT_GRAPHITI_SYSTEM_DEEP_2026-04-22.md`, `AUDIT_SECOND_PASS_GLOBAL_GOVERNANCE_REPORT_2026-04-22.md` |

Règles always-on notables : **`graphiti-memory.mdc`**, **`context-hygiene.mdc`**, **`global.mdc`**.

---

## 3. Périmètre « finition borne + synchronisation » (prochaine vague)

- **Borne (kiosk)** : `FrontendOrderService`, `app/Http/Controllers/Frontend/*`, `resources/js` kiosk, magasin cart/offline, idempotency, paiement (carte / espèces) — cohérence avec **`OrderService` / symétrie**.
- **Sync** : `OrderCreated` / `OrderStatusChanged` / outbox, `DispatchDomainEventsJob`, `eventContract.js` (KDS, POS, Echo), tests **Feature** `SyncComprehensive*`, Kiosk flow E2E si besoin.
- **Mémoire** : alimenter **`memory/episodes/06_kiosk_features.jsonl`** + **`03_domain_events_sync.jsonl`** quand une règle métier change ; puis `bash bin/graphiti-ingest.sh` (fichier ciblé) et `python3 memory/verify.py`.
- **Non oublié** : gate Kiosk = **preuve commerciale**, pas ticket NF525 (cf. doc gate W9).

---

## 4. État connu à la clôture de la session (à vérifier en ouverture)

| Point | Statut |
|-------|--------|
| Commit + push | Fait sur `feat/ton-sujet` (`0c31d771a`) |
| Neo4j 180/180 | Possiblement **non** ; plan : `PLAN_EXECUTION_CLOSEOUT_*.md` + re-ingest |
| CI GitHub | À vérifier sur le dernier push (PHPUnit MySQL + drift) |
| Dossiers **non** commités localement (si présents) | `.cursor/skills/`, `.agents/skills/`, `skills-lock.json` — hors lot principal |

---

## 5. Comment lancer la « nouvelle conversation » (prompt type)

Coller ceci en tête d’un nouveau chat (adapter les TASK_ID) :

```text
Contexte : lire d’abord docs/DOC_EXPO_HER_ANCIEN_AGENT_ALIMENTATION_WORKFLOW_2026-04-22.md puis
docs/orchestration/GLOBAL_SYSTEM_PRIMER.md et AGENTS.md.

Objectif : [décrire : ex. finition Kiosk surface X, sync outbox, tests Vitest, etc.]

Contraintes : invariants foodking-invariants, symétrie Order/FrontendOrder, branch_id, dispatch after commit.

Lancer / reprendre : .cursor/ACTIVE_CYCLE.md, tasks/[TASK].md, run-cycle si cycle borné.
```

---

## 6. Lexique des noms du fichier

- **Expo Her** : point de reprise / nouvelle personne ou nouvelle session.  
- **Ancien agent** : le contexte produit par la session d’orchestration / audit / mémoire précédente, **figé dans le git + Graphiti** — pas l’assistant lui-même.  
- **Alimentation** : alimentation de la **mémoire** (JSONL, ingest Neo4j, `add_memory` après cycles).  
- **Workflow** : `PLAN → EXECUTE → VALIDATE → AUDIT` + règles + Graphiti + CI/prod quand c’est le moment.

---

## 7. Pointers techniques rapides (borne / sync)

| Sujet | Où |
|--------|-----|
| Création commande kiosk | `FrontendOrderService::myOrderStore` |
| Canal broadcast | `private-branch.{branchId}` ( listeners outbox ) ; auth `branch.{id}` côté Laravel |
| KDS listing | `KitchenDisplaySystemOrderService` |
| Tests sync | `tests/Feature/SyncComprehensiveTest.php` |
| Idempotency multi-branche | `tests/Feature/Orders/IdempotencyBranchScoped.php` |

---

*Fin du document de passation. Pour toute évolution structurelle, mettre à jour **GLOBAL_SYSTEM_PRIMER** + un épisode dans **`memory/episodes/13_agents_roles.jsonl`** (ou le domaine adéquat) puis ingérer.*
