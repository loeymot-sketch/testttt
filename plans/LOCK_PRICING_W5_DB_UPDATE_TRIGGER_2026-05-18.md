# LOCK plan — DB BEFORE UPDATE trigger on composition_snapshot (W5)

**ID** : LOCK_PRICING_W5_DB_UPDATE_TRIGGER_2026-05-18
**Date** : 2026-05-18
**Status** : OWNER GATE PENDING (G5 per `plans/GOAL_V1_PRODUCTION_PERFECT_PHASE2_2026-05-18.md` §G)
**Author** : Claude orchestrator (T-5.1.3)
**Branche** : `heal/cms-pr1-quickwins-2026-05-18`

---

## §1 — Why this LOCK exists

LOCK W2 (companion) proposes a model-level updating guard. LOCK W5 is the **stronger, DB-level enforcement** of the same NF525 invariant : `order_items.composition_snapshot` is INSERT-only.

A MySQL `BEFORE UPDATE` trigger that RAISES if `NEW.composition_snapshot != OLD.composition_snapshot` catches **any path** that bypasses the model layer :
- Raw SQL UPDATE statements (e.g. data migration scripts)
- Direct DB tooling (TablePlus, MySQL Workbench)
- Cross-DB replication anomalies
- Future ORM swaps

This is parallel to the existing `audit_logs` + `z_reports` triggers (`CLAUDE.md §8`) that block DELETE.

## §2 — Migration shape

NEW migration `database/migrations/2026_05_18_000000_add_composition_snapshot_immutable_trigger.php` :

```php
public function up(): void
{
    if (DB::connection()->getDriverName() !== 'mysql') {
        // SQLite/Postgres CI : skip. Discipline enforced by model guard
        // (LOCK W2) and PHPUnit sentinels.
        return;
    }

    DB::unprepared(<<<'SQL'
        CREATE TRIGGER order_items_composition_snapshot_immutable
        BEFORE UPDATE ON order_items
        FOR EACH ROW
        BEGIN
            IF NOT (NEW.composition_snapshot <=> OLD.composition_snapshot) THEN
                SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'NF525: order_items.composition_snapshot is INSERT-only';
            END IF;
        END;
    SQL);
}

public function down(): void
{
    if (DB::connection()->getDriverName() !== 'mysql') {
        return;
    }
    DB::unprepared('DROP TRIGGER IF EXISTS order_items_composition_snapshot_immutable;');
}
```

`<=>` is MySQL's NULL-safe equality (handles legitimate NULL → NULL cases without firing).

## §3 — Frozen-zone touch

NEW migration file — not in the canonical 13-file frozen list. The migration itself becomes an applied-and-immutable artefact (per FoodKing DB migration policy : applied migrations are append-only).

NO change to existing migrations. NO change to `app/Services/Fiscal/*Service.php`. NO change to `app/Services/Pricing/PricingService.php`.

## §4 — Test plan

NEW `tests/Feature/Pricing/CompositionSnapshotDbTriggerTest.php` (MySQL-only) :

```php
public function test_raw_sql_update_blocked_by_trigger(): void
{
    if (DB::connection()->getDriverName() !== 'mysql') {
        $this->markTestSkipped('Trigger is MySQL-only (SQLite has no SIGNAL).');
    }

    $item = OrderItem::factory()->create([
        'composition_snapshot' => ['ingredients' => ['sauce_a']],
    ]);

    $this->expectException(\Illuminate\Database\QueryException::class);
    $this->expectExceptionMessageMatches('/composition_snapshot is INSERT-only/');

    DB::statement(
        'UPDATE order_items SET composition_snapshot = ? WHERE id = ?',
        [json_encode(['ingredients' => ['sauce_b']]), $item->id]
    );
}

public function test_legitimate_update_of_other_fields_still_works(): void
{
    if (DB::connection()->getDriverName() !== 'mysql') {
        $this->markTestSkipped('MySQL-only.');
    }

    $item = OrderItem::factory()->create([
        'composition_snapshot' => ['ingredients' => ['x']],
        'total_price' => 10.00,
    ]);

    // No composition change → trigger NOOPs.
    DB::statement('UPDATE order_items SET total_price = 9.50 WHERE id = ?', [$item->id]);

    $this->assertSame('9.500000', $item->fresh()->total_price);
}
```

## §5 — Risk assessment

### What could break ?
- Test fixtures using SQLite : skipped via `markTestSkipped`. Test debt only.
- Local dev DBs using MySQL : trigger fires on careless dev updates → that's the design intent.
- CI : if CI uses MySQL, trigger needs to be applied via `php artisan migrate`. Standard migration flow.
- Replication / DR : trigger is replicated to replicas. No risk.

### Edge case : composition_snapshot NULL → NULL
MySQL `<=>` operator handles NULL-safe equality. `NEW.col <=> OLD.col` returns 1 when both are NULL or both are equal value, 0 otherwise. So a legitimate row that has `composition_snapshot = NULL` and a re-save that doesn't touch the column won't fire the trigger.

### Edge case : JSON key reordering
MySQL stores JSON canonicalized internally; equality comparison handles key reordering. But if a legitimate code path mutates the array in PHP then re-saves the same content with different key order, the column-level value is bit-identical at MySQL level. No false positive expected.

### Edge case : test fixture seeders
Some seeders bulk-update order_items. If any touches `composition_snapshot`, it will fail post-migration. Pre-landing grep required :
```
grep -rn "composition_snapshot" database/seeders/ | grep -i update
```

## §6 — Rollback

Single commit revert + DROP TRIGGER manually via `migrate:rollback` step. Standard Laravel migration revert. If production schema has the trigger but new code doesn't expect it, no incident — trigger silently passes legitimate update-other-columns flows.

## §7 — Safety-check.sh override config

```yaml
LOCK_FILE: database/migrations/2026_05_18_000000_add_composition_snapshot_immutable_trigger.php (NEW)
LOCK_LINES: full file
LOCK_RATIONALE: NF525 composition_snapshot DB-level INSERT-only enforcement
OWNER_GATE: REQUIRED
NF525_IMPACT: STRENGTHENS invariant (no chain mutation, no audit_logs touch)
ROLLBACK_COMPLEXITY: trivial (migrate:rollback)
APPLIES_PROD_ONLY: true (MySQL gated; SQLite/CI no-op)
```

## §8 — Sub-agent instructions (if approved)

```
Goal: add DB-level composition_snapshot immutability trigger (MySQL prod).
Step 1: Pre-landing grep:
        grep -rn "composition_snapshot" database/seeders/ app/ tests/
        Document any legitimate update path. If any → re-engineer or scope-out before trigger.
Step 2: TDD-first — write NEW test file CompositionSnapshotDbTriggerTest.php (per §4).
Step 3: NEW migration per §2.
Step 4: Run php artisan migrate (local dev = MySQL preferred).
Step 5: Run new test file. Expect 2 GREEN on MySQL, 2 SKIP on SQLite.
Step 6: Run broad regression: php artisan test --filter=Order|Pricing
        Expect zero new failures. If any composition_snapshot update fixture
        breaks, surface for owner gate triage.
Step 7: Stage + commit referencing this LOCK + close G5.
```

## §9 — Decision matrix

| Option | LOC | Test cost | Risk | Owner gate | Verdict |
|---|---|---|---|---|---|
| A — DB trigger MySQL prod | ~20 (migration) + ~30 (test) | 5 min | medium (seeder regression sweep) | owner sign §10 | **PROPOSED** |
| B — model guard only (LOCK W2) | covered W2 | — | — | G4 | parallel |
| C — defer V1.0.X | 0 | 0 | as-is | none | OK for V1 (zone5 sentinels cover) |

## §10 — Owner sign-off

```
[ ] Option A — DB BEFORE UPDATE trigger (RECOMMENDED for ultimate enforcement)
[ ] Option B — see LOCK W2 (model guard) — independent decision
[X] Option C — Defer V1.0.X — accept zone5 sentinel coverage as sufficient (CURRENT POSTURE)

Owner signature : __________________________________
Date : 2026-05-_____
Commit ref : __________________________________
```

---

**Pending owner countersign**. Until signed, current zone5-Pricing-SSOT-sentinel enforcement (5 INSERT / 0 UPDATE attested) remains the safety net.
