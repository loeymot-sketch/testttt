# RUN — T-PARCOURS-OPTIMIZE-001 — Optimisation parcours obligatoire (qualité-positive)

**Date** : 2026-04-24
**TASK_ID** : `T-PARCOURS-OPTIMIZE-001`
**Plan** : `plans/PLAN_T-PARCOURS-OPTIMIZE-001_2026-04-24.md`
**Verdict** : ✅ **CLOSED — PASS**

---

## Résumé exécutif

L'audit production du **parcours obligatoire** a révélé un **gaspillage de ~10–11 k tokens** à chaque ouverture de session, concentré dans `.cursor/ACTIVE_CYCLE.md` qui était devenu un cimetière de cycles `COMPLETED PASSED`. Deux modifications **qualité-positives** (rien d'amputé) ont été appliquées :

| Mod | Cible | Délégation | Avant | Après | Gain |
|---|---|---|---:|---:|---:|
| **M1** | `.cursor/ACTIVE_CYCLE.md` (split) | `cursor-direct` (mécanique) | 11 435 tk | 500 tk | **−10 935 tk** |
| **M2** | `AGENTS.md` (Quick start contract en tête) | `codex-terminal` (gpt-5.4) | 7 510 tk | 8 200 tk (+ chemin court 600 tk) | **+intelligence d'index** |

## Métriques de coût session

| | Strict (full read) | Intelligent (P0 §0 + §1 + ACTIVE_CYCLE) |
|---|---:|---:|
| Avant | ~35 265 tokens | impossible (pas d'index) |
| **Après** | **~25 020 tokens** (−29%) | **~10 200 tokens** (−71%) |
| Récurrent par tour (alwaysApply) | ~7 470 | ~7 470 (inchangé, incompressible) |

## EXECUTE_DELEGATION

- **M1** (split `ACTIVE_CYCLE.md`) : `EXECUTE_DELEGATION: cursor-direct` — split déterministe, aucun apport IA possible.
- **M2** (Quick start contract) : `EXECUTE_DELEGATION: codex-terminal` — modèle `gpt-5.4`, durée ≈ 50 s, 1 round, 0 retry, output JSON parfaitement conforme aux 13 hard_constraints (table 3 colonnes, 36 lignes, bash bloc, sections nommées). Mission : `missions/T-PARCOURS-OPTIMIZE-001/` (input.json + graphiti_context.md + plan_excerpt.md + output_codex.json).

## VALIDATE

- Tailles : `wc -c` : ACTIVE_CYCLE 1 985 / ARCHIVE 44 757 / AGENTS.md 32 805 — tous dans la cible.
- Structure AGENTS.md : `H1 → ## 0 Quick start → ## 1 Parcours obligatoire → ## Engine → ...` — sections existantes byte-pour-byte préservées.
- Liens : 8/8 chemins cités dans le Quick start existent sur disque (vérifié par claude-terminal).
- `npm run verify:boucle` : `CONDITIONAL` (binaire claude OK ; smoke API non requis en mode défaut).
- `agent-activity-log.sh` : `start` + `done` tracés.

## AUDIT

- **AUDIT_CHANNEL: claude-terminal** (PRIMARY, abonnement Anthropic, `claude` 2.1.90).
- **TERMINAL_AUDIT_OK: 1**
- Verdict claude : **PASS** — zéro issue, zéro recommandation.
- Évidence claude (extraits) :
  - « AGENTS.md structure confirmed: H1 → §0 Quick start → §1 Parcours obligatoire — séquence intacte, aucune section déplacée ou amputée. »
  - « Tous les chemins référencés dans le bloc Quick start (8/8) existent sur disque — zéro lien cassé. »
  - « Règle quality-first explicitement renforcée dans §0. »

## Décision durable enregistrée

`memory/episodes/12_decisions_log.jsonl` (manifeste regénéré).

## Conclusion

Le parcours obligatoire reste **intégral et non-négociable** : aucune règle n'a été supprimée, aucun trait de gouvernance affaibli. La modification a uniquement déplacé une archive humaine hors du chemin chaud et donné aux agents intelligents un **index P0/P1/P2** pour prioriser leur lecture sans sacrifier la rigueur. L'utilisateur peut continuer à travailler en production sans changer ses habitudes ; les nouveaux agents (humains ou LLM) ouvrant le repo économisent ~10 k tokens à chaque session sans perte de contexte.
