# AUDIT_POS_AMEND_ORDER_GAP_010 — Modification d'une commande existante

## Meta
- **Priority** : P2
- **PRIMARY_MODEL** : Claude
- **TEST_STRATEGY** : `static-inspection`
- **DEPENDS_ON** : AUDIT_POS_ORDER_CREATION_001, AUDIT_POS_STATUS_TRANSITIONS_003
- **Estimation** : 0.75 j-h
- **Vague** : A10

## Contexte

Après création, une commande peut être modifiée : ajout d'un item tardif, correction d'erreur caisse, retrait d'un plat, changement de destination. Gap fréquent : UI existe mais backend ne recalcule pas, ou tolère modification en état avancé (PREPARING) sans notifier le KDS.

## Questions d'audit

1. Quels endpoints permettent d'amender une commande POS (add/remove/update item) ? Listés ?
2. L'amendement est-il bloqué passé un certain statut (ex : PREPARING interdit la modification items) ?
3. Le total est-il recalculé via `PricingService` sur amend, ou uniquement delta additif ?
4. L'amend génère-t-il un event `OrderItemAdded` / `OrderItemRemoved` canonique, ou juste un `OrderStatusChanged` bâtard ?
5. Le KDS reçoit-il la notification d'amend pour mettre à jour son écran sans refresh ?
6. Le caissier a-t-il une trace (history) des modifications (qui, quand, quoi) ?
7. Les prix des items déjà présents sont-ils "gelés" (prix à la création) ou recalculés (risque de divergence) ?
8. L'amend peut-il créer un total négatif (remboursement partiel) ? Géré ?
9. La permission d'amend est-elle limitée par rôle (manager only au-delà d'un seuil de remise) ?
10. L'impression du "ticket rectificatif" ou "note de modification" existe-t-elle pour la conformité fiscale ?

## Scope

### SUBSYSTEMS_TOUCHED
- `app/Services/OrderService.php` — méthodes update/amend
- `app/Http/Controllers/Admin/Order/OrderController.php` (PATCH/PUT)
- `app/Events/OrderItemAdded.php` (doit exister cf. EventContract L37)
- `resources/js/components/admin/pos/**/EditOrder*.vue`

### SUBSYSTEMS_OFF_LIMITS
- Flux remboursement Stripe/Paypal

## Invariants at Risk
- [x] Backend pricing SSOT (recalcul amend)
- [x] OrderStatus enum (pas de mutation sans transition valide)
- [x] Dispatch after DB commit

## Fichiers à lire
1. `app/Services/OrderService.php` — grep update/amend/addItem
2. Routes POS PATCH/PUT
3. `app/Events/OrderItemAdded.php`
4. Vue edit order POS
5. `docs/ORDER_FLOW.md` section amend (si existe)

## Grep patterns

```
grep -rn "amendOrder\|updateOrder\|addItem\|removeItem" app/Services/OrderService.php
grep -rn "OrderItemAdded" app/Events/ app/Listeners/
grep -rn "Route::patch\|Route::put" routes/api.php | grep -i order
grep -rn "freeze\|price_locked\|original_price" app/Models/OrderItem.php
```

## Evidence required
- Liste des endpoints d'amend + protection par statut.
- Comportement event après amend.
- Politique de pricing (gelé vs recalculé).

## Grille de verdict
- **PASS** : amend bloqué en état avancé, recalcul via PricingService, event dédié, audit trail.
- **WARN** : fonctionnalité partielle (ex amend permis mais KDS non notifié).
- **BLOCKED** : amend en PREPARING toléré, total cassé, pas de trace.

## Livrable
`reports/review/AUDIT_POS_AMEND_ORDER_GAP_010_<DATE>.md`

## Status
- [x] Brief rédigé
- [ ] Plan approuvé
- [ ] Audit exécuté
- [ ] Rapport
- [ ] Tasks correctrices
- [ ] Closed
