# Plan d’exécution — clôture mémoire + commit + CI + prod (SSOT)

**Date** : 2026-04-22  
**TASK_ID** : `P_EXEC_CLOSEOUT_GRAPHITI_CI_PROD_2026-04-22`  
**Type** : orchestration humaine + agents (pas de modification produit dans ce document)  
**Objectif** : terminer **PLAN-MEM-1 → PLAN-MEM-6**, **commit groupé**, **CI MySQL**, **prod phasée J-7→J+7** avec critères de succès mesurables et parallélisation intelligente.

---

## Verdict consolidé (état de départ)

| Volet | Statut |
|-------|--------|
| Code application (W1→W9 + Stage 1 + Stage 2) | ✅ prod-ready local |
| Mémoire Neo4j (Graphiti) | ⚠️ objectif **180/180** épisodes visibles ; jusqu’ici **~124/180** possible si drain incomplet |
| MCP Graphiti dans toutes les sessions Cursor | ⏳ `~/.cursor/mcp.json` + redémarrage IDE |
| CI + drift migrations | ⏳ push branche |
| Staging / prod | ⏳ preflight `--strict` + secrets vault |

---

## Piste A — Agent POS + Centrale (MCP Graphiti déjà chargé)

**Durée cible** : 45–70 min (async Neo4j + extraction LLM). **Parallélisable** avec Piste B dès T+0.

### A1 — PLAN-MEM-1 Option A (recommandé : graphe propre)

Prérequis : session Cursor où **`@graphiti`** / outils Graphiti répondent.

```text
@graphiti clear_graph group_ids=["foodking"]
```

Puis en terminal (repo root) :

```bash
cd /Users/1millnonstop/Downloads/projet/foodking-web/web/testttt
nohup bash bin/graphiti-ingest.sh > /tmp/foodking-reingest-$(date +%Y%m%d-%H%M).log 2>&1 & disown
tail -f /tmp/foodking-reingest-*.log | grep -E "sent|FAIL|drain|DONE"
```

**Si Option A impossible** (politique no-clear) → Option B incrémentale :

```bash
bash bin/graphiti-ingest.sh 09_tasks_history
bash bin/graphiti-ingest.sh 10_tests_coverage
bash bin/graphiti-ingest.sh 11_production_plan
bash bin/graphiti-ingest.sh 12_decisions_log
bash bin/graphiti-ingest.sh 13_agents_roles
bash bin/graphiti-ingest.sh 14_conventions
```

### A2 — Fiabiliser le drain (évite 124/180)

Avant relance lourde, exporter (optionnel) :

```bash
export DRAIN_TIMEOUT=7200          # 2 h
export DRAIN_STALL_ITERS=80       # 20 min sans progrès @ 15s
```

Puis relancer ingest (Option A ou fichiers 09–14).

### A3 — Gate de succès PLAN-MEM-1

```bash
cd /Users/1millnonstop/Downloads/projet/foodking-web/web/testttt
python3 memory/verify.py
```

| Critère | Pass |
|---------|:----:|
| `count` épisodes Neo4j | **≥ 175** acceptable si doublons légers ; **= 180** idéal |
| `search_memory_facts` (8 requêtes smoke) | toutes **≥ 1** fact pertinent |
| Log ingest `failed: 0` | ✅ |

**Si count &lt; 175 après 2 h** : augmenter `DRAIN_TIMEOUT`, attendre 30 min sans nouveau run, relancer **uniquement** `bash bin/graphiti-ingest.sh` (sans clear) — la queue serveur peut rattraper.

---

## Piste B — Toi (humain) en parallèle (T+0, ~5–10 min)

### B1 — PLAN-MEM-3 : MCP global Cursor

1. Ouvrir **`~/.cursor/mcp.json`** (créer si absent).
2. Fusionner le bloc **`graphiti`** depuis **`.cursor/mcp/graphiti.json.example`** sans écraser les autres serveurs (`playwright`, etc.).
3. Vérifier **`~/.cursor/mcp-graphiti.env`** (copie de **`.cursor/mcp/mcp-graphiti.env.example`**, `chmod 600`), notamment **`GRAPHITI_DIR`** si le clone Graphiti n’est pas **`~/graphiti`**.
4. **Redémarrer Cursor** sur les 3 profils / machines où tu veux Borne + POS + orchestration.

### B2 — Contrôle post-redémarrage

Dans chaque session : palette MCP → **`graphiti`** visible ; test rapide :

```text
@graphiti search_memory_facts query="DispatchableAfterCommit outbox foodking" group_ids=["foodking"]
```

---

## Séquentiel — après A3 + B2 (ordre strict)

### C1 — PLAN-MEM-4 : smoke test multi-sessions (15 min)

Même requête, **3 sessions** (Borne / POS / Orchestrateur) :

```text
@graphiti search_memory_facts query="SYNC-001 domain_events private-branch" group_ids=["foodking"]
```

| Critère | Pass |
|---------|:----:|
| Latence &lt; 5 s par session | ✅ |
| Au moins **un fait** commun (même idée, pas forcément même UUID) | ✅ |
| Aucune erreur auth Neo4j | ✅ |

### C2 — PLAN-MEM-5 (déjà en grande partie dans le repo)

Vérifier que **`AGENTS.md`** + **`.cursor/rules/graphiti-memory.mdc`** + **`run-cycle.md`** reflètent le protocole ; pas d’action si déjà à jour.

### C3 — PLAN-MEM-6 (optionnel J+1)

Cron ou hook post-merge pour ré-ingérer les nouveaux `reports/**/*.md` — **hors chemin critique** avant premier prod deploy.

---

## D — Commit groupé (après C1 vert)

**Déclencheur** : humain dit explicitement **« go commit »** (règle safety).

### D1 — Fichiers minimaux attendus dans le commit (groupe audit + intégration)

**Backend / CI / prod**

- `app/Console/Commands/FiscalArchiveCommand.php`
- `app/Console/Commands/PreflightProductionCommand.php`
- `app/Services/OrderService.php`
- `app/Providers/AppServiceProvider.php` (si boot guards W9)
- `app/Console/Kernel.php`
- `app/Jobs/CleanupStalePendingKioskOrders.php`
- `app/Jobs/Observability/SloEvaluatorJob.php`
- `config/app.php`
- `.github/workflows/phpunit.yml`

**Frontend / i18n / tests** (si présents dans ton working tree)

- `resources/js/components/admin/pos/ReceiptComponent.vue`
- `resources/js/languages/{fr,en,ar}.json`
- `tests/Feature/Admin/POS/ReceiptPrintControllerTest.php`
- `tests/Feature/Fiscal/ZOpenChainVerifiedTest.php`
- `tests/js/posReceiptPrintFlow.spec.js`

**Docs / cycles / plans**

- `.cursor/ACTIVE_CYCLE.md`
- `.cursor/commands/run-cycle.md`
- `.cursor/rules/graphiti-memory.mdc`, `global.mdc`, `project-continuity.mdc`
- `.cursor/mcp/start-graphiti-mcp.sh`, `mcp-graphiti.env.example`
- `AGENTS.md`
- `reports/audit/AUDIT_W1_W9_GLOBAL_2026-04-21.md`
- `reports/audit/AUDIT_W1_W9_PROD_READY_2026-04-21.md`
- `reports/audit/AUDIT_FINAL_GO_PROD_W1_W9_2026-04-21.md`
- `reports/audit/AUDIT_INTEGRATION_FLOW_COMPLETE_2026-04-22.md`
- `docs/gates/GATE_W9_AUDIT_KIOSK_RECEIPT_NON_FISCAL_2026-04-21.md` (si dans le scope commit)
- **`plans/PLAN_EXECUTION_CLOSEOUT_GRAPHITI_CI_PROD_2026-04-22.md`** (ce fichier)

**Exclure du commit** (sauf décision contraire) : `memory/__pycache__/`, `.env`, `memory/ingest.env`, secrets, gros binaires.

### D2 — Message de commit (modèle)

```text
chore(audit): W9 global closeout — prod hardening, Graphiti protocol, CI drift, preflight

- PROD-1..4: fiscal archive lock, idempotency branch scope, CI migrate guard, preflight command
- Docs: consolidated audit reports + ACTIVE_CYCLE
- Cursor: Graphiti memory rule, run-cycle PRIOR_CONTEXT, portable graphiti start script
```

### D3 — Pré-push local (obligatoire)

```bash
vendor/bin/phpunit --testsuite Feature
vendor/bin/phpunit --testsuite Unit
npx vitest run
```

---

## E — Push CI (après D3 vert)

1. `git push origin <ta-branche>`
2. Attendre workflow **PHPUnit (MySQL)** : step **Migration drift check** puis suite complète.
3. Si **Playwright** requis par le diff UI : lancer workflow ou `npx playwright test` selon politique équipe.

**Gate** : CI rouge → **pas** de merge vers `main` / pas de prod.

---

## F — Prod phasée (référence : `AUDIT_FINAL_GO_PROD_W1_W9_2026-04-21.md` § 3)

**Déclencheur** : merge sur branche de release + environnement staging avec `.env` prod-like.

| Phase | Action clé | Gate |
|-------|------------|------|
| J-7→J-4 | Redis, Pusher/Reverb, workers, cron, secrets fiscal ≥32 | Infra checklist |
| J-3→J-2 | Code freeze, tag RC, CI vert | CI |
| J-1 | `APP_ENV=production php artisan app:preflight-production --strict` | **exit 0** |
| J0 | Backup DB, maintenance courte, migrate, caches, workers | Smoke Borne/POS/KDS |
| J+1→J+7 | Surveiller `domain_events` stale, latences, `fiscal:archive` J-1 | SLO |

---

## Matrice responsabilités (qui fait quoi)

| Étape | POS + Centrale | Humain | Agent Borne (Cursor) |
|-------|----------------|--------|-------------------------|
| PLAN-MEM-1 A | ✅ execute | — | Lecture seule / valide logs si besoin |
| PLAN-MEM-3 | — | ✅ `mcp.json` | Idem |
| PLAN-MEM-4 | ✅ session 1/3 | ✅ session 2/3 | ✅ session 3/3 si dispo |
| Commit | — | ✅ « go commit » | Peut préparer le diff |
| CI | — | merge / surveille | — |
| Prod | Ops | go / no-go | — |

---

## Définition de « terminé » (DONE pour ce plan)

1. `python3 memory/verify.py` → **count ≥ 175** (180 idéal) **et** smoke Graphiti OK sur **3** sessions.
2. Commit groupé poussé ; **CI MySQL vert** (dont drift migrations).
3. Staging : **`app:preflight-production --strict`** → exit 0.
4. `ACTIVE_CYCLE.md` : nouvelle ligne **CLOSED** pour `P_EXEC_CLOSEOUT_*` avec liens vers logs ingest + run CI.

---

## Risques résiduels (intelligence = les nommer)

| Risque | Mitigation |
|--------|------------|
| Ingest long / coût LLM | Option B ciblée ; `DRAIN_TIMEOUT` ↑ |
| Doublons Neo4j après re-runs | `clear_graph` Option A ; accepter fusion entités Graphiti |
| Subagent sans MCP | Plans futurs : toujours **`## PRIOR_CONTEXT`** rempli par le chat parent |
| Secrets dans `mcp.json` | Jamais ; uniquement `~/.cursor/mcp-graphiti.env` |
