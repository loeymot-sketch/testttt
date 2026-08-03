# Rapport de vérification massif — post P0 + code sync (2026-04-22)

**Objectif** : tout bon côté outillage, mémoire Graphiti, fiscal, outbox/broadcast, invariants CI, et smoke Neo4j.  
**Commande exécutée par l’agent** : `bash bin/graphiti-p0-long-drain.sh && python3 memory/verify.py --json` (log complet : `/tmp/foodking-p0-verify-30254.log`).

---

## 1. Synthèse exécutive (30 secondes)

| Domaine | Résultat |
|---------|----------|
| **Ingestion Graphiti P0** | **182 / 182** épisodes envoyés, **failed: 0**, drain Neo4j **182 / sent=182** (cible atteinte) |
| **Neo4j `verify.py` heuristique** | **count = 182** (`"uuid"` dans blob `get_episodes`) — aligné JSONL |
| **17 requêtes `search_memory_facts`** | **17 × 3 hits** (`fact_hits`: 3 partout), **0 erreur** |
| **Fiscal archive + verifyChain** | PHPUnit **8 tests** (3 `FiscalArchiveTest` + 5 `FiscalArchiveVerifyChainTest`) — **OK** |
| **Outbox + after-commit** | `OutboxTest` **6/6**, `DispatchAfterCommitTest` **6/6** — **OK** |
| **`DispatchDomainEventsJob` (T09b)** | Broadcast via `BroadcastManager::connection()` — cohérent tests CI `log` driver |
| **POS invariants `check-invariants.sh`** | **5/6 verts** ; seul **4/6** rouge (**8 hits** dispatch — attendu **P11**) |
| **Vitest `posDineInFlag`** | **11/11** (session précédente ; non re-run dans ce lot court) |
| **Manifest JSONL (P4)** | `scripts/memory-jsonl-manifest.sh` — **OK** (`reports/memory/jsonl_manifest.json`, gitignored) |
| **Delegation warn (P3)** | **84** rapports `RUN_*.md` < 14j sans `EXECUTE_DELEGATION:` — **warning GitHub** attendu, exit 0 |

**Verdict global** : **VERT** pour mémoire runtime + fiscal + outbox/sync ; **JAUNE documenté** pour invariant 4/6 (P11) et dette traceability `EXECUTE_DELEGATION` sur rapports historiques.

---

## 2. Graphiti P0 — détail chiffré

### 2.1 Source JSONL (vérité git)

| Fichier | Lignes |
|---------|-------:|
| 01–14 `memory/episodes/*.jsonl` | **182** total |
| Parse JSON ligne à ligne | **0 erreur** |

### 2.2 Ingestion (`memory/ingest.py` via `bin/graphiti-p0-long-drain.sh`)

Extrait log (`/tmp/foodking-p0-verify-30254.log`) :

```
[ingest] TOTAL = 182 episodes  (DRY_RUN=False)
…
[ingest] DONE
  sent: 182/182
  failed: 0
```

- **DRAIN_TIMEOUT** = 7200 s, **DRAIN_STALL_ITERS** = 120 (poll toutes les 15 s).
- Progression drain observée : 124 → … → **182 / sent=182** puis **`[drain] target reached, queue drained.`** (voir log).
- Durée wall-clock observée sur cette machine : **~10–11 min** entre démarrage ingest et fin drain (dépend LLM/embeddings/OpenRouter + LiteLLM local).

### 2.3 Vérification post-ingest (`memory/verify.py --json`)

Fichier généré : `reports/memory/verify_snapshot.json` (gitignored ; contenu capturé ci-dessous en résumé).

- **`episode_uuid_heuristic`** : **182**
- **`written_at`** : `2026-04-22T01:10:55.670005+00:00` (UTC)
- **Requêtes domaine** : toutes avec `fact_hits: 3`, `error: null` pour :
  - `01_overview` … `14_conventions`
  - `smoke_dispatch`, `smoke_snapshot`, `smoke_openrouter`

→ **Fermeture du gap** documenté précédemment (124 vs 180) : **résolu** après cette passe complète (182).

### 2.4 Avertissements Graphiti (non bloquants)

Le log MCP contient des **WARNING** ponctuels du type :

- `LLM returned invalid duplicate_facts idx values …`
- `Source entity not found in nodes for edge relation: REQUIRES_MANUAL_FILTERING`

Ils reflètent le bruit normal d’extraction LLM / dédup ; **sans** `failed:` côté ingest client.

---

## 3. Code & tests — `DispatchDomainEventsJob` + Fiscal

### 3.1 `app/Jobs/DispatchDomainEventsJob.php`

- Utilise **`app(BroadcastManager::class)->connection()`** puis **`$broadcaster->broadcast($channels, $broadcast_as, $envelope)`**.
- Effet : respecte `config('broadcasting.default')` (**`log`** en PHPUnit, **`pusher`** en prod) — plus de hard-code `connection('pusher')` ni branche « skip si clé vide » qui court-circuitait le chemin uniforme.

### 3.2 `tests/Feature/Fiscal/FiscalArchiveTest.php`

- **`schema_version`** attendu **3** + présence **`z_chain_verified`** et **`z_chain_verify_meta`** dans `manifest.json` du ZIP.

### 3.3 PHPUnit exécutés dans cette session

| Suite | Résultat |
|-------|----------|
| `tests/Feature/Fiscal/FiscalArchiveTest.php` | **3** tests, 16 assertions — **OK** |
| `tests/Feature/Fiscal/FiscalArchiveVerifyChainTest.php` | **5** tests, 24 assertions — **OK** |
| `tests/Feature/OutboxTest.php` | **6** tests, 19 assertions — **OK** |
| `tests/Feature/DispatchAfterCommitTest.php` | **6** tests, 6 assertions — **OK** |

---

## 4. Garde-fous CI & scripts

### 4.1 `scripts/check-invariants.sh`

```
1/6 OK  |  2/6 OK  |  3/6 OK  |  4/6 FAIL (8)  |  5/6 OK  |  6/6 OK
```

- **4/6** : `OrderService` / `FrontendOrderService` — `OrderCreated::dispatch` / `OrderStatusChanged::dispatch` hors contrat `ShouldDispatchAfterCommit` → **P11_DISPATCH_AFTER_COMMIT_REMEDIATION**.
- **5/6** : faux positif `DispatchableAfterCommit.php` neutralisé par exclude `Concerns/DispatchableAfterCommit`.
- **2/6** : ligne idempotency `branch_id` avec `// allow: idempotency PROD-2 scoped lookup`.

### 4.2 `scripts/check-run-delegation-warn.sh`

- Sur fenêtre **14 jours** : **84** fichiers `reports/execution/RUN_*.md` **sans** ligne `EXECUTE_DELEGATION:`.
- Comportement : **`::warning::`** + **exit 0** (non bloquant), conforme au design P3.

### 4.3 `scripts/memory-jsonl-manifest.sh`

- Produit `reports/memory/jsonl_manifest.json` avec **sha256** + **nombre de lignes** par fichier `*.jsonl`.
- Fichier **gitignored** avec `verify_snapshot.json` — régénérable en CI / local.

---

## 5. Mémoire JSONL — drift corrigé (rappel)

`memory/episodes/03_domain_events_sync.jsonl` :

- Job **par `domain_event_id`**, pas scan `pending` par branche dans `DispatchDomainEventsJob`.
- Canaux **`private-branch.{branchId}`** + auth **`branch.{branchId}`** (pas de suffixe `.kds` dans le nom PHP).

Ré-ingéré dans le graphe lors du P0 complet.

---

## 6. Dettes & suivis (hors scope immédiat)

| ID | Sujet | Priorité |
|----|--------|----------|
| **P11** | Remédiation `ShouldDispatchAfterCommit` / refonte dispatch pour vert **4/6** | Haute (qualité prod sync) |
| **Delegation** | Rétro-remplir ou accepter dette : 84 `RUN_*.md` récents sans `EXECUTE_DELEGATION:` | Moyenne (audit seulement) |
| **Baseline manifest** | Commettre un `jsonl_manifest.json` de référence + step `--check` bloquant si souhaité | Basse |

---

## 7. Fichiers & artefacts touchés ou générés (référence)

| Chemin | Rôle |
|--------|------|
| `/tmp/foodking-p0-verify-30254.log` | Trace complète MCP + ingest + drain + `verify.py` |
| `reports/memory/verify_snapshot.json` | Snapshot JSON verify (gitignored) |
| `reports/memory/jsonl_manifest.json` | Empreintes JSONL (gitignored) |
| `memory/episodes/03_domain_events_sync.jsonl` | Drift sync corrigé |
| `memory/episodes/12_decisions_log.jsonl` | +ADR PROD-1 / PROD-4 |
| `app/Jobs/DispatchDomainEventsJob.php` | Broadcast driver-agnostique |
| `tests/Feature/Fiscal/FiscalArchiveTest.php` | Assertions manifest v3 |

---

## 8. Phrase de clôture

Le **pipeline mémoire** est désormais **pleinement aligné** : **182** épisodes dans git, **182** visibles côté Neo4j (heuristique), **0** échec d’ingestion, et les **requêtes de smoke domaine** répondent toutes. Les **tests fiscaux et outbox/after-commit** valident les changements récents sur le dispatch. Il reste **deux sujets de gouvernance** (P11 invariant 4/6, ligne `EXECUTE_DELEGATION` dans les rapports d’exécution récents), **sans** remettre en cause la santé de la mémoire Graphiti ni des archives NF525 vérifiées dans cette session.
