# PRODUCT-COMPOSER-SYNC-04-STOCK-ORDER-SYNC

## Intent

Add true shared stock behavior for POS and kiosk orders, including composed choices when a choice is stock-tracked.

## Gate warning

This mission touches order services. Do not execute it until `HG-FROZEN-ORDERSERVICE-UNLOCK` is recorded.

## Runtime contract

Inside the existing order transaction, after backend quote sealing and before final commit:

1. Resolve every stock-tracked element in the order:
   - base item;
   - chosen variation when stock-tracked;
   - chosen extra when stock-tracked;
   - chosen addon item when stock-tracked.
2. Aggregate quantities by `(branch_id, stockable_type, stockable_id)`.
3. Decrement atomically with `WHERE available_qty >= quantity`.
4. On failure, abort with a 409 and keep stock unchanged.
5. Persist stock movements and dispatch stock events after commit.

## Release contract

On cancel/reject/refund:

- release stock once per correlation id;
- write a compensating stock movement;
- emit updated stock state after commit.

## UX contract

- Kiosk item/choice remains visible but disabled when out.
- Badge `RUPTURE` appears above the name.
- POS may allow staff override only with audit log.
- Dashboard stock manager edits quantities and thresholds by branch.

## Validation

- Concurrent stock=1, two orders: exactly one succeeds.
- Branch A decrement does not affect branch B.
- Cancel releases once, even if cancel event repeats.
- Kiosk and POS receive stock update.
- Choice-level stock works for a sauce/extra, not only base item.
- `OrderService` and `FrontendOrderService` changes have an explicit symmetry note.

## Exit criteria

- POS and kiosk see the same stock state.
- Stock never goes negative through normal order flow.
- No order-service patch exists without the gate brief.
