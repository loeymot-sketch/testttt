# Command Deck — FoodKing (index pur)

> **Rôle** : un seul fichier à connaître pour retrouver les bonnes commandes et les bonnes SSOT.
> **Anti-pattern à éviter** : ce fichier ne **stocke** rien (pas un store mémoire D bis). Il **pointe** vers les fichiers qui font autorité.

---

## 1. Ouvrir une session (humain ou IA)

```bash
npm run session:open          # tail log + verify:boucle + résumé ACTIVE_CYCLE + bloc à coller
npm run session:open:fast     # idem sans verify:boucle (plus rapide)
```

Bloc d'ouverture imposé : [docs/orchestration/SESSION_OPENING_ENFORCEMENT.md](SESSION_OPENING_ENFORCEMENT.md)  
(inclut la règle **PLAN = modèle Claude** dans Cursor — Auto/Composer ne remplacent pas `routing.md`).

**Simulation checkpoints production** : [reports/audit/SIMULATION_PARCOURS_PROD_CHECKPOINTS_2026-04-25.md](../../reports/audit/SIMULATION_PARCOURS_PROD_CHECKPOINTS_2026-04-25.md)

**Simulation Master Play POS / Borne / KDS** (challenge Claude ↔ GPT Pro) : [docs/orchestration/SIM_MASTERPLAY_POS_BORNE_KDS_CHALLENGE.md](SIM_MASTERPLAY_POS_BORNE_KDS_CHALLENGE.md) — **synthèse finale** : [reports/audit/SIM_MASTERPLAY_FINAL_CONSOLIDATED_2026-04-25.md](../../reports/audit/SIM_MASTERPLAY_FINAL_CONSOLIDATED_2026-04-25.md) — Round4 terminal : [SIM_MASTERPLAY_CLAUDE_TERMINAL_ROUND4_2026-04-25.md](../../reports/audit/SIM_MASTERPLAY_CLAUDE_TERMINAL_ROUND4_2026-04-25.md).

**Challenge multi-tours (terminal) Codex `exec` ↔ Claude `audit` — dispute + rapport final consolidé** : [docs/orchestration/CHALLENGE_CODEX_CLAUDE_TERMINAL_PLAYBOOK.md](CHALLENGE_CODEX_CLAUDE_TERMINAL_PLAYBOOK.md) — invites prêtes sous `docs/orchestration/challenge-prompts/`.

**Codex (CLI) : config MCP Graphiti + lancer l’audit Claude au même poste** : [docs/orchestration/CODEX_MCP_CLAUDE_TERMINAL_SETUP.md](CODEX_MCP_CLAUDE_TERMINAL_SETUP.md) — `codex mcp add` + `scripts/codex-invoke-claude-audit.sh` + règles d’injection.

## 2. Démarrer / reprendre un cycle borné

```bash
run-cycle <TASK_ID>           # commande Cursor — voir .cursor/commands/run-cycle.md
```

État du cycle actif : `.cursor/ACTIVE_CYCLE.md` (lecture seule pour l'agent ; écriture en Step 0/5 du cycle).

## 3. Avant toute édition produit (EXECUTE)

Toujours dans cet ordre :

```bash
# 1. Réserver le périmètre (atomique, refus si collision)
bash scripts/agent-activity-log.sh start <AGENT> <TASK_ID> execute "<f1,f2,dir/>" "<note>"

# 2. Garde-fou exécutable (refus si scope déclaré dépasse réservation)
bash scripts/preflight-execute.sh <TASK_ID> --scope="<f1,f2,dir/>"

# 3. Exécution proprement dite
npm run codex:plan-review -- <TASK_ID>   # obligatoire avant EXECUTE
npm run codex:prepare -- <TASK_ID>
npm run codex:complex -- <TASK_ID>      # complexe (PRIMARY)
# pas d’édition produit via Composer / sub-agent routine en cycles de finition
```

Modes du preflight : `--mode=product` (défaut), `--mode=governance`, `--mode=read-only`.
Bypass humain documenté : `--override="raison courte"` (journalisé).

## 4. Après l'EXECUTE, avant VALIDATE

```bash
bash scripts/post-execute-guard.sh <TASK_ID>
# refuse si :
#   - aucun EXECUTE_DELEGATION: dans reports/post_execute_latest.log
#   - fichier modifié hors scope réservé
```

## 5. Audit + close

```bash
# Audit terminal Claude (PRIMARY)
bash scripts/foodking-claude-orchestrate.sh context
bash scripts/foodking-claude-orchestrate.sh audit-brief    # ou: audit
# Si échec (quota / rate limit) : stderr affiche le repli — Task **foodking-planner-orchestrator**
# Doc : docs/orchestration/AUDIT_TERMINAL_QUOTA_FALLBACK.md

# Une fois AUDIT_VERDICT: PASS dans le REPORT_FILE :
npm run codex:final-audit -- <TASK_ID>
# Close seulement si GPT_FINAL_AUDIT_VERDICT: PASS aussi.
bash scripts/agent-activity-log.sh done <AGENT> <TASK_ID> done "résumé une ligne"
```

## 6. Team workflow (cycles avec ta-da liste de sous-tâches)

> Doctrine complète : [docs/orchestration/TEAM_WORKFLOW.md](TEAM_WORKFLOW.md)

```bash
npm run team:status                                         # dashboard équipe (lecture seule)
npm run team:run -- <TASK_ID> <TASK_ID>-S0N                 # 1 sous-tâche : lock + route difficulté
npm run team:audit:sub -- <TASK_ID> <TASK_ID>-S0N           # mini-audit Claude (single)
npm run team:audit:sub -- <TASK_ID> --batch <S0A> <S0B>     # mini-audit batch si le plan l'autorise
npm run team:audit:global -- <TASK_ID>                      # audit global (toutes sous-tâches DONE requis)
```

Machine d'état par sous-tâche : `TODO → PLAN_REVIEWED → CLAIMED → EXECUTED_BY_GPT → GPT_SELF_AUDITED → CLAUDE_MINI_PASS → GPT_FINAL_PASS → DONE`
(autres branches : `CLAUDE_MINI_REWORK/GPT_FINAL_REWORK → RETRY ≤3 → HUMAN_GATE`).
Pas de `[x]` avant double PASS.

## 7. Mémoire durable (post-AUDIT seulement)

Si l'audit acte une décision durable / invariant / ADR :

```bash
# 1. Append 1 ligne dans memory/episodes/<domaine>.jsonl (voir memory/INDEX.md)
# 2. Recalcul + ingest :
bash scripts/after-execute-memory.sh
```

Détails : [docs/orchestration/MEMORY_MATRIX.md](MEMORY_MATRIX.md) (4 stores autorisés A/B/C/D).

---

## SSOT (autorité) — par ordre de lecture

1. `AGENTS.md` § *Parcours obligatoire* — contrat global agents
2. `docs/orchestration/GLOBAL_SYSTEM_PRIMER.md` — primer / table de lecture
3. `.cursor/commands/run-cycle.md` — boucle PLAN→PLAN_REVIEW→EXECUTE→VALIDATE→AUDIT→GPT_FINAL_AUDIT→CLOSE
4. `docs/orchestration/MEMORY_MATRIX.md` — qui écrit quoi, quand, où
5. `docs/orchestration/MULTI_AGENT_ORCHESTRATION.md` — coordination multi-agents
6. `.cursor/rules/cross-agent-sync.mdc` — réservations parallèles (alwaysApply)
7. `docs/orchestration/SESSION_OPENING_ENFORCEMENT.md` — bloc à coller en début de session

## Codes de sortie utiles

| Script | Code | Sens |
|--------|------|------|
| `agent-activity-log.sh start` | 2 | Collision avec une réservation active (halt) |
| `preflight-execute.sh` | 2 | Pas de réservation `start` active pour ce TASK |
| `preflight-execute.sh` | 3 | ACTIVE_CYCLE incohérent avec TASK demandé |
| `preflight-execute.sh` | 4 | SCOPE déclaré dépasse scope réservé |
| `post-execute-guard.sh` | 1 | `EXECUTE_DELEGATION:` manquant après EXECUTE |
| `post-execute-guard.sh` | 4 | Diff produit hors scope réservé |

---

*Ce fichier n'est jamais lu par un script ; il sert uniquement à l'humain (ou à l'agent qui veut un index plat). Toute évolution procédure passe d'abord par `run-cycle.md` et `MEMORY_MATRIX.md`.*
