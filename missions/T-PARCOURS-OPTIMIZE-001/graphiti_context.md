# Graphiti context — T-PARCOURS-OPTIMIZE-001

## Décisions durables récentes (extrait Graphiti / decisions_log)

- **2026-04-24** Symétrie terminal-first : `codex-terminal` (PRIMARY EXECUTE complexe) + `claude-terminal` (PRIMARY AUDIT) ; sub-agents Cursor en fallback.
- **2026-04-24** Parcours obligatoire ajouté en tête d'`AGENTS.md` (§1) + `global.mdc` `## New or continued session — mandatory path` (alwaysApply).
- **2026-04-24** Memory Matrix officielle : 4 stores autorisés (Code A · Graphiti+JSONL B · Mission C · Reports/Cycle D).
- **2026-04-23** Sync multi-agents : `agent-activity-log.sh` + `cross-agent-sync.mdc` (alwaysApply).
- **2026-04-24** Audit production révèle : parcours obligatoire = ~35 k tokens à l'ouverture, dont ~11 k gaspillés dans `ACTIVE_CYCLE.md` (cycles COMPLETED). Décision : split (M1 fait localement) + ajout Quick start contract en tête d'AGENTS.md (M2, cette mission).

## Invariants (extraits)

- Toute modification de gouvernance doit préserver : `EXECUTE_DELEGATION:`, `AUDIT_CHANNEL:`, `TERMINAL_AUDIT_OK:`, `FALLBACK_REASON:`.
- Aucun nouveau store mémoire sans `docs/gates/GATE_MEMORY_*`.
- `global.mdc § Token Discipline` interdit les "économies négatives" (suppression de substance pour économiser).

## Contexte technique pour cette mission

- AGENTS.md actuel : 451 lignes, ~30 000 caractères, ~7 510 tokens.
- Le but du Quick start contract : permettre à un agent de lire 3 sections (~600 tokens) plutôt que les 451 lignes intégrales pour démarrer un cycle borné, **tout en sachant** que le détail est lisible à la demande.
- Il s'agit d'un **index intelligent**, pas d'un raccourci qui amputerait les obligations.
