# AUDIT_KIOSK_ORDER_CREATION_016 — Cycle création commande Kiosk

## Meta
- **Priority** : P0
- **PRIMARY_MODEL** : Claude
- **TEST_STRATEGY** : `static-inspection`
- **DEPENDS_ON** : —
- **Estimation** : 0.75 j-h
- **Vague** : C1

## Contexte

`FrontendOrderService::myOrderStore` (~L121 du fichier 781L) crée la commande kiosk. Particularités kiosk :
- `FrontendOrder` modèle distinct sur même table `orders`.
- Auth Sanctum token avec ability `kiosk:order`.
- Déclenche outbox + Echo subscribe pour OSS/KDS.
- Statut initial peut être PAID direct (cash) ou PENDING (card déféré).

Risques : divergence avec POS sur le calcul de prix ; `branch_id` depuis le token kiosk mal extrait ; event invalide EventContract ; finalizePaidKioskOrder (~L707) asynchrone race.

## Questions d'audit

1. `myOrderStore` est-il encapsulé dans une transaction DB ?
2. Le `branch_id` vient-il **exclusivement** du token kiosk authentifié (PersonalAccessToken → user → branch_id) et jamais du payload ?
3. Le calcul du total passe-t-il par le même PricingService que OrderService POS, ou calcul dupliqué ?
4. L'event `OrderCreated` est-il dispatché en `afterCommit` via `PersistOrderCreatedToOutbox` (lu : oui, L36-38) ?
5. Le `correlation_id` est-il généré via UUID et loggé dans le contexte kiosk pour traçabilité ?
6. `dispatchNewOrderSignals` (~L765) fait-il partie du flow normal ou secondaire ? Que signale-t-il ?
7. `finalizePaidKioskOrder` (~L707) gère-t-il correctement la transition automatique PENDING → PAID → ACCEPT ? Utilise-t-il OrderStateMachine ?
8. En cas d'échec création (validation, FK, lock DB), le kiosk reçoit-il un code HTTP exploitable + message user-friendly ?
9. Le payload envoyé par le kiosk (items[], payment_method, order_type) est-il validé strictement (FormRequest) ?
10. Le `order_type` kiosk (sur_place / emporter) influence-t-il le taux TVA à ce stade ?

## Scope

### SUBSYSTEMS_TOUCHED
- `app/Services/FrontendOrderService.php` (781L) — `myOrderStore`, `finalizePaidKioskOrder`, `dispatchNewOrderSignals`
- `app/Http/Controllers/Frontend/FrontendOrderController.php`
- `app/Models/FrontendOrder.php`, `app/Models/Order.php`
- `app/Listeners/PersistOrderCreatedToOutbox.php` (lu)
- `routes/api.php` section `frontend/order` (~L820+)
- `app/Http/Requests/Frontend/*Order*`

### SUBSYSTEMS_OFF_LIMITS
- POS create (audit A1)
- Flow paiement détaillé (audits C2/C3)

## Invariants at Risk
- [x] Backend pricing SSOT
- [x] OrderStatus enum
- [x] branch_id data isolation
- [x] Dispatch after DB commit
- [x] OrderService / FrontendOrderService symmetry
- [x] Frozen zone (FrontendOrderService)

## Fichiers à lire
1. `app/Services/FrontendOrderService.php` (myOrderStore, finalizePaidKioskOrder, dispatchNewOrderSignals)
2. `app/Http/Controllers/Frontend/FrontendOrderController.php`
3. `app/Models/FrontendOrder.php`
4. `routes/api.php` — bloc kiosk
5. `app/Http/Requests/Frontend/*` — validations
6. `docs/ORDER_FLOW.md`, `docs/DEVICE_FLOW.md`

## Grep patterns

```
grep -n "myOrderStore\|finalizePaidKioskOrder\|dispatchNewOrderSignals" app/Services/FrontendOrderService.php
grep -rn "DB::transaction" app/Services/FrontendOrderService.php
grep -rn "branch_id" app/Services/FrontendOrderService.php
grep -rn "token()->\|tokenCan\|currentAccessToken" app/Http/Controllers/Frontend/
grep -n "subtotal\|total\s*=" app/Services/FrontendOrderService.php
grep -rn "OrderStateMachine" app/Services/FrontendOrderService.php
grep -rn "order_type\|sur_place" app/Services/FrontendOrderService.php app/Http/Requests/Frontend/
```

## Evidence required
- Extrait commenté `myOrderStore`.
- Provenance `branch_id` tracée pas à pas.
- Appel PricingService ou calcul local.
- Utilisation OrderStateMachine dans `finalizePaidKioskOrder`.
- Tableau comparatif OrderService::posOrderStore vs FrontendOrderService::myOrderStore (diffs notables).

## Grille de verdict
- **PASS** : transaction, branch_id server, pricing unifié, events afterCommit, state machine utilisée, validation stricte.
- **WARN** : divergences mineures avec POS (arrondi, un champ optionnel) sans impact prix.
- **BLOCKED** : branch_id depuis payload, pricing dupliqué divergent, transition status sans state machine, event avant commit.

## Livrable
`reports/review/AUDIT_KIOSK_ORDER_CREATION_016_<DATE>.md`

## Status
- [x] Brief rédigé
- [ ] Plan approuvé
- [ ] Audit exécuté
- [ ] Rapport
- [ ] Tasks correctrices
- [ ] Closed
