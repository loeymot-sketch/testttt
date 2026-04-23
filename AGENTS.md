# FoodKing – Cursor Agent Operating Contract

## 0. Quick start contract — read this first

Commence ici. Ne lance aucune action tant que tu n'as pas lu les priorités P0.
Ce contrat fixe l'ordre minimal de lecture pour comprendre le repo en moins de 60 secondes.
Lis complètement ce qui est marqué obligatoire ; diffère seulement ce qui est explicitement classé P2.

### Reading priority

| Priority | What to read | Why |
| --- | --- | --- |
| P0 | `AGENTS.md §1 Parcours obligatoire` | Cadre impératif de travail, ordre de lecture, discipline de cycle. |
| P0 | `.cursor/ACTIVE_CYCLE.md` (continuation) | État courant du cycle actif, contexte vivant, reprise sans divergence. |
| P0 | `.cursor/rules/global.mdc` (auto-attaché — mentionné pour info) | Règles globales toujours applicables, même si déjà injectées par l'outil. |
| P1 | `docs/orchestration/GLOBAL_SYSTEM_PRIMER.md` | Vocabulaire, architecture d'orchestration, invariants transverses. |
| P1 | `.cursor/commands/run-cycle.md` | Procédure exacte pour démarrer un cycle borné correctement. |
| P1 | `docs/orchestration/MEMORY_MATRIX.md` | Où chercher la mémoire utile sans relire tout le dépôt. |
| P1 | `.cursor/routing.md` | Routage des tâches, choix du bon canal, limites d'intervention. |
| P2 | `docs/orchestration/CODEX_API_DELEGATION.md` (quand EXECUTE complexe) | À lire si tu délègues ou exécutes du complexe via API. |
| P2 | `.cursor/ACTIVE_CYCLE_ARCHIVE.md` (forensique humain) | Historique utile pour audit humain, pas requis au démarrage. |
| P2 | `docs/orchestration/EXPORT_CONFIG_CODEX_GPT_API_ET_AUDIT_PERSISTANCE.md` (nouveau poste) | Configuration et persistance utiles surtout lors d'un nouvel environnement. |

Règle simple : P0 avant toute action, P1 avant tout EXECUTE, P2 seulement à la demande du sujet.
Si un doute persiste après P0+P1, arrête-toi et relis ; n'improvise pas.

### One-line bootstrap

```bash
npm run verify:boucle
bash scripts/agent-activity-log.sh tail 50
run-cycle <TASK_ID>
```

Utilise `run-cycle <TASK_ID>` pour initier tout cycle borné.
Les deux autres commandes servent à vérifier l'état local et le journal d'activité avant d'exécuter.

### Quality-first, not token-cheap

Lis P0 et P1 intégralement, sans skim. Les économies de tokens ne se font jamais sur la substance des règles, seulement sur la répétition, le bruit et les relectures inutiles. En cas de tension entre vitesse et rigueur, applique la rigueur ; voir `.cursor/rules/global.mdc § Token Discipline`.

### If you're a new human contributor

Commence par `docs/orchestration/EXPORT_CONFIG_CODEX_GPT_API_ET_AUDIT_PERSISTANCE.md` pour préparer ton poste et comprendre le mode de persistance attendu.
Lis-le après P0, puis enchaîne sur P1 avant toute contribution effective.

---

## 1. Parcours obligatoire — **nouvelle conversation** **et** **continuation** (production, non négociable)

> **Objectif** : qu’**à chaque** session (premier message ou 500e message), l’exécutant sache **quel** chemin suivre — **sans** supposer un historique de chat. Tout est **dans le dépôt** ; l’histoire de conversation **n’est pas** la SSOT.

**Règle d’or** : *aucune* modification de code **produit** (hors `plans/`, `reports/`, `docs/gates/`, `missions/`, JSONL gouvernance) **dans le cadre d’un travail borné** sans **(a)** parcours ci-dessous **et** **(b)** cycle `run-cycle` + plan actif, sauf **exception** explicite humaine (notée dans le plan / gate).

| Étape | Action | Quand / pourquoi |
|-------|--------|------------------|
| **0. Continuation** | Lire **`.cursor/ACTIVE_CYCLE.md`**. | Si `PHASE` n’est **pas** vide et le cycle n’est **pas** archivé : **reprendre** ce `TASK_ID` / ce `PLAN_FILE` / ce `REPORT_FILE` — **ne pas** dupliquer un second cycle fantôme. Si humain confirme **nouveau** sujet : réinitialiser / nouveau `TASK_ID` selon `run-cycle` Step 0. |
| **1. Lecture initiale** | Lire **ce fichier** (`AGENTS.md`) **puis** **`docs/orchestration/GLOBAL_SYSTEM_PRIMER.md`** (table §1 = ordre complet : routing, `run-cycle`, Graphiti, `MEMORY_MATRIX`, etc.). | **Avant** tout code ou plan non trivial. Même en « continuation » si le contexte a dérivé ou l’onglet a été rafraîchi. |
| **2. Cycle structuré (SSOT procédurale)** | Toute tâche avec **`TASK_ID`** : suivre **`.cursor/commands/run-cycle.md`** et la commande **`run-cycle <TASK_ID>`** (ou équivalent explicite dans le chat : enchaîner les **Steps 0 → 5** sans en sauter). | **C’est** le programme de production en boucle : `PLAN → EXECUTE → VALIDATE → AUDIT → [GATE \| CLOSE]`. Aucun « close » sans `AUDIT` (sauf gate humain documenté). |
| **3. Vérification d’environnement** | `npm run verify:boucle` (0 requête API, binaire + doc). Avant un **gros** sujet ou un **nouveau** poste : `npm run verify:boucle:full` (1× smoke `claude` + 1× `codex:smoke` — preuve d’API). | Évite de découvrir en **Step 5** que le terminal d’audit ou le proxy d’`EXECUTE` est mort. Voir `run-cycle` Step 0 item 8. |
| **4. Secrets & outils machine** | Binaire **`claude`** sur le **`PATH`** (Claude Code CLI) pour l’**AUDIT** PRIMARY en terminal. Variables **`CODEX_*`** dans **`.env.codex`** / **`.env`** pour **`codex-terminal`**. (Option) MCP Graphiti selon `~/.cursor/mcp.json`. | Chaque poste de dev/CI. Sans `claude` : noter dès le **plan** l’`AUDIT` fallback + `AUDIT_FALLBACK_REASON`. Sans `CODEX_*` : pas d’`EXECUTE` complexe PRIMARY. |
| **5. Traces & mémoire (déjà dans ce fichier)** | **`EXECUTE_DELEGATION:`** avant VALIDATE ; **`AUDIT_CHANNEL:`** + **`TERMINAL_AUDIT_OK: 1`** si audit terminal OK ; `docs/orchestration/MEMORY_MATRIX.md` ; `scripts/agent-activity-log.sh` (tail / start / done). | Traçabilité = **même** qualité en prod sur N agents parallèles. |

**Ce n’est pas optionnel** pour travailler « en production FoodKing » : c’est le **contrat** d’onboarding. Les **règles** `.cursor/rules/*.mdc` (dont **`global.mdc` — alwaysApply**) et ce fichier **s’imposent** **à** tout modèle, **dans** toute conversation, dès l’ouverture du dépôt.

**Pour un humain / nouveau compte** : mêmes étapes ; la doc **`docs/orchestration/EXPORT_CONFIG_CODEX_GPT_API_ET_AUDIT_PERSISTANCE.md`** regroupe l’**export** config API + persistance des règles hors-chat.

## Engine
Cursor local agent. No cloud orchestration. No external framework.
Auto/Premium routing: disabled. Model selection is explicit per cycle.

## Global system primer (multi-agents, Graphiti, tokens — lecture clé)

Tout nouvel intervenant (session Cursor, sub-agent Task, CLI terminal, humain) qui touche **orchestration**, **mémoire**, ou **discipline de contexte** : lire **`docs/orchestration/GLOBAL_SYSTEM_PRIMER.md`** après ce fichier. Y sont définis : ordre de lecture obligatoire, **`foodking-routine-implementer` / `foodking-complex-implementer`**, terminal **`claude` / `codex`**, **mise à jour continue de Graphiti**, et la politique **« intelligence max — zéro optimisation négative »** (tokens : supprimer le gaspillage, pas la substance). Pour audits longs et robustesse **opération + agentique + mémoire** : **`docs/orchestration/MEGA_CHECKLIST_AUTONOMY_AND_MEMORY.md`** (180 tâches) et le narratif **`reports/audit/MEGA_AUDIT_SYSTEM_OPERATION_AGENTIC_2026-04-23.md`**.

**Discipline mémoire (qui écrit où, qui lit quoi, quand)** : **`docs/orchestration/MEMORY_MATRIX.md`** — matrice unique des **4 stores autorisés** (Code A · Graphiti+JSONL B · Missions C · Rapports D), table d'écriture par phase, ordre de lecture pour une nouvelle session, anti-patterns. Aucun nouveau store de mémoire ne peut être ajouté sans gate `docs/gates/GATE_MEMORY_*`. Décisions 2026-04-23 sur OpenSpace et claude-mem : **non intégrés**, justifications dans la matrice.

**Synchro multi-agents (cross-conv, cross-terminal)** : `.cursor/rules/cross-agent-sync.mdc` (alwaysApply) + `reports/AGENT_ACTIVITY_LOG.md` (append-only) + `scripts/agent-activity-log.sh` (`tail | start | done | collisions | active`). Au démarrage de session : `tail 50` (~500 tokens). Avant édition produit (Step 2 EXECUTE) : `start` (refus exit 2 si collision). À la clôture (Step 5 CLOSE) : `done`. Évite que deux agents (Cursor convs / `codex-terminal` / `claude-terminal` / humain) modifient les mêmes fichiers à leur insu.

## Workflow
PLAN → EXECUTE → VALIDATE → AUDIT → [HUMAN GATE | CLOSE]

No phase may be skipped. Audit always precedes close.

## Model Roles
| Model | Role | Channel (priorité **économie d’abonnement / token**) |
|---|---|---|
| Claude | Plan, architect, orchestrate, **audit** | **PLAN** : le plus souvent en **session Cursor** (orchestrateur) — c’est l’entretien cerveau, pas l’appel `codex`. **AUDIT (après chaque implementation)** : **PRIMAIRE = terminal** `bash scripts/foodking-claude-orchestrate.sh` (`context` → `audit` ou `audit-brief`) — s’appuie sur l’**abonnement Anthropic (Claude Code CLI)**, *pas* sur l’orchestrateur de modèles de Cursor. **FALLBACK AUDIT** : audit dans la **session Cursor** (Claude) **seulement** si le terminal est indisponible — tracer `AUDIT_CHANNEL: cursor-session` + `AUDIT_FALLBACK_REASON:`. Voir `run-cycle.md` Step 5. |
| **GPT-5.4 / GPT-5.4-pro** | **Complex implementation (PRIMARY)** | **`codex-terminal`** — `npm run codex:complex` (proxy + `CODEX_API_KEY`) — n’emprunte **pas** l’**usage de modèles** de l’**abonnement Cursor** (validé 2026-04-23). |
| GPT-5.4 (fallback) | Complex implementation (FALLBACK only) | **Sub-agent** `foodking-complex-implementer` (Task Cursor) — consomme le **compte côté Cursor (API du plan)**. **Uniquement** si `codex-terminal` a échoué ≥3 reprises ou moyen d’exécution indispo. |
| Composer | Routine edits, reports, summaries | Sub-agent `foodking-routine-implementer` — compte côté Cursor. |

**Principe unique (symétrique) — à valider en prod sur chaque cycle :** **implementation complexe = terminale d’abord (GPT + API)**, **audit = terminale d’abord (Claude + abonnement Anthropic)**, le **repli** vers les **sub-agents Cursor** n’intervient qu’en **défaut d’exécution** du **terminal** côté concerné, avec `FALLBACK_*` explicite dans le rapport. Preuve d’environnement : `bash scripts/verify-orchestration-boucle.sh` ; smoke complet : `VERIFY_BILLING_FULL=1` (consomme un minima d’appels API).

One PRIMARY_MODEL per cycle. Roles do not overlap.
Full routing policy: `.cursor/routing.md`. Naming: the **PRIMARY complex implementer is officially "FoodKing API Complex Implementer"** (slug `codex-terminal`); see `docs/orchestration/CODEX_API_DELEGATION.md`.

## Authoritative multi-agent bounded cycle (SSOT)

For **TASK_ID-driven** work in Cursor, this path is **authoritative** and overrides any conflicting step elsewhere in this document:

1. **Command:** `.cursor/commands/run-cycle.md` (invoke with a `TASK_ID`, e.g. `run-cycle SMOKE-001`).
2. **Cycle state:** `.cursor/ACTIVE_CYCLE.md` (`RUNNER_MODE: single-session`, `PHASE`, `PLAN_FILE`, `REPORT_FILE`, completion rows).
3. **Plan artifact:** `plans/PLAN_[TASK_ID]_[DATE].md` per `.cursor/context/plan-context.md` (from `plans/PLAN_TEMPLATE.md` when applicable).
4. **Phase instructions:** `.cursor/context/plan-context.md` (PLAN), `.cursor/context/execute-context.md` (EXECUTE), `.cursor/context/audit-context.md` (AUDIT); VALIDATE per `run-cycle.md` when `validate-context.md` is absent.

**EXECUTE delegation (PRIMARY first, FALLBACK only on failure):**

- **Routine** → `Task` → `foodking-routine-implementer`.
- **Complexe — PRIMARY** : **`codex-terminal`** — la **FoodKing API Complex Implementer** (proxy OpenAI-compatible). Procédure :
  1. Préparer `missions/{TASK_ID}/input.json` (+ optionnels : `graphiti_context.md` issu de `search_memory_facts(group_ids=["foodking"])`, `plan_excerpt.md`, `execute_brief.md`, `cycle_snapshot.md` — auto-fusionnés par le runner).
  2. Lancer `npm run codex:complex -- {TASK_ID}` (variante rapide : `npm run codex:fast` ; modèle par défaut `gpt-5.4`, override : `CODEX_MODEL_COMPLEX=gpt-5.4-pro`). Clés `CODEX_*` dans `.env` / `.env.codex`. Bootstrap mission : `npm run codex:prepare -- {TASK_ID}`.
  3. Appliquer `missions/{TASK_ID}/output_codex.json` dans la session Cursor (créer/éditer les fichiers exactement comme indiqué).
  4. Tracer `EXECUTE_DELEGATION: codex-terminal` dans `reports/post_execute_latest.log` et le `REPORT_FILE`.
- **Complexe — FALLBACK (uniquement si le proxy est HS après ≥3 reprises retry/504/empty)** : `Task` → `foodking-complex-implementer` (sub-agent Cursor, consomme le quota du modèle premium de l'abonnement Cursor — à éviter sauf indispo réelle du proxy).

Référence complète : **`docs/orchestration/CODEX_API_DELEGATION.md`** (naming, fallback contract, audit handoff, token discipline, schéma boucle). Procédure cycle : `.cursor/commands/run-cycle.md`. La trace `EXECUTE_DELEGATION` dans le rapport est **obligatoire** pour passer en VALIDATE.

Sections below labeled **Legacy workflow** remain valid for **PR-centric / review-loop** habits but **do not replace** this SSOT for bounded cycles.

## Source of truth (extended)
- README.md
- docs/PROJECT_CONTINUITY_AND_VISION.md
- docs/ARCHITECTURE.md
- docs/API_MAP.md
- docs/AUTHZ_MATRIX.md
- docs/ORDER_FLOW.md
- docs/DEVICE_FLOW.md
- docs/BUSINESS_RULES.md
- docs/CORE_MODULES.md
- docs/DATABASE_SCHEMA_CORE.md
- docs/ERROR_HANDLING.md
- docs/SECURITY_NOTES.md
- docs/TEST_PLAN.md
- docs/MASSIVE_TEST_PLAN.md
- docs/SAAS_VISION.md
- docs/CONTRIBUTING_QA_BOTS.md
- workflows/qa-loop.md
- workflows/task-routing.md
- workflows/report-format.md
- workflows/task-status.md
- reports/README.md
- docs/orchestration/MEGA_CHECKLIST_AUTONOMY_AND_MEMORY.md
- docs/orchestration/WORKFLOW_AUDIT_GRAPHIQUE_2026-04-23.md
- docs/orchestration/TERMINAL_CLAUDE_GRAPHITI_ORCHESTRATION.md
- docs/orchestration/ROUTING_MATRIX.md

## Stop Conditions
Halt and generate a gate brief on any of:
- Gate trigger detected
- Scope expansion beyond declared boundary
- FoodKing invariant violation
- Two consecutive validation failures
- Planning ambiguity unresolvable from task context
- **`codex-terminal` indisponible après reprises (proxy 502/503/504 ou contenu vide pour ≥3 tentatives consécutives sur la même tâche)** : basculer sur le fallback `Task → foodking-complex-implementer` et **noter explicitement** `EXECUTE_DELEGATION: foodking-complex-implementer (codex-terminal-fallback)` + une ligne `FALLBACK_REASON:` dans le rapport.
- **Audit en terminal (PRIMARY) indisponible** (`claude` absent, auth/quota/réseau après **une** tentative de `context` + `audit-brief` ou `audit` documentée) : basculer l’**AUDIT** dans la **session Cursor** (même checklist `audit-context.md`) + **`AUDIT_CHANNEL: cursor-session`** + **`AUDIT_FALLBACK_REASON:`** (obligatoire). **Ne jamais** omettre la raison, sinon l’on ne sait plus si c’est un choix d’économie ou un défaut d’environnement.

## FoodKing Non-Negotiables
- Backend is pricing SSOT — no frontend price logic
- `OrderStatus` enum is authoritative — no hardcoded strings
- `branch_id` = business data isolation — no cross-branch data bleed
- Dispatch strictly after DB commit
- `OrderService` / `FrontendOrderService` symmetry mandatory on any order change
- Frozen zones require gate clearance before any edit

## MCP

### Phase 1 — Filesystem
Filesystem MCP only for repo reads where applicable.

### Phase 2 — Graphiti (mémoire inter-cycles — **présent dans toutes les sessions où le serveur est enregistré**)

**Objectif** : décisions d’architecture, invariants, sync borne↔POS↔KDS, fiscal NF525, historique de cycles — récupérables sans relire des centaines de fichiers.

| Élément | Détail |
|--------|--------|
| **Enregistrement Cursor (obligatoire côté humain)** | Fusionner le bloc `graphiti` dans **`~/.cursor/mcp.json`** (Settings → MCP). Modèle : **`.cursor/mcp/graphiti.json.example`**. Le dépôt ne peut pas injecter un MCP automatiquement dans l’IDE. |
| **Règle agent (automatique dès que le MCP est chargé)** | Voir **`.cursor/rules/graphiti-memory.mdc`** (always-on) + **`global.mdc`** : avant toute tâche non triviale, appeler au moins **`search_memory_facts`** (et optionnellement **`search_memory_nodes`**) avec `group_ids=["foodking"]`. |
| **Après AUDIT / CLOSE** | Si `add_memory` est disponible : enregistrer les décisions durables (ADR, gate, invariant clarifié). |
| **Si Graphiti absent de la session** | **Ne pas bloquer** PLAN / EXECUTE : une ligne « Graphiti non chargé » + secours **`memory/INDEX.md`** + lecture ciblée des JSONL sous `memory/episodes/`. |
| **Server** | Zep Graphiti — wrapper local **`.cursor/mcp/start-graphiti-mcp.sh`** (voir exemple JSON). Clone typique : `/Users/1millnonstop/graphiti`. |
| **Backend** | Neo4j (ex. Aura) — credentials hors repo. |
| **Dépannage** | **`.cursor/mcp/GRAPHITI_TROUBLESHOOTING.md`** (LiteLLM, embeddings, redémarrage proxy). |
| **Group ID** | Toujours **`foodking`**. |
| **Ingestion / vérif locale** | `memory/ingest.py`, `memory/verify.py`, `bin/graphiti-ingest.sh` ; index des domaines **`memory/INDEX.md`**. |

**Intégration bounded cycle** : la commande **`.cursor/commands/run-cycle.md`** inclut l’appel Graphiti en **Step 0 item 5** (query avant PLAN).

### Phase 3 — Playwright MCP (tests E2E sur flows critiques FoodKing)

- Package : `@playwright/mcp@latest` (npx, pas d’install global)
- Browser : Chromium
- BASE_URL : `http://localhost:8000`
- Config : `.cursor/mcp/playwright.json`
- Flows couverts : POS Cash, POS Card, Kiosk, KDS, Auth refresh (F5)
- Déclencheur : plan déclare `playwright-mcp` | `playwright-critical-flow` | `playwright-full-e2e`
- Rapport : `reports/antigravity/latest.md`
- Règle : le Planner-Orchestrator seul décide si un cycle requiert E2E — jamais auto-déclenché.

---

## Terminal allies — Claude Code & OpenAI Codex (abonnements Pro)

Ces outils **complètent** le routage interne Cursor (`.cursor/routing.md` : PLAN/AUDIT Claude modèle Cursor, EXECUTE complexe GPT-5.4, routine Composer). **Aucun remplacement** des rôles du dépôt : ce sont des **alliés optionnels** que tu lances **toi** depuis le terminal intégré Cursor quand tu veux plus de profondeur ou un second passage.

### A — Anthropic **Claude Code** (audits / orchestration textuelle, abonnement Anthropic)

1. Dans Cursor : **Extensions** (`Ctrl+Shift+X` / `Cmd+Shift+X`) → chercher **Claude Code** (Anthropic) → **Installer**.
2. Ouvrir le terminal intégré Cursor.
3. Lancer une fois :

```bash
claude
```

4. Première exécution : se connecter avec le compte Anthropic (abonnement Pro/Max) — utilise les quotas du compte, **sans** clé API dans le dépôt.

**Appels non interactifs (audit / plan d’orchestration)** :

```bash
claude "Audite tout le code livré sur ce cycle et produis un plan d'orchestration des corrections restantes, en respectant les invariants FoodKing (AGENTS.md)."
```

**Wrapper recommandé (vérification `PATH` + `claude -p` + `--add-dir` vers la racine du dépôt)** — depuis la racine Git :

```bash
bash scripts/foodking-claude-orchestrate.sh check     # s'assure que `claude` (Claude Code) est installé
bash scripts/foodking-claude-orchestrate.sh smoketest # 1 requête API min. — valide abonnement / auth (retourne TERMINAL_OK)
bash scripts/foodking-claude-orchestrate.sh context   # écrit reports/audit/_TERMINAL_CONTEXT_BRIEF.md (cycle + JSONL + INDEX)
bash scripts/foodking-claude-orchestrate.sh audit-brief  # claude -p : lit ce fichier d'abord = tokens maîtrisés + alimentation mémoire
bash scripts/foodking-claude-orchestrate.sh audit     # audit / plan d'orchestration non interactif (prompt FoodKing)
bash scripts/foodking-claude-orchestrate.sh repl      # session interactive (équivalent : `claude` seul)
```

Lecture : **`docs/orchestration/TERMINAL_CLAUDE_GRAPHITI_ORCHESTRATION.md`** (Cursor vs sub-agent vs terminal, Graphiti, économie de contexte).

**Après chaque supplémentation (ADR, JSONL, fin de lot)** — ordre pour alimenter la base **sans** gaspiller l’abonnement :

1. Mettre la décision / invariant dans le bon `memory/episodes/*.jsonl` (voir `memory/INDEX.md` + `docs/orchestration/GLOBAL_SYSTEM_PRIMER.md` §4.2).
2. `bash scripts/after-execute-memory.sh` — rafraîchit le manifeste SHA, vérifie le même contrat que CI, rappelle les `bin/graphiti-ingest.sh <préfixe>` pour les fichiers modifiés, puis lance l’ingest côté poste si la stack est up.
3. (Option Graphiti) `python3 memory/verify.py` si le MCP / Neo4j tournent.
4. (Option abonnement Anthropic **ciblé**) `bash scripts/foodking-claude-orchestrate.sh context` puis `... audit-brief` — le terminal lit d’abord le bref disque ; ou raccourci : `... post-execute` pour enchaîner l’étape 2 + `context` (sans lancer l’audit payant à ta place).

Le wrapper branche `stdin` sur `/dev/null` pour `claude -p` (audit) : dans un **terminal non TTY** (ou agent automatisé), sans cela Claude Code affiche *no stdin data* et refuse de lire le prompt. Ne pas l’enlever si tu scripts l’orchestration.

Si `check` échoue : installer l'extension **Claude Code** (Cursor) ou le binaire `claude` côté Anthropic, et vérifier que le dossier binaire (souvent `~/.local/bin`) est sur le `PATH` du terminal intégré.

**Usage aligné sur ton workflow** : orchestration initiale et tâches courantes → **Claude dans Cursor** ; **audits finaux / second passage** → **`claude "..."` ou le wrapper `foodking-claude-orchestrate.sh audit`** quand tu le décides.

### B — OpenAI **Codex** CLI (implémentations lourdes, abonnement ChatGPT Plus/Pro)

1. Dans le terminal Cursor :

```bash
npm install -g @openai/codex
```

2. Lancer :

```bash
codex
```

3. Première exécution : choisir **Sign in with ChatGPT** — utilise les crédits du compte ChatGPT Pro, **sans** clé API dans le dépôt.

**Appels non interactifs** :

```bash
codex "Implémente uniquement ce qui est décrit dans plans/PLAN_<TASK>_<DATE>.md ; ne modifie pas le hors-scope ; respecte AGENTS.md et les frozen zones."
```

**Usage aligné sur ton workflow** : implémentations **complexes** déjà planifiées dans `plans/` → **`codex "..."`** ; validation → toujours **PHPUnit / Vitest** + cycle Cursor VALIDATE/AUDIT.

### Résumé opérationnel (sans changer le plan de phases du dépôt)

| Besoin | Outil terminal | Quand |
|--------|----------------|-------|
| Audit global / orchestration textuelle profonde | `claude "..."` | Après livraison, ou boucle 2e audit |
| Patch large multi-fichiers guidé par un plan existant | `codex "..."` | EXECUTE complexe hors session Cursor ou en parallèle humaine |
| Cycle officiel FoodKing (gates, routing, preflight prod) | Cursor + `run-cycle` + subagents | Toujours la source de vérité procédurale |

**Sécurité** : ne jamais coller de secrets (Neo4j, OpenRouter, clés API) dans les prompts terminal ; les abonnements gèrent l’auth OAuth du CLI.


## Pre-Execution
Run `.cursor/hooks/safety-check.sh` manually before every execution phase.

## Artifact Locations
| Artifact | Path |
|---|---|
| Task intake | `tasks/` |
| Plans | `plans/` |
| Reports | `reports/` |
| Gate briefs | `docs/gates/` |
| Routing policy | `.cursor/routing.md` |
| Rules | `.cursor/rules/` |

---

## Extended workflow — repository operating instructions

### Claude (Architect & Reviewer)
Responsibilities:
- Architecture decisions and reasoning
- Root-cause analysis and debugging
- Planning with explicit test strategy
- Final review of implementation quality
- Risky refactors and cross-module decisions
- Auth/sync/pricing/state logic analysis
- Determines **test strategy** in plan using the active vocabulary (see **Testing rules**)

### Playwright / E2E verification (Critical QA)
Responsibilities:
- E2E testing (browser, Playwright MCP, complex flows)
- Critical integration testing
- Functional exploration
- Structured QA reporting under `reports/antigravity/` (legacy directory name)
- Only invoked when Claude's plan specifies **`playwright-critical-flow`**, **`playwright-full-e2e`**, or **`playwright-mcp`**, or when review verdict is **`NEEDS_PLAYWRIGHT`**

### Bugbot (Passive Diff Scanner — NO authority)
Responsibilities:
- Automatically scans PR diffs for bugs, security issues, regressions, edge cases
- Writes findings ONLY to `reports/review/bugbot-latest.md`
- NEVER autonomous — generates a file and stops
- NEVER communicates directly with Kimi or the Playwright / E2E executor outside the documented report chain
- NEVER makes architectural decisions
- Governed strictly by `.cursor/BUGBOT.md`

### Cursor
Orchestration environment

## Legacy workflow (PR / review loop — optional)

Use this loop for **historical PR-centric** review habits. It **does not** replace the **Authoritative multi-agent bounded cycle (SSOT)** above for `TASK_ID` + `run-cycle` work.

### Normal Cycle (90% of cases)
1. **Human** requests feature/fix
2. **Claude** analyzes and may write a **narrative or scratch** plan in `reports/planning/latest.md` **only when not** using the bounded SSOT; for bounded cycles the plan **must** be `plans/PLAN_[TASK_ID]_[DATE].md` as in `plan-context.md`.
   - Plan MUST specify test strategy: `no-test` | `static-inspection` | `local-validation` | `playwright-mcp` | `playwright-critical-flow` | `playwright-full-e2e` | `human-verification`
3. **Human** validates plan (GO / MODIFY / STOP)
4. **Kimi** MUST check FIRST: does `reports/review/bugbot-latest.md` exist?
   - **YES** → Kimi **notifies the Human** with:
     `ℹ️ Bugbot findings detected in reports/review/bugbot-latest.md — Claude review needed (ask Claude to fix when ready).`
     Then Kimi **continues normally to step 5** without stopping.
   - **NO** → Kimi continues normally to step 5
5. **Kimi** (or **Cursor** per plan) implements following Claude's plan
6. **Executor** runs **local-validation** (or other declared strategy) when the plan requires it (PHPUnit, Jest, etc.)
7. **Executor** writes execution summary in `reports/execution/latest.md` with test results
8. **Bugbot** (if PR exists) scans the diff → writes `reports/review/bugbot-latest.md` passively
9. **Claude** reads `reports/review/bugbot-latest.md` (when Human convokes Claude) and decides:
   - `ACCEPT` → not blocking, writes verdict in `reports/review/latest.md`
   - `REQUEST_FIX` → writes a minimal correction plan for Kimi
   - `ESCALATE` → schedules **Playwright / E2E verification** (only Claude can do this)
10. **Claude** writes final review in `reports/review/latest.md` with verdict: **APPROVED** / **NEEDS_FIX** / **NEEDS_PLAYWRIGHT**
11. **Kimi** deletes `reports/review/bugbot-latest.md` only after Claude writes `APPROVED` verdict
12. **Human** validates final result

### Playwright / E2E cycle (10% of cases - critical tests only)
1. **Claude's plan** specifies **`playwright-critical-flow`** / **`playwright-full-e2e`** / **`playwright-mcp`** OR **Claude's review** says **`NEEDS_PLAYWRIGHT`**
2. **Human** explicitly requests or authorizes the browser / E2E cycle when gating requires it
3. **Playwright** (MCP or runner) executes E2E/browser/critical tests
4. **Playwright** writes report in `reports/antigravity/latest.md`
5. **Claude** analyzes report → back to Normal Cycle step 2

## Task routing rules

### Use Claude for:
- Architecture decisions
- Synchronization logic
- Auth/authz
- Pricing integrity
- Risky refactors
- Bug root-cause analysis
- Cross-module decisions
- Order lifecycle logic
- State consistency
- Planning (with test strategy)
- Final review

### Use Kimi for:
- Localized code changes
- UI implementation
- CRUD endpoints
- Simple wiring
- Repetitive code generation
- Limited-scope patches
- Unit/integration testing (PHPUnit, Jest, Vitest)
- Linting and formatting

### Use Playwright / E2E verification for:
- E2E testing (browser automation)
- Complex integration flows
- Critical business scenarios
- Multi-device testing
- Performance testing
- Only when explicitly requested or when Claude's plan specifies **`playwright-critical-flow`**, **`playwright-full-e2e`**, or **`playwright-mcp`**

## Repository behavior rules
1. Always read relevant docs before proposing or implementing a change.
2. Treat existing docs and workflow files as required operational context.
3. Do not change code outside the requested scope.
4. Do not touch unrelated modules.
5. Do not modify architecture casually.
6. Do not bypass server-side validations, pricing recalculation, authorization checks, or state transition rules.
7. Preserve the existing business domain language.
8. Respect all boundaries between:
   - admin
   - manager/cashier
   - kiosk machine
   - kitchen display
   - frontend/customer flows
9. Respect the documented order flow and device flow.
10. If a change affects auth, sync, pricing, device behavior, or order states, explicitly mention the risk and propose tests.

## Implementation rules
1. Prefer small diffs.
2. Prefer the simplest working change consistent with the architecture.
3. Reuse existing services, controllers, patterns, and naming conventions where possible.
4. If existing code is inconsistent, point it out before broad cleanup.
5. Do not introduce new dependencies unless necessary and justified.
6. If a task is large, first produce a plan, then implement in phases.
7. After implementation, summarize:
   - files changed
   - why they changed
   - risks
   - test results (if **local-validation** or other test strategy ran)

## Testing rules
1. **Claude decides test strategy in the plan** (active vocabulary):
   - **`local-validation`**: Unit/integration tests (PHPUnit, Jest, Vitest)
   - **`playwright-mcp`** / **`playwright-critical-flow`** / **`playwright-full-e2e`**: E2E / browser / critical paths
   - **`static-inspection`**: Read-only audit without running the full suite
   - **`no-test`**: Trivial changes (docs, comments, formatting)
   - **`human-verification`**: Explicit human sign-off required

2. **Executor runs `local-validation`** when the plan specifies it:
   - Run PHPUnit for backend changes
   - Run Jest/Vitest for frontend changes
   - Run linter (phpcs, eslint)
   - Include test results in execution summary

3. **Playwright / E2E** executes when the plan specifies **`playwright-mcp`**, **`playwright-critical-flow`**, or **`playwright-full-e2e`**:
   - E2E browser testing
   - Complex integration scenarios
   - Critical business flows
   - Generate detailed QA report under `reports/antigravity/`

4. Prioritize tests for:
   - kiosk auth
   - pricing integrity
   - order creation
   - state transitions
   - KDS flows
   - OSS/display flows
   - authorization boundaries

## Operational output rules
- **Bounded SSOT cycles:** plan under `plans/` per `ACTIVE_CYCLE.md` / `plan-context.md`; execution evidence in `REPORT_FILE` and `reports/post_execute_latest.log` as in `run-cycle.md`.
- **Legacy loop:** planning narrative may go to `reports/planning/latest.md` (with test strategy specified) when not using the bounded SSOT.
- Execution summary goes to `reports/execution/latest.md` (with test results if applicable)
- Review output goes to `reports/review/latest.md` (with verdict)
- QA findings come from `reports/antigravity/latest.md` (only when Playwright / E2E verification is invoked; path name is legacy)
- **Bugbot findings** go to `reports/review/bugbot-latest.md` (passive, read only by Claude)
- Use the report format defined in `workflows/report-format.md`
- See `.cursor/BUGBOT.md` for Bugbot operating rules

## Behavior in uncertainty
- If docs and code disagree, say so explicitly.
- If code and reports disagree, investigate first.
- If the change is risky, stop and propose a safer phased approach.
- If uncertain about test strategy, default to **`local-validation`** for safety.

## Definition of good output
A good result is:
- scoped
- consistent with docs
- easy to review
- safe to test
- explicit about risk
- explicit about test strategy
- explicit about next steps

## Workflow autonomy
This workflow is semi-autonomous, not fully autonomous.
Agents must automatically read the relevant project files and latest operational reports,
but the human developer remains the final authority and explicitly validates each major cycle.

## Definition of success
- architecture preserved
- business rules respected
- authorization intact
- no unrelated regressions
- tests passed (if applicable)
- work easy to review
- clear next step

Before making important changes, first summarize the current architecture understanding in 5-10 lines.
