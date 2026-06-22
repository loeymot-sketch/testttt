# Rapport consolidé — Audit E2E massif P0→P5 — RUN `20260504_1956_E2E_MASSIVE`

**Date (UTC)** : 2026-05-04  
**Agent** : `cursor-claude`  
**Discipline** : `agent-activity-log` réservé puis libéré ; pas d’édition code produit dans ce run (preuves + documentation uniquement).  
**Plans sources** : `plans/E2E_MASSIVE_AUDIT_MASTER_2026-05-04.md` + P0–P5.

---

## 1. Synthèse exécutive

| Domaine | Résultat | Verdict global |
| --- | --- | --- |
| **P0 — Contrats backend** (stock, ingrédients, broadcast, submit SSOT) | PHPUnit **71 passed**, **4 skipped** (idempotency key — plan lifecycle documenté) | **GO** pour la couche services / API |
| **P1–P5 — Playwright navigateur** | Sans `E2E_BACKEND_AVAILABLE` : surtout **contrats JS** (9 passed, 9 skipped). Avec backend : **2 passed**, **3 failed**, **1 skipped**, **3 non exécutés** (suite interrompue après échecs) | **NO-GO** pour prétendre « audit massif UI complet » sans corrections données + deps |

**Conclusion** : la **chaîne backend stock/sync** est fortement couverte par PHPUnit (aligné plan P0). Les **parcours Playwright « massifs » admin** échouent aujourd’hui pour **raisons environnementales / données / dépendance axe**, pas pour une absence de plan.

---

## 2. Détail P0 (PHPUnit = proxy « centrale + stock »)

Fichier log : `logs/phpunit_P0_stock_ingredients.log`

| Lot | Contenu | Résultat |
| --- | --- | --- |
| A | `BroadcastDriverConfiguredTest` | 4 passed |
| B | `SubmitRevalidatesChoiceAvailabilityThroughPricingTest` | 1 passed |
| C | `tests/Feature/Stock/*` | 47 passed, **4 skipped** (`StockMovementIdempotencyKeyUniqueTest` — renvoi plan lifecycle) |
| D | `tests/Feature/Ingredients/*` | 19 passed |

### Anomalies / notes (tri)

| Gravité | Id | Description | Action corrective |
| --- | --- | --- | --- |
| P3 | N1 | 4 tests PHPUnit « pending plan » (skipped) | Traiter `PLAN_CV1-LIFECYCLE-UX-001` ou accepter skip en CI avec trace |
| — | N2 | Pas d’UI admin capturée automatiquement en P0 | Normal : P0 plan prévoit Playwright admin séparé ; voir section 3 |

---

## 3. Détail Playwright — vague 1 (`tests/Playwright` sans flag)

Log : `logs/playwright_P1_P5_all.log`

- **9 passed** : surtout specs **contractuelles** (kiosk errors, offline waiting ids, pos-receives-kiosk keys, KdsMultiScreen release contract, etc.) — **pas** des enchaînements caisse complets.
- **9 skipped** : critical-flow conditionné (`E2E_BACKEND_AVAILABLE` absent).

**Implication plan P1/P2/P3** : les exigences « screenshot à chaque étape caisse/borne/cuisine » **ne sont pas couvertes** par cette vague.

---

## 4. Détail Playwright — vague 2 (`critical-flow` + `E2E_BACKEND_AVAILABLE=1`)

Log : `logs/playwright_critical_flow_with_backend.log`

| Test | Verdict | Cause racine |
| --- | --- | --- |
| `v1-category-wizard-affects-products` (1er test) | **PASS** | — |
| `v1-category-wizard-affects-products` (2e test) | **skipped** | marqué `test.fixme` / skip interne |
| `v1-demo-v2-flag-disabled` | **PASS** | — |
| `v1-ingredient-rupture-propagation` | **FAIL** | Aucune ligne ingrédient **extra/attribute** avec `role=switch` dans la DB/UI seed → `openIngredientListWithRows` lève |
| `v1-ingredients-a11y` | **FAIL** | Package **`@axe-core/playwright`** absent (erreur explicite dans spec) |
| `v1-sidebar-cleanup` | **FAIL** | Sélecteur **`.db-sidebar`** introuvable après login — layout admin différent ou page non admin |

### Captures d’échec (preuve)

Répertoire : `screenshots/` (4 fichiers PNG copiés depuis `test-results/`).

---

## 5. Cartographie plans P0–P5 → exécution réelle

| Plan | Exécution ce RUN | Commentaire |
| --- | --- | --- |
| P0 | **PHPUnit complet** + **tentative** UI via Playwright critical-flow | Backend OK ; UI ingrédients **non** validée (fail données) |
| P1 | Partiel | Pas de spec dédiée « POS cash flow » dans `tests/Playwright` hors critical-flow |
| P2 | Partiel | Contrats kiosk OK ; pas parcours paiement E2E |
| P3 | Minimal | `KdsMultiScreenPlaywrightTest` contract seulement |
| P4 | **Non exécuté** | Pas de spec OSS dans le dossier ; à ajouter ou manuel |
| P5 | **Non exécuté** | Scénarios X1–X6 du plan = **non implémentés** en Playwright agrégé |

---

## 6. Liste de corrections priorisée (pour le prochain cycle « GO corriger »)

1. **P0 — données** : seed / fixture garantissant au moins **1 extra** et **1 attribute** listés sur `/admin/ingredients/{type}` avec `data-testid="ingredient-list"` et switch visible.
2. **P0 — tooling** : `npm i -D @axe-core/playwright` **ou** lancer avec `ALLOW_AXE_SKIP=1` si politique QA l’accepte (documenter gate).
3. **P1 — sélecteurs** : mettre à jour `v1-sidebar-cleanup.spec.js` pour le **sélecteur sidebar réel** (ou stabiliser `data-testid` sur layout admin).
4. **P5 — implémentation** : créer `tests/Playwright/e2e-massive/cross-surface-*.spec.js` selon le plan P5 (non présent aujourd’hui).
5. **OSS** : ajouter spec dédiée ou section manuelle dans manifeste.

---

## 7. Fichiers à consulter (preuve brute)

- `reports/e2e-massive/20260504_1956_E2E_MASSIVE/logs/phpunit_P0_stock_ingredients.log`
- `reports/e2e-massive/20260504_1956_E2E_MASSIVE/logs/playwright_P1_P5_all.log`
- `reports/e2e-massive/20260504_1956_E2E_MASSIVE/logs/playwright_critical_flow_with_backend.log`
- Traces Playwright (locales) : sous `test-results/` (zip `trace.zip` — non copiés dans le bundle pour taille)

---

*Fin du rapport consolidé — prochaine étape humaine : valider priorités corrections puis relancer un RUN avec données seed + deps axe.*
