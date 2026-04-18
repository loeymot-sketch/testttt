# BLOCKER_POS_9_4_10 — block `OrderService::destroy()` for orders sealed by a closed Z

**Date** : 2026-04-18
**Track** : B (POS)
**Vague** : POS-9.4
**Item** : 9.4.10
**Finding** : POS-GA-F-14

## Contexte

Le plan §9.4.10 demande d'ajouter, dans `OrderService::destroy()` (`app/Services/OrderService.php:~1585-1598`), un guard qui renvoie **409 Conflict** lorsque :

```
$order->fiscal_sequence_no IS NOT NULL
AND $order->created_at < current_open_z.opened_at
```

Le principe : un ordre qui est déjà inclus dans un Z fermé ne peut plus être supprimé — sinon la ligne du Z serait fausse et la signature HMAC ne vérifierait plus.

## Raison du blocage

Même règle que BLOCKER_POS_9_4_2b / BLOCKER_POS_9_4_5 : `OrderService.php` est zone gelée Track A jusqu'à livraison Kiosk P9.5. POS-9.4.10 nécessite impérativement d'éditer `OrderService::destroy` → STOP.

## Impact sur la vague POS-9.4

- Le gate spécifique "Z report numérotation séquentielle sans trou" reste valide : POS-9.4.7 prouve le verrou côté service.
- Le gate "Aucune suppression rétroactive" (invariant §1.2 POS) n'est pas encore enforce côté DELETE. En pratique :
  - POS-9.1.2 a déjà rendu `destroy()` sécurisé par branche et pour les commandes PAID.
  - Tant que 9.4.10 n'est pas câblée, une commande PAID peut encore être détruite si l'opérateur dispose de `pos-destroy-paid`, même si elle fait partie d'un Z fermé.
- Il faudra garantir, dans la PR qui lèvera ce BLOCKER, que le test `DestroyBlockedAfterZTest` soit "red avant / green après".

## Unblock criteria

1. Kiosk P9.5 mergée.
2. Lock `LOCK_B_POS_9_4_10_OrderService_destroy_*` posé.
3. PR `fix(pos/phase-9.4.10): block destroy when order is sealed by a closed Z (POS-GA-F-14)` avec test dédié :
   - order non scellée → destroy OK (comportement 9.1.2 préservé).
   - order `fiscal_sequence_no != null AND created_at < last_closed_z.opened_at` → 409 Conflict.

## Escalation

Non bloquant pour la livraison POS-9.4 : les mécanismes Z (service, signature, controller) sont prouvés en isolation. Le guard destroy est un patch de 10 lignes à appliquer dès que la zone OrderService sera libérée.


---
## CLOSED (2026-04-18)

Closed by commits:
- BL.1 `2d4d2c846` (fiscal sequence wire-in + allergen snapshot, posOrderStore)
- BL.2 `a7036f6ec` (audit log call-sites: discount/cancel/payment_status/destroy/cashBack)
- BL.3 `c3c0593e6` (409 destroy-after-Z guard)

Branche : `feat/pos-phase-9-2-3`. Tests Fiscal+PosOrder+Orders : 93/93 OK. CI invariants : 6/6. Voir `reports/execution/RUN_POS_9_4_BL_2026-04-18.md`.
