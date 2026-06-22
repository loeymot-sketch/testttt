# T04b — Restauration / implémentation kioskPerf.js K-5.7 (2026-04-20)
## task_id: T04b
## PRIMARY_MODEL: gpt-5.4-high
## cycle scope: réimpl helper perf instrumentation
## Recherche historique
- git log : aucun historique suivi pour `resources/js/helpers/kioskPerf.js` (`git log --all` et `--diff-filter=A` vides)
- reflog : aucune entrée liée à `kioskPerf.js`, uniquement des commits/résolutions de la phase kiosk
- stash : `stash@{0}` et `stash@{1}` ne contiennent pas `kioskPerf.js`
- cross-worktree : aucun autre `kioskPerf.js` trouvé sous `foodking-web`; fichier absent côté clone `testttt`
- versions trouvées : none
- Décision : réimpl from scratch
## Fichiers modifiés
| Path | Δ | Justification |
|---|---:|---|
| `testttt-kiosk-p93/resources/js/helpers/kioskPerf.js` | 0 octet -> impl complète | Restaurer l'instrumentation K-5.7, réactiver les 9 familles `perf.*`, intégrer `kioskPerfBudgets` et respecter le contrat Vitest + `KioskAppComponent` |
| `reports/execution/RUN_T04B_KIOSK_PERF_2026-04-20.md` | new | Rapport d'exécution demandé pour T04b |
| `reports/post_execute_latest.log` | append | Trace d'exécution demandée par l'orchestrateur |
## Émissions perf.* implémentées
| Event | Source | Cadence |
|---|---|---|
| `perf.cold_start` | `performance.getEntriesByType('navigation')[0].domInteractive` avec fallback `performance.timing` | une fois par cycle de page |
| `perf.fcp` | `PerformanceObserver('paint')` filtré sur `first-contentful-paint` | une fois par observation utile |
| `perf.lcp` | `PerformanceObserver('largest-contentful-paint')` + flush `visibilitychange/pagehide` | à la fermeture/masquage de page |
| `perf.inp` | `PerformanceObserver('event')` + percentile p98 roulant | à chaque mise à jour de l'échantillon |
| `perf.cls` | `PerformanceObserver('layout-shift')` sans `hadRecentInput` | cumul roulant |
| `perf.long_task` | `PerformanceObserver('longtask')` | à chaque long task |
| `perf.heap_sample` | `performance.memory.usedJSHeapSize` | immédiat + toutes les 30 s |
| `perf.heap_drift` | dérive entre deux échantillons heap successifs | à chaque échantillon exploitable après le premier |
| `perf.over_budget` | `budgetFor()` / `isOverBudget()` depuis `kioskPerfBudgets.js` | à chaque violation détectée |
## Garde-fous (feature detection / anti-leak / idempotence)
- Feature detection stricte : `window`, `PerformanceObserver`, `performance`, `performance.memory`
- SSR-safe : émission désactivée si `window` absent ou si `kioskAnalytics.track` indisponible
- `start()` idempotent : un seul setup actif, pas de double binding
- `stop()` symétrique : `disconnect()` de tous les observers, `clearInterval()` du sampler heap, retrait des listeners `visibilitychange` / `pagehide`
- Tolérance test/runtime : exports nommés `start`, `stop`, `__peekStateForTests`, `__resetForTests` + `default export` pour le consommateur Vue
## Tests
- Vitest `kioskK5PerfInstrumentation.spec.js` : 13/13 pass (blocs K-5.6, K-5.7, K-5.8, K-5.9)
- Vitest full (optionnel) : non exécuté
## SYMMETRY_NOTE: N/A
## ESCALATION (le cas échéant)
- None
## Anomalies flagged
- Warning non bloquant dans Vitest : `baseline-browser-mapping` annoncé comme ancien de plus de deux mois
## Audit status: PENDING_CLAUDE_REVIEW
