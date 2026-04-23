# Export — configuration API Codex (GPT) + audit persistance multi-agents (double confirmation)

**Fichier unique** demandé : (1) tout ce qu’il faut pour recréer / réutiliser la **configuration OpenAI-compatible** (y compris OpenClaw, LiteLLM, n’importe quel client `base_url` + clé) ; (2) **rassurance procédurale** : qu’est-ce qui persiste entre **nouvelles discussions Cursor** / autres outils, et vérification de boucle (incl. passage **Claude terminal** 2026-04-23).

> **Aucun secret** dans ce document. Copier `agents/codex.env.example` → `.env.codex` (ignoré par git) et y coller `CODEX_API_KEY`.

---

# Partie A — Configuration exportable (identique partout : FoodKing, OpenClaw, autre)

## A.1 Principe

- **Protocole** : OpenAI API v1 (compatible) — en pratique **`POST {BASE}/chat/completions`**.
- **BASE** : URL de ton fournisseur (souvent `https://…/v1` **sans** slash final inutile ; le runner normalise le trailing slash).
- **Auth** : header `Authorization: Bearer <CODEX_API_KEY>`.
- **Notre moteur applicatif** : un script Node **sans dépendance** — `agents/codex.runner.mjs` (copie miroir dans `dist/codex-portable/codex.runner.mjs`). Il n’est **pas** le CLI ChatGPT « Codex » d’OpenAI ; c’est le **connecteur FoodKing** nommé en interne `codex-terminal`.

## A.2 Variables d’environnement (fichier `.env.codex` — non versionné)

Définition de référence : `agents/codex.env.example` + copie longue : `dist/codex-portable/.env.codex.example`.

| Variable | Obligatoire | Rôle |
|----------|-------------|------|
| `CODEX_API_BASE` | **Oui** | Ex. `https://subtp7eu3nc8.tokenclub.top/v1` (fournisseur/proxy) |
| `CODEX_API_KEY` | **Oui** | Clé `sk-…` côté fournisseur (jamais dans le git — `.gitignore` contient `.env.codex`) |
| `CODEX_MODEL_COMPLEX` | Non (défaut `gpt-5.4`) | Sur un proxy **tiers**, le *nom* du modèle est **celui annoncé par le proxy** (souvent pas les noms publics `gpt-4o` de l’API OpenAI directe) |
| `CODEX_WIRE` | Non (défaut `chat`) | `chat` → `/chat/completions` ; `responses` → `/responses` (si le proxy l’expose) |
| `CODEX_RAW_PROMPT` | Non | `1` = envoi brut de `input.json` sans le template `codex.prompt.txt` |
| `CODEX_DISABLE_STREAM` | Non | `1` = requêtes non-stream (souvent **504** sur prompts longs derrière Cloudflare — **déconseillé** par défaut) |
| `CODEX_NO_NORMALIZE_M` | Non | `1` = désactive le renommage de la clé JSON top-level `m` → `instruction` (certaines proxies vident le contenu sinon) |
| `CODEX_AUX_CONTEXT_FILES` | Non | CSV de fichiers sous `missions/<TASK_ID>/` fusionnés (défaut : graphiti, plan, brief, snapshot) |
| `RETRY_MAX` / `SLEEP_BASE_MS` | Non | Repli proxy : 502/503/504/429 + contenu vide |

**Chargement** : le runner charge `.env` puis **`.env.codex`** (sans écrraser des variables déjà posées par le shell).

## A.3 Corps de requête (équivalent `curl` pour OpenClaw / autre outil)

Mode **one-shot** (le runner utilise par défaut **stream** puis le concatène) :

```http
POST {CODEX_API_BASE}/chat/completions
Authorization: Bearer {CODEX_API_KEY}
Content-Type: application/json
User-Agent: FoodKing-codex-runner/2.2 (Node)   # (optionnel)
```

```json
{
  "model": "gpt-5.4",
  "messages": [
    { "role": "user", "content": "<prompt final — string>" }
  ],
  "stream": true
}
```

Pour un outil type **OpenClaw** (ou n’importe quelle intégration « OpenAI-compatible ») : renseigne exactement `base_url` = `CODEX_API_BASE`, `api_key` = `CODEX_API_KEY`, `default_model` = `CODEX_MODEL_COMPLEX`. Aucun autre paramètre n’est requis côté FoodKing pour l’**identité** du moteur ; le **cahier des charges** (invariants) est porté par `agents/codex.prompt.txt` côté runner, pas par l’API.

## A.4 Fichiers « 2 cœurs » portables (autre dépôt)

| Fichier | Rôle |
|--------|------|
| `agents/codex.runner.mjs` | Transport, fusion contexte mission, normalisation `m`, retry, stream |
| `agents/codex.prompt.txt` | Identité *FoodKing API Complex Implementer* + contraintes + format JSON (si pas `RAW`) |

Copie telle quelle : voir `dist/codex-portable/README.md`.

## A.5 Commandes npm (ce dépôt)

- `npm run codex:complex -- <TASK_ID>` : mission `missions/<TASK_ID>/input.json` → `output_codex.json`
- `npm run codex:smoke` : santé proxy
- `npm run codex:prepare -- <TASK_ID>` : ossature mission

## A.6 Comment c’était « fait » techniquement (rappel court)

1. **Sélection du wire** : `doStream()` / `doOneShot()` / `doResponses()` selon `CODEX_WIRE` et `CODEX_DISABLE_STREAM`.
2. **Extraction** : texte assistant depuis `choices[0].message.content` (string ou tableaux d’objets outils).
3. **Fiabilisation proxy** : retry sur 502/503/504/429, et sur 200 + contenu vide.
4. **Détails** : relecture de `docs/orchestration/CODEX_API_DELEGATION.md`.

---

# Partie B — Persistance des règles, nouvelle discussion Cursor, « ça marche toujours ? »

## B.1 Qu’est-ce qui est **dans le dépôt** (reprend toute l’intelligence de procédure) — **OUI, ça se réapplique**

Ces éléments sont des **fichiers versionnés** : toute personne / machine qui clone le repo a **les mêmes règles** tant que l’on ouvre le **dossier du projet** dans Cursor (ou un IDE qui respecte le dossier) :

| Artefact | Effet |
|----------|--------|
| `AGENTS.md` | Contrat : phases, délégation `codex-terminal`, invariants, MCP, terminal `claude` |
| `.cursor/routing.md` | Qui fait quoi par phase (Claude / GPT-5.4 / Composer) |
| `.cursor/commands/run-cycle.md` | Déroulé du cycle + Graphiti + trace `EXECUTE_DELEGATION` |
| `.cursor/rules/*.mdc` | Règles (dont `global.mdc`, invariants) — *always-apply* selon globs |
| `.cursor/agents/*.md` | Rôles des sub-agents Cursor (dont fallback `foodking-complex-implementer`) |
| `docs/orchestration/CODEX_API_DELEGATION.md` | Nom officiel, boucle, fallbacks, traces |
| `plans/`, `memory/`, `missions/` | Plans, JSONL, missions — contexte de travail |

**Conclusion** : une **nouvelle conversation** dans **un nouveau chat Cursor** sur le **même repo** hérite à nouveau des **mêmes instructions déposées** — pas besoin de « mémoriser » le fil précédent pour que *les règles du dépôt* s’appliquent. Ce qui n’est **pas** repris, c’est le **fil de la conversation** (décisions orales, hypothèses non écrites).

## B.2 Ce qui **n’est pas** dans le chat (système véracité / agentique en multi-tâches)

| Sujet | Comportement |
|-------|----------------|
| **Mémoire de chat** | N’inclut pas l’historique d’un autre onglet ; reprise = lire `ACTIVE_CYCLE.md`, le plan, `REPORT_FILE`, `memory/INDEX.md` et Graphiti/JSONL |
| **MCP Graphiti** | Règles = dans `AGENTS.md` + `.cursor/mcp/`, mais **l’enregistrement** MCP = sur la machine (ex. `~/.cursor/mcp.json`) : **pas** dans le git seul — à refaire sur un autre poste (voir C.2) |
| **Clés** | `.env.codex` n’est **pas** commité (`.gitignore` : `.env.codex`) : chaque clone exige de **recréer** le fichier (copie depuis `agents/codex.env.example`) |
| **Claude / codex en terminal** | Binaires + PATH = configuration OS ; reproductible, pas implicite au dépôt |

## B.3 Phases multi-agents (défis / tâches) — un schéma unique

1. **PLAN** (Claude dans Cursor, rôle plan/orchestrateur) → plan `plans/PLAN_*_*.md` + `ACTIVE_CYCLE.md`  
2. **EXECUTE — complexe** (PRIMARY) : **`codex-terminal`** = `npm run codex:complex` + appliquer `output_codex.json`  
3. **EXECUTE — routine** : `foodking-routine-implementer`  
4. **FALLBACK** explicite : `foodking-complex-implementer` si le proxy ne répond pas (tracé)  
5. **VALIDATE** (tests, log)  
6. **AUDIT** (Claude session ou `claude` terminal)  
7. **CLOSE** ou remédiation / gate  

Si **Graphiti n’est pas chargé** : `run-cycle` Step 0.5 = une ligne *non bloquante* ; on continue en s’appuyant sur `memory/INDEX.md` + JSONL si besoin (`AGENTS.md`).

## B.4 « Un autre IDE / OpenClaw » (sans les `.mdc` Cursor)

- Les **fichiers** `AGENTS.md` et `docs/…` restent la **référence** si tu **ouvres le dépôt** ailleurs.
- Les **règles `alwaysApply`** (`.mdc`) sont **propriétaires Cursor** : en dehors de Cursor, il n’y a pas d’injection magique — il faut **lier manuellement** l’outillage (p. ex. règles user, instructions système, copier un extrait de `AGENTS.md` dans l’app).
- L’**API** (`CODEX_API_BASE` + clé) reste **identique** pour n’importe quel client OpenAI-compatible.

---

# Partie C — Vérification externe : appel **Claude Code** (terminal) — 2026-04-23

**Commande** (abonnement Anthropic / binaire `claude` sur le PATH) :

`bash scripts/foodking-claude-orchestrate.sh audit "<prompt d’audit multi-agents>"`

Résultat (extrait synthétisé; intégralité : voir exécution terminal du même jour) :

- **Lecture des règles** : `.mdc` chargés par Cursor ; `ACTIVE_CYCLE` / `plans` / `reports` sur disque.
- **Ce qui requiert configuration humaine** : `.env.codex` (codex-terminal), MCP Graphiti + Neo4j, `claude` en PATH, évent. Redis / Pusher / secrets prod, CI secrets, ingestion Graphiti.
- **Risques** : proxy 504 / dépendance proxy tiers, oubli `EXECUTE_DELEGATION`, possible dérive JSONL↔Neo4j si pas d’ingest, listes *frozen* hook vs docs, noms de modèles côté proxy.
- **Verdict procédurale** (Claude) : `AUDIT_DOUBLE_CONFIRM: **CONDITIONAL**` — *robuste sur le fond* ; *conditionnel* sur setup humain (clés, Graphiti, robustesse des hooks) et sur la **continuité du service proxy**.

> **Note d’honnêteté** : certaines métriques citées ad hoc dans une sortie longue (ex. « 707 tests ») peuvent ne pas correspondre **exactement** au dépôt au moment T — vérifier par `npx vitest run` / `phpunit` si besoin de chiffre contractuel.

**Double confirmation (poste assistant)** : aligné avec l’intention du dépôt — règles **persistantes = git** ; exécution `codex-terminal` **conditionnelle** à un `.env.codex` valide sur la machine ; cohérence accrue en utilisant le **plan + missions + traces** pour chaque tâche, indépendamment d’une nouvelle discussion.

---

# Partie D — Check-list avant d’avancer sereinement (1 page)

| # | Fait | Détail |
|---|------|--------|
| 1 | Clone / ouverture du **bon dossier** racine | Évite l’oubli de `AGENTS.md` / `.cursor/` |
| 2 | `.env.codex` (copié d’`agents/codex.env.example`) + `npm run codex:smoke` = exit 0 | Prouve que l’**API** répond |
| 3 | `~/.cursor/mcp.json` avec Graphiti (si besoin de MCP) + Neo4j | Optionnel planifié, pas always |
| 4 | Chaque cycle complexe : dossier `missions/<TASK_ID>/` + trace `EXECUTE_DELEGATION` | Auditabilité |
| 5 | Lire `AGENTS.md` + `docs/orchestration/CODEX_API_DELEGATION.md` après un long hiatus | *Handoff* humain |

**Réponse directe** : *Oui* — en **ouvrant ce repo** dans **Cursor (ou ailleurs avec relecture de la doc)**, le **jeu de règles écrites** est le même ; *non* en ce sens : une **nouvelle conversation** ne transporte **pas** l’historique de la conversation précédente, mais le **dépôt** (et `ACTIVE_CYCLE` / plan / rapports) reste la file d’attente fiable de vérité.

---

**Fichiers liés** : `AGENTS.md`, `docs/orchestration/CODEX_API_DELEGATION.md`, `dist/codex-portable/README.md`, `agents/codex.env.example`  
**Audit terminal** : `bash scripts/foodking-claude-orchestrate.sh audit` (génère une analyse; ce document en résume les conclusions utiles)  
**Date** : 2026-04-23
