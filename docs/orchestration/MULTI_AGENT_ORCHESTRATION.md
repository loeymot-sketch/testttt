# Multi-agents, Graphiti, et synchronisation (sans conflit)

> **Lecture cible** : toute personne / agent qui travaille en **parallèle** (plusieurs conversations Cursor, terminal `claude` / `codex`, humain).  
> **Règle d’or** : le **chat n’est pas la SSOT** — le **dépôt** l’est (`AGENTS.md`, `run-cycle`, matrice ci-dessous).

---

## 1. Les trois piliers (déjà dans le dépôt)

| Pilier | Rôle | Fichier / outil |
|--------|------|-----------------|
| **Mémoire longue durée (décisions)** | Faits stables, invariants, ADR, `group_id: foodking` | **Store B** : Graphiti MCP + `memory/episodes/*.jsonl` — voir `MEMORY_MATRIX.md` |
| **Coordination concurrente (qui touche quoi)** | Réservation de chemins, pas de double édition surprise | **Store D** : `reports/AGENT_ACTIVITY_LOG.md` + `bash scripts/agent-activity-log.sh` — voir `cross-agent-sync.mdc` |
| **État du cycle (une tâche à la fois par TASK_ID)** | Phase, plan, verdict audit | **Store D** : `.cursor/ACTIVE_CYCLE.md` + `plans/PLAN_*.md` + `AUDIT_VERDICT` / `REMEDIATION_AUDIT_CYCLE` |

Aucun agent n’a besoin d’un “chat partagé” : il lit **B + D** avant d’agir, et **écrit** seulement là où sa phase le permet (voir `MEMORY_MATRIX.md`).

---

## 2. Ce que Graphiti partage (et ne partage pas)

- **Partagé** entre agents : `search_memory_facts` / nœuds avec **`group_id: foodking`** — mêmes faits pour tous les MCP qui tournent sur le **même backend** Neo4j.
- **Pas partagé** : le **contexte de fenêtre** d’une conversation Cursor. Chaque onglet ne “voit” pas l’autre ; la **synchronisation** = **fichiers** (D) + **Graphiti** (B) + discipline `start`/`done`.
- **Ne pas** écrire des hypothèses brutes dans Graphiti pendant EXÉCUTE : les écritures durables **B** viennent surtout **AUDIT/CLOSE** (voir `graphiti-memory.mdc`).

---

## 3. Comportement obligatoire (checklist)

**Au démarrage d’une session** (même un nouvel onglet) :

1. `bash scripts/agent-activity-log.sh tail 50`
2. Lire **`.cursor/ACTIVE_CYCLE.md`** (ne pas dupliquer un `TASK_ID` en cours ailleurs sans accord)
3. Si MCP chargé : au moins une requête `search_memory_facts` ciblant le sujet, `group_ids=["foodking"]` ; sinon `memory/INDEX.md`

**Avant toute édition produit** (Step 2 EXECUTE) :

```bash
bash scripts/agent-activity-log.sh start <AGENT> <TASK_ID> execute "<fichiers.csv>" "<note>"
```

Refus (exit 2) = **stop** : autre agent sur le même périmètre — attendre, réduire le scope, ou coordination humaine.

**À la fin (CLOSE ou abandon)** : `… done` pour libérer (sinon “réservation fantôme”).

---

## 4. Rôles (qui tranche quoi) — cohérent `run-cycle.md`

| Rôle | Canal typique | Décide |
|------|---------------|--------|
| **Plan + arbitrage invariants** | Session Cursor (Claude) + option terminal `context` | Contenu de `plans/PLAN_*.md`, `SUBSYSTEMS_TOUCHED` |
| **Exécution lourde / diff large** | **`codex-extension`** (CLI `codex` + Pro) + missions `input.json` | Patch selon le plan, JSON `output_codex.json` |
| **Auto-contrôle pré-audit** | Même exécution (wrapper) | `reports/audit/GPT_SELF_AUDIT_*.md` (outil, pas remplacement de l’audit Claude) |
| **Acceptation / refus de livraison** | **Claude en terminal** (`foodking-claude-orchestrate.sh` audit) PRIMARY | `AUDIT_VERDICT: PASS` ou `REWORK` ; clôture **jamais** sans `PASS` |
| **Routine** | `foodking-routine-implementer` | Tâches bornées, hors frozen |

“Maximum intelligence” ne veut **pas** dire mélanger les rôles : le **décideur d’acceptation** reste l’**audit terminal** (sauf `AUDIT_FALLBACK_REASON:` documenté).

---

## 5. Comparaison “Claude mem / Squad / équipes d’agents” (références Web)

- Beaucoup de patterns **repos GitHub** promettent une *mémoire d’équipe* : en pratique, ils orchestrent des **sous-tâches** + **fichiers** + parfois une base externe. FoodKing a **choisi** de **ne pas** intégrer d’outils type `claude-mem` en parallèle (voir `MEMORY_MATRIX.md` §4 — AGPL, recouvrement) : **Graphiti (B) + D** suffisent **si** la politique `start`/`done` + `run-cycle` est suivie.
- L’**équivalent “squad”** ici = **même `TASK_ID`** dans `ACTIVE_CYCLE` (un seul meneur) **ou** `TASK_ID` disjoints + réservations de fichiers disjointes.

---

## 6. Anti-patterns (à ne pas faire)

- Réserver `app/` entier “au cas où” → bloque tout le monde (`cross-agent-sync.mdc`).
- S’appuyer sur le thread de chat pour l’“état du projet” → **dérive** ; l’état = **D** + **B**.
- Fermer un cycle **sans** `AUDIT_VERDICT: PASS` parce que les tests sont verts → **interdit** (`run-cycle` Step 5).
- Cinq `REWORK` sur le même `TASK_ID` sans progrès → `HUMAN_GATE` (débloquer scope ou stratégie) — `auto-remediation.mdc`.

---

## 7. Point d’entrée rapide (nouvel agent / nouvel onglet)

1. `AGENTS.md` — *Parcours obligatoire*  
2. `GLOBAL_SYSTEM_PRIMER.md` — table §1  
3. `MEMORY_MATRIX.md` — stores A–D  
4. Ce fichier  
5. `ACTIVE_CYCLE.md` + `PLAN_FILE` s’il est set  

Ensuite exécution du cycle : `.cursor/commands/run-cycle.md`.
