# Graphiti MCP — dépannage (FoodKing)

## Où mettre les clés (depuis 2026-04)

Les secrets **ne sont plus** dans `~/.cursor/mcp.json`. Ils vivent dans **`~/.cursor/mcp-graphiti.env`** (une ligne `KEY=value` par variable, `chmod 600`). Modèle vide : `.cursor/mcp/mcp-graphiti.env.example` dans le dépôt.

## Symptôme : le serveur MCP « graphiti » ne démarre pas ou reste rouge dans Cursor

### 0. `MOONSHOT_API_BASE` (Chine vs international)

LiteLLM utilise l’URL Moonshot / Kimi définie par la variable d’environnement **`MOONSHOT_API_BASE`** (voir [doc Moonshot LiteLLM](https://docs.litellm.ai/docs/providers/moonshot)) :

- **International (Kimi / plateforme .ai)** : `https://api.moonshot.ai/v1` — valeur par défaut dans `~/.cursor/mcp.json` et dans les scripts.
- **Chine** : `https://api.moonshot.cn/v1` — remplace la valeur dans `mcpServers.graphiti.env` si ton compte est uniquement sur la zone CN.

### 1. LiteLLM répond mais `healthy_count = 0`

Le proxy LiteLLM (`http://127.0.0.1:4000/health`) renvoie **HTTP 200** même lorsque **tous** les modèles upstream sont en erreur (ex. Moonshot **401 Invalid Authentication**). Les scripts `start-graphiti-mcp.sh` et `start-litellm-bg.sh` attendent désormais **au moins un modèle sain** avant de lancer Graphiti.

**Correctif :**

1. Vérifie ta clé sur la console Moonshot : [platform.moonshot.cn](https://platform.moonshot.cn/).
2. Mets à jour `MOONSHOT_API_KEY` dans `~/.cursor/mcp.json` → `mcpServers.graphiti.env`.
3. Arrête un vieux proxy qui tournerait avec une mauvaise clé :

   ```bash
   bash .cursor/mcp/stop-litellm.sh
   ```

4. (Optionnel) Relance un proxy propre avec une clé valide dans l’environnement :

   ```bash
   export MOONSHOT_API_KEY='ta-clé-valide'
   bash .cursor/mcp/start-litellm-bg.sh
   ```

5. Dans Cursor : **Command Palette** → **MCP: Restart Servers** (ou recharger la fenêtre).

### 2. `Invalid value for '--debug': 'release' is not a valid boolean`

L’IDE ou la toolchain Rust peut exporter **`DEBUG=release`**. Le CLI LiteLLM (Click) interprète **`DEBUG`** comme l’option **`--debug`** et quitte immédiatement.

**Correctif :** déjà appliqué dans `start-graphiti-mcp.sh` et `start-litellm-bg.sh` (`unset DEBUG` + `env -u DEBUG` au lancement de LiteLLM). Recharge le MCP Graphiti dans Cursor.

### 3. `MOONSHOT_API_KEY vide`

Cursor ne transmet pas la variable : ajoute-la explicitement dans `~/.cursor/mcp.json` sous `graphiti.env`, ou exporte-la avant de lancer `start-litellm-bg.sh` depuis un terminal.

### 4. `uv` ou `litellm` introuvable

Le script prépare le `PATH` (Homebrew, `~/.local/bin`). Installe si besoin :

```bash
pip install 'litellm[proxy]' fastembed
# uv : https://docs.astral.sh/uv/getting-started/installation/
```

### 5. `main.py` introuvable

Clone Graphiti attendu : `~/graphiti` avec `mcp_server/main.py` (voir `AGENTS.md`).

### 6. Neo4j

Les variables `NEO4J_URI`, `NEO4J_USERNAME`, `NEO4J_PASSWORD`, `NEO4J_DATABASE` doivent rester cohérentes avec ton instance (Aura ou locale).

### 7. LiteLLM 1.83+ : plus de route `fastembed/...`

Les entrées `fastembed/BAAI/...` ne sont plus enregistrées par le routeur LiteLLM récent (`LLM Provider NOT provided` dans les logs). Les alias `text-embedding-*` passent par **OpenAI** (`OPENAI_EMBEDDING_API_KEY` + `https://api.openai.com/v1`) ; le chat Kimi reste sur **Moonshot** (`MOONSHOT_API_KEY`).

### 8. Embeddings OpenAI (recommandé avec Kimi / Moonshot)

Les comptes Kimi ne fournissent souvent **pas** `/v1/embeddings` au même titre qu’OpenAI. Configure **`OPENAI_EMBEDDING_API_KEY`** (clé projet `sk-proj-…`) dans `~/.cursor/mcp.json` : LiteLLM route alors `text-embedding-3-small` vers **`https://api.openai.com/v1`**, tandis que le **chat** reste sur Moonshot via `MOONSHOT_API_KEY`.

**Piège :** Cursor passe aussi `OPENAI_BASE_URL` / `OPENAI_API_URL` (localhost) pour Graphiti. Si le processus **LiteLLM** les hérite, le SDK OpenAI peut ignorer le `api_base` du YAML et rappeler le proxy → erreur type *« The API you are accessing is not open »*. Les scripts `start-graphiti-mcp.sh` et `start-litellm-bg.sh` enlèvent ces variables **uniquement** pour le sous-processus LiteLLM (Graphiti les garde).

### 9. Erreur OpenAI : `Incorrect API key provided: litellm-*roxy` (embeddings)

Le serveur Graphiti lit **`OPENAI_API_URL`** pour le LLM **et** l’embedder (`mcp_server/config/config.yaml`). La variable `OPENAI_BASE_URL` **seule** ne suffit pas : sans `OPENAI_API_URL`, les embeddings partent vers `https://api.openai.com/v1` avec la clé factice `litellm-proxy`.

**Correctif :** dans `~/.cursor/mcp.json` sous `graphiti.env`, ajoute par exemple :

```json
"OPENAI_API_URL": "http://127.0.0.1:4000/v1"
```

Le script `start-graphiti-mcp.sh` dérive désormais `OPENAI_API_URL` depuis `OPENAI_BASE_URL` si elle est absente.

## Vérification manuelle (sans Cursor)

```bash
curl -s http://127.0.0.1:4000/health | python3 -c "import json,sys; d=json.load(sys.stdin); print('healthy_count=', d.get('healthy_count'), 'unhealthy=', len(d.get('unhealthy_endpoints') or []))"
```

`healthy_count` doit être **> 0** avant que Graphiti MCP soit utilisable.

---

## 10. Matrice API — quoi utilise quoi (sans te forcer à deviner)

Graphiti = **trois briques indépendantes**. Ce n’est **pas** « tout OpenAI ou rien ».

| Brique | Rôle | API / compte typique aujourd’hui (FoodKing) | Obligatoire ? |
|--------|------|-----------------------------------------------|----------------|
| **Neo4j** | Stockage graphe + index vectoriel | Aura / self-host (`NEO4J_*`) | **Oui** |
| **LLM (chat)** | Extraction d’entités, raisonnement sur les épisodes | Souvent **Moonshot/Kimi** via LiteLLM (`MOONSHOT_API_KEY`) **ou** endpoint OpenAI-compatible (ex. Willow) via `OPENAI_API_URL` + clé associée | **Oui** |
| **Embedder** | Vecteurs pour recherche sémantique (`add_memory`, `search_*`) | Aujourd’hui : **OpenAI** `text-embedding-3-small` via `OPENAI_EMBEDDING_API_KEY` (routé par LiteLLM vers `api.openai.com`) | **Oui** — mais le **fournisseur** peut changer |

**Ce que LiteLLM fait chez toi** (`litellm_config.yaml`) :

- **Chat** : alias type `gpt-4o-mini` → **Moonshot** (`moonshot/...`) — OK si `MOONSHOT_API_KEY` est valide.
- **Embeddings** : alias `text-embedding-3-small` → **OpenAI** — chez toi **429 `insufficient_quota`** sur la clé embeddings : la mémoire vectorielle **casse** même si le chat marche.

**Ce que Kimi / Grok / Anthropic ne remplacent pas « gratuitement »** :

- **Moonshot (doc LiteLLM)** : opération supportée typiquement **`/chat/completions`** — pas une piste embeddings fiable comme OpenAI.
- **Grok (xAI)** : chat / reasoning — **pas d’API embeddings** équivalente OpenAI dans l’offre habituelle.
- **Anthropic** : messages / tools — **pas** d’endpoint `/v1/embeddings` style OpenAI pour brancher Graphiti tel quel.

Donc tu n’es **pas** obligé de mettre **tout** sur OpenAI : seul l’**embedder** a besoin d’un service qui expose de **vrais vecteurs** numériques stables. Aujourd’hui ta stack est calée sur **OpenAI pour ça** — d’où la sensation « on m’oblige à OpenAI ».

### Piste A — La plus simple (garder la config actuelle)

1. Recharge le **quota** du compte OpenAI lié à **`OPENAI_EMBEDDING_API_KEY`** (clé **projet** dédiée aux embeddings, pas besoin du gros modèle chat).
2. `bash .cursor/mcp/stop-litellm.sh` puis recharge le MCP Graphiti (ou relance Cursor).
3. Vérifie : `curl -s http://127.0.0.1:4000/health | python3 -c "import json,sys; d=json.load(sys.stdin); print('unhealthy', len(d.get('unhealthy_endpoints') or []))"` — les lignes `openai/text-embedding-3-small` ne doivent plus être en erreur.

### Piste B — Sans payer OpenAI (changement d’architecture)

1. Choisir un **embedder supporté nativement par Graphiti** : **`voyage`** ou **`gemini`** (voir `graphiti/mcp_server/config/config.yaml` : `embedder.provider` peut être `openai` | `azure_openai` | `gemini` | `voyage`).
2. **LiteLLM** : ajouter une entrée `model_list` avec `model_info.mode: embedding` pour ce fournisseur (ex. `voyage/voyage-3-lite` + `VOYAGE_API_KEY`), et faire pointer `text-embedding-3-small` **ou** le modèle configuré dans Graphiti vers cette route.
3. **Aligner `dimensions`** dans `config.yaml` Graphiti avec le modèle (ex. Voyage souvent **1024** par défaut dans le code Graphiti pour `voyage-3`).
4. **Important** : si tu as déjà des vecteurs **1536** dans Neo4j, changer de dimension = **nouveau graphe / purge / réindex** — sinon incohérences. Pour un graphe vide ou de test, pas de souci.

### Ce que tu dois « fournir » intelligemment (checklist)

| Variable / secret | Pour quoi |
|-------------------|-----------|
| `NEO4J_URI`, `NEO4J_USERNAME`, `NEO4J_PASSWORD`, `NEO4J_DATABASE` | Base graphe |
| `MOONSHOT_API_KEY` (+ `MOONSHOT_API_BASE` .ai vs .cn) | Chat via LiteLLM (déjà OK chez toi) |
| **`OPENAI_EMBEDDING_API_KEY`** avec **quota** | Embeddings **actuels** (sinon 429) |
| *Si piste B Voyage* | `VOYAGE_API_KEY` + mise à jour `embedder` Graphiti + LiteLLM + éventuellement reset Neo4j |
| *Si piste B Gemini* | `GOOGLE_API_KEY` + idem |

**Test rapide embeddings** (après correctif) :

```bash
curl -sS http://127.0.0.1:4000/v1/embeddings \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer litellm-proxy" \
  -d '{"model":"text-embedding-3-small","input":"ping"}' | python3 -c "import json,sys; d=json.load(sys.stdin); print('keys', list(d.keys())); print('err', d.get('error'))"
```

Attendu : réponse avec `data[0].embedding` (liste de floats), **pas** de champ `error`.
