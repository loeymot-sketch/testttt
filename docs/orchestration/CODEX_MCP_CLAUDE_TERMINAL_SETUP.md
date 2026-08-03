# Configurer **Codex (CLI) terminal** : MCP **Graphiti** + appel **Claude (audit)**

Objectif : quand **Codex** tourne en **mode terminal** (`codex` / `codex exec` depuis la racine du dépôt), il peut :

1. **Utiliser** le serveur MCP **Graphiti** (même stack que Cursor, outils `search_memory_facts` / `add_memory`, `group_id=foodking` si exposé).
2. **Déléguer** un passage « orchestrateur / audit » à **Claude** en exécutant le **même** wrapper que le dépôt (`foodking-claude-orchestrate.sh`).

**Ce fichier ne met aucun secret** dans Git. Les clés vont dans `~/.cursor/mcp-graphiti.env` (voir `.cursor/mcp/mcp-graphiti.env.example`).

---

## 1) Pré-requis Graphiti (déjà valides pour Cursor)

- Fichier `**~/.cursor/mcp-graphiti.env`** présent, rempli (modèle : `testttt/.cursor/mcp/mcp-graphiti.env.example` — `chmod 600` recommandé).
- Le wrapper `testttt/.cursor/mcp/start-graphiti-mcp.sh` démarre (lit ce fichier) ; diagnostic : `.cursor/mcp/GRAPHITI_TROUBLESHOOTING.md`.

> Si l’environnement est KO, l’MCP ne démarrera pas côté Codex non plus.

---

## 2) Enregistrer **Graphiti** dans le **config Codex** (MCP intégré)

Le CLI a une commande `codex mcp` (SSOT **utilisateur** : `~/.codex/config.toml`, **pas** dans le dépôt).

**Remplace** `<RACINE>` par le chemin absolu vers *ce* dépôt (là où vit `.cursor/mcp/`).

```bash
codex mcp add graphiti -- bash <RACINE>/.cursor/mcp/start-graphiti-mcp.sh
```

Vérification :

```bash
codex mcp list
```

Attendu : une entrée **graphiti** avec la commande ci-dessus.  
(Équivalent possible : copier le bloc manuellement dans `~/.codex/config.toml` seulement si l’`add` échoue — lire l’aide de ta version : `codex mcp add --help`.)

**Lancement d’une session** avec le même répertoire de travail (pour que le repo et les outils aient le bon contexte) :

```bash
cd <RACINE>
codex -C <RACINE>
# ou
codex exec "..." -C <RACINE> --add-dir <RACINE>
```

**Note** : les **MCPs** enregistrés par `codex mcp` sont en général **chargés** en session interactive / `exec` (comportement du CLI 2025–2026). Si ton build ne charge pas l’MCP, vérifier la version : `codex --version` et `codex mcp help`.

---

## 3) Donner à **Codex** le droit d’**exécuter des shell** (dont Claude)

L’agent peut proposer `bash` ; le **sandbox** bloque par défaut les commandes « dangereuses ».

- Pour **travail de dépôt seulement** : ex. `codex exec -s workspace-write "…"`.
- Pour lancer le **binaire** `claude` + le wrapper (et évent. `/usr/local` si besoin) : souvent `danger-full-access` ou l’**approbation** moins stricte — *à ton risque* sur la machine, **jamais** en CI public sans resserrer.

Exemple **non** bypass (recommandé pour commencer) :

```bash
codex exec -s workspace-write "..." -C <RACINE> --add-dir <RACINE>
```

Exemple **friction min** (poste de dev local uniquement) :

```bash
codex exec --full-auto "..." -C <RACINE> --add-dir <RACINE>
```

*(Lire l’aide locale : `codex exec -h`.)*

**Instruction à coller dans** « Custom instructions **Codex** » (ou en tête du prompt) :

```text
FoodKing — règles outillage terminal :
(1) Tu peux lire/éditer le repo. Pour la mémoire de projet, utilise les outils **MCP** si le serveur **graphiti** est chargé : `search_memory_facts` (group_id foodking) en premier sur toute tâche non triviale, puis le code.
(2) Quand l’orchestrateur (humain) exige un **audit / synthèse Claude** pour la même tâche, lance **exactement** (depuis la racine du dépôt) :
   bash scripts/codex-invoke-claude-audit.sh "MÊME PROMPT QUE CELUI DEMANDÉ, EN FRANÇAIS"
   Puis intègre la sortie stdout (ou le fichier généré par tee) dans ton raisonnement.
(3) Ne mets jamais de clé API dans le prompt ; NEO4J / clés = uniquement côté env local (`~/.cursor/mcp-graphiti.env`).
```

---

## 4) **Pont** « Codex → **Claude** » (script du dépôt)

Pour éviter que l’agent invente un chemin vers `claude` :

- Script : `scripts/codex-invoke-claude-audit.sh`  
- Il enchaîne sur : `bash scripts/foodking-claude-orchestrate.sh audit "…"` (défaut modèle : **Opus 4.7** + `effort high` — `FOODKING_CLAUDE_TERMINAL_`*).

**Exemple** (toiture sortie) :

```bash
cd <RACINE>
bash scripts/codex-invoke-claude-audit.sh "Lis reports/audit/MA_NOTE.md. Orchestre une synthèse P0/P1 (AGENTS.md, invariants) en français." 2>&1 | tee reports/audit/MA_SORTIE_CLAUDE.md
```

L’**humain** ou **Codex** (si shell autorisé) exécute cette ligne.

**Ce n’est pas** un seul cerveau hybride : c’est **deux** appels **séquentiels** (Codex puis **ou** Claude) avec **fichier partagé** (tee / markdown) — c’est voulu pour l’**audit** et la **facturation** (OpenAI vs Anthropic).

---

## 5) Personnalisation **Codex** côté machine (`~/.codex/config.toml`)

- Toute la logique d’orchestrateur et de dépôt reste dans `AGENTS.md` et les scripts.  
- Tu peux y définir **profil** (modèle, sandbox par défaut) : `codex` lit ce fichier.  
- **Ne** commit **pas** ce fichier ici (hors depôt) ; voir `docs/orchestration/EXPORT_CONFIG_CODEX_GPT_API_ET_AUDIT_PERSISTANCE.md`.

---

## 6) Récap une ligne


| Besoin                          | Action                                                                                                                        |
| ------------------------------- | ----------------------------------------------------------------------------------------------------------------------------- |
| **Graphiti dans Codex**         | `codex mcp add graphiti -- bash <RACINE>/.cursor/mcp/start-graphiti-mcp.sh` + `mcp-graphiti.env` valide                       |
| **Claude depuis le flux Codex** | Lancer `bash scripts/codex-invoke-claude-audit.sh "…"` (sandbox assez permissif pour `bash` + binaire `claude`)               |
| **Secrets**                     | Uniquement `~/.cursor/mcp-graphiti.env` (copie depuis `.cursor/mcp/mcp-graphiti.env.example`) — **jamais** le coller dans Git |


Lecture complémentaire : [CHALLENGE_CODEX_CLAUDE_TERMINAL_PLAYBOOK.md](CHALLENGE_CODEX_CLAUDE_TERMINAL_PLAYBOOK.md) (défi multi-tours).

---

*SSOT 2026 — ne pas supprimer le wrapper `start-graphiti-mcp.sh` : les deux IDE (Cursor + Codex) s’y alignent.*