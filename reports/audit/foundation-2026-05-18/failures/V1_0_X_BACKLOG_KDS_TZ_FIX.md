# V1.0.X Backlog Item — KDS TZ-aware test fixture alignment

**Source** : Foundation Audit cluster investigation 2026-05-18 (KDS_CLUSTER_10_FAILURES.md)
**Priority** : P1 V1.0.X (10 sentinel tests red, blocks broad smoke CI)
**Owner-route** : session-A (parallel session has been driving Wave 3b/3c TZ-aware heals)
**Effort estimate** : ≤30 min

## Single root cause for 10 failing tests

Commit `c2613cab0` (Wave 3b P0 KDS+OSS TZ-aware boundaries, 2026-05-18 10:27 +0200) converted query bounds from `Carbon::today()` (Paris-local) to `Carbon::today($appTz)->setTimezone('UTC')` in 4 sites of `app/Services/KitchenDisplaySystemOrderService.php` (2 in `list()`, 2 in `orderItems()`).

The fix is **correct for production MySQL** (TIMESTAMP columns store UTC, query bounds must be UTC). But breaks the test suite because:

1. Test fixtures use `'order_datetime' => now()` which writes a Paris-local naive string to SQLite
2. Post-fix query binds UTC bounds (`'2026-05-18 21:59:59'`) against that Paris-local string (`'2026-05-18 22:20:33'`)
3. SQLite string-compares lexicographically → row excluded

**Time-of-day dependent**: tests pass during Paris hours [02:00, 21:59:59] CEST but fail in the 22:00-23:59:59 evening window. The investigation occurred at 22:20 CEST → inside the failure window → all 10 fail.

## 10 failing tests covered

| Test class | Cases |
|---|---|
| `Tests\Feature\KDS\KDSDeliveryEnrichmentTest` | 3 (delivery payload, dine-in payload, eager-loaded relations) |
| `Tests\Feature\KDS\KdsAllergenAggregationSplitTest` | 5 (same id different/same allergens merge logic) |
| `Tests\Feature\OrderPipeline\KioskFullFlowE2ETest` | 1 (kiosk order full flow to KDS) |
| `Tests\Feature\Orders\KDSAllergenVisibilityTest` | 1 (kds endpoint exposes per-item allergens snapshot) |

## Recommended fix (≤5 LOC, no production code change)

**Option A — global TestCase clock pin (recommended)** :

```php
// tests/TestCase.php — inside setUp() after parent::setUp()
\Carbon\Carbon::setTestNow(\Carbon\Carbon::create(2026, 5, 18, 12, 0, 0, 'UTC'));
```

**Option B — per-class clock pin (more surgical)** :

Add the same line to setUp() of each of the 4 failing test classes (4 LOC total).

Both options:
- Pin Carbon's "now" to a fixed UTC time inside the Paris business day
- Test fixtures `now()` writes pinned Paris-local time
- Query bounds resolve to pinned UTC bounds
- String comparison passes → rows included → 10 tests green

## What NOT to do

❌ **DO NOT revert `c2613cab0`**. The fix is real:
- Pre-fix : `Carbon::today()` returned Paris-local midnight. Production MySQL `order_datetime` is TIMESTAMP (UTC-stored). Query `WHERE created_at >= '2026-05-19 00:00:00' AND created_at < '2026-05-20 00:00:00'` (Paris-local bounds) would miss orders created 00:00-02:00 Paris (22:00-24:00 UTC the previous day).
- Reverting re-introduces a P0 in production (nightly orders dropped from KDS UI).

❌ **DO NOT add a new sentinel test that only runs during Paris business hours**. The current pattern is fragile but production-correct.

## Sentinel test addition (V1.0.X complementary)

Add a 5th case to `SisterServicesTzAwareTest` (or create new `KdsTzAwareRowVsBoundInclusionTest`) :

```php
public function test_row_at_paris_evening_included_in_query_bounds(): void
{
    // Simulate Paris 22:20 to catch the row-vs-bound inclusion bug
    Carbon::setTestNow(Carbon::create(2026, 5, 18, 20, 20, 0, 'UTC')); // = 22:20 Paris CEST
    
    Order::factory()->create(['order_datetime' => now()]);
    
    $items = app(KitchenDisplaySystemOrderService::class)->list(...);
    
    $this->assertNotEmpty($items->data, 'Row created at Paris-evening must be picked up by query bounds');
}
```

This locks the contract so any future fixture/bound regression fires immediately.

## Parallel sister cluster

The same 4-site TZ pattern was applied to `OrderStatusScreenOrderService.php` in `c2613cab0`. **Session-A should grep OSS tests for the same symptom**:

```bash
php artisan test --filter "OssOrderListTest|OrderStatusScreenTest" 2>&1
```

If similar failures appear in the evening window, the same Carbon::setTestNow fix applies.

## V1.0.X scheduling recommendation

- **Sprint** : V1.0.X immediate (next session-A pickup)
- **Risk** : low (test-only change, no production behavior change)
- **Dependencies** : none
- **Verification** : `php artisan test --filter "KDSDeliveryEnrichment|KdsAllergenAggregationSplit|KioskFullFlowE2E|KDSAllergenVisibility"` → 10/10 PASS

---

**Decision deferred to session-A per owner mandate 2026-05-18** ("Flag session-A + document V1.0.X backlog (Recommended)").
