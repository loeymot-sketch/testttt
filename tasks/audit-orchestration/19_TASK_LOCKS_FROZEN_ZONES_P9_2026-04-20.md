# T19 — Locks P9 + frozen zones : gouvernance EXECUTE

**Date** : 2026-04-20  **Statut** : PENDING  **Subagent** : `explore`

## Objectif unique

Vérifier que **chaque LOCK_A / LOCK_B** ouvert pendant les phases P9.x a été **fermé**
correctement, que les **frozen zones** (FrontendOrderService, OrderService, PricingService,
OrderStateMachine) ne contiennent **aucun changement non-couvert** par un lock + gate
humaine documentée. Aussi : valider qu'aucun BLOCKER `tasks/phase9*` ne reste ouvert
silencieusement.

## Subagent à lancer (prompt prêt à coller)

```
Tu es un sous-agent `explore`. Racines :
A = /Users/1millnonstop/Downloads/projet/foodking-web/web/testttt
B = /Users/1millnonstop/Downloads/projet/foodking-web/web/testttt-kiosk-p93

Étapes :
1) Lister tous les LOCK :
   `find tasks/phase9-sync tasks/phase9 -name 'LOCK_*' -o -name 'BLOCKER_*'` sur A et B.
2) Pour CHAQUE LOCK : statut (ouvert / fermé) — convention : footer "Lock released
   <date>" ou statut explicite.
3) Pour CHAQUE BLOCKER : statut (open / resolved). Si open → escalade.
4) Lire :
   - reports/execution/PLAN_PHASE_9_KIOSK_2026-04-18.md (section SUBSYSTEMS_TOUCHED +
     Frozen zones + ESCALATION + SYMMETRY_NOTE)
5) `git log --since=2026-04-15 -- app/Services/FrontendOrderService.php app/Services/OrderService.php app/Services/PricingService.php app/Services/OrderStateMachine.php`
   → chaque commit touchant frozen doit mapper à un LOCK_A correspondant.
6) Identifier commits orphelins (touchent frozen sans LOCK).
7) Vérifier broadcast P9.5 merged : tasks/phase9-sync/BROADCAST_P9_5_MERGED_2026-04-18.md.
8) SYMMETRY_NOTE : vérifier que les notes de symétrie OrderService↔FrontendOrderService
   sont à jour pour P9.5.1 et P9.5.5.

Sortie : /Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/reports/audit-orchestration/REPORT_TASK19_LOCKS_FROZEN_ZONES_2026-04-20.md
```

## Lecture obligatoire

- `tasks/phase9-sync/LOCK_*.md`, `tasks/phase9-sync/BLOCKER_*.md`
- `tasks/phase9/P9_2_BLOCKER_SCOPE_GOVERNANCE_2026-04-18.md`
- `reports/execution/PLAN_PHASE_9_KIOSK_2026-04-18.md`

## Checklist multi-points

- [ ] V1. Liste exhaustive LOCK_A / LOCK_B avec statut
- [ ] V2. Liste exhaustive BLOCKER avec statut
- [ ] V3. 0 BLOCKER open silencieux
- [ ] V4. Chaque commit frozen mappé à un lock
- [ ] V5. Aucun commit orphelin sur frozen zones
- [ ] V6. SYMMETRY_NOTE à jour P9.5
- [ ] V7. ESCALATION P9.2 résolue (commit af4139b01)
- [ ] V8. BROADCAST_P9_5_MERGED présent

## Critères PASS / FAIL

- **PASS** : 8 V cochées, 0 lock zombie.
- **FAIL** : ≥ 1 lock ouvert ou commit orphelin.

## Output

`reports/audit-orchestration/REPORT_TASK19_LOCKS_FROZEN_ZONES_2026-04-20.md`

## Si FAIL → action

→ T19b `generalPurpose` : fermer locks zombies + producer notes symétrie manquantes
(documentation only, pas de code).
