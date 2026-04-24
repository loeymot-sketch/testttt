# FoodKing Codex Complex Implementer (`codex-extension`) — naming, routage, boucle, fallback

> **PRIMARY (2026+)** : **EXECUTE** complexe = CLI **`codex`** (OpenAI), *Sign in with ChatGPT* (**Pro**), slug **`codex-extension`**, wrapper `bash scripts/codex-extension-execute.sh` (alias `npm run codex:complex`). Aucune clé dans le dépôt ; voir `agents/codex-extension-instructions.md`. Sorties : `missions/.../output_codex.json` + `reports/audit/GPT_SELF_AUDIT_*.md`. **Ancien slug** `codex-terminal` = connecteur **proxy+clé** (legacy) via `npm run codex:complex:proxy-legacy` / `codex.runner.mjs`.
>
> **Legacy (proxy+clé)** : toujours documenté dans les **§4–8** (runner HTTP, `CODEX_*`, streaming, retry). Réservé **urgence** / CI, pas au flux Pro principal.

**Handoff équipe technique (variables, JSON type, preuves sans clé) :** `docs/orchestration/CODEX_TECH_TEAM_HANDOFF_CONFIG.md`.

---

## 0. Symétrie « terminal d’abord » (économie d’abonnement / SSOT 2026-04-24)

C’est le **même principe** appliqué **deux fois** (implementation vs audit) :


| Phase                            | **PRIMARY (terminal, économie Cursor en évitant sub-agents)**                                                                             | **FALLBACK (Cursor, usage API de l’abonnement Cursor)**  | Trace                                                                               |
| -------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------- | ----------------------------------------------------------------------------------- |
| **EXECUTE complexe**             | `**codex-extension`** — `npm run codex:complex -- {TASK_ID}` (CLI `codex` + Pro) ; `reports/audit/GPT_SELF_AUDIT_*.md` généré                                                        | **Sub-agent** `foodking-complex-implementer`             | `EXECUTE_DELEGATION: …` + `FALLBACK_REASON:` si fallback                            |
| **AUDIT (après implementation)** | `**claude` en terminal** — `bash scripts/foodking-claude-orchestrate.sh` (`context` → `audit` ou `audit-brief`, abonnement **Anthropic**) | **Même rôle** dans la **session Cursor** (orchestrateur) | `AUDIT_CHANNEL: claude-terminal` **ou** `cursor-session` + `AUDIT_FALLBACK_REASON:` |


- **Règle d’or** : n’inverser l’ordre (Cursor en premier) **que** si le terminal a été **tenté** et **a échoué** (ou binaire indispo), et **le documenter** (`FALLBACK_*`).
- Vérification d’environnement : `bash scripts/verify-orchestration-boucle.sh` ; preuve d’extremité (optionnel) : `VERIFY_BILLING_FULL=1` (1× smoke `claude` + 1× `npm run codex:smoke` — consomme de minimes quotas).
- Détails procéduaux : `run-cycle.md` **Step 5 (AUDIT)** = PRIMARY terminal **obligatoire en intent** ; `AGENTS.md` § rôles modèles = même doctrine.

---

## 1. Pourquoi `codex-extension` (CLI `codex`) est PRIMAIRE (pas le sub-agent)

Validé le 2026-04-23 sur 4 missions complexes (POS Vue + Job Laravel) — `reports/execution/CODEX_REAL_COMPLEX_TEST_2026-04-23.md` :

- 4/4 sorties non vides, code production-ready, **tous** les invariants respectés (pricing SSOT, OrderStatus enum, branch_id, post-commit dispatch).
- Coût marginal sur API ≪ coût d'un slot premium Cursor pour de l'EXECUTE long.
- Streaming + retry 502/503/504/429 fiabilisent le proxy au-delà de 95 % en prod.

**Conséquence** : pour toute EXECUTE complexe, le chemin par défaut est `codex-extension` (ou proxy legacy en secours). Le sub-agent `foodking-complex-implementer` reste **fonctionnel** mais est un **fallback documenté**.

---

## 2. Routage (source de vérité)


| Situation                               | Chemin                                                                                                                                                                    | Trace dans le rapport                                                                             |
| --------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------- |
| **EXECUTE — tâche routée *routine***    | Sub-agent `**foodking-routine-implementer`** (Cursor Task)                                                                                                                | `EXECUTE_DELEGATION: foodking-routine-implementer`                                                |
| **EXECUTE — tâche complexe (PRIMARY)**  | `**codex-extension`** : 1) `missions/{TASK_ID}/input.json` + contextes optionnels ; 2) `npm run codex:complex -- {TASK_ID}` ; 3) appliquer `output_codex.json` + lire l’auto-audit ; 4) tracer | `EXECUTE_DELEGATION: codex-extension`                                                              |
| **EXECUTE — tâche complexe (FALLBACK)** | Sub-agent `**foodking-complex-implementer`** (Task tool) — **uniquement** si `codex` / `codex exec` indisponible (reprises) ou rejet du proxy legacy                   | `EXECUTE_DELEGATION: foodking-complex-implementer (codex-extension-fallback)` + `FALLBACK_REASON:` |


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
│           (b) complexe → codex-extension (PRIMARY) :                      │
│                missions/{TASK_ID}/input.json                                │
│                + graphiti_context.md (facts du Step 0)                      │
│                + plan_excerpt.md (PRIOR_CONTEXT, scope, hard constraints)   │
│                + execute_brief.md (3-5 lignes execute-context)              │
│                bash scripts/codex-extension-execute.sh {TASK_ID}          │
│                                                                             │
│                ┌──────────────────────────────────────────────────────┐     │
│                │  Terminal — `codex exec` (wrapper extension)         │     │
│                │  (legacy) codex.runner.mjs → proxy OpenAI            │     │
│                │  Retry 502/503/504/429 + empty content (≤8 attempts) │     │
│                └──────────────────────────────────────────────────────┘     │
│                                                                             │
│              Apply output_codex.json (touched files)                        │
│              Append to reports/post_execute_latest.log:                     │
│                  EXECUTE_DELEGATION: codex-extension                        │
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
│           Rapport: AUDIT_VERDICT: PASS | REWORK  (décision Claude)          │
│           PASS  →  CLOSE + (optional) add_memory Graphiti                    │
│           REWORK  →  replan + back Step 2 (souvent codex-extension) ;     │
│                      REMEDIATION_AUDIT_CYCLE ; 5e REWORK → HUMAN_GATE     │
│           En parallèle (triage) : zone critique  OU  3× même bug → GATE    │
└─────────────────────────────────────────────────────────────────────────────┘
```

Les règles `.cursor/rules/auto-remediation.mdc` (REWORK plafond 5, `bug_signature` 3) et `.cursor/rules/human-gates.mdc` (zones critiques) s'appliquent — `codex-extension` (ou sub-agent / proxy legacy).

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

Sortie : `missions/T-MA-TACHE-001/output_codex.json`. Avec `CODEX_WIRE=responses` (défaut), requêtes `POST` sur `{CODEX_API_BASE}/responses` ; le flux **stream** s’applique en priorité en mode **`chat`**. **Streaming** par défaut en mode `chat` (le proxy time-out en non-stream sur prompts longs ⇒ HTTP 504 Cloudflare). Override modèle : `CODEX_MODEL_COMPLEX=gpt-5.5-pro npm run codex:complex -- T-MA-TACHE-001` (ou `gpt-5.5` pour alléger le coût / la latence).

---

## 5. Choix de modèle (gpt-5.4 par défaut)

- **Défaut runner** : `CODEX_MODEL_COMPLEX=gpt-5.4` (overridable ; aligné profil type OpenAI / tokenclub).
- `**gpt-5.5-high**` / `**gpt-5.5**` / `**gpt-5.5-pro**` : variantes si le fournisseur les expose.
- **Option API** (si l’endpoint supporte `reasoning` sur `responses` ou sur `/chat/completions`) : `CODEX_REASONING_EFFORT=low|medium|high|xhigh|…` (le runner mappe `xhigh` → `high` pour le JSON).
- **Plafond de génération (sortie)** : par défaut, le runner envoie `max_completion_tokens` au **plafond technique géré ici (2M)** — l’API ne prend pas `Infinity` en JSON ; `CODEX_NO_DEFAULT_OUTPUT_BUDGET=1` retire ce champ (fournisseur seul). Surcharge : `CODEX_MAX_COMPLETION_TOKENS` / `CODEX_DEFAULT_MAX_COMPLETION_TOKENS` / `CODEX_MAX_TOKENS` (ancienne API). `CODEX_LOG_USAGE=1` : **stderr** = `usage` retourné par l’API. **Pourquoi on voit souvent ~2k–15k de *completion* par appel (ordre de grandeur « 10k ») :** ce n’est **pas** le runner qui « économise » des crédits : c’est en général la **tâche** (réponse suffisante + `stop`) et/ou un **plafond côté modèle / fournisseur** sur *une* complétion. **Graphiti** et l’orchestrateur FoodKing **réduisent surtout le *prompt* inutile** (mémoire, contexte) — pas un toit 10k sur la **sortie** de `chat/completions`. L’**usage affiché dans l’UI Cursor** (Claude, etc.) est un autre compteur (session / produit) que le **proxy** + clé `CODEX_API_KEY` (ou `OPENAI_API_KEY`).
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

Le proxy peut silencier l'`assistant` si l'`input` utilisateur est un JSON avec la clé top-level `**m*`*. Le runner **renomme** automatiquement `m` → `instruction` (désactiver : `CODEX_NO_NORMALIZE_M=1`).

---

## 8. Reprise / fiabilité

- **Générations longues (durer / ne pas tomber en 504)** : garder le **stream** (ne pas `CODEX_DISABLE_STREAM=1` sur grosses tâches). `CODEX_MAX_COMPLETION_TOKENS` élevé seulement si la fiche tâche le justifie. Sous **Node 20+**, le runner tente d’enregistrer le dispatcher `node:undici` avec `bodyTimeout` **0** (illimité entre morceaux SSE) — ajustable via `CODEX_UNDICI_*` (voir `agents/codex.env.example`). `CODEX_NO_ONESHOT_FALLBACK=1` : **désactiver** le repli vers `one-shot` après un échec stream (ce repli provoque souvent un **504** côté passerelle quand le prompt est massif). **Node 18** : le patch `undici` intégré est absent — préférer **Node 20+** si les flux s’interrompent après ~5 min d’inactivité entre morceaux.
- Reprises : 502, 503, 504, 429 + `200 + empty content`. Backoff exponentiel 2 s → 40 s, max 8 essais (`RETRY_MAX`).
- HTML 504/502 (Cloudflare) sont caught dans `doOneShot` et reformulés en erreur API ré-essayable.
- Stream → fallback one-shot automatique si stream tombe.

---

## 9. Kit portable (pour réutiliser ailleurs)

Voir `**dist/codex-portable/README.md`**. Les 2 fichiers indispensables sont `agents/codex.runner.mjs` et `agents/codex.prompt.txt` — tout le reste est glue projet.

---

## 10. Trace dans les rapports

Chaque cycle qui modifie du produit doit contenir, dans `reports/post_execute_latest.log` ET dans `REPORT_FILE` :

```
EXECUTE_DELEGATION: codex-extension
EXECUTE_MODEL: gpt-5.4 | gpt-5.5-high | gpt-5.5 | gpt-5.5-pro
AUDIT_CHANNEL: cursor-session | claude-terminal
```

Et en cas de fallback :

```
EXECUTE_DELEGATION: foodking-complex-implementer (codex-extension-fallback)
FALLBACK_REASON: codex exec / CLI indisponible (ou proxy legacy: HTTP 504 ×4, contenu vide ×3)
```

Sans cette trace, VALIDATE doit halt (`run-cycle.md` Step 2).