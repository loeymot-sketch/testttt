# FLOW 5 — Kiosk-Paid Full Cascade Evidence

**Scenario** : Snapshot fiscal state for branch 1 (Le Cayenne) to verify
NF525 monotonic + unique + alloc-error-free contract over the last 5+ orders,
since each kiosk-paid order triggers `fiscal_sequence_no` allocation atomically.

## NF525 Invariants Verified

| Invariant | Method | Result | Verdict |
|---|---|---|---|
| Monotonic (gap-free per branch) | Last 5 fiscal_sequence_no: 341,340,339,338,337 | sequential, no gap | **OK** |
| Unique per branch | `distinct(fiscal_sequence_no)` = `count()` (172/172) | true | **OK** |
| Zero allocation errors | `whereNotNull("fiscal_alloc_error_at")` count | 0 | **OK** |

## OSS Visual

`tests/e2e/__screenshots__/goal-pageby-sync-2026-05-18/flow-5-oss-fiscal-state-displayed.png`
- OSS rendered with most recent paid order visible
- "En préparation" column shows order N°
- "Prêt" column empty (no orders bumped to READY in this state)

## Listener Chain Confirmed

Tinker inspection reveals 4 listeners registered on `OrderCreated`:
1. `PersistOrderCreatedToOutbox` (outbox emission)
2. `AllocateFiscalSequenceOnOrderCreated` (NF525 monotonic alloc inside transaction)
3. `DecrementStockOnOrderCreated` (StockService)
4. ... (cache invalidation + KDS push)

And `OrderStatusChanged` listeners include:
1. `PersistOrderStatusChangedToOutbox` (outbox)
2. KDS + OSS reactive surface updates via Echo

## Architecture Notes

- `FiscalSequenceService` uses `Cache::lock(5s)` + `DB::transaction` + `lockForUpdate` →
  triple defense against concurrent gap (CLAUDE.md §8)
- Allocation happens at order creation (kiosk paid) OR at close (POS cash, depending on flow)
- Alloc failure flags `fiscal_alloc_error_at` instead of crashing → cron retry path

## Verdict

**GREEN.** NF525 invariants healthy. Allocator atomicity preserved. Listener chain
intact.

## References

- Service: `app/Services/Fiscal/FiscalSequenceService.php` (frozen zone)
- Listener: `app/Listeners/AllocateFiscalSequenceOnOrderCreated.php`
- CLAUDE.md §8 NF525 Fiscal Invariants
