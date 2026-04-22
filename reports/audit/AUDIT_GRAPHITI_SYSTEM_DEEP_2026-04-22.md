# Audit profond — système global Graphiti + données + orchestration

**Date** : 2026-04-22  
**Méthode** : lecture code (`memory/ingest.py`, `verify.py`, listeners outbox, `DispatchDomainEventsJob`), inventaire JSONL, exécution **`python3 memory/verify.py`** sur Neo4j live, recoupement avec `routes/channels.php` et règles Cursor.

---

## 1. Cartographie logique (trip dans le fonctionnement)

### 1.1 Couches empilées

```
┌─────────────────────────────────────────────────────────────────────────┐
│ COUCHE A — SSOT textuel (git, reviewable, diffable)                      │
│   memory/episodes/*.jsonl (180 lignes = 180 épisodes atomiques)         │
│   memory/INDEX.md — taxonomie domaines → fichiers                        │
└───────────────────────────────────┬─────────────────────────────────────┘
                                    │ bin/graphiti-ingest.sh + memory/ingest.py
                                    ▼
┌─────────────────────────────────────────────────────────────────────────┐
│ COUCHE B — transport MCP stdio (JSON-RPC 2.0)                            │
│   Process : bash .cursor/mcp/start-graphiti-mcp.sh                       │
│   Outil principal ingestion : add_memory(name, episode_body, group_id)   │
│   Outil contrôle        : get_episodes(group_ids, max_episodes)          │
└───────────────────────────────────┬─────────────────────────────────────┘
                                    ▼
┌─────────────────────────────────────────────────────────────────────────┐
│ COUCHE C — Graphiti server (Python uv)                                  │
│   Queue async : réception add_memory ≠ fin extraction LLM/embedding       │
│   Workers : extraction entités/relations + embeddings (LiteLLM/OpenRouter)│
└───────────────────────────────────┬─────────────────────────────────────┘
                                    ▼
┌─────────────────────────────────────────────────────────────────────────┐
│ COUCHE D — Neo4j Aura (persistance graphe + vecteurs)                   │
│   group_id = foodking — isolation logique                               │
└───────────────────────────────────┬─────────────────────────────────────┘
                                    ▼
┌─────────────────────────────────────────────────────────────────────────┐
│ COUCHE E — interrogation (sessions Cursor / scripts)                    │
│   search_memory_facts / search_memory_nodes / get_episodes                │
│   Règles : graphiti-memory.mdc + run-cycle Step 0.5 + plan/audit context │
└─────────────────────────────────────────────────────────────────────────┘
```

**Invariant architectural** : la **vérité primaire** pour l’équipe reste **JSONL + code + tests**. Neo4j est un **index sémantique dérivé** : s’il diverge, on regénère depuis A, pas l’inverse.

---

## 2. État des données implémentées (fait mesuré)

### 2.1 Fichiers JSONL (source locale)

| Fichier | Lignes (wc) | Épisodes JSON valides | Rôle |
|---------|------------:|----------------------:|------|
| 01_project_overview.jsonl | 11 | 11 | Vision stack 3 surfaces |
| 02_architecture_invariants.jsonl | 12 | 12 | Frozen zones, BranchScope |
| 03_domain_events_sync.jsonl | 14 | 14 | Outbox, Echo, SYNC-001/002 |
| 04_pricing_ssot.jsonl | 10 | 10 | Cents, SSOT |
| 05_fiscal_nf525.jsonl | 12 | 12 | Z, audit chain, composition_snapshot |
| 06_kiosk_features.jsonl | 14 | 14 | Wizard, offline |
| 07_pos_features.jsonl | 16 | 16 | Park/recall, NFC, KDS routing |
| 08_kds_features.jsonl | 10 | 10 | Bump, stations |
| 09_tasks_history.jsonl | 24 | 24 | Tâches V14, findings |
| 10_tests_coverage.jsonl | 12 | 12 | Vitest/PHPUnit sentinelles |
| 11_production_plan.jsonl | 12 | 12 | Phases rollout, KPIs |
| 12_decisions_log.jsonl | 15 | 15 | ADR |
| 13_agents_roles.jsonl | 8 | 8 | Orchestration multi-agents |
| 14_conventions.jsonl | 10 | 10 | Hooks, i18n |
| **Total** | **180** | **180** | Couverture multi-domaine |

**Contrôle qualité JSON** : script interne — **0 erreur JSON** sur les 180 lignes non vides.

### 2.2 Neo4j live (exécution `python3 memory/verify.py`)

```
=== Episodes visible in Neo4j (group=foodking) ===
  count = 124

=== search_memory_facts samples (8 requêtes) ===
  [3] à [3] facts chacune — toutes OK
```

**Écart 180 (source) vs 124 (graphe)** : **56 épisodes** en retard d’indexation **ou** abandonnés par le **drain** (timeout / stall) alors que `add_memory` a renvoyé succès côté client. Ce n’est **pas** une perte des JSONL : une **re-ingestion** + **drain allongé** (ou attente serveur) referme le gap.

---

## 3. Audit technique du pipeline (points forts / fragilités)

### 3.1 `memory/ingest.py`

| Aspect | Évaluation | Détail |
|--------|------------|--------|
| Transport | ✅ | JSON-RPC séquentiel + lock asyncio — pas de races stdin |
| Buffer stdout | ✅ | `limit=8*1024*1024` — corrige troncature 64 KiB |
| `uuid` dans add_memory | ✅ | Commentaire explicite : ne pas passer uuid pour création |
| Drain | ⚠️ | Compte les sous-chaînes `"uuid"` dans `json.dumps(get_episodes)` — **heuristique** (faux positifs possibles si le mot apparaît ailleurs dans le JSON) |
| Condition fin drain | ⚠️ | `cnt >= sent` : correct si 1 uuid par épisode complété ; si schéma Graphiti change, revérifier |
| Stall | ⚠️ | Défaut **40 × 15s = 10 min** sans progrès → stop ; extraction lourde peut dépasser → **124/180** observé |
| `DRAIN_TIMEOUT` | ⚠️ | Défaut **1800s** ; charge 180 épisodes ≈ **45–90 min** extraction — **sous-dimensionné** pour garantir 180/180 en une passe |

**Recommandation** : pour une passe « garantie », exporter avant ingest :

```bash
export DRAIN_TIMEOUT=7200
export DRAIN_STALL_ITERS=120   # 30 min sans progrès avant abandon
```

### 3.2 `memory/verify.py`

| Aspect | Évaluation |
|--------|------------|
| Même heuristique `count('"uuid"')` | ⚠️ alignée sur ingest — cohérente mais fragile |
| Docstring ligne 2 | ⚠️ dit `get_episodes(last_n=300)` — **obsolète** (code utilise `max_episodes`) |
| Couverture smoke | ⚠️ 8 requêtes `search_memory_facts` — **pas** de `search_memory_nodes` ni de requêtes sur domaines 12–14 |

**Recommandation** : étendre `verify.py` avec 4–6 requêtes supplémentaires ciblant `12_decisions_log`, `13_agents_roles`, `11_production_plan` pour détecter un trou **domaine** même si le count uuid est trompeur.

### 3.3 `start-graphiti-mcp.sh` + env

| Aspect | Évaluation |
|--------|------------|
| Secrets hors repo | ✅ `~/.cursor/mcp-graphiti.env` |
| `REPO_DIR` portable | ✅ dérivé du script (`../..`) |
| `GRAPHITI_DIR` | ✅ défaut `${HOME}/graphiti` + surcharge env |
| LiteLLM health | ✅ garde-fou avant `exec` Graphiti |

---

## 4. Drift mémoire ↔ code (échantillon critique)

Les épisodes sont **stables par design** mais **vieillissent** quand le code change. Un audit « imbattable » inclut une **politique de rafraîchissement**, pas seulement une ingestion one-shot.

### 4.1 Canal broadcast (exemple concret)

- **Mémoire** (épisode pipeline, step 4) mentionne un canal du style `private-branch.X.kds` dans la narration JSON.
- **Code** (`PersistOrderCreatedToOutbox`, `PersistOrderStatusChangedToOutbox`) : `private-branch.{branchId}` **sans** suffixe `.kds`.
- **Auth** (`routes/channels.php`) : pattern **`branch.{branchId}`** (pas le préfixe `private-` dans le nom PHP — Laravel mappe vers private côté client).

**Verdict** : légère **imprécision pédagogique** dans l’épisode ; **pas bloquant** pour la recherche sémantique, mais à corriger lors du prochain **refresh ciblé** du fichier `03_domain_events_sync.jsonl` pour éviter qu’un LLM infère un mauvais nom de canal.

### 4.2 Verrou outbox dans `DispatchDomainEventsJob`

- **Mémoire** : formulation « select avec `lockForUpdate()` par branch » (synthèse).
- **Code actuel** : le job charge **`DomainEvent::find($id)`** pour **un** id — verrouillage fin granulaire implicite via **une ligne** ; la contention multi-workers est surtout **à l’insert** + **dispatch par id**, pas un scan `branch_id` locké dans ce job.

**Verdict** : raffiner l’épisode pour dire « **un job par `domain_event_id`** » plutôt qu’un lock scan branch — **précision** pour événements future doc.

---

## 5. Orchestration « niveau supérieur » (ce qui est déjà câblé)

| Mécanisme | Rôle | Force |
|-----------|------|-------|
| `AGENTS.md` § MCP + Terminal allies | Contrat humain + outils externes | Claire |
| `.cursor/rules/graphiti-memory.mdc` | always-on : query début, write fin | Toutes sessions |
| `run-cycle.md` Step 0.5 | Graphiti avant PLAN | Traçabilité cycle |
| `plan-context.md` | `## PRIOR_CONTEXT` | Anti-perte context subagent |
| `audit-context.md` | `add_memory` après CLOSED | Fermeture boucle mémoire |
| `plans/PLAN_EXECUTION_CLOSEOUT_*.md` | Séquence MEM + CI + prod | Opérable |
| `ACTIVE_CYCLE.md` | État + W10 closeout | SSOT cycle |

**Lacune orchestration** : les **subagents Cursor** ne héritent pas automatiquement des MCP du parent — la **PRIOR_CONTEXT** dans le plan reste le **pont obligatoire** entre mémoire globale et exécuteur.

---

## 6. Ce qui reste à faire (priorisé, « intelligence » = ordre et gates)

### P0 — Fermer le gap Neo4j (indispensable pour mémoire « complète »)

1. `@graphiti clear_graph` **ou** ingest incrémental fichiers **09–14** si politique no-clear.
2. `DRAIN_TIMEOUT=7200` `DRAIN_STALL_ITERS=120` puis `nohup bash bin/graphiti-ingest.sh`.
3. Boucle : `python3 memory/verify.py` jusqu’à **count ≥ 175** (180 idéal) sur **24–48 h** si workers lents.

### P1 — Durcir la vérification (mémoire « imbattable » côté outil)

1. Corriger docstring `verify.py`.
2. Ajouter **12–15 requêtes** couvrant chaque fichier `memory/episodes/*.jsonl` (une requête signature par domaine).
3. Option : exporter le **résultat structuré** JSON de `get_episodes` vers `reports/memory/verify_snapshot.json` en CI **optionnel** (ne pas bloquer merge si Neo4j indisponible).

### P2 — Gouvernance des données (évolution continue du projet)

| Pratique | But |
|----------|-----|
| **Episoder tout gate / ADR** | `12_decisions_log.jsonl` + ingest ciblé |
| **Versionner les épisodes** | Champ optionnel `episode_version` ou préfixe `[2026-04-22]` dans `name` |
| **Lier code ↔ mémoire** | `source_description` = `path:line` + commit SHA dans le corps |
| **Refresh trimestriel** | Relecture `03_` `05_` `07_` vs code (sync, fiscal, POS) |

### P3 — Orchestration multi-agents (déjà bien ; une amélioration)

- **Template plan** : case à cocher « **PRIOR_CONTEXT** copiée/collée dans le message de délégation subagent ».
- **Post-mortem cycle** : une ligne `MEMORY_REFRESH:` dans `REPORT_FILE` listant les épisodes JSONL à mettre à jour.

### P4 — Optionnel long terme

- **CI** : job « memory-drift » qui compare hashes des JSONL vs dernier snapshot ingéré (fichier manifest dans `reports/memory/`).
- **Multi-tenant mémoire** : aujourd’hui un seul `group_id` ; si un jour multi-marque, préfixer `foodking__{tenant}`.

---

## 7. Synthèse verdict

| Critère | Note |
|---------|------|
| Qualité sémantique des 180 épisodes | **Élevée** (JSON structuré + `source_description` traçable) |
| Intégrité JSON locale | **100%** |
| Complétude Neo4j | **~69%** (124/180) — **à corriger par re-ingest + drain** |
| Cohérence code ↔ quelques épisodes | **Bon avec drift mineur** (canal, détail lock) |
| Orchestration repo (règles + run-cycle + plans) | **Très bonne** |
| Outils verify/ingest | **Bons** ; heuristique drain à surveiller |

**Phrase de clôture** : le système est **architecturalement prêt à être imbattable** ; l’état **runtime** du graphe doit **rattraper** les 56 épisodes restants, puis adopter **P1–P2** pour que chaque vague du projet **ré-enrichisse** la mémoire sans dérive silencieuse.
