# Plan – HOTFIX_OBSERVABILITY_001 – 2026-04-20

## TASK_ID
HOTFIX_OBSERVABILITY_001_2026-04-20

## PRIMARY_MODEL
GPT-5.4

## Origin

Cycle hotfix décidé par Claude (planner / orchestrator) suite aux audits READ-ONLY :

- `reports/audit-orchestration/REPORT_TASK03_SENTRY_FRONT_2026-04-20.md` (FAIL P0 sub-verdict C)
- `reports/audit-orchestration/REPORT_TASK04_KIOSK_PERF_K5_2026-04-20.md` (FAIL P0 sub-verdict C)

État constaté : deux livrables K-9 / K-5 sont des stubs **0 octet untracked** sous `testttt-kiosk-p93`, cassent les suites Vitest correspondantes, et laissent l'instrumentation observability/perf inopérante en production.

## Pre-execute checklist (developer / orchestrator)

- [x] `.cursor/hooks/safety-check.sh` exécuté en début de session par l'orchestrateur Claude — sortie : `[safety-check] Frozen zones: OK` / `[safety-check] Passed. Proceed with execution.` (cf. transcript orchestration 2026-04-20).
- [x] Aucun frozen zone ciblé (FrontendOrderService / PricingService / OrderService / migrations / auth) — confirmé ci-dessous.
- [x] Périmètre étroit, deux fichiers cibles + leurs specs Vitest.

## SUBSYSTEMS_TOUCHED

| Subsystem | Scope | Read/Write | branch_id affected | Dispatch involved |
|---|---|---|---|---|
| `testttt-kiosk-p93/resources/js/observability/sentry.js` | Restauration / réimplémentation ADR-9 (init no-op si DSN absent, beforeSend/beforeBreadcrumb, scrubString/scrubObject patterns PII) | Write | No | No |
| `testttt-kiosk-p93/resources/js/helpers/kioskPerf.js` | Restauration / réimplémentation K-5.7 (PerformanceObservers FCP/LCP/INP/CLS/longtask, heap sampling 30 s, cold start, intégration `kioskPerfBudgets.js`, émission via `kioskAnalytics.track('perf.*', …)`) | Write | No | No |
| `testttt-kiosk-p93/tests/js/kioskSentryBoot.spec.js` | Lecture (le test fait foi). Modification UNIQUEMENT si une assertion exige une signature contradictoire avec une implémentation simple — alors documenter dans le rapport. | Read (Write conditionnel motivé) | No | No |
| `testttt-kiosk-p93/tests/js/kioskK5PerfInstrumentation.spec.js` | Idem ci-dessus. | Read (Write conditionnel motivé) | No | No |
| `testttt-kiosk-p93/package.json` | UNIQUEMENT pour ajouter `@sentry/vue` en `optionalDependencies` SI une décision explicite de l'orchestrateur le demande. Par défaut : ne pas toucher (stratégie ADR-1 = dynamic import + no-op). | Read (Write seulement sur GO orchestrateur) | No | No |

## SUBSYSTEMS_OFF_LIMITS

- `app/Services/FrontendOrderService.php`
- `app/Services/Pricing/PricingService.php`
- `app/Services/OrderService.php`
- `app/Services/PaymentService.php`
- `app/Http/Controllers/Frontend/OrderController.php`
- `app/Http/Controllers/Frontend/KioskEventController.php` (whitelist `perf.*` déjà alignée — n'y toucher sous AUCUN prétexte)
- `resources/js/helpers/kioskAnalytics.js` (whitelist front déjà alignée)
- `resources/js/helpers/kioskPerfBudgets.js` (référence — ne pas modifier la table de budgets)
- `app/Console/Kernel.php` (déjà restauré par T02b — ne pas réécrire)
- Toute migration `database/migrations/*`
- Toute middleware d'auth, guard, token Sanctum
- Toute référence `branch_id` ou logique de scoping multi-branche

## INVARIANTS_AT_RISK

- **Backend pricing SSOT** : aucun risque (cibles 100 % JS observability).
- **OrderStatus enum** : aucun usage prévu.
- **branch_id isolation** : aucun usage.
- **Dispatch after DB commit** : aucun dispatch backend dans le scope.
- **OrderService / FrontendOrderService symmetry** : non touchés → SYMMETRY_NOTE = N/A.
- **PII scrubbing K-9 ADR-9** : invariant à RESTAURER côté front Sentry (c'est l'objet de T03b — ne pas régresser le contrat backend `App\Observability\SentryBridge` qui reste hors scope).
- **Performance budgets K-5** : invariant à RÉACTIVER côté instrumentation (T04b — ne pas modifier `kioskPerfBudgets.PERF_BUDGETS`).

## GATE_CONDITIONS

Aucune gate anticipée. Hotfix de remise à niveau de livrables K-5 / K-9 documentés et déjà audités.

Si l'implémentation requiert d'ajouter une dépendance npm (`@sentry/vue`), **stop** et logger sous `ESCALATION` — l'orchestrateur Claude tranche, pas le sub-agent.

Si une spec Vitest exige un comportement qui implique de toucher un `SUBSYSTEMS_OFF_LIMITS`, **stop** et logger sous `ESCALATION`.

## Execution Steps

### Étape 1 — T03b — `resources/js/observability/sentry.js`

1. Recherche historique exhaustive : `git log --all`, `git reflog`, `git stash list`, `find` cross-worktree (cf. prompt T03b complet).
2. Si version trouvée → restauration + adaptation minimale aux exports requis par `kioskSentryBoot.spec.js`.
3. Sinon → réimplémenter from scratch :
   - Exports : `installSentry`, `beforeSend`, `beforeBreadcrumb`, `scrubString`, `scrubObject` (vérifier la liste exacte attendue par la spec).
   - `installSentry` : no-op silencieux si DSN falsy (ADR-1) ; `import('@sentry/vue')` dynamique avec fallback no-op + warn console si absent.
   - Patterns PII : email, téléphone, carte 13-19 chiffres, CVV (contexte). Clés sensibles à masquer : `password|token|email|phone|device_id|session_id|username|card|cvv|secret|authorization` (case-insensitive contains).
   - `event.request.url` : strip query params sensibles avant envoi.
4. Lancer `npx vitest run tests/js/kioskSentryBoot.spec.js` jusqu'à 100 % vert (16 tests).
5. Pas de commit. Laisser le travail en WT pour validation Claude.

### Étape 2 — T04b — `resources/js/helpers/kioskPerf.js`

1. Recherche historique exhaustive (idem étape 1.1).
2. Restauration ou réimplémentation des 9 familles `perf.*` (cf. prompt T04b complet).
3. Garde-fous obligatoires :
   - Feature detection `PerformanceObserver`, `performance.memory`, `window`.
   - SSR-safe (no-op si `window` absent ou `kioskAnalytics` absent).
   - `start()` idempotent (un seul setup actif si appelé deux fois).
   - `stop()` `disconnect()` tous les observers + `clearInterval` tous les samplers (anti-leak K-5).
4. Default export `{ start, stop, __peekStateForTests }` (vérifier la signature exacte attendue par la spec).
5. Lancer `npx vitest run tests/js/kioskK5PerfInstrumentation.spec.js` jusqu'au vert (blocs K-5.6 budgets + K-5.7 instrumentation).
6. Pas de commit.

### Étape 3 — Rapports + post-execute

- Écrire `reports/execution/RUN_T03B_SENTRY_FRONT_2026-04-20.md`.
- Écrire `reports/execution/RUN_T04B_KIOSK_PERF_2026-04-20.md`.
- Écrire la ligne `EXECUTE_DELEGATION: foodking-complex-implementer` dans `reports/post_execute_latest.log` (en append OK).
- Si possible, lancer `.cursor/hooks/post-execute.sh`. Sinon noter dans le rapport.

## SYMMETRY_NOTE
N/A — Aucun service `Order*` n'est dans `SUBSYSTEMS_TOUCHED`.

## SCOPE_PRESSURE
[Vide à plan time. À remplir uniquement si la cible révèle un débordement obligatoire.]

## ESCALATION
[Vide à plan time. À remplir si un blocage gouvernance, scope, ou frozen zone apparaît.]

## Audit Status
[ ] Pending — Claude orchestrator audit après EXECUTE
[x] Passed — cycle closed (Claude audit 2026-04-20 : 25/25 Vitest, exports OK, no-op DSN/Sentry, garde-fous K-5 en place)
[ ] Gate opened — `docs/gates/GATE_HOTFIX_OBSERVABILITY_001_2026-04-20.md`

## Post-cycle action

Une fois T03b + T04b validés par Claude, l'orchestrateur restaure `.cursor/ACTIVE_CYCLE.md` à l'état `KIOSK_PHASE_9_5_2026-04-18` et reprend l'orchestration audit T06 → T20.
