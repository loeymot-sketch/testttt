# AUTO_AUDIT_GPT — CV1-LOT-K02-ORDER-TYPE-EXPLICIT

## 1. Conformité au plan / scope

`EXECUTE_DELEGATION: codex-extension`

Scope respecté. Les changements sont limités à l'allowlist K-02:

- `resources/js/components/frontend/kiosk/KioskIdleScreenComponent.vue`
- `resources/js/components/frontend/kiosk/KioskCategoriesComponent.vue`
- `resources/js/store/modules/kioskCart.js`
- `tests/js/kioskOrderTypeExplicit.spec.js`
- `tests/Playwright/kiosk-order-type-required.spec.js`
- livrables mission/audit/mémoire

K-02 n'a pas touché backend, ledger, migrations, offline scope, POS, KDS, `OrderService.php` ou `FrontendOrderService.php`.

## 2. Invariants FoodKing

| Invariant | Résultat | Note |
|---|---|---|
| pricing_backend_ssot | OK | Aucun calcul de prix final ajouté; `submitOrder` bloque seulement le type de commande absent. |
| branch_id | N/A | Aucun flux branche modifié; les commandes kiosk continuent de résoudre la branche côté serveur. |
| OrderStatus enum | N/A | Aucun statut modifié. |
| dispatch_after_commit | N/A | Aucun event/job backend modifié. |
| frozen_zones | OK | Aucun fichier frozen ou gate modifié. |
| symmetry OS/FOS | N/A | `OrderService.php` et `FrontendOrderService.php` non modifiés. |

## 3. Tests

- `npx vitest run tests/js/kioskOrderTypeExplicit.spec.js` — PASS, 4 tests
- `npx playwright test tests/Playwright/kiosk-order-type-required.spec.js` — `NO_TESTS_FOUND` sous la config racine actuelle (`testDir: ./tests/e2e`)
- `npx playwright test tests/Playwright/kiosk-order-type-required.spec.js --config tests/Playwright` — PASS, 1 test
- `npx vitest run tests/js/kioskCartSendPayload.spec.js tests/js/kioskWizardEditRoundtrip.spec.js tests/js/KioskCategoriesRestyle.spec.js tests/js/kioskCategoriesTopChips.spec.js` — PASS, 24 tests
- `git diff --check` scoped tracked files — PASS

## 4. Risques

- Le test Playwright est statique par cohérence avec K-01, car `tests/Playwright` n'est pas collecté par la config racine. Le contrat est néanmoins couvert avec la config locale `tests/Playwright`.
- `buildKioskOrderPayload` conserve un fallback legacy hors `submitOrder`; le chemin production `submitOrder` passe `requireExplicitOrderType=true` et rejette `KIOSK_ORDER_TYPE_REQUIRED` si le choix est absent.
- Le backend abuse case `order_type=POS` reste hors lot K-02 et dépend de `myOrderStore`, comme prévu dans le plan kiosk.

## 5. Verdict

`VERDICT: PASS`

K-02 force un choix client explicite avant catalogue/panier et bloque la création de commande kiosk sans `order_type`, sans élargir le scope ni toucher aux invariants financiers.
