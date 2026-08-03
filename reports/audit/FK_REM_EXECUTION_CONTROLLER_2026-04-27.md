# FoodKing Remaining Demands - Execution Controller - 2026-04-27

Purpose: single control sheet for the user's remaining demands.  
Mode: orchestrate, implement train by train, audit each point, then run global validation and optional Claude challenge audit.

## Global Verdict Now

Current state: NOT COMPLETE.  
Main blocker before this pass: frozen staged `OrderService.php` and `FrontendOrderService.php`. This is now isolated by non-destructive unstage; remaining order/stock trains still need mission-by-mission gate discipline.

No demand is being ignored. The list below separates what is already done, what is ready to implement, and what is blocked by gate/staging.

## A. User Demands Register

| ID | Demand | Status | Next proof |
| --- | --- | --- | --- |
| D01 | Borne customer screen locked, no admin, no caisse route, no PIN | Implemented | Browser/E2E kiosk route check |
| D02 | Remove false "connexion perdue" on POS/kiosk | Implemented | POS/kiosk screenshot + Vitest |
| D03 | POS order without manual Client ID | Implemented | PHPUnit POS quote/store without customer |
| D04 | Takeaway/emporter direct | Implemented at controller/runtime level | POS E2E order |
| D05 | Delivery by address/distance | Partially implemented | Google Maps live key + address E2E |
| D06 | Delivery fee rule 0-5 km = 5 EUR, above by started km | Implemented | PHPUnit fee matrix |
| D07 | Dashboard product/category/price/offer management | Advanced frontend control plane on `/admin/items`; backend CRUD reused, deeper drawer/API audit still open | Train T2 + Claude review |
| D08 | Dashboard stock management | Not complete | Train T3 |
| D09 | POS/Kiosk same catalog and categories, different design | Partially complete | PH2-04 consumer migration |
| D10 | Stock synchronized between POS and kiosk | Not complete quantitatively | Stock V2 |
| D11 | Queue numbers no duplicates | Mostly implemented; frozen files now unstaged for mission review | D-M13 close |
| D12 | POS sees kiosk orders/live list | Not complete | Order live board |
| D13 | KDS lifecycle and OSS update | Partial existing | T4 + E2E |
| D14 | Payment simulation/manual terminal | Approved/partially existing | Payment E2E |
| D15 | Legacy Bangladesh/demo cleanup | Advanced non-destructive dry-run implemented | T5 cleanup |
| D16 | French-first runtime | Advanced: EUR seeder + runtime removed bn/de bundle | T5 i18n/UI audit |
| D17 | Global final audit + Claude challenge after Codex | Pending | T6 final report |

## B. Architecture Target

```text
Dashboard/POS Admin
  writes catalog, price source, availability, stock adjustments
        |
        v
Backend SSOT services
  PricingService
  MenuProjectionService
  Availability/Stock service
  Order/FrontendOrder services
  Queue allocator
  Outbox/domain events
        |
        v
Realtime channels by branch
  branch.catalog
  branch.stock
  branch.orders
  branch.kds
  branch.oss
        |
        v
Read surfaces
  POS
  Kiosk
  KDS
  OSS
```

Rule: POS and kiosk share data, not screens. Kiosk remains customer-only.

## C. Train Breakdown

### T0 - Governance unlock

Objective:

- Resolve frozen staging.
- Keep current user fixes.
- Make next edits auditable.

Tasks:

1. Review staged/unstaged frozen diffs. Status: done.
2. Select gate operation A/B/C in `docs/gates/GATE_FROZEN_ORDERSERVICE_UNLOCK_2026-04-27.md`. Status: Option B applied.
3. Re-run safety hook. Status: PASS.
4. If safety cannot pass, record explicit waiver and keep train scope restricted.

Exit:

- Frozen staging classified.
- `safety-check.sh` pass or documented waiver.

### T1 - Catalog projection consumer migration

Objective:

- Finish Train 2 PH2-04.
- Make POS/kiosk consume the same canonical menu projection where possible.

Tasks:

1. Inspect current `MenuProjectionService`, `KioskMenuService`, POS category/item controllers.
2. Create parity tests for kiosk/POS menu payload identity.
3. Add `X-Menu-Version`/version metadata if already supported by existing snapshot service.
4. Migrate kiosk read path behind wrapper, no UI design merge.
5. Migrate POS read path or document exact remaining gap.

Audit checks:

- Pricing fields are backend-provided.
- `branch_id` is explicit on every menu query.
- Kiosk design remains separate.

### T2 - Dashboard control plane V1

Objective:

- Real usable admin interface for category/product/price/image/offer/disponibilite.
- No quantitative stock yet unless T3 is ready.

Status update 2026-04-27:

- Added a control-plane band to `resources/js/components/admin/items/ItemListComponent.vue`.
- The band links operators to products, categories, offers, and branch availability from the same admin surface.
- Product table now shows image thumbnails and keeps the existing backend-provided price field.
- Availability remains wired through the existing `AvailabilityToggleComponent` and admin availability API.
- This is a frontend consolidation, not a full new CRUD backend. Stock V2 and live order board remain separate trains.

Backend tasks:

1. Inventory existing admin item/category routes/controllers.
2. Add missing endpoints only if absent.
3. Reuse `ItemService`, `ItemCategoryService`, variation/extra/addon services.
4. Emit existing catalog/menu invalidation events after mutations.
5. Add authz tests for manager/admin only.

Frontend tasks:

1. Product list with category filter and search.
2. Category manager: add/edit/sort/toggle visibility.
3. Product drawer: name, image, base price source, tax, variations, extras, addons, offer flag.
4. Availability toggle per branch.
5. Save states, validation errors, optimistic refresh only after backend success.

Audit checks:

- Dashboard never computes final price.
- Kiosk receives data changes through refresh/event path.
- No admin controls appear on kiosk.

### T3 - Stock V2

Objective:

- Real stock counters shared POS/kiosk.

Backend tasks:

1. Gate `HG-STOCK-V2-SOURCE-OF-TRUTH`.
2. Add `stock_levels` and `stock_movements` only after migration gate.
3. Implement branch-scoped atomic decrement.
4. Implement idempotent release on cancel/reject/refund.
5. Broadcast stock update after commit.
6. Add reconciliation command.

Frontend tasks:

1. Shared rupture badge.
2. Kiosk keeps item visible with red `RUPTURE`, tap disabled.
3. POS shows staff override modal and logs audit if override accepted.
4. Dashboard stock adjustment screen.

Audit checks:

- Stock=1 with two concurrent orders -> exactly one succeeds.
- Branch A stock does not affect Branch B.
- Event payload has version/correlation ID before realtime fanout is trusted.

### T4 - Order operations: queue, live board, handover, KDS/OSS

Objective:

- Complete order lifecycle across POS/Kiosk/KDS/OSS.

Tasks:

1. Centralize queue allocator or close current D-M13 implementation if equivalent.
2. Confirm DB unique `(branch_id, business_date, queue_number)`.
3. Add/review POS live endpoint for active orders from POS and kiosk.
4. Add live board UI columns: New, Accepted, Preparing, Ready, Handed over.
5. Add explicit handover/remise endpoint after `READY`.
6. Confirm KDS bump emits status changes and OSS updates.
7. Validate simulated/manual card payment path.

Audit checks:

- No duplicate queue numbers in branch+business day.
- POS live board includes kiosk orders.
- KDS cannot illegal-transition status.
- Handover does not violate NF525/fiscal sequence rules.

### T5 - Legacy cleanup and French-first runtime

Objective:

- Remove visible old Bangladesh/demo residue without destructive data loss.

Tasks:

1. Audit seeders/factories for Bangladesh-specific defaults: `BDT`, `Dhaka`, `+880`, Bangla language defaults, fake branches. Status: partial implemented through `foodking:cleanup-demo-data --dry-run --json`.
2. Replace V1 default seed data with FoodKing French defaults. Status: partial implemented for currency (`EUR` primary, no BDT demo currency).
3. Keep non-French language files if they are part of supported i18n; do not delete blindly. Status: runtime bundle now loads `fr`, `en`, `ar`; `bn/de` files remain on disk for later gated cleanup.
4. Hide/deactivate demo branches rather than purge historical data. Status: previously implemented active-only branch selector; DB cleanup still gated.
5. Disable missing/legacy gateway routes such as non-France Senangpay if confirmed unused. Status: pending.

Audit checks:

- Fresh seed creates French FoodKing data.
- Existing history is not deleted.
- UI branch selector does not show inactive/demo branches.

### T6 - Global validation and release report

Objective:

- Prove the system end to end.

Validation matrix:

1. `php artisan test`
2. `npx vitest run`
3. `npm run production`
4. Browser kiosk idle/payment/order flow.
5. Browser POS cash/takeaway flow.
6. Browser POS delivery flow with distance.
7. Browser dashboard product/category mutation.
8. Browser stock out-of-stock visual contract.
9. Browser KDS bump -> OSS update.
10. Queue concurrency test.
11. Payment simulation test.
12. Final audit report.
13. Claude terminal challenge audit, if human requests after Codex pass.

Exit:

- `VERDICT: PASS` only if all critical flows pass.
- `VERDICT: REWORK` if any P0 flow fails.
- `VERDICT: ESCALATE` if gate/architecture conflict remains.

## D. Implementation Rules For All Trains

1. No mega patch.
2. One train at a time.
3. No destructive cleanup without backup/gate.
4. No hidden admin path on kiosk.
5. No frontend final pricing logic.
6. No cross-branch query without explicit branch guard.
7. Order/FrontendOrder symmetry note required for order changes.
8. Every train gets a small audit report.

## E. Current Next Step

Next required operational step: resolve T0.

Recommended action:

```text
Option B from GATE_FROZEN_ORDERSERVICE_UNLOCK_2026-04-27:
non-destructively unstage frozen files, keep working-tree content, then restage mission by mission.
```

Codex will not run destructive cleanup or broad product edits before this is resolved.
