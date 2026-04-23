# FoodKing API Complex Implementer (`codex-terminal`) — naming, routage, boucle, fallback

> **Naming officiel** : le système terminal qui délègue les EXECUTE complexes au proxy OpenAI-compatible (GPT-5.4 / GPT-5.4-pro) s'appelle **FoodKing API Complex Implementer** ; son **slug technique partout** (rapports, `EXECUTE_DELEGATION:`, plans, scripts) est **`codex-terminal`**.
>
> Ne pas confondre avec le CLI **`codex`** d'OpenAI / ChatGPT-Plus mentionné dans `AGENTS.md` §B (autre canal, autre auth, autre tarification — celui-ci utilise le proxy `/v1/chat/completions` et la clé `CODEX_API_KEY`).

---

## 0. Symétrie « terminal d’abord » (économie d’abonnement / SSOT 2026-04-24)

C’est le **même principe** appliqué **deux fois** (implementation vs audit) :

| Phase | **PRIMARY (terminal, économie Cursor en évitant sub-agents)** | **FALLBACK (Cursor, usage API de l’abonnement Cursor)** | Trace |
|---|---|---|---|
| **EXÉCUTE complexe** | **`codex-terminal`** — `npm run codex:complex -- {TASK_ID}` (clé `CODEX_*`, proxy) | **Sub-agent** `foodking-complex-implementer` | `EXECUTE_DELEGATION: …` + `FALLBACK_REASON:` si fallback |
| **AUDIT (après implementation)** | **`claude` en terminal** — `bash scripts/foodking-claude-orchestrate.sh` (`context` → `audit` ou `audit-brief`, abonnement **Anthropic**) | **Même rôle** dans la **session Cursor** (orchestrateur) | `AUDIT_CHANNEL: claude-terminal` **ou** `cursor-session` + `AUDIT_FALLBACK_REASON:` |

- **Règle d’or** : n’inverser l’ordre (Cursor en premier) **que** si le terminal a été **tenté** et **a échoué** (ou binaire indispo), et **le documenter** (`FALLBACK_*`).
- Vérification d’environnement : `bash scripts/verify-orchestration-boucle.sh` ; preuve d’extremité (optionnel) : `VERIFY_BILLING_FULL=1` (1× smoke `claude` + 1× `npm run codex:smoke` — consomme de minimes quotas).
- Détails procéduaux : `run-cycle.md` **Step 5 (AUDIT)** = PRIMARY terminal **obligatoire en intent** ; `AGENTS.md` § rôles modèles = même doctrine.

---

## 1. Pourquoi `codex-terminal` est PRIMAIRE (pas le sub-agent)

Validé le 2026-04-23 sur 4 missions complexes (POS Vue + Job Laravel) — `reports/execution/CODEX_REAL_COMPLEX_TEST_2026-04-23.md` :
- 4/4 sorties non vides, code production-ready, **tous** les invariants respectés (pricing SSOT, OrderStatus enum, branch_id, post-commit dispatch).
- Coût marginal sur API ≪ coût d'un slot premium Cursor pour de l'EXECUTE long.
- Streaming + retry 502/503/504/429 fiabilisent le proxy au-delà de 95 % en prod.

**Conséquence** : pour toute EXECUTE complexe, le chemin par défaut est `codex-terminal`. Le sub-agent `foodking-complex-implementer` reste **fonctionnel** mais devient explicitement un **fallback documenté**.

---

## 2. Routage (source de vérité)

| Situation | Chemin | Trace dans le rapport |
|---|---|---|
| **EXECUTE — tâche routée *routine*** | Sub-agent **`foodking-routine-implementer`** (Cursor Task) | `EXECUTE_DELEGATION: foodking-routine-implementer` |
| **EXECUTE — tâche complexe (PRIMARY)** | **`codex-terminal`** : 1) `missions/{TASK_ID}/input.json` + contextes optionnels ; 2) `npm run codex:complex -- {TASK_ID}` ; 3) appliquer `output_codex.json` ; 4) tracer | `EXECUTE_DELEGATION: codex-terminal` |
| **EXECUTE — tâche complexe (FALLBACK)** | Sub-agent **`foodking-complex-implementer`** (Task tool) — **uniquement** si `codex-terminal` échoue ≥3 reprises (HTTP 502/503/504/429 ou contenu vide) | `EXECUTE_DELEGATION: foodking-complex-implementer (codex-terminal-fallback)` + `FALLBACK_REASON:` |

`.cursor/routing.md` est le mirroir formel ; `AGENTS.md` § "EXECUTE delegation" l'autorité opérationnelle.

---

## 3. Boucle complète (orchestrateur → exec → audit → close)

```
┌─────────────────────────────────────────────────────────────────────────────┐
│  Cursor session — Orchestrator (Claude PRIMARY_MODEL pour PLAN/AUDIT)       │
│                                                                             │
│  Step 0   ACTIVE_CYCLE check + Graphiti search_memory_facts (group=foodking)│
│  Step 1   PLAN → plans/PLAN_{TASK_ID}_{DATE}.md (PRIOR_CONTEXT inline)      │
│              │                                                              │
│              ▼                                                              │
│  Step 2   EXECUTE delegation:                                               │
│           (a) routine → Task foodking-routine-implementer                   │
│           (b) complexe → codex-terminal (PRIMARY) :                         │
│                missions/{TASK_ID}/input.json                                │
│                + graphiti_context.md (facts du Step 0)                      │
│                + plan_excerpt.md (PRIOR_CONTEXT, scope, hard constraints)   │
│                + execute_brief.md (3-5 lignes execute-context)              │
│                npm run codex:complex -- {TASK_ID}                           │
│                                                                             │
│                ┌──────────────────────────────────────────────────────┐     │
│                │  Terminal — agents/codex.runner.mjs                  │     │
│                │  Stream → /chat/completions → output_codex.json      │     │
│                │  Retry 502/503/504/429 + empty content (≤8 attempts) │     │
│                └──────────────────────────────────────────────────────┘     │
│                                                                             │
│              Apply output_codex.json (touched files)                        │
│              Append to reports/post_execute_latest.log:                     │
│                  EXECUTE_DELEGATION: codex-terminal                         │
│                                                                             │
│  Step 3   post-execute hook                                                 │
│  Step 4   VALIDATE (vitest / phpunit / lint per plan)                       │
│  Step 5   AUDIT — **PRIMARY = claude en terminal (obligatoire d’exécuter  │
│            en priorité) :**                                                 │
│                context → audit-brief **ou** audit  (abonnement Anthropic)  │
│                → AUDIT_CHANNEL: claude-terminal  (+ TERMINAL_AUDIT_OK:1)  │
│           **FALLBACK = auditer dans la session Cursor** (même checklist)  │
│                seulement si le terminal a échoué →                        │
│                AUDIT_CHANNEL: cursor-session  +  AUDIT_FALLBACK_REASON:    │
│                                                                             │
│           OK → CLOSE + (optional) add_memory pour Graphiti                  │
│           KO normal → REMEDIATION (back to Step 2, codex-terminal again)    │
│           Critical zone OR 3rd same bug → HUMAN_GATE                        │
└─────────────────────────────────────────────────────────────────────────────┘
```

Les règles `.cursor/rules/auto-remediation.mdc` (boucle KO normal) et `.cursor/rules/human-gates.mdc` (zones critiques) s'appliquent à l'identique — `codex-terminal` ou sub-agent.

---

## 4. Bootstrap d'une mission

```bash
npm run codex:prepare -- T-MA-TACHE-001    # crée missions/T-MA-TACHE-001/ + stubs
# remplir : missions/T-MA-TACHE-001/input.json
#           missions/T-MA-TACHE-001/graphiti_context.md  (facts MCP)
#           missions/T-MA-TACHE-001/plan_excerpt.md      (PRIOR_CONTEXT du plan)
#           missions/T-MA-TACHE-001/execute_brief.md     (3-5 lignes execute-context)
npm run codex:complex -- T-MA-TACHE-001
```

Sortie : `missions/T-MA-TACHE-001/output_codex.json`. **Streaming** par défaut (le proxy time-out en non-stream sur prompts longs ⇒ HTTP 504 Cloudflare). Override modèle : `CODEX_MODEL_COMPLEX=gpt-5.4-pro npm run codex:complex -- T-MA-TACHE-001`.

---

## 5. Choix de modèle (gpt-5.4 vs gpt-5.4-pro)

- **Défaut projet** : `CODEX_MODEL_COMPLEX=gpt-5.4` (le plus stable sur le proxy, ~17–20 s par tâche complexe).
- **`gpt-5.4-pro`** : override ponctuel pour les tâches qui demandent une solution plus défensive / plus réécrite (~22–28 s).
- Le **dashboard fournisseur** prouve que la clé / l'endpoint sont vivants — il ne garantit **pas** que chaque réponse contienne du `content`. Le runner **retry sur réponse vide** pour combler ces trous.
- **Avant toute grosse tâche** : `npm run codex:smoke` (exit 0 = texte assistant reçu).

---

## 6. Token / contexte / Graphiti

- **Côté Cursor (orchestrateur)** : `search_memory_facts(group_ids=["foodking"])` une fois en Step 0, fold les facts → `graphiti_context.md`. Pas de re-lecture de `AGENTS.md`/`.mdc` côté API.
- **Côté runner** : fusion automatique des 4 fichiers (`graphiti_context.md`, `plan_excerpt.md`, `execute_brief.md`, `cycle_snapshot.md`) dans le bloc `## Prior context` du prompt. Override : `CODEX_AUX_CONTEXT_FILES=a.md,b.md`.
- **Côté Claude audit terminal** : utiliser `_TERMINAL_CONTEXT_BRIEF.md` produit par `bash scripts/foodking-claude-orchestrate.sh context` — pas de `claude` interactif sans bref.
- **Cache** : le runner ne recharge jamais les `.mdc` ni `AGENTS.md` ; il dépend exclusivement du prompt système (`agents/codex.prompt.txt`) qui mirorre les hard constraints.

---

## 7. Variable `m` en JSON côté proxy

Le proxy peut silencier l'`assistant` si l'`input` utilisateur est un JSON avec la clé top-level **`m`**. Le runner **renomme** automatiquement `m` → `instruction` (désactiver : `CODEX_NO_NORMALIZE_M=1`).

---

## 8. Reprise / fiabilité

- Reprises : 502, 503, 504, 429 + `200 + empty content`. Backoff exponentiel 2 s → 40 s, max 8 essais (`RETRY_MAX`).
- HTML 504/502 (Cloudflare) sont caught dans `doOneShot` et reformulés en erreur API ré-essayable.
- Stream → fallback one-shot automatique si stream tombe.

---

## 9. Kit portable (pour réutiliser ailleurs)

Voir **`dist/codex-portable/README.md`**. Les 2 fichiers indispensables sont `agents/codex.runner.mjs` et `agents/codex.prompt.txt` — tout le reste est glue projet.

---

## 10. Trace dans les rapports

Chaque cycle qui modifie du produit doit contenir, dans `reports/post_execute_latest.log` ET dans `REPORT_FILE` :

```
EXECUTE_DELEGATION: codex-terminal
EXECUTE_MODEL: gpt-5.4 | gpt-5.4-pro
AUDIT_CHANNEL: cursor-session | claude-terminal
```

Et en cas de fallback :

```
EXECUTE_DELEGATION: foodking-complex-implementer (codex-terminal-fallback)
FALLBACK_REASON: codex-terminal exhausted retries (HTTP 504 ×4, then empty content ×3)
```

Sans cette trace, VALIDATE doit halt (`run-cycle.md` Step 2).
