# Codex — Global POS/Kiosk Order Trace Audit — 2026-05-01

## Verdict

`GLOBAL_SOFTWARE_SYNC_VERDICT: PASS`

`RELEASE_DECISION: SOFTWARE_READY_FOR_HARDWARE_UAT`

Le système logiciel central POS + borne + KDS + catalogue/composer + stock + queue number + snapshots est validé localement par simulation runtime. Les blocages restants sont uniquement les équipements/providers réels : TPE, imprimante fiscale, réseau physique, Google Maps live et écrans dédiés.

## Scope Audité

- POS caisse : `admin/pos/quote -> admin/pos`
- Borne kiosk : `frontend/order/quote -> frontend/order -> frontend/order/{order}/payment-confirm`
- KDS : réception sans reload, affichage variation/extra/addon, transition `ACCEPT -> PREPARING -> PREPARED`
- Catalogue central : projection POS/Kiosk depuis `MenuProjectionService`
- Composer wizard : profil publié avec 3 steps obligatoires variation/extra/addon
- Stock : décrément du produit principal, variation, extra et addon
- Queue number : unicité par `branch_id + business_date + queue_number`
- Backend data : `orders`, `order_items.composition_snapshot`, `stock_movements`, `domain_events`, `order_status_transitions`
- Sécurité métier : pricing SSOT backend, branch scope, payment status, fiscal sequence kiosk card non allouée

## Corrections / Polishing Appliqués

- `tests/e2e/global-pos-kiosk-order-trace.spec.js`
  - Ajout/solidification d’un test global qui crée une commande POS et une commande kiosk card simulée, puis trace les deux jusqu’à KDS `PREPARED` avec audit backend.
  - Correction du fixture E2E : `sort` unsigned, pas de colonne `items.visible_on`, session admin pour `admin/menu-projection`, parcours kiosk via idle/type de commande.
  - Alignement queue-number sur la règle réelle `branch_id + business_date + queue_number`.
  - Cleanup/reporting stock compatible avec le schéma actuel `stock_movements.stock_level_id`.

- `public/js/admin-kds.js` et manifest assets
  - Rebuild `npm run development` pour servir le rendu KDS actuel des addons composer. Avant rebuild, la source Vue savait afficher `item_addons`, mais le bundle servi ne contenait pas encore ce rendu.

- `app/Services/Menu/MenuProjectionService.php`
  - Commentaire P3 déjà corrigé : la projection est maintenant documentée comme SSOT runtime POS/Kiosk/KDS.

## Preuves Runtime Finales

Artifact JSON : `reports/antigravity/global-pos-kiosk-order-trace.json`

Dernier run :

- POS order id : `724`
- Kiosk order id : `725`
- Queue numbers : `A0001`, `A0002`
- Business date : `2026-05-01`
- KDS réception :
  - POS visible en `3879 ms`
  - Kiosk visible en `2 ms`
  - Addon visible en `4 ms`
- Total POS : `12.50`
- Total kiosk : `12.50`
- Stock final après 2 commandes :
  - main item : `18`
  - variation : `18`
  - extra : `18`
  - addon item : `18`
- Stock movements par commande :
  - `Item:-1`
  - `ItemVariation:-1`
  - `ItemExtra:-1`
  - `Item addon item:-1`
- Kiosk card :
  - `payment_status = PAID`
  - `payment_method = CARD`
  - `fiscal_sequence_no = NULL`
  - `transaction_id = PW-GLOBAL-TRACE-TPE-SIM-*`
- POS cash :
  - `payment_status = PAID`
  - `pos_payment_method = CASH`
  - fiscal sequence allouée côté POS

## Tests Exécutés

- `bash .cursor/hooks/safety-check.sh`
  - PASS

- `npm run development`
  - PASS, assets rebuildés

- `npx playwright test tests/e2e/global-pos-kiosk-order-trace.spec.js --project=chromium --workers=1 --timeout=240000 --retries=0`
  - PASS 1/1

- `npx playwright test tests/e2e/global-pos-kiosk-order-trace.spec.js --project=chromium --workers=1 --timeout=240000 --retries=0 --repeat-each=3`
  - PASS 3/3 après correction finale

- `npx playwright test tests/e2e/central-management-dashboard-crud.spec.js --project=chromium --workers=1 --timeout=300000 --retries=0`
  - PASS 1/1
  - Ferme le P2 dashboard CRUD navigateur : catégorie, produit, photo, variation, extra, addon, composer profile, publish, POS/Kiosk/KDS/stock.

- `npx playwright test tests/e2e/c3-runtime-multi-surface.spec.js --project=chromium --workers=1 --timeout=240000 --retries=0`
  - PASS 2/2
  - Kiosk cash -> KDS/POS counter/OSS et POS -> KDS/OSS sans reload.

- `php artisan test tests/Feature/KDS/KdsSnapshotImmutableTest.php --stop-on-failure`
  - PASS 4/4

- `php artisan test tests/Feature/Services/Menu/MenuProjectionParitySentinelTest.php --stop-on-failure`
  - PASS 6/6

- `php artisan test tests/Feature/Stock/StockDecrementOrderServiceTest.php --stop-on-failure`
  - PASS 2/2

- `php artisan test tests/Feature/Stock/StockDecrementFrontendOrderServiceTest.php --stop-on-failure`
  - PASS 1/1

- `php artisan test tests/Feature/Payment/CounterDeferredPaymentLifecycleTest.php --stop-on-failure`
  - PASS 5/5

- `php artisan test tests/Feature/Payment/PaymentStateMachineTransitionsTest.php --stop-on-failure`
  - PASS 2/2

- `php artisan test tests/Feature/QueueNumberConcurrencyTest.php --stop-on-failure`
  - PASS 5/5

- `php artisan test tests/Feature/Sentinels/QueueNumberUniquenessSentinelTest.php --stop-on-failure`
  - PASS 1/1

- `npx vitest run tests/js/userReportedBlockersRuntime.spec.js tests/js/kioskWaitingAutoReturn.spec.js tests/js/posItemAvailabilityHandler.spec.js tests/js/kioskRuptureUx.spec.js tests/js/posRuptureUx.spec.js`
  - PASS 23/23

## Findings

### P0

Aucun P0 logiciel trouvé.

### P1

Aucun P1 logiciel trouvé.

### P2

Aucun P2 logiciel bloquant UAT matériel restant sur le scope demandé.

Le P2 précédent “dashboard CRUD navigateur pas prouvé” est fermé par `central-management-dashboard-crud.spec.js`.

### P3

Le commentaire obsolète `MenuProjectionService` est corrigé. Aucun P3 bloquant.

## Notes D’Audit

- L’alerte “Connexion temps réel perdue” visible en local ne bloque pas le système : les tests C3 et global trace prouvent le fallback/polling et la synchronisation sans reload.
- Le KDS affichait initialement les commandes mais pas les addons dans le bundle servi. La source Vue était correcte ; le rebuild asset était nécessaire pour aligner `public/js/admin-kds.js`.
- Les queue numbers peuvent recommencer à `A0001` sur une nouvelle `business_date`, ce qui est voulu. L’unicité réelle est `branch_id + business_date + queue_number`, confirmée par migration et sentinels.
- Les fixtures `PW-GLOBAL-TRACE%` sont nettoyées après run : contrôle final `global_items=0`, `global_categories=0`, `global_order_items=0`.

## Conclusion

Le flux logiciel global demandé est validé :

1. Commande POS complète créée depuis la surface caisse.
2. Commande borne créée depuis la surface kiosk avec paiement carte simulé.
3. Les deux commandes arrivent au KDS sans reload manuel.
4. Les variations, extras et addons composer sont visibles et persistés dans les snapshots immuables.
5. Le stock central diminue symétriquement pour produit, variation, extra et addon.
6. Les queues ne se doublonnent pas dans le périmètre métier réel.
7. Les statuts, événements, transitions et paiements sont cohérents.

`CODEX_FINAL_VERDICT: PASS_PROCEED_TO_HARDWARE_UAT`
