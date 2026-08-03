# TRAIN 5 - E2E, Global Audit, Claude Handoff

TASK_ID: PRODUCT-COMPOSER-SYNC-05-E2E-CLAUDE-HANDOFF  
MODE: validation and audit  
GOAL: prouver le systeme complet avant intervention Claude finale.

## 1. Scenario principal

1. Admin cree categorie `Assiette Test Composer`.
2. Admin cree produit `Assiette Composee Test`.
3. Admin upload photo.
4. Admin configure composer:
   - template assiette,
   - step crudites,
   - step sauces,
   - step supplements,
   - pas de step pain,
   - boisson optionnelle.
5. Admin publie.
6. POS voit le produit.
7. Kiosk voit le produit.
8. POS commande avec choix.
9. Kiosk commande avec choix.
10. Stock d'un choix passe a 0.
11. POS et kiosk voient rupture.
12. KDS recoit les commandes.
13. OSS affiche puis retire.
14. Queue numbers restent uniques.

## 2. Tests commandes

- `php artisan test --filter ProductComposer`
- `php artisan test --filter Stock`
- `php artisan test --filter QueueNumber`
- `php artisan test --filter PosWalkInAndDeliveryFeeTest`
- `npx vitest run tests/js/productComposer*.spec.js tests/js/kiosk*Composer*.spec.js tests/js/pos*Composer*.spec.js`
- `npm run production`

## 3. Playwright

Specs a creer:

- `tests/Playwright/product-composer-admin-creates-pos-kiosk-sees.spec.js`
- `tests/Playwright/product-composer-stock-rupture.spec.js`
- `tests/Playwright/product-composer-pos-kiosk-kds-oss-flow.spec.js`

## 4. Claude handoff

Rapport final attendu:

- demandes utilisateur archivees,
- chaque train PASS/REWORK,
- fichiers modifies,
- tests executes,
- risques residuels,
- prompt Claude pour audit critique,
- verdict `GO|HOLD|NO_GO`.

## 5. Exit

`PASS` uniquement apres:

- tests backend,
- tests JS,
- build,
- Playwright,
- audit Codex,
- audit Claude,
- gate humain hardware si release physique.
