# Rapport — Deuxième passe globale (Graphiti, sub-agents, tokens, gouvernance)

**Date** : 2026-04-22  
**Type** : audit + **exécution** des livrables documentaires et règles (A→Z dans le périmètre repo)  
**Hors exécution automatique** : ingestion Neo4j complète (nécessite session humaine / agent avec MCP + temps long) — **état vérifié** : **124/180** épisodes visibles, **8/8** requêtes `search_memory_facts` OK.

---

## A — Objectif de la passe

1. Re-vérifier **l’intégration Graphiti** dans le flux (règles, `run-cycle`, plan/audit, scripts).  
2. Cadrer les **deux sub-agents Cursor** (`foodking-routine-implementer`, `foodking-complex-implementer`) **et** les **deux terminal allies** (`claude`, `codex`) dans un **document maître**.  
3. Clarifier la politique **tokens / contexte** : **maximum d’intelligence et de données**, optimisation **uniquement** du gaspillage (re-reads, redondance), **zéro** effet négatif sur la profondeur des réponses.  
4. Définir **comment Graphiti vit** quand le projet avance (nombreux cycles / nouveaux exécuteurs).  
5. **Rattraper** les points oubliés : fichier d’entrée unique, liens depuis `AGENTS.md`, `memory/README.md`, règles.

---

## B — Audit de l’existant (2e lecture croisée)

| Zone | Verdict 2e passe |
|------|------------------|
| `AGENTS.md` (MCP, terminal, routing) | Cohérent ; manquait un **pointeur unique** vers un primer multi-agents |
| `run-cycle.md` Step 0.5 Graphiti | OK |
| `plan-context.md` / `audit-context.md` Graphiti | OK |
| `graphiti-memory.mdc` | OK ; manquait **évolution projet** + **obligation d’écriture** explicite |
| `global.mdc` « Token Discipline » | Risque d’interprétation « court = bon » — **corrigé** (quality-first) |
| `context-hygiene.mdc` §4 | Risque de confusion avec « résumé = moins intelligent » — **clarifié** (handoff uniquement) |
| `memory/*.jsonl` | **180** épisodes valides (audit précédent) |
| `verify.py` (live) | **124** comptés, smoke **8/8** verts |
| Sub-agents Task | Documentés dans `AGENTS.md` / `run-cycle` mais **pas** dans un doc `docs/` unique |
| Terminal allies | Dans `AGENTS.md` seulement |

---

## C — Exécution réalisée (A→Z dans le repo)

| ID | Livrable | Fichier / action |
|----|----------|------------------|
| C1 | **Fichier principal** multi-agents + Graphiti + tokens | **`docs/orchestration/GLOBAL_SYSTEM_PRIMER.md`** (nouveau) |
| C2 | Lien depuis le contrat global | **`AGENTS.md`** — section *Global system primer* |
| C3 | Lien continuité planification | **`project-continuity.mdc`** |
| C4 | Mémoire JSONL = SSOT + checklist MAJ | **`memory/README.md`** — § *Mise à jour continue* |
| C5 | Roster agents | **`docs/orchestration/AGENT_ROLES.md`** — lien vers Primer |
| C6 | Règle Graphiti « ne pas oublier » + cycles N | **`graphiti-memory.mdc`** — points 2–4 enrichis |
| C7 | Tokens : zéro optimisation négative | **`global.mdc`** — section *Token Discipline* réécrite |
| C8 | Résumés de phase = anti-gaspillage, pas anti-intelligence | **`context-hygiene.mdc`** — §4 entête + paragraphe |
| C9 | Re-vérification Neo4j | **`python3 memory/verify.py`** — count **124**, queries OK |

**Non exécuté ici** (hors capacité session outil-only courte) :

- `clear_graph` + `nohup bin/graphiti-ingest.sh` jusqu’à **180/180** — **à faire** par l’agent/humain avec MCP + fenêtre 45–120 min (voir `plans/PLAN_EXECUTION_CLOSEOUT_GRAPHITI_CI_PROD_2026-04-22.md`).

---

## D — Synthèse « intelligence max » vs tokens

| Mécanisme | Effet sur la qualité | Effet sur les tokens |
|-----------|----------------------|----------------------|
| Graphiti + `## PRIOR_CONTEXT` | **+** stabilité, faits structurés | **−** re-lecture de dizaines de fichiers |
| Résumés post-phase (`context-hygiene` §4) | **=** si uniquement pour handoff | **−** re-load des artefacts finis |
| Couper risques / invariants du plan | **Interdit** | (tentation « court ») → **bloqué** par règle |

**Cache** : Redis / locks applicatifs = runtime Laravel ; **ne remplace pas** Graphiti. Les deux coexistent.

---

## E — Sub-agents & terminal : position dans le système global

```
                    ┌─────────────────────────────────────┐
                    │  GLOBAL_SYSTEM_PRIMER.md + AGENTS.md   │
                    └─────────────────┬───────────────────┘
                                      │
          ┌───────────────────────────┼───────────────────────────┐
          ▼                           ▼                           ▼
   run-cycle +              foodking-* Task              claude / codex
   plan-context             subagents                    (terminal, optionnel)
```

- **Task subagents** : exécution **bornée** par le plan ; **PRIOR_CONTEXT** obligatoire dans le plan pour compenser l’absence possible de MCP.  
- **Terminal** : audits / patches massifs **en dehors** du gate `run-cycle` ; la **preuve** reste tests + `REPORT_FILE` + AUDIT Cursor.

---

## F — Points rattrapés (vous aviez raison de demander une 2e passe)

1. **Un seul point d’entrée doc** pour « comment tout fonctionne ensemble » → **Primer**.  
2. **Mise à jour Graphiti « tout le temps »** → checklist §4.2 + `memory/README` + renforcement `graphiti-memory.mdc`.  
3. **Politique tokens** explicitement **non destructrice** de l’intelligence → `global.mdc` + `context-hygiene.mdc`.  
4. **Échelle « 1000 agents »** : traité comme **N cycles / N exécuteurs** — la robustesse vient du **JSONL versionné** + ingest + `add_memory` post-CLOSED, pas d’un seul chat.

---

## G — Audit profond final (verdict)

| Critère | Statut |
|---------|--------|
| Intégration Graphiti dans le flux Cursor | ✅ **Renforcée** (règles + doc + liens) |
| Sub-agents Cursor dans le flux | ✅ **Documentés** et liés au Primer + PRIOR_CONTEXT |
| Terminal `claude` / `codex` | ✅ **Cadrés** (complément, pas SSOT gates) |
| Tokens / contexte | ✅ **Politique clarifiée** — optimisation **sans** effet négatif |
| Évolution mémoire dans le temps | ✅ **Procédure écrite** (checklist + README) |
| Complétude Neo4j | ⚠️ **124/180** — inchangé sans ingest ; **non bloquant** pour merge code, **bloquant** pour mémoire « pleine » |

**Verdict global** : le **système d’orchestration + mémoire** est **plus robuste** après cette passe. La **dernière mile** reste **opérationnelle** : compléter l’index Neo4j (P0 ingest) quand tu lances la commande sur une machine avec MCP.

---

## H — Fichiers modifiés / créés (pour commit)

- `docs/orchestration/GLOBAL_SYSTEM_PRIMER.md` **(créé)**
- `docs/orchestration/AGENT_ROLES.md`
- `AGENTS.md`
- `.cursor/rules/graphiti-memory.mdc`
- `.cursor/rules/global.mdc`
- `.cursor/rules/context-hygiene.mdc`
- `.cursor/rules/project-continuity.mdc`
- `memory/README.md`
- `reports/audit/AUDIT_SECOND_PASS_GLOBAL_GOVERNANCE_REPORT_2026-04-22.md` **(ce rapport)**
