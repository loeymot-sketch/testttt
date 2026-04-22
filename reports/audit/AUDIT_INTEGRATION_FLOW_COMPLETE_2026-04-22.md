# Audit — Intégration « flow complet » FoodKing

**Date** : 2026-04-22  
**Périmètre** : chaîne **humain → Cursor → règles → run-cycle → Graphiti / mémoire locale → hooks → routage → subagents → domaine métier (orders / outbox / broadcast) → terminal allies (optionnel) → prod (preflight / CI)**.

---

## 1. Verdict synthétique

| Couche | Intégration | Commentaire |
|--------|:-----------:|-------------|
| Phases cycle (PLAN→…→AUDIT) | ✅ | `run-cycle.md` + `routing.md` + `ACTIVE_CYCLE.md` cohérents |
| Graphiti **lecture** avant PLAN | ✅ | `run-cycle` Step 0.5 + `plan-context.md` § Graphiti + `graphiti-memory.mdc` |
| Graphiti **écriture** après CLOSED | ✅ | `audit-context.md` § Graphiti write + `graphiti-memory.mdc` |
| Secours sans MCP | ✅ | `memory/INDEX.md` + JSONL `memory/episodes/` |
| Ingestion batch → Neo4j | ⚠️ | `ingest.py` + `bin/graphiti-ingest.sh` OK ; drain **heuristique** + **async queue** → risque 124/180 sans re-run |
| Démarrage MCP Graphiti | ✅ (après patch) | `REPO_DIR` désormais **portable** ; `GRAPHITI_DIR` surchargeable via env |
| Subagents EXECUTE | ⚠️ | Mémoire **non injectée automatiquement** dans le sous-agent ; dépend du transfert de contexte parent |
| Terminal `claude` / `codex` | ℹ️ | Documentés dans `AGENTS.md` ; **hors** `run-cycle` (volontaire) |
| Hooks | ✅ | `safety-check.sh`, `post-execute.sh` présents ; safety **manuel** par contrat |
| Flux métier Kiosk/POS/KDS | ✅ | Hors intégration Cursor ; déjà audité (orders + outbox + `private-branch`) |

**Verdict global** : **GO avec 3 points d’attention** (MED : subagent + drain ; LOW : nommage `group_id` vs `group_ids` dans docs).

---

## 2. Cartographie du flow « de bout en bout »

```
[Humain] TASK_ID + run-cycle
    → Step 0 : ACTIVE_CYCLE, RUNNER_MODE, gates, Graphiti search_memory_facts (si MCP)
    → Step 1 : plan-context → PLAN file + ## PRIOR_CONTEXT si mémoire
    → Step 2 : routing → subagent routine | complex + EXECUTE_DELEGATION trace
    → Step 3 : post-execute.sh → post_execute_latest.log
    → Step 4 : VALIDATE (stratégie tests du plan)
    → Step 5 : audit-context → AUDIT + Graphiti add_memory si CLOSED
    → [Prod hors cycle] app:preflight-production + CI phpunit (drift) + deploy
```

**Alignement vérifié** : `plan-context.md` impose Graphiti avant plan ; `run-cycle.md` duplique l’appel en Step 0 item 5 (redondance **saine** pour les sessions qui ne rechargent pas `plan-context`).

**Correction appliquée pendant cet audit** : `run-cycle.md` référençait une sous-section « Memory context » alors que `plan-context.md` impose **`## PRIOR_CONTEXT`** — aligné sur `plan-context.md`.

---

## 3. Graphiti & mémoire

### 3.1 Règles Cursor

| Fichier | Rôle |
|---------|------|
| `.cursor/rules/graphiti-memory.mdc` | always-on : query début, `add_memory` fin, secours local |
| `.cursor/rules/global.mdc` | Rappel court Graphiti |
| `.cursor/rules/project-continuity.mdc` | Lien vers graphiti + `memory/INDEX.md` |
| `AGENTS.md` § MCP + Terminal allies | Source de vérité humaine (enregistrement `~/.cursor/mcp.json`, Claude/Codex CLI) |

### 3.2 Scripts

| Composant | Statut | Risque |
|-----------|--------|--------|
| `memory/ingest.py` | ✅ JSON-RPC, buffer 8 MiB, pas d’UUID erroné | Drain compte les `"uuid"` dans le JSON dump — **approximation** si le schéma change |
| `bin/graphiti-ingest.sh` | ✅ Passe `memory/ingest.env` → `GRAPHITI_ENV_FILE` | — |
| `.cursor/mcp/start-graphiti-mcp.sh` | ✅ Corrigé : **`REPO_DIR`** relatif au script (`../..` depuis `.cursor/mcp`) | **`GRAPHITI_DIR`** défaut `${HOME}/graphiti` — doit être surchargé dans `~/.cursor/mcp-graphiti.env` si clone ailleurs (voir `mcp-graphiti.env.example`) |

### 3.3 Incohérences mineures (LOW)

- `plan-context.md` / `audit-context.md` parlent de `group_id: foodking` (style YAML) ; les outils MCP utilisent `group_ids=["foodking"]`. **Pas bloquant** ; harmonisation cosmétique possible plus tard.

---

## 4. Subagents & mémoire

**Constat** : `run-cycle` exige la délégation `foodking-routine-implementer` | `foodking-complex-implementer`. Les **sous-agents Cursor** peuvent avoir une **liste MCP différente** du chat parent.

**Risque** : l’implémenteur ne relance pas Graphiti si le plan ne duplique pas explicitement le bloc `## PRIOR_CONTEXT`.

**Mitigation recommandée** (sans changer le routing) :

1. Le plan **doit** toujours inclure `## PRIOR_CONTEXT` (même « vide : aucun fait Graphiti ») après Step 0.5.
2. La première étape EXECUTE du plan peut dire : « Reprendre uniquement le fichier PLAN + PRIOR_CONTEXT ; ne pas élargir le scope. »

---

## 5. Hooks & preflight

| Élément | Contrat | Intégration |
|---------|---------|-------------|
| `safety-check.sh` | Manuel avant EXECUTE (`AGENTS.md`) | ✅ présent |
| `post-execute.sh` | Automatique Step 3 | ✅ présent |
| `app:preflight-production` | Prod / staging | ✅ existe ; **non** référencé dans `run-cycle` (normal : hors bounded cycle) |

---

## 6. Terminal allies (Claude Code / Codex)

- Documentés dans **`AGENTS.md`** comme couche **optionnelle** ; **non** dans le graphe `run-cycle` (évite double source de vérité sur les gates).
- **Sécurité** : pas de secrets dans les prompts ; OAuth CLI.

---

## 7. Actions recommandées (priorisées)

1. **HIGH (mémoire complète)** : après merge stable, `clear_graph` + `bash bin/graphiti-ingest.sh` puis `python3 memory/verify.py` jusqu’à **count ≈ 180** ; si besoin augmenter `DRAIN_TIMEOUT` / `DRAIN_STALL_ITERS` dans l’env.
2. **MED (subagents)** : renforcer dans `plans/PLAN_TEMPLATE.md` (si existe) une ligne « EXECUTE : lire `## PRIOR_CONTEXT` du plan uniquement ».
3. **LOW** : harmoniser `group_id` vs `group_ids` dans `plan-context.md` / `audit-context.md`.
4. **Ops** : vérifier `GRAPHITI_DIR` dans `~/.cursor/mcp-graphiti.env` sur chaque poste où le clone Graphiti n’est pas `~/graphiti`.

---

## 8. Fichiers touchés par cet audit

- `.cursor/commands/run-cycle.md` — alignement `## PRIOR_CONTEXT`
- `.cursor/mcp/start-graphiti-mcp.sh` — `REPO_DIR` portable ; `GRAPHITI_DIR` configurable
- `.cursor/mcp/mcp-graphiti.env.example` — commentaire `GRAPHITI_DIR`
- `reports/audit/AUDIT_INTEGRATION_FLOW_COMPLETE_2026-04-22.md` — ce rapport
