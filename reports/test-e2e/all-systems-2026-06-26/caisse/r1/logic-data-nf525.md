# CAISSE r1 — Lentille LOGIQUE / DATA / NF525 (Commandes · parking · loyalty · refund)

Cible : sous-système refund / loyalty / état-commande du POS.
DB live `foodking_e2e` (READ-ONLY, 0 écriture). Serveur :8766 vivant.
Preuves PHPUnit = base test isolée (jamais foodking_e2e).

---

## [P1] app/Domain/Order/OrderStateMachine.php:76-77 — Refund-bypass : un POS Operator (sans `pos-refund`) émet un remboursement cash complet via `change-status → RETURNED`

### repro (prouvé end-to-end)
1. DB live : l'opérateur `pos@lecayenne.fr` (id=3, branch 1) a `pos-orders=1`, `pos`=1, **`pos-refund=0`**
   `mysql -u root foodking_e2e -e "SELECT u.id, MAX(p.name='pos-orders') has_po, MAX(p.name='pos-refund') has_ref FROM users u JOIN model_has_roles mhr ON mhr.model_id=u.id JOIN roles r ON r.id=mhr.role_id AND r.name='POS Operator' LEFT JOIN role_has_permissions rhp ON rhp.role_id=r.id LEFT JOIN permissions p ON p.id=rhp.permission_id WHERE u.id=3 GROUP BY u.id"` → has_po=1, has_ref=0
2. Cibles réelles : 39 commandes POS DELIVERED+PAID en base (ex. #4464, total 147€, fiscal 2143)
   `... WHERE order_type=15 AND status=13 AND payment_status=5 AND deleted_at IS NULL` → 39
3. Exécution prouvée par le probe existant `tests/Feature/ZZ_RefundBypassProbe_Test.php`
   `vendor/bin/phpunit --filter ZZ_RefundBypassProbe_Test` → **OK (1 test, 5 assertions)** avec STDERR :
   - `[PROBE] dedicated refund-with-counter-entry status = 403`  (la garde `pos-refund` marche sur l'endpoint dédié)
   - `[PROBE] change-status->RETURNED status = 200`              (la route sœur passe)
   - `[PROBE] orderB status_after = 22 | cash_back_txn = YES amount=30.000000`  (RETURNED + argent sorti)

### evidence
- `POST /api/admin/pos-order/{id}/refund-with-counter-entry` est gardé `abort_unless(can('pos-refund'))` (PosOrderController.php:58-62) → 403 attendu.
- `POST /api/admin/pos-order/change-status/{id}` (route api.php:983) passe par `permission:pos-orders` (PosOrderController.php:28, que l'Operator possède) puis `OrderService::changeStatus` → `ValidStatusTransition::passes` (Rules/ValidStatusTransition.php:35) → `OrderStateMachine::allows($from, RETURNED, $user)`.
- `OrderStateMachine.php:76-77` : `case OrderStatus::DELIVERED: return $to === OrderStatus::RETURNED;` — **AUCUN check `pos-refund`** (contrairement aux edges ACCEPT/PREPARING/PREPARED→RETURNED, lignes 48/59/67, qui exigent `hasPermissionTo('pos-refund')`).
- Le sentinel `OrderStateMachinePreZRefundLockSentinelTest.php:164` **épingle explicitement** ce trou comme intentionnel : `assertTrue(allows(DELIVERED, RETURNED, null))` → l'edge est légal pour `null`/sans-permission. Test vert (8/8).
- Une fois la transition acceptée, `OrderService::changeStatus` (OrderService.php:2150-2196) déclenche `PaymentService::cashBack()` (argent rendu, balance client +total, txn `cash_back` sign `-`) **+** `LoyaltyService::refundPoints()` — sans aucune autorisation `pos-refund`.
- Réel-monde confirmé : la base live contient déjà des commandes POS RETURNED avec ligne `cash_back` (#4875 amount=10.00, #4206 amount=7.00) — ce chemin EST emprunté en pratique.
- Aucun sentinel permanent ne verrouille « change-status→RETURNED requiert pos-refund » : seul `PosRefundUiPermissionSentinelTest` couvre l'endpoint dédié (vérifié : `grep change-status tests/Feature/Sentinels/` ne pointe pas ce cas).

### lentille
commerçant (anti-fraude employé) + technique. Le doc-bloc de `PosRefundUiPermissionSentinelTest:24-31` documente le motif exact de la permission `pos-refund` : « a cashier could issue refunds at will... mass-refund vector by junior cashier ». Ce contrôle est **entièrement contournable** sur toute commande DELIVERED via la route sœur `change-status`. Un caissier junior peut, à volonté, marquer « retournée » une commande livrée+payée et faire sortir l'argent du tiroir.

### NF525 / sévérité
Le chemin pré-Z capture le négatif dans le Z OUVERT (audit `order.returned` écrit, OrderService.php:2224-2245 ; pas de gap de séquence fiscale) → **ce n'est pas une corruption de chaîne NF525**. Le préjudice est : sortie d'argent + retour-marchandise par un employé SANS l'autorité de remboursement = bypass d'autorisation activant la fraude employé. Classé **P1** (argent + sécurité ; menace centrale du mono-poste Le Cayenne où un caissier abuse ses droits). Pas P0 (chaîne fiscale intègre).

### reco (NON-frozen)
La racine (OrderStateMachine.php:76-77) est **FROZEN** (CLAUDE.md §7 / discipline §6) → ne pas éditer sans LOCK+gate. Heal hors-frozen recommandé :
- Dans `OrderService::changeStatus` (NON-frozen) OU `PosOrderController::changeStatus` (NON-frozen) : quand `target === OrderStatus::RETURNED` ET la commande porte un paiement/argent-sorti (`payment_status===PAID` ou `transaction` présent), exiger `Auth::user()?->can('pos-refund')` sinon `abort(403)` — miroir exact de la garde de `refundWithCounterEntry`. Cela ferme le bypass sans toucher le frozen state-machine.
- Ajouter un **sentinel permanent** `RefundBypassGuardTest` (déjà prévu dans le plan §1.d.4) qui pingle : Operator sans pos-refund → 403 sur change-status→RETURNED d'une commande PAID, et 0 ligne `cash_back` créée.
- Convertir/garder `ZZ_RefundBypassProbe_Test` après inversion de l'assertion (200→403) comme test de non-régression.
- Si l'owner préfère corriger la racine : escalade LOCK sur OrderStateMachine pour gater l'edge DELIVERED→RETURNED par `pos-refund` (impacte aussi les undo Admin — à arbitrer).

---

## Vecteurs ABUSÉS et VÉRIFIÉS-TENUS (pas de finding — la défense tient)

- **Double-refund (mirror ×2) via change-status** : TENU. `PaymentService::cashBack` est idempotent (early-return sur ligne `cash_back` existante, PaymentService.php:97-103) ET `changeStatus` no-op si déjà RETURNED (OrderService.php:2130-2137 ; `allows` `from===to` true mais l'idempotent-guard sort avant cashBack). → pas de double sortie d'argent.
- **Refund cross-branch via change-status** : TENU. Garde explicite `abort(403)` non-Admin hors-branche dans la transaction (OrderService.php:2122-2126). L'endpoint dédié a sa propre garde cross-branch (PosOrderController.php:69-73).
- **Loyalty redeem > solde / après refund / sur commande PAID** : TENU. `PosRedemptionService::assertOrderRedeemable` rejette PAID (409) + états terminaux dont RETURNED (PosRedemptionService.php:271-297) ; check solde sous `lockForUpdate` (l.135-141) ; UNIQUE(user_id,order_id,type) anti-double-redeem ; `pos.redeem-loyalty` gate (route api.php:1002 + FormRequest). `PosLoyaltyRedeemTest` vert (7/7). En V1 la remise loyalty est même globalement désactivée (`DISCOUNTS_DISABLED_V1`, l.72-78).
- **Resume order déjà payé / parking** : non-pingé sur cette lentille (couvert par sous-agents prise-commande) ; redeem sur ordre déjà payé déjà bloqué ci-dessus.

---

## NF525 chain — inchangée (READ-ONLY confirmé)
Aucune écriture émise sur foodking_e2e par cette lentille. Toutes les preuves d'exécution proviennent de la base test PHPUnit isolée. `orders` POS RETURNED réels (#4875/#4206) sont des données pré-existantes observées, pas créées ici.
