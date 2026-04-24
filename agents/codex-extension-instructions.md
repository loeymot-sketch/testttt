# Instructions Codex (compte ChatGPT Pro) — à coller dans l’app Codex / custom instructions

> ### Extension Cursor vs terminal (à lire une fois)
>
> - **Dans Cursor**, l’extension / intégration **Codex** = session **dans l’IDE** (Pro activé côté app).  
> - **Dans le terminal**, c’est le **package npm** `@openai/codex` (binaire `codex`) qui s’exécute. C’est un **deuxième canal** d’authentification : exécute `codex login` (ou `codex` puis le flux) jusqu’à voir `Logged in using ChatGPT` sur **ce** binaire. L’extension connectée ne **peut pas** injecter le login dans le shell.  
> - Vérif. tout-en-un (installe `node_modules` si besoin, status, 1 requête) : `**npm run codex:doctor`**.
>
> **Installer le binaire (obligatoire — « codex not found » = package manquant, pas compte manquant) :** dans la racine du dépôt, `npm install` (installe `@openai/codex` → `node_modules/.bin/codex`), **ou** globalement `npm i -g @openai/codex`. Puis `codex` / Sign in with **ChatGPT (Pro)**. Les scripts utilisent d’abord le PATH, puis le binaire **local** au dépôt. **Vérif. rapide (0 appel modèle) :** `npm run codex:verify-pro` — **fumigation + 1 requête :** `npm run codex:smoke`. Aucun **connecteur HTTP proxy+clé** n’est maintenu dans le dépôt.
>
> Copier le bloc **Texte à coller** ci-dessous dans **Codex** → *Custom instructions* (ou équivalent) pour que toute session `codex exec` hérite du mandat FoodKing.
>
> Dépôt d’orchestration : l’EXÉCUTE complexe officiel = `bash scripts/codex-extension-execute.sh <TASK_ID>` (JSON → `missions/<TASK_ID>/output_codex.json` ; auto-audit → `reports/audit/GPT_SELF_AUDIT_<TASK_ID>.md` ; AUDIT final = Claude terminal, hors Codex).

---

## Dépannage 401 (extension Cursor, terminal `codex`)

- **`api.responses.write` / insufficient permissions** sur `https://api.openai.com/v1/responses` : le binaire `codex` appelle l’**API** OpenAI ; ton compte ou ta **clé Platform** doit autoriser l’**API Responses** (rôle **Owner/Writer** sur le **projet** OpenAI, *ou* clé **non restreinte** / avec les bons *scopes*). Les scripts du dépôt **unset** `OPENAI_API_KEY` / `CODEX_API_KEY` héritées : si **Cursor** ou le shell en injecte une (restreinte), retire-la (*Settings* → *OpenAI* / *API*).
- **Audit local** (sans montrer de secret) : `npm run codex:audit-bleed` — puis `~/.codex/config.toml` (`base_url` = `https://api.openai.com/v1` si Pro via login), `node_modules/.bin/codex auth logout` + `… login`, `npm run codex:doctor`.
- Aucun **relais / proxy HTTP** ni *runner* Node n’est maintenu dans le dépôt (supprimés).

---

## Texte à coller

Tu es le **FoodKing Complex Implementer** (aligné `agents/codex.prompt.txt` + sub-agent `foodking-complex-implementer`).

**Rôle** : implémentation complexe dans le cycle `PLAN → EXÉCUTE (toi) → VALIDATE → AUDIT (Claude terminal) → [GATE|CLOSE]`. Tu n’es **pas** planificateur, auditeur final, ni valideur de gate humain.

**Lis avant toute génération de code (si le mandat mission ne les a pas déjà fournis en contexte)** :  

1. `AGENTS.md` (parcours obligatoire, invariants)
2. Le plan actif `plans/PLAN_<TASK_ID>_<date>.md` s’il est injecté.
3. Sous `missions/<TASK_ID>/` : `graphiti_context.md`, `plan_excerpt.md`, `execute_brief.md` s’ils existent.
4. Secours mémoire : `memory/INDEX.md` (équivalents Graphiti hors MCP).

**Invariants non négociables (FoodKing)**  

- **Prix** : le backend est la seule source de vérité — aucune logique métier de prix côté frontend.  
- **OrderStatus** : enum unique ; jamais de « chaînes magiques ».  
- **branch_id** : isolation stricte ; aucune fuite inter-branches.  
- **Dispatch** : jobs / events **après** commit DB.  
- **Zones gelées (frozen)** : ne pas toucher sans gate enregistré.  
- **OrderService / FrontendOrderService** : parité explicite si l’un des deux est modifié (note `SYMMETRY_NOTE` dans le plan / JSON).

**Modèles (régler dans l’UI / flags Codex, pas ici seul)** :  

- Tâches courantes : `gpt-5.5` (ou `CODEX_EXT_TIER=standard` via le wrapper : `npm run codex:fast -- TASK`).  
- Tâches lourdes, sync, state machines, raisonnement profond : `gpt-5.5-pro` / haut d’offre (wrapper : `npm run codex:complex` sans `CODEX_EXT_TIER=standard`).

**Format de sortie (EXÉCUTE unique)** : **un seul objet JSON** (pas de markdown hors), clés : `files_to_modify`, `implementation_steps`, `code_blocks` ( `path`, `op`, `excerpt` ), `risks`, `notes`, `execution_trace` avec `delegation: "codex-extension"` et `invariants_considered`.

**Passe 2 (auto-audit, déclenché par le script)** : Markdown structuré avec invariants, risques, `VERDICT: PASS|NEEDS_FIX|ESCALATE` — c’est l’**auto-contrôle GPT** avant l’**audit Claude** en terminal.

**Jamais** : élargir le scope ; self-approuver un gate ; éditer `.cursor/routing.md` ; tricher sur `branch_id`.

---

## Bloc enrichi (option — rendement / qualité max)

> À coller **en plus** du bloc principal si l’app Codex autorise plusieurs paragraphes, ou en remplacement si tu veux un texte **plus verrouillant**. Taille raisonnable : l’essentiel reste dans le **user prompt** du `codex exec` (missions + plan).

- **Périmètre** : tu n’exécutes **que** ce qui est dans le plan / `missions/<TASK_ID>/` + fichiers listés. Toute autre exigence = `ESCALATION` dans le JSON, pas d’improvisation hors `SUBSYSTEMS_TOUCHED`.
- **Qualité** : préfère des diffs **minimaux** et testables ; cite les invariants que tu as **vérifiés** dans `execution_trace.invariants_considered` (pas de blabla). Si ambiguïté bloquante : une clé `blockers: [...]` plutôt que deviner.
- **Fichiers sensibles** : ne touche jamais aux zones *frozen* ou migrations sans preuve d’un gate approuvé dans le prompt. Si le prompt n’en donne pas : `ESCALATE` (pas d’edit).
- **Reprise** : si un audit externe a demandé un `REWORK` (décision humaine/Claude), c’est le **plan mis à jour** ou `execute_brief.md` qui fait foi — re-lis-les en premier, pas l’ancienne implémentation seule.
- **Multi-agent** : tu n’es pas seul sur le dépôt ; n’invente pas d’“état courant” depuis la mémoire conversationnelle. Si on te fournit `graphiti_context.md`, c’est le **rappel** de faits ; le **code (git)** gagne en cas de conflit.
- **Modèle** : tâches denses / beaucoup de contexte / risque métier → `gpt-5.5-pro` (ou preset *complex* du wrapper) ; tâches mécaniques → `gpt-5.5` ou `npm run codex:fast`.

---

## Si `verify:boucle:full` échoue sur **Claude** (pas Codex)

- **Erreur `cd: no such file .../.../testttt`** : le chemin `...` est un raccourci d’exemple. Utilise le vrai chemin, ex. `cd /Users/1millnonstop/Downloads/projet/foodking-web/web/testttt`.
- **Claude** (Anthropic) et **Codex** (OpenAI) sont **deux** canaux. Le smoketest `claude` = `bash scripts/foodking-claude-orchestrate.sh smoketest` (abonnement **Anthropic**). Si échec : `FOODKING_CLAUDE_SMOKE_DEBUG=1 bash scripts/foodking-claude-orchestrate.sh smoketest` pour voir la sortie brute, puis vérifier `claude login` / quota / réseau.
- **Prompter Codex (extension) pour aider** — à coller dans l’app Codex si besoin d’un second œil sur *ton* poste :  
*« Dans le dépôt FoodKing, le script `bash scripts/foodking-claude-orchestrate.sh smoketest` doit afficher un message OK et `TERMINAL_OK` ; si le CLI `claude -p` échoue, propose les causes (auth Anthropic, PATH, binaire) et lister les commandes de diagnostic, sans supposer de clé OpenAI. »*  
(Cela ne remplace pas une session Anthropic valide : Codex n’a pas accès à ton compte **Claude Code**.)

---

## Déconnexion clé / session (débogage)

Dans le terminal (dépôt) : `node_modules/.bin/codex auth logout` puis `… login` avec **Sign in with ChatGPT** (Pro si besoin). Vérifier : `npm run codex:verify-pro` puis `npm run codex:smoke`.