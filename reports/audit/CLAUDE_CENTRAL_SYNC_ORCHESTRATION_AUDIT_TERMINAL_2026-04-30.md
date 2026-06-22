# Claude terminal — audit centralisation sync / orchestration VA-SYS — 2026-04-30

## Statut

- **Modèle** : `claude-opus-4-7` · **Effort** : `max` (`FOODKING_CLAUDE_TERMINAL_EFFORT=max`)
- **Résultat** : **non exécuté** — limite compte Anthropic / Claude Code :
  ```
  You've hit your limit · resets 11am (Europe/Paris)
  ```
- **Durée** : ~11 s (refus immédiat)

## Prompt archivé (relancer après reset quota)

`reports/audit/_CENTRAL_SYNC_ORCHESTRATION_CLAUDE_AUDIT_PROMPT_2026-04-30.txt`

## Commande à relancer (après 11h Europe/Paris ou hausse de limite)

```bash
cd /Users/1millnonstop/Downloads/projet/foodking-web/web/testttt
FOODKING_CLAUDE_TERMINAL_MODEL=claude-opus-4-7 FOODKING_CLAUDE_TERMINAL_EFFORT=max \
  bash scripts/foodking-claude-orchestrate.sh audit "$(cat reports/audit/_CENTRAL_SYNC_ORCHESTRATION_CLAUDE_AUDIT_PROMPT_2026-04-30.txt)" \
  | tee reports/audit/CLAUDE_CENTRAL_SYNC_ORCHESTRATION_AUDIT_TERMINAL_RETRY.md
```

(Si `max` pose problème au CLI, essayer `FOODKING_CLAUDE_TERMINAL_EFFORT=high`.)

## Repli

`docs/orchestration/AUDIT_TERMINAL_QUOTA_FALLBACK.md` — même checklist en session Cursor / Task **foodking-planner-orchestrator** avec traces `AUDIT_FALLBACK_REASON`.
