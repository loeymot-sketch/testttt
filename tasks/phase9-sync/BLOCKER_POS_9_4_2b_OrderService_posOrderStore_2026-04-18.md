# BLOCKER_POS_9_4_2b — wire `FiscalSequenceService` into `OrderService::posOrderStore`

**Date** : 2026-04-18
**Track** : B (POS)
**Vague** : POS-9.4
**Item** : 9.4.2 (deuxième moitié — wire-in)
**Finding** : POS-GA-F-38

## Contexte

Le plan POS-9.4 item 9.4.2 décrit deux responsabilités :

1. **9.4.2a — service** : écrire `FiscalSequenceService::next($branchId)` atomique (Cache lock + `SELECT MAX+1`).
   → Livré (commit `f1bff8bfd` précédent + service commit dans ce même lot).
2. **9.4.2b — câblage** : appeler `FiscalSequenceService::next()` **depuis `posOrderStore` et `FrontendOrderService::myOrderStore`**, juste avant `$order->save()`, et persister le retour dans `orders.fiscal_sequence_no`.

## Raison du blocage

Décision orchestrateur explicite (message d'exécution POS-9.4) :

> "Si un item POS-9.4 nécessite absolument une modif `OrderService` → STOP, crée
> `tasks/phase9-sync/BLOCKER_POS_9_4_<id>.md`, attends arbitrage humain."

La méthode `posOrderStore` est définie dans `app/Services/OrderService.php:546`. Kiosk P9.5 (state machine shared) n'est pas encore démarrée — la zone reste réservée Track A.

`FrontendOrderService::myOrderStore` (dans `app/Services/FrontendOrderService.php:121`) est également touchée par le même wire-in et ne peut pas être traitée seule sans créer un schéma bancal (moitié des commandes avec séquence, moitié sans).

## Impact sur la vague POS-9.4

- `orders.fiscal_sequence_no` reste **NULL** par défaut tant que le wire-in n'est pas en place.
- `ZReportService::close` peut agréger par `created_at` (c'est ce que le plan prévoit), donc la vague reste fonctionnelle — le Z fiscal sera correct sur la *période*, mais sans numérotation atomique par commande.
- Le gate POS-9.4 n° 2 du plan (« `order.fiscal_sequence_no` sans trou sur une branche ») est **partiellement atteint** : le service est prouvé atomique en isolation (`FiscalSequenceTest::test_atomic_per_branch_no_gaps`, 5/5), mais pas encore câblé aux call-sites de production.

## Zones concernées (interdites tant que BLOCKER ouvert)

- `app/Services/OrderService.php::posOrderStore` (ligne ~546, bloc `DB::transaction`).
- `app/Services/FrontendOrderService.php::myOrderStore` (ligne ~121).

## Unblock criteria

1. Track A livre Kiosk P9.5 (state machine shared) et merge sur `main`.
2. Un lock `LOCK_B_POS_9_X_OrderService_*` Track B est posé après vérification absence `LOCK_A_*`.
3. Le câblage est livré en PR dédiée `fix(pos/phase-9.4.2b): wire FiscalSequenceService into posOrderStore + myOrderStore (POS-GA-F-38)`.
4. Le test `FiscalSequenceTest::test_wire_in_posOrderStore_assigns_next_number` est ajouté et passe.

## Escalation

Non bloquant pour POS-9.4 (les 10 autres items livrables passent). À traiter après POS-9.5 kiosk ou en hotfix ciblé si orchestrateur revalide la zone OrderService.


---
## CLOSED (2026-04-18)

Closed by commits:
- BL.1 `2d4d2c846` (fiscal sequence wire-in + allergen snapshot, posOrderStore)
- BL.2 `a7036f6ec` (audit log call-sites: discount/cancel/payment_status/destroy/cashBack)
- BL.3 `c3c0593e6` (409 destroy-after-Z guard)

Branche : `feat/pos-phase-9-2-3`. Tests Fiscal+PosOrder+Orders : 93/93 OK. CI invariants : 6/6. Voir `reports/execution/RUN_POS_9_4_BL_2026-04-18.md`.
