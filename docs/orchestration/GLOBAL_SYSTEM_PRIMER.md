# FoodKing — Primer système global (agents, sous-agents, Graphiti, tokens)

> **Fichier d’entrée** pour toute nouvelle conversation, tout nouvel outil d’agent (Cursor, terminal, futur bot), ou tout humain qui reprend le projet.  
> Objectif : **robustesse** = même avec 100 cycles et des exécuteurs différents, le comportement reste **prévisible**, **traçable**, et la **mémoire** reste **alignée** sur le code.

---

## 1. Ordre de lecture obligatoire (minimum viable)

Lire **dans cet ordre** avant d’écrire du code ou un plan non trivial :

| # | Fichier | Pourquoi |
|---|---------|----------|
| 1 | **`AGENTS.md`** | Contrat global : phases, routing, MCP, terminal allies, non-négociables |
| 2 | **`.cursor/routing.md`** | Qui fait quoi (Claude plan/audit, GPT-5.4 complexe, Composer routine) |
| 3 | **`.cursor/commands/run-cycle.md`** | Déroulé exact d’un cycle `TASK_ID` (incl. Graphiti Step 0.5) |
| 4 | **`.cursor/rules/graphiti-memory.mdc`** | Mémoire Graphiti : quand lire / quand écrire |
| 5 | **`.cursor/rules/global.mdc`** + **`context-hygiene.mdc`** | Gates, discipline tokens **sans** réduire l’intelligence |
| 6 | **`memory/INDEX.md`** | Carte des domaines mémoire (secours si MCP absent) |
| 7 | **`tasks/[TASK_ID].md`** | Quand un cycle borné est lancé — périmètre de la tâche |

Ensuite, **selon le domaine** : `docs/ORDER_FLOW.md`, `docs/DEVICE_FLOW.md`, `docs/ARCHITECTURE.md`, `foodking-invariants.mdc`, etc.

Référence roster court : **`docs/orchestration/AGENT_ROLES.md`**.

---

## 2. Sous-agents Cursor (Task tool) — intégration dans le flux

Ce ne sont **pas** des fichiers dans le repo ; ce sont des **profils** invoqués par Cursor selon `.cursor/routing.md` et **`run-cycle.md` Step 2**.

| Sub-agent | Modèle cible | Quand |
|-----------|--------------|--------|
| **`foodking-routine-implementer`** | Composer | CRUD, UI copy, config, docs, tests simples — **hors** frozen zones, migrations, auth, pricing cœur |
| **`foodking-complex-implementer`** | GPT-5.4 | Backend non trivial, sync, `OrderService` / `FrontendOrderService`, fiscal, schéma si gate OK |

**Règles d’or**

1. **Délégation obligatoire** pour toute modification produit en EXECUTE, avec trace `EXECUTE_DELEGATION:` dans le log de validation.
2. Le sous-agent **ne voit pas toujours** le MCP Graphiti du parent : le plan **doit** contenir **`## PRIOR_CONTEXT`** (faits Graphiti + invariants) — copier ou résumer dans le message de délégation.
3. Aucun sub-agent ne **contourne** un gate humain ni n’édite une frozen zone sans `docs/gates/` approuvé.

---

## 3. Terminal allies (hors Task tool) — intégration

Documentés dans **`AGENTS.md` § Terminal allies** :

| Outil | Rôle | Position dans le flux |
|-------|------|-------------------------|
| **`claude`** (Claude Code CLI) | Audit textuel profond, second avis | **Après** livraison ou entre deux passes — ne remplace pas le gate `run-cycle` |
| **`codex`** (OpenAI Codex CLI) | Gros patch guidé par un plan existant | **Parallèle humain** au besoin — la validation reste PHPUnit/Vitest + phase VALIDATE |

Ils ne mettent **pas** à jour Graphiti automatiquement : c’est la **responsabilité explicite** du cycle (AUDIT / humain) d’enregistrer les décisions durables (voir §5).

---

## 4. Graphiti — vivre avec l’avancement du projet (N agents, N cycles)

### 4.1 Rôles

| Rôle | Responsable |
|------|----------------|
| **Lecture** avant plan / audit complexe | Tout agent avec MCP `graphiti` chargé |
| **Écriture** après décision durable | Phase AUDIT + CLOSED (`audit-context.md`) ou humain via `add_memory` |
| **Alimentation batch** (JSONL → Neo4j) | Humain ou pipeline : `bash bin/graphiti-ingest.sh` |

### 4.2 Quand mettre à jour la mémoire (checklist — « ne pas oublier »)

Cocher mentalement à **chaque** fin de sujet significatif :

- [ ] **Invariant** clarifié ou renforcé → nouvelle ligne dans `memory/episodes/02_architecture_invariants.jsonl` (ou fichier le plus proche) + `ingest` ciblé.
- [ ] **Sync / event / canal** modifié → `03_domain_events_sync.jsonl` + ingest.
- [ ] **Décision produit / ADR** → `12_decisions_log.jsonl` + ingest.
- [ ] **Nouvelle tâche V14+** ou finding cross-vagues → `09_tasks_history.jsonl` + ingest.
- [ ] **Changement prod / rollout** → `11_production_plan.jsonl` + ingest.
- [ ] **Nouveau rôle agent ou règle d’orchestration** → `13_agents_roles.jsonl` + ingest + **mettre à jour ce Primer** si le modèle change.

**Règle d’or** : si le code ou la doc **canonical** a changé et que la mémoire dit encore l’ancienne vérité → **mise à jour sous 48 h** (sinon dérive silencieuse).

### 4.3 Outils

- Ingestion : `bin/graphiti-ingest.sh [filtre]` — voir `memory/README.md`.
- Vérification : `python3 memory/verify.py`.
- Reset rare : `@graphiti clear_graph` puis full ingest (politique humaine).

---

## 5. Tokens, contexte, cache — politique « intelligence max, gaspillage min »

**But** : réponses **détaillées et stables**, pas des réponses courtes pour économiser des tokens au détriment de la qualité.

| On optimise (effet ≥ 0) | On n’optimise pas (effet négatif interdit) |
|-------------------------|---------------------------------------------|
| Re-lire un fichier **déjà** dans la fenêtre contexte | Tronquer un plan, une analyse de risque, ou un gate pour « faire court » |
| Résumer une phase **terminée** pour handoff (voir `context-hygiene.mdc` §4) | Supprimer `## PRIOR_CONTEXT` ou les invariants du plan |
| Utiliser **Graphiti** pour récupérer faits structurés au lieu de rouvrir 50 rapports | Désactiver Graphiti pour « aller plus vite » sur du sync / fiscal |
| Écrire les preuves dans `reports/` structuré | Remplacer des tests par de la prose vague |

**Cache applicatif** (Redis, etc.) : régie par le code Laravel et **`app:preflight-production`** — hors scope de ce Primer, mais **ne jamais** confondre « cache métier » et « mémoire Graphiti » : ce sont deux systèmes.

---

## 6. Révision de ce document

- **À chaque** changement majeur d’orchestration (nouveau sub-agent, nouveau MCP, nouveau cycle obligatoire) : mettre à jour **ce fichier** + une ligne dans `13_agents_roles.jsonl` + ingest.
- **Trimestriel** : relire §4.2 avec un lead dev.

---

## 7. Pointers rapides

| Besoin | Aller à |
|--------|---------|
| Cycle complet | `.cursor/commands/run-cycle.md` |
| Gates | `.cursor/rules/human-gates.mdc` |
| Invariants code | `.cursor/rules/foodking-invariants.mdc` |
| Mémoire locale | `memory/INDEX.md` |
| Pannes Graphiti | `.cursor/mcp/GRAPHITI_TROUBLESHOOTING.md` |
| Closeout prod + mémoire | `plans/PLAN_EXECUTION_CLOSEOUT_GRAPHITI_CI_PROD_2026-04-22.md` |
