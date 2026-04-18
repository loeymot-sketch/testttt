# AUDIT_POS_PAYMENT_CASH_CARD_002 — Paiements POS (cash / carte / split)

## Meta
- **Priority** : P0
- **PRIMARY_MODEL** : Claude
- **TEST_STRATEGY** : `static-inspection`
- **DEPENDS_ON** : AUDIT_POS_ORDER_CREATION_001
- **Estimation** : 0.5 j-h
- **Vague** : A2

## Contexte

`OrderService::changePaymentStatus` (~L1485) et les flows de paiement POS couvrent :
- Cash : encaissement immédiat, rendu de monnaie, ouverture tiroir.
- Carte : interaction TPE, retour OK/KO, gestion annulation client.
- Split : plusieurs méthodes sur une même commande (cash partiel + carte).
- Edge : paiement annulé après acceptation, remboursement, pourboires.

Risques : incohérence `payment_status` vs `status`, double encaissement, rendu de monnaie mal calculé, fuite TPE (pas de preuve d'encaissement).

## Questions d'audit

1. `changePaymentStatus` est-il atomique ? Garantit-il une transition valide (UNPAID → PARTIALLY_PAID → PAID) ?
2. Le split payment est-il modélisé (table `order_payments` ?) ou bricolé sur une colonne unique `payment_method` ?
3. Le rendu de monnaie est-il calculé **côté backend** ou laissé au client ? (doit être backend, SSOT)
4. L'ouverture de tiroir `window.borne.openDrawer()` est-elle conditionnée à un ACK serveur (pas juste UI) ?
5. Le `PaymentLog` ou équivalent consigne-t-il : méthode, montant, référence TPE, timestamp, caissier ?
6. Le montant total payé ≤ total commande ? Règle gérée ? Cas overpay (pourboire intentionnel) ?
7. En cas de refus TPE, le statut reste-t-il UNPAID et l'Order reste-t-il en PENDING (pas d'ACCEPT silencieux) ?
8. Existe-t-il un event `OrderPaid` / `OrderPaymentChanged` au contrat V1, ou bien c'est mélangé dans `OrderStatusChanged` ?
9. Le POS refuse-t-il un changement de paiement sur une commande déjà CANCELED/REJECTED/RETURNED (terminaux) ?
10. Les reçus impriment-ils le détail complet (méthode, montant par méthode, rendu) ?

## Scope

### SUBSYSTEMS_TOUCHED
- `app/Services/OrderService.php` — `changePaymentStatus` (~L1485)
- `app/Http/Controllers/Admin/Order/OrderController.php`
- `app/Models/Order.php` — colonnes `payment_status`, `payment_method`, `paid_at`, `change`, `paid_amount`
- `app/Enums/PaymentStatus.php` (si existe)
- Tables migrations `database/migrations/*_*payment*`
- `resources/js/components/admin/pos/*` — composants UI paiement POS

### SUBSYSTEMS_OFF_LIMITS
- Stripe / Paypal (gelés)
- Flux kiosk (audit C2 / C3)

## Invariants at Risk
- [x] Backend pricing SSOT (rendu de monnaie)
- [x] OrderStatus enum (pas de transition implicite depuis payment)
- [x] Dispatch after DB commit
- [ ] branch_id isolation (indirecte)

## Fichiers à lire
1. `app/Services/OrderService.php` (section changePaymentStatus + dépendances)
2. `app/Http/Controllers/Admin/Order/OrderController.php` (routes paiement)
3. `app/Models/Order.php` (colonnes paiement)
4. `app/Enums/PaymentStatus.php` ou grep `payment_status`
5. Migrations récentes sur `orders.payment_*` ou `order_payments`
6. `resources/js/components/admin/pos/**/Payment*.vue`
7. `docs/BUSINESS_RULES.md` section paiement

## Grep patterns

```
grep -n "changePaymentStatus\|payment_status\|paid_amount" app/Services/OrderService.php
grep -rn "openDrawer" resources/js/
grep -rn "payment_method\|paymentMethod" app/ resources/js/components/admin/pos/
grep -rn "order_payments" app/ database/
grep -rn "OrderPaid\|OrderPaymentChanged" app/Events/ app/Listeners/
grep -n "change\b\|rendu" app/Services/OrderService.php
grep -rn "PaymentLog\|payment_log" app/
```

## Evidence required
- Tableau des transitions payment_status autorisées (modélisées en code).
- Preuve que le rendu est calculé serveur (sinon WARN).
- Modélisation split payment : si colonne unique → BLOCKED ou WARN fort.
- Vérification que les chemins de retour erreur TPE n'altèrent pas le status de commande.

## Grille de verdict
- **PASS** : transitions payment atomiques, rendu backend, split modélisé, events conformes.
- **WARN** : rendu côté front mais sans impact DB OU absence d'event payment dédié.
- **BLOCKED** : double encaissement possible, payment_status sautant UNPAID → PAID sans traçabilité, ACCEPT silencieux sur refus TPE.

## Livrable
`reports/review/AUDIT_POS_PAYMENT_CASH_CARD_002_<DATE>.md`

## Status
- [x] Brief rédigé
- [ ] Plan approuvé
- [ ] Audit exécuté
- [ ] Rapport
- [ ] Tasks correctrices
- [ ] Closed
