# RED-Z1 — Stock Cascade Sync Audit
**Date**: 2026-05-19 · **Mode**: read-only adversarial · **Agent**: RED-Z1
**Branch**: v1-0-1-hardening-2026-05-17 · **HEAD**: post-1e7c65ecc

---

## A. Anchors verified (file:line, Read this session)

- `app/Services/Menu/AvailabilityService.php:39-85` — `toggle()` row-lock + after-commit dispatch (manual 86 SSOT).
- `app/Services/Menu/AvailabilityService.php:215-274` — `assertItemsOrderableForBranch()` revalidation gate w/ optional `lockForUpdate`.
- `app/Services/Menu/AvailabilityService.php:285-342` — `decrementForOrder()` atomic capped UPDATE + CAS auto-86 flip.
- `app/Services/Menu/AvailabilityService.php:344-354` — `dispatchEvent()` `DB::afterCommit` boundary.
- `app/Services/Menu/AvailabilityService.php:484-531` — `toggleStockable()` (extra/variation) row-lock + idempotency.
- `app/Services/Menu/AvailabilityService.php:611-734` — `releaseForOrderItems()` cancel/refund compensator w/ `released_qty` ledger.
- `app/Services/Stock/StockService.php:27-30` — `decrementForOrder()` entry; calls `mutateForOrder('order_created', -1)`.
- `app/Services/Stock/StockService.php:86-138` — per-line `lockForUpdate` + idempotency_key check + `StockUnavailableException` raise (line 112).
- `app/Services/Stock/StockService.php:149-215` — `syncItemAvailabilityForStockLevel()` on_hand→is_available bridge.
- `app/Services/Stock/StockService.php:243-295` — `requirementsForOrderItem()` decomposes item + variations + extras + composition_snapshot addons.
- `app/Services/Stock/ChoiceAvailabilityResolver.php:124-201` — `assertSelectionsOrderable()` per-choice revalidation gate (variations / extras / addon items).
- `app/Services/Stock/ChoiceAvailabilityResolver.php:209-265` — `stockLevelsForBranch()` + `itemBranchAvailability()` lockable readers.
- `app/Services/Pricing/PricingService.php:46-55` — first availability gate at pricing entry (preview = read-only; real = locked).
- `app/Services/Pricing/PricingService.php:100-107` — second gate covering addon items inside composition.
- `app/Services/Pricing/PricingService.php:545-554` — `ChoiceAvailabilityResolver::assertSelectionsOrderable()` invocation.
- `app/Services/OrderService.php:394-398, 724, 907, 1186` — `AvailabilityService::assertItemsOrderableForBranch()` callsites inside parent `DB::transaction` (locked).
- `app/Services/OrderService.php:625, 907` — `DB::transaction` opens at 625; `StockService::decrementForOrder()` called inside at 907.
- `app/Services/FrontendOrderService.php:338, 511` — kiosk path mirrors POS: revalidation gate + synchronous decrement inside parent tx.
- `app/Http/Controllers/Admin/AvailabilityController.php:45-98` — admin 86 toggle endpoint w/ branch scope authz + `DB::transaction`.
- `app/Models/StockLevel.php:14-26` — `BranchScope` global scope; `saving` guard rejects negative on_hand/reserved & reserved>on_hand.
- `app/Models/ItemBranchAvailability.php:39-42` — `BranchScope` global scope.
- `app/Models/StockMovement.php:14-26, 46-50` — `BranchScope` + `updating` throws `LogicException` (append-only).
- `database/migrations/2026_04_27_143120_create_stock_levels_table.php:22-29` — `UNIQUE(branch_id, stockable_type, stockable_id)` + CHECK constraints on_hand≥0, reserved≥0, reserved≤on_hand.
- `database/migrations/2026_04_27_143130_create_stock_movements_table.php:19` — `UNIQUE` on `idempotency_key`.
- `database/migrations/2026_04_15_230100_create_item_branch_availability_table.php:24-26` — `UNIQUE(item_id, branch_id)`.
- `app/Listeners/PersistItemAvailabilityChangedToOutbox.php:35-91` — outbox row firstOrCreate (idempotency keyed on correlation_id), commit-before-dispatch via `DB::afterCommit`.
- `app/Listeners/DecrementStockOnOrderCreated.php:32-62` — try/Throwable isolation (WG-2 policy); failure → log + `StockDecrementFailedEvent` (no compensation).
- `app/Listeners/DecrementItemAvailabilityOnOrder.php:33-67` — mirror isolation; both registered in EventServiceProvider.php:146-152.
- `app/Providers/EventServiceProvider.php:146-152, 181-189` — listener order: `PersistOrderCreatedToOutbox` runs FIRST; availability/stock decrements second; FCM last.
- `app/Console/Commands/StockScanRupture.php:56-61, 130` — preventive auto-86 cron gated on `catalog_v15.auto_86_preventive_cron.enabled` (default `false` per `config/catalog_v15.php`).
- `app/Console/Kernel.php:130-135` — schedule registration; `->when(fn () => (bool) config('catalog_v15.auto_86_preventive_cron.enabled', false))` predicate.
- `app/Console/Kernel.php:156-160` — `foodking:availability:reset-stale-quota` daily at 00:05 (unconditional).
- `resources/js/services/eventContract.js:330-380` — `onEvents(branchId, bindings)` Echo private-branch subscription + correlation-id de-dup.
- `routes/channels.php:41-62` — `branch.{branchId}` Pusher auth: kiosk-token name-check, admin/tenant-admin role, staff own-branch.
- `resources/js/components/frontend/kiosk/KioskAppComponent.vue:546-580, 662-743` — kiosk listener: subscribes `ItemAvailabilityChanged` + `CatalogChanged` + `ComposerProfileChanged` + `CouponChanged`; `_handleItemAvailabilityChanged` dispatches `kioskMenu/UPDATE_ITEM` + `kioskCart/pruneUnavailableLines` + toast.
- `resources/js/store/modules/kioskMenu.js:207-250` — `UPDATE_ITEM` mutation: non-destructive merge of `is_available` + `unavailable_reason`.
- `resources/js/store/modules/kioskCart.js:664-680` — `pruneUnavailableLines` removes lines whose menu row is now unavailable.
- `resources/js/components/admin/pos/PosComponent.vue:2329-2390` — POS surface: live `ItemAvailabilityChanged` subscription; cart pruning; type='full' triggers `itemList(1)` refetch.
- `resources/js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue:1797-1801, 2140-2206` — KDS: `kdsInflight/markDeavailable` + visible red badge + aria-live + `alertService.warning` toast; restricted to `ACCEPT|PREPARING` cards.

---

## B. Findings — sorted P0 → P3

### P1-1  Preventive `stock:scan-rupture` cron defaults OFF
- **File**: `config/catalog_v15.php:1-30` + `app/Console/Commands/StockScanRupture.php:58-61` + `app/Console/Kernel.php:135`.
- **Code**:
  ```
  // Kernel.php
  $schedule->command('stock:scan-rupture')
    ->cron(config('catalog_v15.auto_86_preventive_cron.cron_expression', '*/5 * * * *'))
    ->withoutOverlapping()->onOneServer()
    ->when(fn () => (bool) config('catalog_v15.auto_86_preventive_cron.enabled', false));
  // StockScanRupture.php
  if (config('catalog_v15.auto_86_preventive_cron.enabled', false) !== true) {
      $this->info('[stock:scan-rupture] Skipped: ... enabled=false');
      return self::SUCCESS;
  }
  ```
- **Reproduction**: Last unit of an item is consumed by a refund-reversed-decrement (`releaseForOrder` adds +1 back to on_hand). Then admin manually edits `stock_levels.on_hand=0` via SQL or a future ingredient-recipe drop. Between orders the item stays `is_available=true` on the menu until the NEXT order arrives — at which point `StockService::syncItemAvailabilityForStockLevel` would flip it. But if no order arrives for hours/days, the kiosk shows it as available and a checkout will receive a 409 from the inside-tx `StockUnavailableException`.
- **Sync risk**: documented as "REACTIVE only" in `StockScanRupture.php:24-31`. Preventive auto-86 is the ONLY guard against this gap, and it's disabled. .env defaults inherit `false` because the env var `FK_CATALOG_..._ENABLED` is absent from `.env.example` (verify on owner machine).
- **Scope-minimal fix proposal** (NOT implemented): flip default to `true` for V1 single-resto (5-minute cadence is cheap; `withoutOverlapping`+`onOneServer` already in place). Alternatively, document explicitly in `.env.example` with a recommended value.

### P2-1  `StockDecrementFailedEvent` has no listener — drift is log-only
- **File**: `app/Events/StockDecrementFailedEvent.php:25` + `app/Providers/EventServiceProvider.php` (no entry).
- **Code**:
  ```
  // DecrementStockOnOrderCreated.php:34-44
  } catch (Throwable $e) {
      Log::error('DecrementStockOnOrderCreated isolated', [...]);
      event(new StockDecrementFailedEvent(...));   // <- no listener wired
  }
  ```
- **Reproduction**: Listener path (post-commit) on `OrderCreated` raises a `Throwable` other than `StockUnavailableException` (e.g. transient DB blip, FK race). The order persisted (commit done). The synchronous decrement in OrderService:907 / FrontendOrderService:511 ALREADY succeeded inside the parent tx — so the listener was a defense-in-depth no-op and idempotency made it idempotent — BUT if for any reason the synchronous call was bypassed (config flag flip, future refactor, V1.0.2 cleanup), the listener becomes the only decrement path. Drift now is log-only; no alert, no retry, no reconciliation cron.
- **Sync risk**: low for V1 local single-resto (synchronous decrement path is the active SSOT), high if anyone ever flips `app/Listeners/DecrementStockOnOrderCreated.php` to be the primary path. Observability hole.
- **Scope-minimal fix proposal** (NOT implemented): wire a `LogStockDecrementFailedEvent` listener that pushes to a `stock_decrement_failures` table (or reuse existing `domain_events` outbox) so ops can scan + retry.

### P2-2  Global `ItemAvailabilityChanged` fans out N channels per active branch — N×kiosk refetch storm risk on multi-branch deploy
- **File**: `app/Listeners/PersistItemAvailabilityChangedToOutbox.php:36-49`.
- **Code**:
  ```
  if ($event->branchId !== null) {
      $channels = ['private-branch.' . $event->branchId];
  } else {
      $channels = Branch::query()
          ->whereIn('status', [Status::ACTIVE, 1])
          ->pluck('id')
          ->map(fn (int $branchId): string => 'private-branch.' . $branchId)
          ->all();
  }
  ```
- **Reproduction**: Admin edits a global item price → fan-out to every active branch. Each kiosk's `KioskAppComponent.vue:686-697` sees `hasAvailabilitySignal=false` AND, if `payload.type === 'full'`, triggers `kioskMenu/fetchMenu({force:true})`. For V1 single-resto Le Cayenne (one branch), no impact. For V2 SaaS with N branches the same admin click triggers N kiosk full-menu refetches.
- **Sync risk**: not a V1 LOCAL Le Cayenne blocker. V2 SaaS scalability hazard. Flagging because Z1 audit should surface it.
- **Scope-minimal fix proposal** (NOT implemented): debounce/coalesce `type==='full'` refetches at the kiosk side (e.g. 5-second window); or fan-out by `private-catalog` channel instead of per-branch for global edits.

### P3-1  Race window: two admins click the manual 86 toggle simultaneously on a missing row → UNIQUE constraint 23000 → 500 to losing admin
- **File**: `app/Services/Menu/AvailabilityService.php:46-69`.
- **Code**:
  ```
  $row = ItemBranchAvailability::query()
      ->where('item_id', $itemId)->where('branch_id', $branchId)
      ->lockForUpdate()->first();
  if (! $row) {
      $row = new ItemBranchAvailability([...]);
      $row->save();   // race: 2nd admin also got null → second save() = QueryException
      ...
  ```
- **Reproduction**: A and B both click "86" on an item that has no row yet. Both enter the transaction, both `lockForUpdate->first()` returns null (no row to lock), both try `INSERT`. `unique(['item_id','branch_id'])` on `item_branch_availability` rejects the second. Loser gets a 500 stack trace (no try/QueryException catch).
- **Sync risk**: UX micro-defect. Final state is consistent (whichever INSERT wins owns the toggle). Recovery = admin refreshes + clicks again.
- **Scope-minimal fix proposal** (NOT implemented): replace `where()->first()` + conditional `new + save()` with `ItemBranchAvailability::updateOrCreate([item_id, branch_id], [...])` which Eloquent serializes via the unique key, OR catch `QueryException(23000)` and re-`->first()`.

---

## C. Hard questions for owner

1. **CRON DEFAULT**: `catalog_v15.auto_86_preventive_cron.enabled=false` by default — was this intentional for Le Cayenne LOCAL, or was V1.0.1 hardening supposed to flip it? Without it, the only auto-86 trigger is the next order. If the kitchen sells the last unit and then has a 2-hour gap, kiosk will keep showing it as available until the next order's commit raises a 409.
2. **DOCUMENT `FK_CATALOG_AUTO_86_PREVENTIVE_CRON_ENABLED`** in `.env.example`? If not, ops have no signal.
3. **DUAL DECREMENT PATHS**: Synchronous (`OrderService.php:907` inside parent tx) + async listener (`DecrementStockOnOrderCreated` post-commit). The listener is idempotency-keyed via `stock_movements.idempotency_key`, so duplicates collapse. Is the listener still doing useful work, or is it dead weight to remove?
4. **`StockDecrementFailedEvent` dead-letter handling**: 0 listeners. If the post-commit listener ever fails, drift is log-only. Should we wire a dead-letter table?
5. **CHANNEL `private-branch.{branchId}`** for global catalog edits: fan-out to every active branch. V2 SaaS hazard. Plan to coalesce?
6. **KDS warning scope**: `KitchenDisplaySystemComponent.vue:2199-2206` excludes `PREPARED` cards from the OOS warning. A ticket in READY state that goes 86 mid-pickup gets no signal. Is the assumption "PREPARED = food in hand, no risk" durable for menus where prep ≠ pickup (drive-thru)?
7. **GRANULAR STOCK ON COMPOSITION**: `StockService::requirementsForOrderItem` decomposes `composition_snapshot.addons` into per-addon-item stock requirements (line 280-292). What about `composition_snapshot.steps.*.selections` — supplements that are NOT addons (extras with stock_levels rows)? Verified `extras` array IS decomposed (line 267-278) — confirmed. But the `composition_snapshot` for kiosk wizard MAY contain step-selections that are not mirrored in `item_extras` payload. Audit Z2 (order-lifecycle) overlap — flag.
8. **TEST FIXTURES**: do fixtures reset `stock_levels.on_hand` between tests? `tests/Feature/CleanupTestFixturesCommand.php` was found; haven't read. Stock decrement is the kind of side-effect that, if not cleaned, makes test 22 cascade-fail with `StockUnavailableException`.
9. **`releaseForOrderItems`** (`AvailabilityService.php:611-734`): uses `released_qty` ledger on `order_items`. What ensures `order_items.released_qty` exists? grep returned a migration?
10. **CRON `availability:reset-stale-quota`** runs at 00:05 every day. What timezone? Verified `Carbon::today(config('app.timezone'))` is used in `AvailabilityService.php:64,115,291` — Paris-local. Server clock vs `app.timezone` drift → quota reset could miss midnight.
11. **CONCURRENT ORDERS LAST UNIT**: customer A and B both checkout last unit. `StockService::decrementForOrder` runs inside the parent tx with `lockForUpdate` on `stock_levels` (line 91). First to lock wins, on_hand→0, COMMIT. Second waits, then sees on_hand=0, raises `StockUnavailableException(409)` → tx rolls back, B's order never persists. Confirm with owner: is the desired UX a 409 to B (clean), or "B's order is partially saved but missing items"? Code answer: clean 409.
12. **MID-WIZARD 86**: customer is mid-wizard at the kiosk when item is 86. `KioskAppComponent.vue:560-565` subscribes to `ComposerProfileChanged`, but the item itself goes through `ItemAvailabilityChanged`. The wizard SPA is in `KioskWizardComponent.vue` (frozen-zone per CLAUDE.md §7). Question: does the wizard re-check at confirm-cart? Final answer from grep: `PricingService::calculateOrder` is the only gate — if the wizard doesn't call `/api/pricing/preview` between item-add and order-create, the 86 only surfaces at the order POST. That's UX-cruel: 5 minutes of selection then a 409 wall.
13. **`StockLevel::saving` guard** (line 67-73): rejects `reserved > on_hand` and negative values. Does `releaseForOrder` (StockService.php:418) bypass with `forceFill`? Yes — `forceFill(['on_hand' => ...])->save()` STILL goes through Eloquent `saving` event (forceFill only bypasses `$guarded`). So the guard catches negative.
14. **WEBSOCKET FAILURE FALLBACK**: kiosk uses `Echo.private('branch.X')`. If Pusher/Soketi is down, frontend has no live 86 signal. Falls back to TTL menu refetch (5 min per `kioskMenu.js` cache). Worst-case desync = 5 minutes between admin 86 and kiosk visibility. Confirmed in `PersistItemAvailabilityChangedToOutbox.php:96-117`: catches `Throwable` on `DispatchDomainEventsJob::dispatch`, logs, does NOT bubble. So Pusher down → kiosk waits TTL. Outbox-retry cron is the catch-up — verify it covers `ItemAvailabilityChanged`.
15. **POS `_onItemAvailabilityChanged`** (line 2364): logic mirrors kiosk, BUT POS also runs cart-pruning. Is the POS cart locked while the cashier is keying? If the cashier has the item in cart and the kitchen 86's it server-side, the line is REMOVED from cart — does the cashier see a toast?
16. **CART RE-VALIDATION AT CHECKOUT**: `PricingService::calculateOrder` at OrderService:669 runs INSIDE the tx with `useRowLock=true`. `ItemBranchAvailability::lockForUpdate` serializes against concurrent toggles. Confirmed: revalidation IS performed; client cannot bypass.
17. **BIDIRECTIONAL UN-86**: `AvailabilityService::toggle($itemId, $branchId, true, null)` clears `unavailable_reason` and `unavailable_since`, dispatches event. Kiosk receives `is_available: true`, `UPDATE_ITEM` mutation patches in-place (kioskMenu.js:245-247 clears the previous reason). Confirmed: instant un-hide.
18. **CRON STATUS-1 LEGACY**: `StockScanRupture::targetBranches` uses `whereIn('status', [Status::ACTIVE, 1])` (line 177) — handles the dual-state branches.status. Confirmed.
19. **`ItemExtra::is_available` priority over stock_level**: `ChoiceAvailabilityResolver.php:294-303` — ingredient unavailable trumps on_hand. Owner aware? Doc'd.
20. **WAVE 3C `daily_reset_at`**: `Carbon::today(config('app.timezone'))` (line 64,115,291). What if `app.timezone` is mutated mid-process? Caching `$today` at line 291 within the loop = OK; toggle() reads it once per transaction = OK.

---

## D. Sync invariants verified GREEN

- **Branch isolation on stock_levels**: `StockLevel.php:25` `addGlobalScope(new BranchScope())` + admin (branch_id=0) bypass.
- **Branch isolation on stock_movements**: `StockMovement.php:14-22` BranchScope present; `updating` guard at line 46-50 enforces append-only.
- **Branch isolation on item_branch_availability**: `ItemBranchAvailability.php:39-42` BranchScope.
- **Composite UNIQUE constraints**: `stock_levels.(branch_id, stockable_type, stockable_id)`, `item_branch_availability.(item_id, branch_id)`, `stock_movements.idempotency_key`. Schema-enforced.
- **Triple-defense on decrement**: parent `DB::transaction` (OrderService:625) → `lockForUpdate` on `stock_levels` row (StockService:91) → idempotency_key check on `stock_movements` (StockService:102). Sufficient against duplicate fires + double POST.
- **Checkout-time revalidation**: `assertItemsOrderableForBranch` called from PricingService:50, OrderService:394/724/907/1186, FrontendOrderService:338 — inside parent tx with `useRowLock=true`.
- **Commit-before-broadcast**: `ItemAvailabilityChanged` uses `DispatchableAfterCommit`; outbox row firstOrCreate before `DB::afterCommit` dispatch (PersistItemAvailabilityChangedToOutbox.php:71-119).
- **Idempotent compensating release**: `releaseForOrderItems` (AvailabilityService.php:611-734) tracks `released_qty` ledger per order_item → duplicate cancel-then-refund is a safe no-op once `released_qty == quantity`.
- **Channel auth**: `routes/channels.php:41-62` — kiosk-token NAME (not tokenCan wildcard), admin role gating, staff own-branch only. Closes Guest-Echo-Bypass.
- **Append-only stock_movements**: `StockMovement::updating` LogicException (line 46-50) + migration trigger `2026_05_18_140000_add_stock_movements_immutability_triggers.php`.

---

## E. Out-of-scope or unverifiable locally

- Outbox-retry cron coverage for `ItemAvailabilityChanged` (Z3 sync-reliability owner).
- BranchScope correctness deep-dive (Z6 branchscope owner).
- Idempotency middleware on the admin 86 toggle endpoint (Z7 idempotency owner).
- KDS recall path on stock-out mid-prep — only visual badge verified; no enforced order cancellation (intentional design but worth Z2 confirmation).
- Test fixture cleanup state (didn't read `tests/Feature/Stock/*` files).
- Production env values of `FK_CATALOG_AUTO_86_PREVENTIVE_CRON_ENABLED`.

---

## F. RED verdict

- **Zone sync quality**: **8/10**. Triple-defense decrement, multi-layer revalidation, BranchScope+UNIQUE on all three stock tables, after-commit broadcast, ledger-based compensating release, channel auth hardened, append-only audit trail. This is the most disciplined zone of the Wave I-J convergence.
- **Top 3 risks**:
  1. **Preventive auto-86 cron disabled by default** — gap between "last unit consumed" and "next order arrival" is desync window. Single-resto Le Cayenne low risk (high traffic); V1.0.2 SaaS multi-branch high risk.
  2. **`StockDecrementFailedEvent` is fire-and-forget** — no listener, no dead-letter, no alert. Future refactor could promote the listener path from defense-in-depth to primary; observability gap would be load-bearing then.
  3. **Mid-wizard 86 UX**: kiosk doesn't pre-validate the cart before checkout; 86 surfaces as a final 409. Acceptable for V1 local; document for V1.0.2 UX hardening.
- **Shippable V1 LOCAL Le Cayenne?**: **yes-with-caveats**. The caveats are operational (enable the preventive cron) + documentation (env example). The code paths are sound.
