<?php

namespace Tests\Feature\Fiscal;

use App\Enums\Status;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * [GOAL-J2-HEAL-06 2026-05-24] Phase J-ADV-2 FV-F5-1 P1 NF525.
 *
 * Sentinel — order_items.composition_snapshot is immutable post-INSERT
 * at TWO defence-in-depth layers:
 *
 *   Layer 1 (DB trigger): migration 2026_05_24_040211 installs a
 *     BEFORE UPDATE trigger that raises SQLSTATE 45000 (MySQL) /
 *     RAISE(ABORT) (SQLite) on snapshot mutation.
 *
 *   Layer 2 (Eloquent guard): OrderItem::booted() registers an
 *     `updating()` callback that throws RuntimeException at the app
 *     layer for the same condition (preserves Eloquent stack trace
 *     visibility for developers running in tests / IDE).
 *
 * Coverage:
 *   - INSERT with snapshot: ALLOWED (baseline).
 *   - UPDATE of non-snapshot column: ALLOWED (no false positives).
 *   - UPDATE of snapshot via Eloquent ::save(): BLOCKED (Layer 2).
 *   - UPDATE of snapshot via raw DB::table()->update(): BLOCKED (Layer 1).
 *   - UPDATE setting snapshot from NULL: ALLOWED (backfill scenario).
 *   - UPDATE nulling a non-null snapshot: BLOCKED (evidence-destruction).
 *
 * This sentinel is a CI ratchet: a PR that drops either layer will turn
 * one of these assertions red. Mirrors AuditLogImmutabilityTest pattern.
 */
class CompositionSnapshotImmutabilityTriggerSentinel extends TestCase
{
    use RefreshDatabase;

    private function seedOrderItem(?array $snapshot = null): OrderItem
    {
        $cat = ItemCategory::factory()->create([
            'wizard_template' => 'tacos',
            'has_menu'        => true,
        ]);
        $item = Item::factory()->create([
            'item_category_id' => $cat->id,
            'price'            => 10.0,
            'status'           => Status::ACTIVE,
        ]);
        $order = Order::factory()->create();

        $payload = $snapshot ?? [
            'schema_version' => 1,
            'lines'          => [
                [
                    'variation_id'   => 1,
                    'variation_name' => 'Algerienne',
                    'unit_price'     => 1.0,
                    'quantity'       => 1,
                ],
            ],
        ];

        return OrderItem::query()->create([
            'order_id'             => $order->id,
            'branch_id'            => $order->branch_id,
            'item_id'              => $item->id,
            'quantity'             => 1,
            'discount'             => 0,
            'tax_name'             => 'TVA',
            'tax_rate'             => 10,
            'tax_type'             => \App\Enums\TaxType::PERCENTAGE,
            'tax_amount'           => 1.0,
            'price'                => 10.0,
            'item_variations'      => json_encode([]),
            'item_extras'          => json_encode([]),
            'composition_snapshot' => $payload,
            'item_variation_total' => 0,
            'item_extra_total'     => 0,
            'total_price'          => 11.0,
        ]);
    }

    /**
     * Baseline — INSERT path is unaffected.
     */
    public function test_insert_with_snapshot_is_allowed(): void
    {
        $row = $this->seedOrderItem();

        $this->assertDatabaseHas('order_items', ['id' => $row->id]);
        $this->assertNotNull($row->fresh()->composition_snapshot);
    }

    /**
     * Baseline — UPDATE of an unrelated column does NOT trip the guard.
     */
    public function test_update_of_unrelated_column_is_allowed(): void
    {
        $row = $this->seedOrderItem();

        $row->instruction = 'extra spicy';
        $row->save();

        $this->assertSame('extra spicy', $row->fresh()->instruction);
    }

    /**
     * Layer 2 — Eloquent guard.
     *
     * Mutating composition_snapshot via Eloquent ::save() raises
     * RuntimeException from OrderItem::booted() updating() hook BEFORE
     * the query reaches the DB.
     */
    public function test_layer2_eloquent_update_of_snapshot_blocked(): void
    {
        $row = $this->seedOrderItem();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('composition_snapshot is immutable');

        $row->composition_snapshot = [
            'schema_version' => 1,
            'lines'          => [['variation_id' => 999, 'variation_name' => 'TAMPERED', 'unit_price' => 0.01, 'quantity' => 1]],
        ];
        $row->save();
    }

    /**
     * Layer 1 — DB trigger.
     *
     * Raw DB::table()->update() bypasses Eloquent and the booted()
     * hook entirely — only the migration's BEFORE UPDATE trigger can
     * catch it. Surfaces as QueryException (MySQL SIGNAL or SQLite
     * RAISE(ABORT) both wrap to QueryException).
     */
    public function test_layer1_raw_sql_update_of_snapshot_blocked(): void
    {
        $row = $this->seedOrderItem();
        $tamperedSnapshot = json_encode([
            'schema_version' => 1,
            'lines'          => [['variation_id' => 999, 'variation_name' => 'RAW_TAMPERED', 'unit_price' => 0.01, 'quantity' => 1]],
        ]);

        try {
            DB::table('order_items')
                ->where('id', $row->id)
                ->update(['composition_snapshot' => $tamperedSnapshot]);
            $this->fail('Raw UPDATE on order_items.composition_snapshot should have been rejected by the DB trigger.');
        } catch (QueryException $e) {
            $msg = strtolower($e->getMessage());
            $this->assertTrue(
                str_contains($msg, 'composition_snapshot') || str_contains($msg, 'nf525') || str_contains($msg, 'immutable'),
                'Expected trigger rejection message, got: ' . $e->getMessage()
            );
        }

        // Snapshot remains the original value byte-for-byte.
        $reloaded = OrderItem::query()->find($row->id);
        $this->assertSame('Algerienne', $reloaded->composition_snapshot['lines'][0]['variation_name']);
    }

    /**
     * Backfill scenario — NULL → JSON must remain allowed for the legacy
     * row backfill use case. Insert with NULL, then UPDATE to set the
     * snapshot. Neither layer should fire.
     */
    public function test_update_from_null_to_snapshot_is_allowed(): void
    {
        $cat = ItemCategory::factory()->create([
            'wizard_template' => 'tacos',
            'has_menu'        => true,
        ]);
        $item = Item::factory()->create([
            'item_category_id' => $cat->id,
            'price'            => 10.0,
            'status'           => Status::ACTIVE,
        ]);
        $order = Order::factory()->create();

        $row = OrderItem::query()->create([
            'order_id'             => $order->id,
            'branch_id'            => $order->branch_id,
            'item_id'              => $item->id,
            'quantity'             => 1,
            'discount'             => 0,
            'tax_name'             => 'TVA',
            'tax_rate'             => 10,
            'tax_type'             => \App\Enums\TaxType::PERCENTAGE,
            'tax_amount'           => 1.0,
            'price'                => 10.0,
            'item_variations'      => json_encode([]),
            'item_extras'          => json_encode([]),
            'composition_snapshot' => null,
            'item_variation_total' => 0,
            'item_extra_total'     => 0,
            'total_price'          => 11.0,
        ]);

        $row->composition_snapshot = [
            'schema_version' => 1,
            'lines'          => [['variation_id' => 1, 'variation_name' => 'Backfilled', 'unit_price' => 1.0, 'quantity' => 1]],
        ];
        $row->save();

        $this->assertSame('Backfilled', $row->fresh()->composition_snapshot['lines'][0]['variation_name']);
    }

    /**
     * Evidence-destruction guard — UPDATE that nulls a non-null snapshot
     * is forbidden via raw SQL (DB trigger). The Eloquent layer also
     * traps it via isDirty('composition_snapshot') + getOriginal() !== null.
     */
    public function test_layer1_raw_sql_nulling_snapshot_blocked(): void
    {
        $row = $this->seedOrderItem();

        try {
            DB::table('order_items')
                ->where('id', $row->id)
                ->update(['composition_snapshot' => null]);
            $this->fail('Raw UPDATE nulling order_items.composition_snapshot should have been rejected by the DB trigger.');
        } catch (QueryException $e) {
            $msg = strtolower($e->getMessage());
            $this->assertTrue(
                str_contains($msg, 'composition_snapshot') || str_contains($msg, 'nf525') || str_contains($msg, 'cannot be nulled') || str_contains($msg, 'immutable'),
                'Expected trigger rejection message, got: ' . $e->getMessage()
            );
        }

        // Snapshot still present.
        $reloaded = OrderItem::query()->find($row->id);
        $this->assertNotNull($reloaded->composition_snapshot);
    }
}
