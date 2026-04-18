# AUDIT_KIOSK_PAYMENT_CASH_017 — Paiement cash Kiosk

## Meta
- **Priority** : P0
- **PRIMARY_MODEL** : Claude
- **TEST_STRATEGY** : `static-inspection`
- **DEPENDS_ON** : AUDIT_KIOSK_ORDER_CREATION_016
- **Estimation** : 0.5 j-h
- **Vague** : C2

## Contexte

Le cash kiosk est un flow atypique : le client choisit "cash", la commande est créée PAID immédiatement (paiement à la caisse physique après), auto-ACCEPT déclenché côté backend, le client reçoit un ticket avec file d'attente. Risques :
- PAID avant encaissement réel → écart caisse en fin de shift.
- Auto-ACCEPT bypass workflow cuisine si configuration KDS.
- Pas de réconciliation avec la caisse physique.

## Questions d'audit

1. Le flow cash kiosk crée-t-il la commande avec `payment_status = PAID` immédiatement, ou `PENDING_CASH_AT_COUNTER` ?
2. Si PAID immédiat : comment la caisse physique réconcilie-t-elle l'argent reçu vs commandes kiosk cash créées ?
3. `finalizePaidKioskOrder` déclenche-t-il auto l'ACCEPT ? Via `OrderStateMachine::apply(PENDING, ACCEPT)` ?
4. Le KDS reçoit-il la commande immédiatement (channel private-branch.X) pour démarrer la préparation ?
5. Le `queue_number` est-il communiqué au client kiosk avant impression ?
6. L'impression ticket utilise `window.borne.print()` + fallback serveur si HS ?
7. Si l'impression échoue, le kiosk affiche-t-il clairement le queue_number à l'écran ?
8. L'annulation post-paiement (client part sans manger) est-elle possible depuis POS ? (interaction audit A10)
9. Un event dédié `KioskCashPending` existe-t-il pour alerter caisse, ou mélangé avec `OrderCreated` ?
10. La séquence est-elle atomique : create Order → transition PAID → transition ACCEPT → dispatch outbox ? Ou split en plusieurs transactions avec race ?

## Scope

### SUBSYSTEMS_TOUCHED
- `app/Services/FrontendOrderService.php` — `myOrderStore` + `finalizePaidKioskOrder`
- `app/Domain/Order/OrderStateMachine.php`
- `resources/js/components/frontend/kiosk/**/*Payment*.vue`, `*Cash*.vue`
- `app/Models/PaymentLog.php` (si existe)

### SUBSYSTEMS_OFF_LIMITS
- Card / TR (audit C3)
- POS cash (audit A2)

## Invariants at Risk
- [x] OrderStatus enum
- [x] Dispatch after DB commit
- [ ] Backend pricing SSOT (indirect)

## Fichiers à lire
1. `app/Services/FrontendOrderService.php` — flow cash
2. `resources/js/components/frontend/kiosk/**/Cash*.vue`, `*Payment*.vue`
3. `app/Domain/Order/OrderStateMachine.php`
4. `docs/BUSINESS_RULES.md` section paiement kiosk

## Grep patterns

```
grep -rn "cash\|CASH" app/Services/FrontendOrderService.php
grep -n "finalizePaidKioskOrder" app/Services/FrontendOrderService.php
grep -rn "payment_method.*cash\|CASH" resources/js/components/frontend/kiosk/
grep -rn "auto.*accept\|autoAccept" app/ resources/js/
grep -rn "KioskCashPending\|cash_at_counter" app/
```

## Evidence required
- Séquence exacte cash kiosk (étapes create → finalize → transitions → events).
- Statut payment_status initial.
- Stratégie de réconciliation caisse (documentée ou absente).
- Comportement si impression HS.

## Grille de verdict
- **PASS** : flow atomique, state machine respectée, reconciliation prévue, UI fallback queue_number.
- **WARN** : PAID immédiat sans réconciliation dédiée (acceptable V1 avec process métier manuel documenté).
- **BLOCKED** : transitions sans state machine, events avant commit, queue_number invisible si impression HS.

## Livrable
`reports/review/AUDIT_KIOSK_PAYMENT_CASH_017_<DATE>.md`

## Status
- [x] Brief rédigé
- [ ] Plan approuvé
- [ ] Audit exécuté
- [ ] Rapport
- [ ] Tasks correctrices
- [ ] Closed
