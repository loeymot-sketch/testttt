# Menu availability (86 / branch stock)

## Overview

Per-branch availability lives in `item_branch_availability` (`App\Models\ItemBranchAvailability`).  
If no row exists for `(item_id, branch_id)`, the item is treated as **available** with **unlimited** daily quantity.

## Server behaviour

- `App\Services\Menu\AvailabilityService::decrementForOrder()` runs on `OrderCreated` via `DecrementItemAvailabilityOnOrder`.
- When `max_daily_qty` is set and the daily counter reaches the cap, `is_available` flips to `false` with `unavailable_reason = out_of_stock`.

## Events

Availability broadcasts continue to use the existing outbox + `ItemAvailabilityChanged` contract (`docs/EVENT_CONTRACT.md`).
