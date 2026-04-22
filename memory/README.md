# FoodKing — Mémoire d'intelligence (Graphiti)

> Cerveau persistant du projet FoodKing (Kiosk + POS + KDS + Backoffice).
> Indexé dans Neo4j via Graphiti MCP, group_id = `foodking`.
> Permet à n'importe quelle nouvelle session Cursor (ou autre LLM connecté)
> de retrouver instantanément contexte, décisions, invariants, état des tâches,
> sentinels de tests, plan de production, sans relire des centaines de rapports.

## Structure

```
memory/
├── README.md                    # ce fichier
├── INDEX.md                     # table des matières navigable des épisodes
├── ingest.py                    # client MCP minimal (asyncio + stdio JSON-RPC)
└── episodes/
    ├── 01_project_overview.jsonl
    ├── 02_architecture_invariants.jsonl
    ├── 03_domain_events_sync.jsonl
    ├── 04_pricing_ssot.jsonl
    ├── 05_fiscal_nf525.jsonl
    ├── 06_kiosk_features.jsonl
    ├── 07_pos_features.jsonl
    ├── 08_kds_features.jsonl
    ├── 09_tasks_history.jsonl
    ├── 10_tests_coverage.jsonl
    ├── 11_production_plan.jsonl
    ├── 12_decisions_log.jsonl
    ├── 13_agents_roles.jsonl
    └── 14_conventions.jsonl
```

Chaque ligne JSONL = un **épisode Graphiti** atomique :

```json
{"name": "string court", "episode_body": "texte ou JSON échappé", "source": "text|json|message", "source_description": "...", "group_id": "foodking"}
```

## Configuration LLM (override Willow → OpenRouter)

Willow API ne supporte pas l'endpoint `/v1/responses` que Graphiti attend (404
sur tous les modèles). On contourne en pointant l'ingestion vers OpenRouter,
qui supporte l'endpoint OpenAI-compatible avec `openai/gpt-4o-mini`.

Le fichier `memory/ingest.env` (chmod 600, **gitignored**) override la config
Cursor par défaut (`~/.cursor/mcp-graphiti.env`) UNIQUEMENT pour l'ingestion.
Les chats normaux dans Cursor continuent d'utiliser la config Willow.

## Ingestion (one-shot)

```bash
# Depuis la racine du repo
bash bin/graphiti-ingest.sh                 # ingère TOUS les fichiers episodes/*.jsonl
bash bin/graphiti-ingest.sh 03_domain      # ingère seulement les fichiers matchant '03_domain'
DRY_RUN=1 bash bin/graphiti-ingest.sh      # affiche sans envoyer

# Ingestion longue (≈182 épisodes JSONL courants, 60–120 min selon workers) : lancer détaché en background
nohup bash bin/graphiti-ingest.sh > /tmp/foodking-ingest.log 2>&1 &
disown
tail -f /tmp/foodking-ingest.log | grep -E "ingest|drain"

# P0 — drain étendu (recommandé pour fermer l’écart Neo4j vs JSONL) :
#   bash bin/graphiti-p0-long-drain.sh
# (exporte DRAIN_TIMEOUT=7200 et DRAIN_STALL_ITERS=120 puis délègue à graphiti-ingest.sh)
```

Le script :
1. Charge `memory/ingest.env` s'il existe (override LLM provider)
2. Démarre le serveur Graphiti MCP en stdio
3. Pour chaque épisode JSONL → appelle `add_memory` (queue async)
4. **Drain bloquant** : poll `get_episodes(max_episodes=500)` toutes les 15s
   jusqu'à ce que `count >= sent` ou `DRAIN_TIMEOUT` (30 min par défaut)
5. Affiche compteur progressif et résumé final

Variables utiles :
- `SEMAPHORE_LIMIT=25` (défini dans `ingest.env`) : workers parallèles côté Graphiti
- `DRAIN_TIMEOUT=3600` : durée max d'attente du drain en secondes
- `DRAIN_STALL_ITERS=40` : nombre de polls (×15s) sans progrès avant abandon
- `SKIP_DRAIN=1` : envoyer puis fermer immédiatement (perd les épisodes en cours)

## Vérification de l'état Neo4j

```bash
python3 memory/verify.py              # compte épisodes + requêtes domaine (14 fichiers + smoke)
python3 memory/verify.py --json       # idem + écrit reports/memory/verify_snapshot.json
```

## Recherche après ingestion

Dans n'importe quelle session Cursor avec le MCP `graphiti` chargé :

```
@graphiti search_memory_facts query="DispatchableAfterCommit pourquoi"
@graphiti search_nodes query="composition_snapshot NF525"
@graphiti get_episodes group_ids=["foodking"] max_episodes=20
```

## Mise à jour incrémentale

Quand un épisode change (par exemple production rollout phase franchie), on relance
seulement le fichier concerné. Graphiti gère la dédup et la temporalité (chaque épisode
créé à un timestamp, les facts plus récents écrasent/complètent les anciens).

```bash
bash bin/graphiti-ingest.sh 11_production    # remet à jour le plan production
```

## Reset complet (rare, prudence)

```bash
# Via une session Cursor avec graphiti chargé :
@graphiti clear_graph group_ids=["foodking"]
# Puis ré-ingest :
bash bin/graphiti-ingest.sh
```

## Convention de nommage des épisodes

- `name` court et orienté facts (ex: `"NF525 — composition_snapshot immutable on order_items"`)
- `source_description` cite le path du fichier source ou le rapport d'origine
  (ex: `"app/Services/Orders/OrderItemAllergenSnapshot.php + RAPPORT_FINAL_PRODUCTION_ALL_GREEN_2026-04-20.md"`)
- `source = "json"` pour structures, `"text"` pour facts narratifs, `"message"` pour décisions/conversations

## Sécurité

Les credentials Neo4j / LLM sont dans `~/.cursor/mcp-graphiti.env` (chmod 600).
Aucun secret dans `memory/` ni dans `bin/graphiti-ingest.sh` : tout est lu depuis l'env.

## Mise à jour continue (obligatoire pour une mémoire robuste)

À chaque décision ou invariant **nouveau** qui doit survivre aux prochains agents :

1. Ajouter **une ligne** (un épisode) dans le fichier JSONL du domaine le plus proche (`memory/episodes/` — voir `INDEX.md`).
2. Lancer **`bash bin/graphiti-ingest.sh <sous-chaîne_fichier>`** (ex. `11_production` pour `11_production_plan.jsonl`).
3. Vérifier avec **`python3 memory/verify.py`** après quelques minutes si la charge Neo4j est async.

Checklist détaillée (quand mettre à jour quoi) : **`docs/orchestration/GLOBAL_SYSTEM_PRIMER.md` §4.2**.

Ne pas s’appuyer uniquement sur le chat : le **JSONL versionné** reste la source de vérité ; Neo4j est l’index dérivé.

## Manifest JSONL (drift guard P4)

```bash
bash scripts/memory-jsonl-manifest.sh              # → reports/memory/jsonl_manifest.json
bash scripts/memory-jsonl-manifest.sh --check reports/memory/jsonl_manifest.json
```
