# memory/POLICIES.md — Politiques d'exploitation Graphiti

> Items A11 (clear_graph) et A13 (duplicate_facts) méga-checklist.

## `clear_graph` — destruction du graphe

- **Qui** : orchestrateur humain (jamais sub-agent automatique)
- **Quand** : reset complet de l'environnement Neo4j de dev OU bug majeur d'ingestion
- **Procédure** :
  1. Décision écrite dans `memory/episodes/12_decisions_log.jsonl`
  2. Double confirmation humaine (item D09)
  3. Snapshot Neo4j (export) avant exécution
  4. `@graphiti clear_graph` (group `foodking`)
  5. Re-ingest complet via `bin/graphiti-ingest.sh` (tous les fichiers)
- **Interdit** : appel automatique en CI / hooks / sub-agent

## `duplicate_facts` — politique de doublons

- Graphiti maintient son propre cap de déduplication (réf code Graphiti `max_episodes`).
- En cas de re-ingestion partielle d'un même fichier JSONL : les épisodes peuvent doublonner côté graphe. Acceptable tant que :
  - Le contenu factuel est identique (idempotence sémantique)
  - Aucun timestamp ambigu n'est introduit
- Si doublons sémantiques divergent (deux versions contradictoires d'un même fait) → ouvrir ticket et privilégier le ré-écriture du JSONL avant ingest.

## Cap `max_episodes`

- Configuré via `memory/ingest.py` (défaut `500` aujourd'hui).
- Tant qu'on est < 200 lignes JSONL totales, pas de risque.
- Au-delà de 400, alerte (item A15).
