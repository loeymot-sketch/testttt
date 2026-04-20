# T04 — kioskPerf K-5 : régression instrumentation

**Date** : 2026-04-20  **Statut** : PENDING  **Subagent** : `explore`

## Objectif unique

`resources/js/helpers/kioskPerf.js` a été supprimé sous `testttt-kiosk-p93` mais
`kioskPerfBudgets.js` reste (orphelin). Évaluer impact K-5 (cold-start, FCP, LCP, INP, CLS,
heap drift, long tasks) et K-9 (`perf.*` whitelist).

## Subagent à lancer (prompt prêt à coller)

```
Tu es un sous-agent `explore`. Racine : /Users/1millnonstop/Downloads/projet/foodking-web/web/testttt-kiosk-p93.

Étapes :
1) Confirmer absence : `find resources/js/helpers -name "kioskPerf*"`.
2) Lister les usages :
   - `rg -n "kioskPerf|perf\.cold_start|perf\.fcp|perf\.lcp|perf\.inp|perf\.cls|perf\.heap_sample|perf\.long_task" -g '!node_modules'`
3) Lire `resources/js/components/frontend/kiosk/KioskAppComponent.vue` (mounted/beforeUnmount).
4) Lire `resources/js/helpers/kioskPerfBudgets.js` (orphelin ? consommé par autre chose ?).
5) Lire `resources/js/helpers/kioskAnalytics.js` : whitelist `perf.*` toujours présente ?
6) Lire backend `app/Http/Controllers/Frontend/KioskEventController.php` : familles
   `perf.*` toujours autorisées ?
7) Lire tests : `tests/js/kioskPerf*.spec.js` — encore présents ? skipped ?
8) Lire reports/execution/RUN_K5_PERFORMANCE_STABILITY_2026-04-18.md + VERIFY_K5_*
   pour cibles K-5 (zéro leak 24 h, 100 orders/h rush, FCP/LCP budgets).

Verdict :
- A. Renommage / fusion dans un autre helper.
- B. Désactivation volontaire (toggle).
- C. Régression silencieuse → P0/P1 K-5 + K-9.

Sortie : /Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/reports/audit-orchestration/REPORT_TASK04_KIOSK_PERF_K5_2026-04-20.md
```

## Lecture obligatoire

- `testttt-kiosk-p93/resources/js/helpers/kioskPerfBudgets.js`
- `testttt-kiosk-p93/resources/js/helpers/kioskAnalytics.js`
- `testttt-kiosk-p93/app/Http/Controllers/Frontend/KioskEventController.php`
- `testttt-kiosk-p93/resources/js/components/frontend/kiosk/KioskAppComponent.vue`
- `testttt-kiosk-p93/tasks/k-hardening/PLAN_K5_PERFORMANCE_STABILITY_2026-04-18.md`
- `testttt-kiosk-p93/reports/execution/VERIFY_K5_PERFORMANCE_STABILITY_2026-04-18.md`

## Checklist multi-points

- [ ] V1. Absence `kioskPerf.js` confirmée
- [ ] V2. Aucun import orphelin
- [ ] V3. Statut `kioskPerfBudgets.js` (consommé / orphelin)
- [ ] V4. Whitelist `perf.*` côté frontend (`kioskAnalytics`)
- [ ] V5. Whitelist `perf.*` côté backend (`KioskEventController`)
- [ ] V6. Tests Vitest perf : présents / skipped / supprimés
- [ ] V7. Wiring `KioskAppComponent.mounted` toujours appelle `kioskPerf.start()` ?
- [ ] V8. Verdict + impact chiffré sur garanties K-5

## Critères PASS / FAIL

- **PASS** : remplacement documenté, garanties K-5 toujours satisfaites.
- **FAIL** : régression confirmée → backlog K-10.1 ou hotfix.

## Output

`reports/audit-orchestration/REPORT_TASK04_KIOSK_PERF_K5_2026-04-20.md`

## Si FAIL → action

→ T04b `generalPurpose` : restaurer depuis git ou réécrire le minimum vital (cold_start +
heap_sample + long_task) ; budgets restent dans `kioskPerfBudgets.js`. Patch proposé.
