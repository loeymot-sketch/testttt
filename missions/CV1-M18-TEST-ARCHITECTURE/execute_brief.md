# EXECUTE BRIEF — CV1-M18-TEST-ARCHITECTURE (M-18)

## INVIOLABLE
1. Lis `AGENTS.md`, `missions/CV1-M18-TEST-ARCHITECTURE/input.json`, `plans/PLAN_CAISSE_V1_GPT_MASTERPLAY_2026-04-25.md` (mission M-18, super master § PLAN-18).
2. Allowlist : 3 fichiers `.md` uniquement.
3. **Tu n'écris aucun test ni aucun script.** Tu produis la grille + le plan.
4. Tu peux **lire** `tests/` pour inventorier l'existant (ls/glob/grep), pas le modifier.

## OBJECTIF EXACT

Grille de couverture tests Caisse V1 + plan de campagne, **machine-vérifiable** par audit Claude.

## INVENTAIRE EXISTANT À PRODUIRE

Pour chaque surface, lister les **tests déjà présents** :

```
POS Feature : tests/Feature/Pos/*.php → liste fichiers + nombre tests par fichier (grep "function test_")
POS Vitest  : tests/js/pos*.spec.js
KDS Feature : tests/Feature/Kds/*.php (si dossier existe) ; sinon tests/Feature/*Kds*.php
KDS Vitest  : tests/js/kds*.spec.js
Kiosk Feature : tests/Feature/Kiosk*.php
Kiosk Vitest  : tests/js/kiosk*.spec.js
Kiosk Playwright : tests/e2e/03-kiosk-*.spec.js + tests/Playwright/kiosk/
Branch isolation : tests/Feature/BranchIsolationTest.php
Fiscal : tests/Feature/Fiscal/*.php
Outbox/Dispatch : tests/Feature/Dispatch*Test.php, tests/Feature/Outbox*Test.php
Sentinels : tests/Feature/Sentinels/* (créés par M-02)
```

Si dossier absent : noter `(absent)` dans la grille.

## STRUCTURE — `CAISSE_V1_TEST_COVERAGE_MATRIX_2026-04-25.md`

```
# CAISSE V1 — Test Coverage Matrix

## 0. Cibles minimales (super master PLAN-18)
| Surface | Coverage Feature | Coverage Vitest | Critical Playwright flows |
|---------|------------------|-----------------|---------------------------|
| POS     | ≥ 80%            | ≥ 70%           | cash, card, parked, void  |
| Kiosk   | ≥ 70%            | ≥ 80%           | cash, card, offline, loyalty |
| KDS     | ≥ 80%            | ≥ 70%           | bump, multi-screen, overflow |
| OSS     | ≥ 70%            | n/a             | display refresh           |
| Backend | ≥ 80% sur services critiques (OrderService, PaymentService, FiscalSequenceService, KitchenDisplaySystemOrderService) | n/a | n/a |
| Ops     | n/a              | n/a             | preflight, outbox-rescue, after-commit |

## 1. POS

### 1.1 Feature (PHPUnit)
| Existant | Tests | Mission de fix |
| ... |

### 1.2 Vitest
| ... |

### 1.3 Playwright
| Flow | Existant | Mission |
| Cash → KDS → OSS → ticket | (absent) | M-15 / E2E |
| ... |

### 1.4 À créer (par mission)
| TASK_ID | Tests à créer | Type |
| CV1-M06 | PaymentConfirmAbilityTest, ... | Feature |

## 2. Kiosk
(même structure)

## 3. KDS
(même structure)

## 4. OSS
(même structure)

## 5. Backend services
(même structure — OrderService, FrontendOrderService, PaymentService, OrderQuoteService (NEW), FiscalSequenceService, KitchenDisplaySystemOrderService)

## 6. Ops / CI
| Test | Type | Commande | Mission |
| OpsPreflightCaisseV1Test | shell | bash scripts/ops-preflight-caisse-v1.sh | M-14 |
| AfterCommitDispatchTest | Feature | php artisan test --filter=AfterCommitDispatch | M-14 |
| MigrationDryRunTest | Feature | php artisan test --filter=MigrationDryRun | M-13 |
| RolloutCanaryDrillTest | drill | runbook | M-15 |

## 7. Synthèse compteurs
- Existants : NN tests Feature, NN Vitest, NN Playwright
- À créer (toutes missions) : NN
- Cibles atteintes après campagne : POS=NN%, Kiosk=NN%, KDS=NN%
```

## STRUCTURE — `CAISSE_V1_TEST_CAMPAIGN_PLAN_2026-04-25.md`

```
# CAISSE V1 — Test Campaign Plan

## Phases

### Phase 0 — Baseline (M-02 sentinels)
- Lance les sentinels rouges → 18 RED documentés
- Lance suite existante → baseline coverage actuelle
- Output : reports/qa/CAISSE_V1_BASELINE_TESTS_RUN_<date>.log

### Phase 1 — Sécurité / Branch / POS guards (après M-06, M-09)
- Re-run sentinels #1-#11 → attendu VERT
- Run nouveaux tests M-06 / M-09
- Vérifier coverage Backend services ≥ 80%

### Phase 2 — Quote + Paiement (après M-05, M-04A/B)
- Sentinels #4 + nouveaux QuoteExpirationTest, QuoteTamperTest, QuoteReplayIdempotencyTest
- Re-run PaymentLedger / PaymentMethodRestricted

### Phase 3 — KDS + Fiscal (après M-07, M-08)
- Sentinels #12-#13 + KdsExpectedStatusConflictTest
- ZAggregationKioskRoutingTest, RefundPreZTest, RefundPostZTest

### Phase 4 — Kiosk runtime (après M-11)
- Sentinels #17-#18 + Vitest + Playwright kiosk

### Phase 5 — Ops + Rollout (après M-13, M-14, M-15)
- OpsPreflightCaisseV1Test
- MigrationDryRunTest
- RolloutCanaryDrillTest

### Phase 6 — Hardware (M-16)
- Checklist hardware signée par Ops

### Phase 7 — Pré-go-live
- Suite complète PHPUnit + Vitest verts
- Playwright critical flows verts
- Performance : LCP < 2.5s POS/Kiosk
- Coverage globale rendue : reports/qa/CAISSE_V1_FINAL_COVERAGE_<date>.html

## Owners
| Phase | Owner | Backup |
| ... |

## Durées estimées
| Phase | Heures dev | Heures QA |
| ... |

## Critère sortie campagne
- 100% sentinels verts
- Cibles couverture atteintes
- Aucun test skip non documenté
- Aucune régression vs baseline
```

## STRUCTURE — `docs/qa/TEST_TYPES_AND_TARGETS_CAISSE_V1.md`

Référence courte sur :
- quand utiliser Feature vs Unit vs Vitest vs Playwright
- conventions de nommage (`*SentinelTest`, `*ContractTest`, `*RaceTest`, `*Test`)
- isolation `RefreshDatabase` vs `DatabaseTransactions`
- patterns mock TPE, mock printer, mock outbox, mock broadcast
- comment marquer un test "blocking" vs "informational" pour la CI

## INTERDITS

- Créer un test, un script, une migration.
- Inventer un test qui n'a pas de mission de fix associée.
- Modifier `tests/` même pour ajouter un commentaire.

## SI BLOCAGE

- Si une surface n'a aucun test existant → noter `(absent — création par M-XX)` dans la grille, pas d'erreur.
- Si tu ne peux pas lister les tests existants (filesystem inaccessible) → `risks: ["ESCALATION: cannot read tests/ to inventory"]`.
