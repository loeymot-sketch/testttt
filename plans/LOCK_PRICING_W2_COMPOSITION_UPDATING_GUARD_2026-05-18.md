# LOCK plan — composition_snapshot model-level UPDATE guard (W2)

**ID** : LOCK_PRICING_W2_COMPOSITION_UPDATING_GUARD_2026-05-18
**Date** : 2026-05-18
**Status** : OWNER GATE PENDING (G4 per `plans/GOAL_V1_PRODUCTION_PERFECT_PHASE2_2026-05-18.md` §G)
**Author** : Claude orchestrator (T-5.1.2)
**Branche** : `heal/cms-pr1-quickwins-2026-05-18`

---

## §1 — Why this LOCK exists

NF525 invariant : `order_items.composition_snapshot` is **frozen at order creation**, never UPDATEd. Today this invariant is enforced by :
- Discipline at write sites (5 documented INSERT-only call sites; PricingService is the SSOT)
- Critical-Focus Wave verification (5 INSERT / 0 UPDATE in zone5-pricing-ssot E2E)

But there is **no model-level guard**. A future careless `OrderItem::update(['composition_snapshot' => ...])` would silently bypass NF525 and contaminate the audit trail.

## §2 — Proposed model-level guard

Add an `updating` event listener in `App\Models\OrderItem::boot()` :

```php
static::updating(function (OrderItem $item) {
    if ($item->isDirty('composition_snapshot')) {
        throw new \RuntimeException(
            'NF525 invariant violation: composition_snapshot is frozen at INSERT '
            .'and MUST NOT be UPDATEd (order_item id='.($item->id ?? 'new').'). '
            .'If this fires legitimately, the call site must be re-engineered '
            .'to INSERT a new order_item row instead of mutating the existing one. '
            .'See plans/LOCK_PRICING_W2_COMPOSITION_UPDATING_GUARD_2026-05-18.md'
        );
    }
});
```

The guard fires at `model.updating` (boot listener, line ~28 of `app/Models/OrderItem.php`), BEFORE the SQL UPDATE statement is executed.

## §3 — Frozen-zone touch

`app/Models/OrderItem.php` is NOT in the canonical 13-file frozen list (`memory/reference_frozen_zones.md`). It IS NF525-adjacent (carries `composition_snapshot`). This LOCK exists to make the implicit invariant explicit and machine-enforced.

NO touch to :
- `app/Services/Pricing/PricingService.php` (canonical frozen)
- `app/Services/Fiscal/*Service.php` (canonical frozen)
- `app/Models/Scopes/BranchScope.php` (canonical frozen)

## §4 — Test plan (TDD-first if owner approves)

NEW `tests/Feature/Pricing/CompositionSnapshotUpdatingGuardTest.php` :

```php
public function test_updating_composition_snapshot_throws(): void
{
    $item = OrderItem::factory()->create([
        'composition_snapshot' => ['ingredients' => ['sauce_andalouse']],
    ]);

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessageMatches('/NF525 invariant violation/');

    $item->update(['composition_snapshot' => ['ingredients' => ['sauce_diabolo']]]);
}

public function test_updating_other_fields_still_allowed(): void
{
    $item = OrderItem::factory()->create([
        'composition_snapshot' => ['ingredients' => ['x']],
        'total_price' => 10.00,
    ]);

    // Re-pricing legitimate use case (discount recalc, tax_rate fix).
    $item->update(['total_price' => 9.50]);

    $this->assertSame('9.500000', $item->fresh()->total_price);
}

public function test_inserting_composition_snapshot_at_creation_allowed(): void
{
    $item = OrderItem::factory()->create([
        'composition_snapshot' => ['ingredients' => ['mayo']],
    ]);

    $this->assertSame(['ingredients' => ['mayo']], $item->fresh()->composition_snapshot);
}
```

## §5 — Risk assessment

### What could break ?
- Any legitimate code path that re-syncs `composition_snapshot` after order creation. To my knowledge none exists in the codebase (zone5 audit attested 5 INSERT / 0 UPDATE). Worth a final grep before landing.
- Test fixtures that DO update composition_snapshot for setup convenience. These should be re-written to insert with the snapshot they want, or use `OrderItem::withoutEvents(...)` for explicit test-only bypass.

### Pre-landing audit grep
```
grep -rn "composition_snapshot" app/ | grep -vE "->fillable|=>" | head -20
grep -rn "composition_snapshot.*->update\|update.*composition_snapshot" app/ tests/
```

## §6 — Rollback

Revert single commit. Model reverts to current behavior. No state migration. Tests that rely on the guard will fail loudly, easy to identify.

## §7 — Safety-check.sh override config

```yaml
LOCK_FILE: app/Models/OrderItem.php
LOCK_LINES: 15-30 (boot method extension)
LOCK_RATIONALE: NF525 composition_snapshot frozen-at-insert invariant
OWNER_GATE: REQUIRED
NF525_IMPACT: STRENGTHENS invariant (no chain change, no audit_logs touch)
ROLLBACK_COMPLEXITY: trivial (single commit revert)
```

## §8 — Sub-agent instructions (if approved)

```
Goal: add composition_snapshot updating guard at OrderItem model level.
Step 1: TDD-first — write NEW test file CompositionSnapshotUpdatingGuardTest.php
        with 3 cases (per §4). Expect 2 RED, 1 GREEN.
Step 2: Implement guard in OrderItem::boot() per §2.
Step 3: All 3 tests must turn GREEN.
Step 4: Run broad regression: php artisan test --filter=Order|Pricing|Pos|Kiosk
        Expect zero new failures. If a fixture-test breaks, document the
        offending test and propose fix in the same PR.
Step 5: Stage + commit referencing this LOCK + close G4.
```

## §9 — Decision matrix

| Option | LOC | Test cost | Risk | Owner gate | Verdict |
|---|---|---|---|---|---|
| A — implement model guard | ~10 (model) + ~40 (test) | 3-5 min | medium (regression sweep needed) | owner sign §10 | **PROPOSED** |
| B — DB BEFORE UPDATE trigger only | covered by LOCK W5 | — | — | G5 | parallel |
| C — defer V1.0.X | 0 | 0 | as-is | none | OK for V1 |

## §10 — Owner sign-off

```
[ ] Option A — model-level updating guard + 3 tests (RECOMMENDED for defense-in-depth)
[ ] Option B — see LOCK W5 (DB trigger) — independent decision
[X] Option C — Defer V1.0.X — accept current discipline-only enforcement (CURRENT POSTURE)

Owner signature : __________________________________
Date : 2026-05-_____
Commit ref : __________________________________
```

---

**Pending owner countersign**. Until signed, current discipline-only enforcement remains the operational state. Zone 5 Pricing SSOT sentinels and Critical-Focus Wave attestations remain the safety net.
