# Plan – SIM-MASTERPLAY-2026-04-25

## TASK_ID
SIM-MASTERPLAY-2026-04-25

## PRIMARY_MODEL
Claude (orchestration docs + rapports) **puis** codex-extension (Round 2 adversarial)

## SUBSYSTEMS_TOUCHED
| Subsystem | Scope | Read/Write | branch_id | Dispatch |
|-----------|--------|------------|-----------|----------|
| `docs/orchestration/SIM_MASTERPLAY_POS_BORNE_KDS_CHALLENGE.md` | méthodologie simulation | Write | No | No |
| `reports/audit/SIM_MASTERPLAY_BREAKDOWN_SYNTH_V0_2026-04-25.md` | breakdown V0 | Write | No | No |
| `missions/SIM-MASTERPLAY-2026-04-25/*` | mission Codex | Write | No | No |
| `plans/PLAN_SIM_MASTERPLAY_2026-04-25.md` | ce plan | Write | No | No |

## SUBSYSTEMS_OFF_LIMITS
- `app/`, `resources/` (code produit), `routes/`, `database/` — **aucune** modification dans ce cycle simulation sauf nouveau TASK_ID explicite.

## INVARIANTS_AT_RISK
None (meta-documents uniquement).

## GATE_CONDITIONS
None anticipated.

## Execution Steps
1. Publier V0 breakdown + doc challenge (fait ou par orchestrateur).
2. `npm run codex:complex -- SIM-MASTERPLAY-2026-04-25` (Round 2 GPT Pro).
3. Humain / Claude Round 3 : fusion → `SIM_MASTERPLAY_SYNTH_FINAL_*.md` + entrées JSONL si décisions stables.

## SUBTASKS (optionnel)
| SUBTASK_ID | Description | Difficulty | Owner | Invariants | Mini-audit | Status | Retry |
|------------|-------------|------------|-------|------------|------------|--------|-------|
| SIM-MASTERPLAY-2026-04-25-S01 | Doc challenge + V0 breakdown | routine | claude (manuel) | None | batch-eligible | DONE | 0 |
| SIM-MASTERPLAY-2026-04-25-S02 | Codex adversarial Round 2 | complex | codex-extension | None | 1:1 | TODO | 0 |
| SIM-MASTERPLAY-2026-04-25-S03 | Synthèse finale + Graphiti | complex | claude-terminal | None | 1:1 | TODO | 0 |

## Audit Status
[ ] Pending
[ ] Passed
[ ] Gate opened
