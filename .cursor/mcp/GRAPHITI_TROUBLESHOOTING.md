# Graphiti MCP — dépannage (FoodKing)

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
