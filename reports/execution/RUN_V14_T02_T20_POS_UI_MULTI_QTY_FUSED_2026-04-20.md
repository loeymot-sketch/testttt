EXECUTE_DELEGATION: foodking-complex-implementer

# RUN_V14_T02_T20_POS_UI_MULTI_QTY_FUSED_2026-04-20

## Scope executed

Ticket executed exactly inside the authorized POS/frontend boundaries:

- `resources/js/components/admin/pos/ItemComponent.vue`
- `resources/js/components/admin/pos/PosComponent.vue`
- `resources/js/components/admin/pos/PaymentComponent.vue`
- `resources/js/store/modules/posCart.js`
- `resources/js/helpers/posNormalizeIds.js`
- `tests/js/posNormalizeIds.spec.js`
- `tests/js/posVariationMultiQty.spec.js`

No backend service, request, resource, migration, seeder, or kiosk frontend file was modified in this execution.

## Behavior delivered

- Added `posNormalizeIds.js` with defensive normalization for ids, quantities, legacy selection payloads, and checkout cart API serialization.
- Migrated POS cart storage and checkout shaping to support `item_variations: [{ id, quantity, item_attribute_id, ... }]` and `item_extras: [{ id, quantity, ... }]`.
- Added lazy migration for legacy cart lines loaded from localStorage so existing POS carts remain editable.
- Reworked `ItemComponent.vue` variation UI:
  - legacy single-select branch preserved for `max_select=1 && !allow_repeat`
  - multi-qty counter branch for `max_select > 1 || allow_repeat`
  - add-to-cart blocked while attribute minimum selection is not met
  - extras moved to counter-based quantity selection
- Updated cart fallback rendering in `PosComponent.vue` to work with quantity-aware variation/extra arrays.
- Normalized outgoing order payload in `PaymentComponent.vue` before POST to `/admin/order`.

## Diff summary

Modified files and line counts:

- `resources/js/components/admin/pos/ItemComponent.vue` — `+295 / -198`
- `resources/js/components/admin/pos/PaymentComponent.vue` — `+5 / -1`
- `resources/js/components/admin/pos/PosComponent.vue` — `+62 / -59`
- `resources/js/store/modules/posCart.js` — `+158 / -50`
- `resources/js/helpers/posNormalizeIds.js` — new file, `188` lines
- `tests/js/posNormalizeIds.spec.js` — new file, `77` lines
- `tests/js/posVariationMultiQty.spec.js` — new file, `269` lines

## Test results

### Targeted Vitest

- `npx vitest run tests/js/posNormalizeIds.spec.js`
  - `7/7` tests passed
- `npx vitest run tests/js/posNormalizeIds.spec.js tests/js/posVariationMultiQty.spec.js`
  - `15/15` tests passed
- `tests/js/posVariationMultiQty.spec.js`
  - `8/8` tests passed

Covered scenarios:

1. `4x Steak` stored as one variation row with `quantity: 4`
2. `2x Steak + 2x Poulet`
3. `3x Steak + 1x Poulet`
4. Add-to-cart disabled when `total < min_select`
5. Attribute max guard reached when `total === max_select`
6. Quantity decremented to zero removes the variation row
7. Editing a cart line restores multi-qty selections
8. Legacy single-select keeps quantity `1` behavior

### Full Vitest regression

- Command: `npx vitest run`
- Result: `66` files passed, `574/574` tests passed
- Baseline before task: `535/535`
- Net green increase after this run: `+39` tests in the workspace state, including the `+15` tests introduced/validated by this ticket pair

### Backend regression guard

- Command: `php artisan test --filter='Pricing|OrderItem|FrontendOrder|PosOrder|ItemAttribute'`
- Result: `97/97` tests passed

### Post-execute hook

- Command: `.cursor/hooks/post-execute.sh`
- Hook executed successfully
- Hook internal global test step reported a failure in `Tests\Feature\DispatchAfterCommitTest`
- This failure is outside the POS/frontend scope executed here; the ticket-targeted backend regression command above remained green at `97/97`

## DOM / UI evidence

No browser capture was produced in this run.

Validation evidence used instead:

- direct method-driven assertions on `ItemComponent.vue` selection logic
- payload assertions on cart row shaping
- legacy cart restoration assertions via `openEditFromCart()`

## Assumptions

- `itemAttribute.min_select`, `max_select`, and `allow_repeat` are exposed by the POS item details API for multi-select products; if absent, the implementation falls back to legacy-compatible defaults (`min=1`, `max=1`, `allow_repeat=false` for single-select behavior).
- Extras do not expose grouped min/max metadata on the current POS payload; the implementation therefore applies quantity counters per extra without attribute-level min/max blocking.
- Existing wizard/menu addon bridging remains source-compatible because bundled addon payloads are normalized lazily at cart/store and checkout boundaries.

## Blockers / escalations

- No blocking scope gap encountered.
- No `ESCALATION` added.
- No `SYMMETRY_NOTE` required because no backend service symmetry surface was touched.
