# PLAN POS V4 CASHIER OPS — 2026-05-02

TASK_ID: POS-V4-CASHIER-OPS-2026-05-02
PHASE: EXECUTE
PRIMARY_EXECUTION_MODEL: claude-opus-4-7-thinking-high (autonomous continuous mode requested by human)
REASONING_EFFORT: high
RUNNER_MODE: single-session
PLAN_REVIEW: skipped — bounded UI-only batch, autonomous execution requested by user; backend touched only via existing endpoints already in production.
EXECUTE_DELEGATION: in-session (UI + i18n + tests; no backend code change; no schema change).

## Context (carry-forward, no reload)

User requested a continuous execution wave covering remaining cashier operations to close Caisse V1 (P0), high-value low-complexity P1 items, and simple P2 items. They explicitly asked NOT to stop between cycles, to run continuous audit, and to perform a global audit + tests at the end.

Prior cycles already shipped (see ACTIVE_CYCLE_ARCHIVE):
- POS-V4-WIZARD-DRINKS-SYNC-2026-05-02 (drinks list catalog-driven)
- POS-V4-VIEWPORT-UI-2026-05-02 (wizard fullscreen, kiosk-cash button relocation)
- POS-V4-ORDERS-TRACKER-2026-05-02 (PosOrdersTrackerComponent + active orders glow button)
- POS-V4-ORDERS-ACCESS-2026-05-02 (history + detail links exposed from cashier)

## Backlog inventory & scope decision

| Item | Priority | Backend ready? | Decision this cycle |
|------|----------|----------------|---------------------|
| Réimpression ticket caisse + cuisine depuis tracker/show | P0 | Yes — ReceiptComponent + OrderDetailsResource already supply all fiscal fields | **EXECUTE — W1.A** |
| Bouton no-sale / ouvrir tiroir | P1 | Yes — `kioskHardware.openDrawer()` exists | **EXECUTE — W1.B** |
| Annuler dernière ligne (raccourci panier) | P1 | Yes — `posCart/removeItem` exists | **EXECUTE — W1.C** |
| Recherche commande parking par n°/nom | P1 | Yes — list already client-side | **EXECUTE — W1.D** |
| Notes commande (allergie / instruction client) | P1 | **NO — requires schema migration** on orders/order_items | **DEFERRED — hard gate (schema)** |
| Annulation commande avec motif depuis tracker | P0 | Yes — `posOrder/changeStatus` accepts `reason` (OrderService L1546-1551) | **EXECUTE — W2.A** |
| Remise commande avec motif | P0 | Yes — backend enforces `discount_reason` ≥3 chars (OrderService L2007-2011) | **EXECUTE — W2.B** (UI strengthening + tests) |
| Paiement split (cash + carte) | P0 | **NO — backend `pos_payment_method` is a single value, no payment_lines table** | **DEFERRED — dedicated cycle (backend symmetry)** |
| Pourboire (tip) | P1 | **NO — depends on payment refactor + new field** | **DEFERRED — dedicated cycle** |
| Modification commande déjà envoyée | P0 | **NO — touches dispatch/idempotency, fiscal sequence implications** | **DEFERRED — dedicated cycle** |
| Recall parking par n°/nom | P1 | (subsumed in W1.D) | covered |
| Rapport X/Z | P1 | partially (existing SalesReport) | **DEFERRED — verify scope, dedicated cycle** |

## SUBSYSTEMS_TOUCHED

- `resources/js/components/admin/pos/PosComponent.vue` — cart cancel-last button + no-sale button + i18n labels (read+write)
- `resources/js/components/admin/pos/ParkedOrdersComponent.vue` — search filter (read+write)
- `resources/js/components/admin/pos/PosOrdersTrackerComponent.vue` — reprint + cancel-with-reason actions on order cards (read+write)
- `resources/js/components/admin/pos/ReceiptComponent.vue` — accept `order` prop already (no change expected); ensure modal can be opened standalone — read only or minor wiring
- `resources/js/languages/fr.json` + `en.json` — new i18n keys (write)
- `resources/js/store/modules/posCart.js` — read only (use existing actions)
- `resources/js/store/modules/posOrder.js` — read only (use existing `changeStatus`, `show`)
- `resources/js/services/kioskHardware.js` — read only (use existing `openDrawer`)
- `tests/js/PosComponent.spec.js` — extend for new buttons (write)
- `tests/js/PosOrdersTrackerComponent.spec.js` — new file (write)
- `tests/js/ParkedOrdersComponentSearch.spec.js` — new file (write)

## SUBSYSTEMS_OFF_LIMITS

- `app/**` — no backend code change
- `database/migrations/**` — no schema change
- `routes/**` — no new routes
- `app/Services/OrderService.php` — read-only; we use existing `changeStatus` reason path
- `resources/js/components/admin/pos/PaymentComponent.vue` — out of scope (payment split deferred)

## INVARIANTS_AT_RISK

1. **Backend pricing SSOT** — risk: NONE. UI only sends existing fields (`reason`, `discount_reason`, status). No client-side price math added.
2. **OrderStatus enum authoritative** — risk: LOW. Cancel uses `orderStatusEnum.CANCELED` (constant import). No string literal.
3. **branch_id isolation** — risk: NONE. UI calls existing `posOrder/changeStatus` which is already branch-scoped.
4. **Dispatch after commit** — risk: NONE. Backend handles dispatch.
5. **OrderService/FrontendOrderService symmetry** — risk: NONE. We don't touch services.
6. **Frozen zones** — risk: NONE. No frozen file edited.

## GATE_CONDITIONS

- None anticipated for the 6 features executed.
- Hard gates blocking deferred items: (a) schema migration for kitchen-note column, (b) backend payment-split refactor, (c) order-modification fiscal/dispatch implications.

## SCOPE_PRESSURE

  trigger: While wiring W2.A cancel-with-reason, found pre-existing bug in PosOrdersTrackerComponent.markDelivered shipped in cycle POS-V4-ORDERS-TRACKER-2026-05-02 — payload used `order_status` instead of `status`, which silently fails OrderStatusRequest validation (rule binds to `status`). The "Mark delivered" button never actually transitioned status backend-side.
  subsystem: PosOrdersTrackerComponent.vue (already in SUBSYSTEMS_TOUCHED)
  decision: FIXED-IN-CYCLE — change is 1 line, stays inside declared scope, and a parallel cancel feature would be misleading without correct mark-delivered semantics in the same screen. Audit trail recorded here.

## TEST STRATEGY

- `local-validation`: vitest for new component behaviors (search filter, reprint trigger, cancel modal); existing PosComponent.spec extended.
- `static-inspection`: ReadLints after each wave.
- `static + build`: `npm run dev` after each wave to ensure no compilation regression.
- No E2E in this cycle (already covered in prior cycles for tracker/orders-access).

## EXECUTION ORDER

WAVE 1 (UI quick wins, low risk):
- W1.A Reprint from tracker + show
- W1.B No-sale (open drawer)
- W1.C Cancel last cart line
- W1.D Parked-orders search

WAVE 2 (P0 with backend):
- W2.A Cancel order with reason from tracker
- W2.B Discount with reason — UI strengthening + Vitest

After each wave: build + lint + vitest spec relevant to the wave.

## FINAL AUDIT (end of cycle)

- Full `npm run dev`
- ReadLints workspace-wide on touched files
- Full Vitest run on `tests/js/*Pos*.spec.js`
- Manifest of files touched + 1-line outcome each
- agent-activity-log done
