# GPT (codex-extension) — audit production parcours

**Mission** : `PROD-CHK-PARCOURS-2026-04-25`  
**Source** : extrait de `missions/PROD-CHK-PARCOURS-2026-04-25/output_codex.raw.log` (bloc JSON final).

## GPT_AUDIT_VERDICT

**GO_WITH_CAVEATS**

## Caveats (max 5)

1. Le démarrage « orchestration Claude » n’est **pas** automatique au niveau moteur Cursor ; il dépend du modèle choisi et du respect des règles repo.
2. Les checkpoints sont portés par fichiers et scripts, mais **ne s’activent pas** si personne ne lance `session:open` / `run-cycle` / preflight / post-guard.
3. Si `verify:boucle` exit 1 (binaire `claude` absent), l’audit terminal PRIMARY est bloqué → fallback `cursor-session` + `AUDIT_FALLBACK_REASON` jusqu’à correction PATH.
4. `ACTIVE_CYCLE` peut pointer un autre `TASK_ID` qu’une mission ponctuelle — aligner avant un cycle « preuve ».
5. Preflight/post-guard couvrent surtout EXECUTE/VALIDATE ; ils ne routent **pas** le premier message du chat vers Claude.

## Affirmations demandées

| Affirmation | Verdict GPT |
|---|---|
| N’importe quel modèle Cursor commence **automatiquement** en orchestration Claude | **Infirmée** (faux) |
| Tous les checkpoints sont **mécaniquement forcés** sans action humaine | **Infirmée** (faux) |

## Recommandation GPT

Rendre `npm run session:open` puis `run-cycle <TASK_ID>` le **point d’entrée unique** documenté (wrapper éventuel) et refuser EXECUTE si `ACTIVE_CYCLE.TASK_ID` ≠ mission courante (renforcement futur).
