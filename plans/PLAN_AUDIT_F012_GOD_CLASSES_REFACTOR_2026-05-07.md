# PLAN_AUDIT_F012 — God Classes Refactor (POS surface)
**Severity:** P2 — Maintenabilité, vélocité dégradée
**Owner agent:** Agent E
**Sprint:** Backlog rolling

## THINK

| File | LOC | Type |
|---|---|---|
| `app/Services/OrderService.php` | 1888 | God class backend |
| `resources/js/components/admin/pos/PosComponent.vue` | 2078 | God component frontend |
| `public/js/pos-wizard.js` | 5769 | Vanilla JS monolith **FROZEN** |
| `public/css/pos-wizard.css` | 1987 | CSS monolith **FROZEN** |

`OrderService` mélange : creation, payment, KDS, delivery, refund, loyalty. Chaque modification a un blast radius énorme.

## PLAN (incremental, low-risk)

1. **OrderService split** (sans casser API publique) :
   - `OrderService\OrderCreationService` (posOrderStore, myOrderStore-equivalent)
   - `OrderService\OrderStateService` (changeStatus, changePaymentStatus)
   - `OrderService\OrderRefundService` (cashBack, destroy)
   - `OrderService` devient un façade qui délègue.
   - Test : la suite POS complète reste verte sans modification.

2. **PosComponent.vue** : extraire en composables Composition API :
   - `useCart`, `usePayment`, `useReceipt`, `useWizardOrchestration`.

3. **pos-wizard.js + pos-wizard.css** : **FROZEN, pas de touche** sauf gate explicite owner (mémoire `feedback_wizard_popup_pos_protected`). Pas dans ce plan.

## BUILD

Seulement étape 1 dans ce sprint. Étape 2 séparée (PosV5 design system existe déjà — voir agent map).

## Contraintes

- ❌ Aucune modification de `pos-wizard.js` / `pos-wizard.css`.
- ❌ Pas de changement de signature publique (facade pattern).
- ✅ Refactor doit être 100 % isofonctionnel — diff de comportement = `block`.

## Decision

`continue` si full POS test suite verte avant/après. `heal` si tests intermittents.
