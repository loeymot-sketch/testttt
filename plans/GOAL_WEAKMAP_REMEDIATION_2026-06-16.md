# GOAL — Weak-map remediation (ultra-plan + corrige, adversarial, smart)

> Source: `reports/test-e2e/abuse-e2e-2026-06-16/weakmap/WEAKMAP_STRUCTURE.md`. Mandate (standing):
> petites corrections robustesse SANS rien casser · 0 frozen sans gate · 0 DB schema · 0 changement de
> comportement des flux validés · scope-minimal · TDD + vérif adversaire par fix · commit par vague.

## Guardrails
- **Frozen §7 intouchable** (PaymentComponent, pos-wizard.js, PricingService, NF525, BranchScope, OrderStateMachine, IdempotencyKeyMiddleware…).
- Chaque fix = display/perf/a11y/observabilité **only**, jamais une règle métier.
- Chaque vague : implémente → test ciblé → **agent adversaire qui REFUTE** → rebuild+preuve live si frontend → commit.
- Convergence = 0 P0/P1 introduit, fixes tiennent en re-vérif, frozen 0.

## EXÉCUTÉ (no-harm, vérifié safe)
| Wave | ID | Fix | Fichiers (non-frozen) | Acceptance |
|---|---|---|---|---|
| W1 | **B4** | KDS N+1 ×2 : eager-load `orderItems.orderItem` + `diningTable` | `KitchenDisplaySystemOrderService.php:73`, `KdsSyncService.php:96` | nouveau test compte les requêtes (assertQueryCount baisse) ; board identique ; KDS list/sync 200 |
| W2 | **A2** | `appService.currencyFormat` → FR (Intl fr-FR, virgule+NBSP+grouping) en honorant symbol/position/decimal | `resources/js/services/appService.js:71-77` | test : `0,00 €` / `37 063,87 €` ; **adversaire vérifie qu'aucun des 11 consommateurs ne reparse la sortie** ; preuve live checkout/table/frontend |
| W3 | **B9** | a11y : aria-label sur boutons icône-seule génériques + fix `for="password"` orphelin (6 comp.) | `SmIcon*`/modal-close partagés, password-confirm Show comp. | test mount : aria-label non vide ; `<label for>` pointe un id existant |
| W4 | **B6** | `salesReportOverview` : drop eager-load `orderItems` inutile + agrégat SQL `selectRaw`/scope | `OrderService.php:2730` | **test égalité chiffres avant/après** (CA identique) + requêtes en baisse |
| W4 | **B7** | `customerStates` : `->get()->count()` → `->count()` SQL | `DashboardService.php:301-315` | test : mêmes buckets/valeurs, 0 hydratation |
| W4 | **DOC** | CLAUDE.md périmé | §9 baseline 66 (pas 69), §8 réf `:215`, §6 route `/admin/stock/rupture` | grep cohérent |

## PLAN-ONLY (gate — NE PAS exécuter seul ce cycle)
- **A1** trait `jsonError($e)` partagé (434 lignes / 104 fichiers) → trop large pour un passage no-harm ; **vagues highest-risk-first** (Pos/Order/Payment/Loyalty/Fiscal) en gate owner.
- **B3** dé-doublonner le poll KDS (`KitchenDisplaySystemComponent.vue:1900-1919`) → touche le contrat sync §6 = LOCK+gate.
- **B8** `SETTINGS_CACHE_ENABLED` → **REFUSÉ** : `set()` n'invalide PAS le cache group-`all()` (`Settings.php:234` forget(key) seul) → config admin **stale** sur le frontend. Régression fonctionnelle. Ne pas activer sans patch d'invalidation du package (upstream/gate).
- **Bundles** (app.js 7,4 Mo…) → config build + câblage frozen `pos-wizard.js` = gate.
- **B10 dead-code purge** → vérifier orphan-ness 2× avant suppression ; bénin → après W1-W4 si budget.
