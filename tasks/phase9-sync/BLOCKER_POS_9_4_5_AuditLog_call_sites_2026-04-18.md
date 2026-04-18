# BLOCKER_POS_9_4_5 — wire `AuditLogService::write()` into sensitive call-sites

**Date** : 2026-04-18
**Track** : B (POS)
**Vague** : POS-9.4
**Item** : 9.4.5
**Finding** : POS-GA-F-04

## Contexte

Le plan POS-9.4 §9.4.5 demande d'appeler `AuditLogService::write()` depuis les call-sites sensibles suivants :

- `app/Services/OrderService.php` → cancel, destroy, discount > 0, refund, `changePaymentStatus`.
- `app/Services/PaymentService.php` → tout mouvement cash drawer (open/close) et refund.
- `app/Services/Pricing/DiscountCalculator.php` → application d'un discount.

Les hooks `Z open/close` et `drawer open` sont déjà prévus en POS-9.4.7 (service nouveau) et POS-9.5 (service nouveau), donc ne passent pas par OrderService.

## Raison du blocage

Décision orchestrateur (message d'exécution POS-9.4) : toute modification `OrderService` est réservée au lock Track A (Kiosk P9.5 state machine shared). Même si `PaymentService` et `DiscountCalculator` ne sont pas explicitement cités, leurs call-sites provoquent systématiquement un effet de bord sur `OrderService` (flux `posOrderStore` → `DiscountCalculator`, `destroy` → `PaymentService::cashBack`) → risque élevé d'introduire une régression dans une zone gelée.

## Impact sur la vague POS-9.4

- Les P0 écritures d'audit "sensibles" (cancel/destroy/refund/discount/payment_status) ne passent pas encore par `AuditLogService` — elles continuent d'utiliser `ActionLog` (mutable).
- Le gate POS-9.4 *Audit log immuable* reste validé **au niveau du schéma et du service** : les tests `AuditLogImmutabilityTest` (5/5) et `AuditLogHashChainTest` (5/5) prouvent que, dès qu'un call-site bascule sur `AuditLogService::write`, la chaîne d'intégrité tient et aucune altération n'est possible.
- En attendant l'adaptation des call-sites, `ActionLog` reste en ligne de défense (POS-9.1.4 lui a déjà ajouté `branch_id`).

## Unblock criteria

1. Kiosk P9.5 livrée et mergée.
2. Lock `LOCK_B_POS_9_4_5_OrderService_*` posé après vérification absence `LOCK_A_*`.
3. PR dédiée `fix(pos/phase-9.4.5): route sensitive writes through AuditLogService (POS-GA-F-04)`.
4. Test `AuditLogSensitiveActionsTest` créé : couvre cancel → 1 ligne `order.cancel`, destroy → 1 ligne `order.destroy`, discount > 0 → 1 ligne `order.discount_applied`, refund → 1 ligne `order.refund`, `changePaymentStatus` → 1 ligne `order.payment_status`.

## Escalation

Non bloquant pour le reste de POS-9.4 : le schéma, le service et la chaîne HMAC sont tous prouvés indépendamment. La bascule des call-sites est un patch mécanique qui peut se faire en une seule PR courte après déblocage.


---
## CLOSED (2026-04-18)

Closed by commits:
- BL.1 `2d4d2c846` (fiscal sequence wire-in + allergen snapshot, posOrderStore)
- BL.2 `a7036f6ec` (audit log call-sites: discount/cancel/payment_status/destroy/cashBack)
- BL.3 `c3c0593e6` (409 destroy-after-Z guard)

Branche : `feat/pos-phase-9-2-3`. Tests Fiscal+PosOrder+Orders : 93/93 OK. CI invariants : 6/6. Voir `reports/execution/RUN_POS_9_4_BL_2026-04-18.md`.
