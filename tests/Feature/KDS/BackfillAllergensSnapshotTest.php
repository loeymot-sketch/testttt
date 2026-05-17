<?php

namespace Tests\Feature\KDS;

use App\Models\Allergen;
use App\Models\Branch;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Scopes\BranchScope;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * [Sprint H4 Z3-NEW-005 2026-05-17] BackfillAllergensSnapshotCommand
 * walks order_items rows with allergens_snapshot=NULL and populates
 * them from the current Item::allergens pivot (best-effort) — same
 * shape as OrderItemAllergenSnapshot::hydrate() write path.
 *
 * Discipline:
 *   - Idempotent: only NULL rows match; existing snapshots untouched (NF525 §8).
 *   - Bypasses BranchScope (CLI ops backfill walks all tenants).
 *   - Orphan items (item_id with no items row) → empty snapshot + log warn.
 *   - --dry-run for ops verification.
 *
 * CLAUDE.md §11.
 */
class BackfillAllergensSnapshotTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function test_backfill_populates_null_snapshots_from_current_item_pivot(): void
    {
        [$branch, $order, $item] = $this->seedScaffolding('backfill-burger');

        // Attach allergens via pivot (production path).
        $gluten = Allergen::forceCreate(['code' => 'gluten', 'name_key' => 'allergens.gluten', 'sort' => 1]);
        $lactose = Allergen::forceCreate(['code' => 'lactose', 'name_key' => 'allergens.lactose', 'sort' => 2]);
        $item->allergens()->sync([
            $gluten->id => ['is_trace' => false],
            $lactose->id => ['is_trace' => false],
        ]);

        $line = $this->createOrderItem($order, $item, $branch, null);

        $this->artisan('foodking:backfill:allergens-snapshot')
            ->assertSuccessful();

        $stored = DB::table('order_items')->where('id', $line->id)->value('allergens_snapshot');
        $this->assertNotNull($stored, 'allergens_snapshot must be populated after backfill');
        $decoded = json_decode((string) $stored, true);
        $this->assertContains('gluten', $decoded);
        $this->assertContains('lactose', $decoded);
    }

    /** @test */
    public function test_backfill_is_idempotent_and_immutable_for_existing_snapshots(): void
    {
        [$branch, $order, $item] = $this->seedScaffolding('idempotent-burger');

        $gluten = Allergen::forceCreate(['code' => 'gluten', 'name_key' => 'allergens.gluten', 'sort' => 1]);
        $item->allergens()->sync([$gluten->id => ['is_trace' => false]]);

        // Create the row first (allergens_snapshot defaults to null), then write the
        // pre-existing snapshot via DB::table so it lands as raw JSON in DB (matches
        // the production write path in OrderItemAllergenSnapshot::hydrate() →
        // OrderItem::insert(raw)). Bypassing the Eloquent 'array' cast prevents the
        // double-encode that would otherwise occur on `OrderItem::create()`.
        $line = $this->createOrderItem($order, $item, $branch, null);
        DB::table('order_items')->where('id', $line->id)->update([
            'allergens_snapshot' => json_encode(['arachides'], JSON_UNESCAPED_UNICODE),
        ]);

        $this->artisan('foodking:backfill:allergens-snapshot')
            ->assertSuccessful();

        $stored = DB::table('order_items')->where('id', $line->id)->value('allergens_snapshot');
        $decoded = json_decode((string) $stored, true);
        // Snapshot UNCHANGED — backfill must not overwrite existing snapshots (NF525 §8 immutability).
        $this->assertEquals(['arachides'], $decoded, 'Existing snapshots must remain immutable (NF525 §8)');
    }

    /** @test */
    public function test_backfill_handles_orphan_items_gracefully(): void
    {
        $branch = Branch::factory()->create();
        $user = User::factory()->create(['branch_id' => $branch->id]);
        $order = $this->createOrder($branch, $user);

        // Simulate a legacy hard-deleted item: insert order_item with non-existent item_id
        // by temporarily disabling FK constraints (matches legacy data shape before FK
        // hardening landed). Backfill MUST NOT crash on orphans.
        \Illuminate\Support\Facades\Schema::disableForeignKeyConstraints();
        $id = DB::table('order_items')->insertGetId([
            'order_id' => $order->id,
            'branch_id' => $branch->id,
            'item_id' => 9999999, // no such item — hard-deleted ancestor
            'quantity' => 1,
            'price' => 10,
            'discount' => 0,
            'item_variation_total' => 0,
            'item_extra_total' => 0,
            'total_price' => 10,
            'item_variations' => '[]',
            'item_extras' => '[]',
            'instruction' => '',
            'allergens_snapshot' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        \Illuminate\Support\Facades\Schema::enableForeignKeyConstraints();

        $this->artisan('foodking:backfill:allergens-snapshot')
            ->assertSuccessful();

        // Orphan row was visited but couldn't resolve allergens → remains NULL (best-effort),
        // logged as warning. Backfill MUST NOT crash on orphans.
        $stored = DB::table('order_items')->where('id', $id)->value('allergens_snapshot');
        $this->assertNull($stored, 'Orphan rows stay NULL — cannot synthesize allergens without item');
    }

    /** @test */
    public function test_dry_run_does_not_write_to_database(): void
    {
        [$branch, $order, $item] = $this->seedScaffolding('dryrun-burger');

        $gluten = Allergen::forceCreate(['code' => 'gluten', 'name_key' => 'allergens.gluten', 'sort' => 1]);
        $item->allergens()->sync([$gluten->id => ['is_trace' => false]]);

        $line = $this->createOrderItem($order, $item, $branch, null);

        $this->artisan('foodking:backfill:allergens-snapshot', ['--dry-run' => true])
            ->assertSuccessful();

        $stored = DB::table('order_items')->where('id', $line->id)->value('allergens_snapshot');
        $this->assertNull($stored, 'Dry-run must not write to DB');
    }

    // ---------- helpers ----------

    /**
     * @return array{0:Branch,1:Order,2:Item}
     */
    private function seedScaffolding(string $slug): array
    {
        $branch = Branch::factory()->create();
        $user = User::factory()->create(['branch_id' => $branch->id]);
        $category = ItemCategory::firstOrCreate(
            ['slug' => 'backfill-cat-' . $slug],
            ['name' => 'Backfill cat ' . $slug, 'status' => 5]
        );
        $item = Item::forceCreate([
            'name' => 'Backfill ' . $slug,
            'slug' => $slug,
            'price' => 10,
            'status' => 5,
            'item_category_id' => $category->id,
        ]);
        $order = $this->createOrder($branch, $user);

        return [$branch, $order, $item];
    }

    private function createOrder(Branch $branch, User $user): Order
    {
        return Order::create([
            'order_serial_no' => strtoupper('BF-' . uniqid()),
            'branch_id' => $branch->id,
            'user_id' => $user->id,
            'status' => 5,
            'payment_status' => 10,
            'subtotal' => 10.0,
            'total' => 10.0,
            'order_type' => 5,
            'order_datetime' => now(),
            'is_advance_order' => 0,
            'source' => 5,
        ]);
    }

    private function createOrderItem(Order $order, Item $item, Branch $branch, ?string $snapshot): OrderItem
    {
        return OrderItem::withoutGlobalScope(BranchScope::class)->create([
            'order_id' => $order->id,
            'branch_id' => $branch->id,
            'item_id' => $item->id,
            'quantity' => 1,
            'price' => 10,
            'discount' => 0,
            'item_variation_total' => 0,
            'item_extra_total' => 0,
            'total_price' => 10,
            'item_variations' => '[]',
            'item_extras' => '[]',
            'instruction' => '',
            'allergens_snapshot' => $snapshot, // null or JSON string
        ]);
    }
}
