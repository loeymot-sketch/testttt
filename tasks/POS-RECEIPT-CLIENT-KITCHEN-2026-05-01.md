# Task — POS receipt split client / kitchen

## TASK_ID

`POS-RECEIPT-CLIENT-KITCHEN-2026-05-01`

## Context

The POS printed a single ticket mixing **structured composition** (variations) and long **wizard instruction** text, duplicate for the customer. Branch phone on the ticket could be stale (Vuex cache vs API `order.branch`).

## Acceptance criteria

1. **Ticket client** : composition structurée uniquement (variations + extras) ; totaux, TVA, paiement, NF525, remerciements ; **pas** de bloc « Instruction » narratif du wizard.
2. **Ticket cuisine** : même commande avec **instructions complètes** (`item.instruction`) pour la préparation ; pas de compteur fiscal dédié au seul bon cuisine.
3. **Téléphone / adresse** : priorité aux données **`order.branch`** (API) sur le cache Vuex `branchShow`.
4. **Impression** : deux actions distinctes (boutons) — client déclenche `POST .../print-receipt` (NF525) ; cuisine **sans** ce POST.
5. Tests Vitest mis à jour (`posReceiptBuilder`, `posReceiptPrintFlow`).

## Plan file

`plans/PLAN_POS_RECEIPT_CLIENT_KITCHEN_2026-05-01.md`
