# P3 — Refund lifecycle complet (handoff)

## Audit état / ce cycle

| Sujet | État |
|-------|------|
| `Order::restore()` | **Bloqué** (`RuntimeException`) — voir `PosOrderRestoreIntegrityTest` |
| Statut `RETURNED` (22) | Transition `DELIVERED` → `RETURNED` (`OrderStateMachine`) |
| Audit NF525 annulation | `order.cancelled` / `order.rejected` existaient |
| Audit **retour / remboursement** | **Ajouté** : `order.returned` avec payload (serial, statuts, motif, total, `fiscal_sequence_no`, `payment_status`) |
| Motif obligatoire | Aligné **CANCELED / REJECTED / RETURNED** (validation HTTP même pattern) |
| Cashback gateway | Si `orders` a une ligne `transactions`, `PaymentService::cashBack` + `refundPoints` — **étendu à RETURNED** (comme annulation) |
| Remboursement **partiel** (ligne ou montant) | **Backlog** — pas de table `order_refund_lines` |
| `PaymentStatus::REFUNDED` | **Inexistant** (seulement PAID / UNPAID) — évolution schéma si besoin |
| Z-report `refund_count` | Compte les commandes `status = RETURNED` (inchangé) |

## Tests

- `tests/Feature/Fiscal/PosOrderBL2AuditCallSitesTest.php` — `order.returned` + chaîne HMAC + 422 sans motif
- `tests/Feature/PosOrderRestoreIntegrityTest.php` — invariant restore (inchangé, référence)

## SYMMETRY_NOTE

- Frontend annulation kiosk / remboursements : **non touché** ici — chemins staff `OrderService::changeStatus` (else).

## Reliquat P3 (phases)

1. Remboursement partiel (lignes ou montant) + immuabilité `order_items`.
2. Permission Spatie dédiée `pos-order-return` vs caissier (optionnel).
3. Statut paiement `REFUNDED` ou lignes `order_payments` avec contrepartie Z.
4. Cas « tender disparu » / après clôture Z — règles métier + audit `order.refund_partial`.

## Invariants

- **Dispatch after commit** — inchangé (broadcast après `DB::transaction`).
- **branch_id** — filtre staff inchangé.
