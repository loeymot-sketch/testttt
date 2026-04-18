# AUDIT_KIOSK_CANCEL_REFUND_025 — Annulation & Remboursement Kiosk

## Meta
- **Priority** : P2
- **PRIMARY_MODEL** : Claude
- **TEST_STRATEGY** : `static-inspection`
- **DEPENDS_ON** : AUDIT_KIOSK_ORDER_CREATION_016, AUDIT_KIOSK_PAYMENT_CASH_017, AUDIT_KIOSK_PAYMENT_DEFERRED_CARD_TR_018
- **Estimation** : 0.75 j-h
- **Vague** : C10

## Contexte

Trois cas d'annulation kiosk :
1. Avant paiement : clear panier, aucun side effect.
2. Après paiement cash, avant préparation : staff annule → remboursement manuel en caisse.
3. Après paiement carte, avant préparation : remboursement carte via TPE (partial or full).
Et potentiellement : annulation post-PREPARING (plat raté) → remboursement.

Risques : loyalty crédité sur annulé, remboursement partiel mal calculé, state machine cassée si annulation à mauvais stade.

## Questions d'audit

1. Avant paiement : clear panier kiosk est-il purement local (aucune `Order` créée) ?
2. Après paiement cash : quel flow staff POS pour annuler une commande kiosk ? Interface dédiée ?
3. Après paiement carte : existe-t-il un endpoint `refund` qui déclenche le TPE refund automatiquement ou seulement trace l'intention ?
4. L'event `OrderCancelled` (cf EventContract L38) est-il émis avec les keys required (`order_id`) ?
5. Le state machine accepte-t-il la transition vers CANCELED depuis quels états (PENDING, PAID, ACCEPT, PREPARING) ?
6. La permission d'annuler est-elle role-gated (manager only après PAID) ?
7. Loyalty : les points crédités sont-ils retirés sur annulation ?
8. Stock / 86 : la réserve d'un item 86 est-elle libérée sur annulation ?
9. Le kiosk (OSS subscribe) voit-il l'event `OrderCancelled` pour afficher "Annulée" au client si applicable ?
10. Le ticket rectificatif / avoir fiscal est-il généré ?

## Scope

### SUBSYSTEMS_TOUCHED
- `app/Services/OrderService.php` — cancel logique (POS initie souvent)
- `app/Services/FrontendOrderService.php` — si cancel depuis kiosk existe
- `app/Events/OrderCancelled.php`
- `app/Domain/Order/OrderStateMachine.php` — transitions vers CANCELED
- `resources/js/components/admin/pos/**/Cancel*.vue`
- `resources/js/components/frontend/kiosk/**/Cancel*.vue`

## Invariants at Risk
- [x] OrderStatus enum
- [x] Dispatch after DB commit
- [x] Backend pricing SSOT (remboursement partiel)

## Fichiers à lire
1. `app/Services/OrderService.php` — grep cancel
2. `app/Services/FrontendOrderService.php` — grep cancel
3. `app/Events/OrderCancelled.php`
4. `app/Domain/Order/OrderStateMachine.php`
5. `docs/ORDER_FLOW.md`

## Grep patterns

```
grep -rn "cancel\|Cancel\|CANCELED" app/Services/OrderService.php app/Services/FrontendOrderService.php
grep -rn "refund\|Refund" app/Services/ app/Http/Controllers/
grep -rn "OrderCancelled::dispatch" app/
grep -rn "allows.*CANCELED\|CANCELED.*allows" app/Domain/Order/OrderStateMachine.php
grep -rn "revokeLoyalty\|revertPoints\|subtractPoints" app/
```

## Evidence required
- Quelles transitions vers CANCELED sont autorisées, depuis quels rôles.
- Flow remboursement carte (auto TPE ou manuel).
- Loyalty rollback.
- Event conforme V1.
- Avoir fiscal / ticket rectificatif.

## Grille de verdict
- **PASS** : toutes transitions protégées, refund automatisé (ou process manuel documenté), loyalty rollback, event conforme.
- **WARN** : refund manuel sans automatisation TPE.
- **BLOCKED** : CANCELED autorisé depuis état terminal, loyalty non revert, event manquant.

## Livrable
`reports/review/AUDIT_KIOSK_CANCEL_REFUND_025_<DATE>.md`

## Status
- [x] Brief rédigé
- [ ] Plan approuvé
- [ ] Audit exécuté
- [ ] Rapport
- [ ] Tasks correctrices
- [ ] Closed
