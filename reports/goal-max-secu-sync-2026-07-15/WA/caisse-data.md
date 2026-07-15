# Audit CAISSE — intégrité données / fiscal (WA)
Date : 2026-07-15 — Agent : sub-agent caisse-data (goal-max-secu-sync)
Périmètre : SplitPaymentService, CashDrawerService, CashDrawerSession(Controller), PaymentService, OrderService::posOrderStore/changeStatus, RefundWithCounterEntryService, PosRedemptionService, ZReportController. Fichiers `app/Services/Fiscal/*` lus en LECTURE SEULE (frozen).

Config live vérifiée (.env + config) : `SPLIT_PAYMENT_ENABLED=true`, `POS_WALKIN_ROUTE_TO_COUNTER=false`, `POS_SIMULATION_HARDWARE=true` (dev), `kiosk.payment_route_all_to_counter=true` (Plan B actif), `pos.manual_discount_enabled=true` (légitime : F1 fixé sous LOCK_ZREPORT_F1_DISCOUNT_NETTING_2026_05_31 + ZReportDiscountNettingTest).

---

## FINDING 1 — P1 — Refund pre-Z d'une vente encaissée en ESPÈCES au comptoir : aucune sortie tiroir journalisée + « remboursement » crédité en wallet fantôme

### Défaut
Le flux borne Plan B (V1 primaire : `kiosk.payment_route_all_to_counter=true`) et les commandes téléphone/walk-in différées sont encaissés via `PaymentService::confirmCounterPayment` qui :
- écrit `cash_movements` IN = total (`app/Services/PaymentService.php:488-490`),
- crée une ligne `transactions` type=payment (`app/Services/PaymentService.php:408-419`).

Au remboursement PRE-Z (bouton « Rembourser » → `PosOrderController::refundWithCounterEntry` → parent non scellé → `refundPreZ` `app/Http/Controllers/Admin/PosOrderController.php:215-229` → `OrderService::changeStatus(RETURNED)`) :
- `app/Services/OrderService.php:2314` : `if ($locked->transaction)` est VRAI (la ligne Transaction existe) → `cashBack($locked, 'credit', …)` (`OrderService.php:2315-2319`).
- Dans `cashBack`, le mouvement tiroir OUT n'est écrit que si `strtolower($gatewaySlug) === 'cash'` (`app/Services/PaymentService.php:183`) — or les **3 seuls call sites production passent `'credit'` en dur** (`app/Services/OrderService.php:2174`, `:2317`, `app/Services/FrontendOrderService.php:782`). Le garde est donc mort : **jamais de sortie tiroir** sur ce chemin.
- La branche compensatoire `recordCashRefundMovement` (`OrderService.php:2320-2329`, fix SELF-AUDIT R2 P2) est court-circuitée par le `if ($locked->transaction)` — elle ne couvre que les ventes cash SANS Transaction (POS inline).
- En prime, `PaymentService::cashBack` crédite `users.balance += order.total` (`app/Services/PaymentService.php:146-150`) — pour un walk-in, c'est le compte partagé « Walking Customer » (id=2) : avoir fantôme qu'aucun client ne peut consommer (et consommable via le gateway `credit` si le compte était utilisé au checkout web).

Ironie : le refund **post-Z** (miroir counter-entry) journalise correctement la sortie tiroir (4-ter/4-quater `app/Services/Order/RefundWithCounterEntryService.php:270-362`) ; c'est le refund **pre-Z** — le cas le plus fréquent (client qui se ravise le jour même) — qui a le trou.

### Reproduction
1. Borne : passer une commande (Plan B → paiement au comptoir), ex. 7 €.
2. Caisse (session tiroir OUVERTE) : `POST /api/admin/pos/counter-collect/{id}/confirm` `{mode:1, received:7}` → PAID.
3. DB : `SELECT type,direction,amount FROM cash_movements WHERE order_id={id}` → 1 ligne `order_payment/in/7.00` ; `SELECT * FROM transactions WHERE order_id={id} AND type='payment'` → 1 ligne `counter_cash`.
4. Même journée (Z ouvert) : `POST /api/admin/pos/orders/{id}/refund-with-counter-entry` `{reason:"client parti"}` (compte avec `pos-refund`) → réponse `mode:'pre_z'`.
5. DB : `cash_movements` pour {id} → **toujours 1 seule ligne IN, aucun OUT** ; `transactions` a une ligne `cash_back` ; `users.balance` du customer a +7 €.
6. Clôture tiroir : `expected_closing_amount` inclut les 7 € encaissés alors que le caissier a rendu le cash → variance fantôme −7 € (ou, si le cash n'est pas rendu, client « remboursé » sur un wallet inutilisable).

### Preuve DB (base locale, historique)
```
order 4206 : source=pos, pos_payment_method=1(CASH), status=22(RETURNED), payment_status=20(REFUNDED), total=7.00
  transactions: payment (has_txn=1) + cash_back 'counter_cash' (id 982)
  cash_movements: [{type:order_payment, dir:in, 7.00}]  ← AUCUN out, AUCUN mirror out
order 4740 : idem, total=1.50 → 1 IN, 0 OUT
7 commandes CASH RETURNED/CANCELED matchent le pattern IN-sans-OUT (dont 4 kiosk).
```
(Contre-exemple 4517 : IN 3.80 + OUT 3.80 — écrit AVANT le durcissement `gatewaySlug==='cash'` du 2026-07-11, qui a fermé le tiroir-fantôme carte mais ré-ouvert ce trou pour les ventes cash AVEC Transaction.)

### Fix scope-minimal proposé (aucun fichier frozen)
Dans les branches refund de `OrderService::changeStatus` (2 sites) et `FrontendOrderService::changeStatus` : déterminer la méthode d'ENCAISSEMENT d'origine — espèces si `(int)$locked->pos_payment_method === PosPaymentMethod::CASH` (posé par confirmCounterPayment) ou `transaction.payment_method ∈ {cash, counter_cash}` :
- si origine ESPÈCES → journaliser la sortie tiroir (`recordCashRefundMovement($locked, total)` après cashBack) et NE PAS créditer le wallet (ex. passer `'cash'` à cashBack et conditionner `balance +=` à un slug non-cash) ;
- sinon (carte/en ligne) → comportement actuel inchangé (pas de OUT — préserve le fix anti-tiroir-fantôme du 2026-07-11 et le test I-D `tests/Feature/Cash/PaymentServiceCashHookTest.php:171-208`).
Étendre `ChangeStatusReturnedSelfAuditR2Test` avec le cas « Transaction counter_cash présente → OUT écrit, wallet non crédité ; Transaction counter_card → 0 OUT ».

---

## FINDING 2 — P3 — Mode simulation : asymétrie de journalisation tiroir entre split et single-tender (dev-only)

`SplitPaymentService::persistTranches` (`app/Services/Payments/SplitPaymentService.php:240-252`) : quand `pos.simulation_hardware === true`, `$cashSession = null` → **aucun** `cash_movement` n'est écrit pour les tranches CASH, même si une session tiroir est OUVERTE. Le chemin single-tender (`OrderService.php:1346-1354` → `recordCashOrderMovement`, `PaymentService.php:526-583`) ne fait que dégrader strict→soft : il **écrit** le mouvement si une session est ouverte.

Repro (dev, `POS_SIMULATION_HARDWARE=true` — valeur .env:93 actuelle) : session ouverte, 2 ventes cash 10 € — une single-tender, une split 1 tranche cash → la 1re produit un IN, la 2e aucun → `expected_closing_amount` incohérent entre deux ventes identiques. En production le boot-guard interdit simulation=true (AppServiceProvider) → impact dev/staging uniquement, d'où P3. Fix : dans `persistTranches`, ne pas nuller la session en simulation (chercher la session et écrire le mouvement en soft, miroir exact du path single-tender), OU ne pas écrire non plus côté single-tender (symétrie) — la 1re option préserve la piste NF525.

---

## Axes audités SAINS (preuves)
| Axe | Verdict | Preuve |
|---|---|---|
| Split : cap overpay | OK | non-cash ≤ total + tolérance min(1€, cash) — `SplitPaymentService.php:165-198` (fix 016a4e48b en place) |
| Split : rendu monnaie | OK | recalculé serveur `tendered−amount` clampé ≥0 — `SplitPaymentService.php:268-270` ; jamais pris du client |
| Split : tranche CARD | OK | terminal_id requis + ACTIVE + branch-scoped, double couche request+service — `SplitPaymentService.php:121-143`, `PosOrderRequest.php:253-294` |
| Split différé | OK | rejeté request (`PosOrderRequest.php:188-205`) + gate service `!$deferToCounter` (`OrderService.php:1318`) — pas de double cash-in |
| Rendu single-tender | OK | garde `received ≥ total` serveur (`OrderService.php:1135-1143`, `PaymentService.php:338-342`) ; affichage recalculé `max(0, received−total)` (`OrderDetailsResource.php:202-211`) |
| Sessions tiroir : double open | OK | Cache::lock + lockForUpdate + UNIQUE fonctionnel MySQL (generated column) — `CashDrawerService.php:75-99`, migration `2026_05_10_020000` |
| Sessions : close/reconcile | OK | idempotents + lockForUpdate + variance gate permission — `CashDrawerService.php:162-205, 233-334` |
| Sessions : IDOR inter-caissier | OK | ownership gate POS-RED-04 — `CashDrawerSessionController.php:317-338` |
| Double Z-close (2 onglets) | OK | `Cache::lock('z_report_b{n}')` + lockForUpdate dans ZReportService (frozen, lu seul) ; contrôleur exige `pos-manage-fiscal` + branch pinned (`ZReportController.php:97-115`) |
| Double encaissement 2 caissiers | OK | lockForUpdate + K2-HEAL-01 → 409 typed pour caissier étranger — `PaymentService.php:286-319` |
| Fidélité double-burn | OK | lockForUpdate client + UNIQUE(user,order,type) → 409 + garde remise CUMULÉE ≤ sous-total + pre-payment guard — `PosRedemptionService.php:112-210, 281-307` |
| Pricing vs snapshot | OK | SSOT PricingService actif (`use_ssot_service=true`), champs financiers client unset (`OrderService.php:714-715`), trigger `order_items_composition_snapshot_no_update` VIVANT en DB locale (SHOW TRIGGERS : 5 triggers dont audit_logs no_update/no_delete, cash_movements no_delete, z_reports no_delete) |
| Remises | OK | `manual_discount_enabled=true` légitime post-fix F1 (LOCK + ZReportDiscountNettingTest) ; motif ≥3 obligatoire ; TVA ticket proratée par ratio remise (`OrderReceiptEscPosRenderer.php:617-632`) |
| Tiroir fantôme refund CARTE | OK (fixé) | garde `gatewaySlug==='cash'` `PaymentService.php:183` — mais voir FINDING 1 pour l'effet de bord sur l'origine CASH |
| Ajustements tiroir arbitraires | OK | `TYPE_ADJUSTMENT` whitelisté mais AUCUN writer HTTP (grep app/ : seul le whitelist du service) |

## Exclusions respectées
Stripe sk_test_, orphelins pré-C33, PREPARED→CANCELED (frozen), fidélité redeem order_id=NULL, RBAC online-orders, UNI-03 — non re-remontés. Aucun fichier frozen modifié ni proposé à l'édition.
