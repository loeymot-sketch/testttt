<?php

namespace Tests\Feature\OrderHistory;

use App\Enums\OrderType;
use App\Enums\PaymentStatus;
use App\Models\Branch;
use App\Models\Item;
use App\Models\ItemAttribute;
use App\Models\ItemVariation;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * [GOAL_MGMT_TESTPLAN Wave B — HIST-10] NF525 snapshot integrity (read path).
 *
 * Invariant under audit (CLAUDE.md §8): the immutable `composition_snapshot`
 * frozen on each `order_items` row at order creation is the fiscal SSOT for
 * the receipt/detail. The order-history detail
 * (GET /api/admin/order-history/show/{order}) MUST faithfully replay that
 * frozen snapshot — item lines, options, prices — and MUST NEVER recompute or
 * re-derive any value from the *current* menu catalog on read.
 *
 * Source-confirmed read path (no recompute):
 *  - OrderItem casts `composition_snapshot => 'array'` (verbatim JSON column).
 *  - OrderItemResource::toArray ships `composition_snapshot` as-is and derives
 *    `item_variations`/`item_extras`/`item_addons` from the snapshot's own
 *    `lines`/`extras`/`addons` — never from a live ItemVariation/Item lookup.
 *  - OrderService::show returns the Order model untouched (pure passthrough).
 *
 * TEETH (dual-guard): a recompute regression on variation lines would read the
 * *live* `ItemVariation.price`. We therefore freeze `unit_price: 7.50` into the
 * snapshot, then mutate the underlying `ItemVariation.price` to `999.00` AFTER
 * creation. If the detail recomputed, the response would surface 999.00; we
 * assert it still returns the frozen 7.50. This test would FAIL if read
 * recomputed from the current menu — it is not merely asserting "Laravel
 * serializes a column".
 *
 * sqlite note: the MySQL BEFORE-UPDATE immutability trigger is not exercised
 * here — we INSERT the OrderItem once and never UPDATE it, so neither the DB
 * trigger nor the Eloquent `updating()` guard is in scope.
 */
class OrderHistoryShowSnapshotIntegrityTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch;
    protected User $admin;

    // Frozen snapshot values — the fiscal truth the receipt must replay.
    private const FROZEN_UNIT_PRICE  = 7.50;
    private const FROZEN_QUANTITY    = 2;
    private const FROZEN_LINE_TOTAL  = 15.00;   // 7.50 * 2
    private const FROZEN_VAR_NAME    = 'Menu Maxi';
    private const FROZEN_EXTRA_PRICE = 1.25;
    // The value the live menu is mutated to AFTER order creation. A recompute
    // bug would leak this into the response; a faithful replay never will.
    private const LIVE_MUTATED_PRICE = 999.00;

    protected function setUp(): void
    {
        parent::setUp();
        // Reused verbatim from OrderHistoryUnifiedTest — admin branch_id=0
        // bypasses BranchScope and satisfies the can('pos-orders')||can('pos')
        // gate on OrderHistoryController::show.
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();

        $this->branch = Branch::factory()->create();

        $this->admin = User::factory()->create([
            'branch_id' => 0,
            'password'  => Hash::make('pwd'),
        ]);
        $this->admin->assignRole('Admin');
    }

    public function test_history_detail_replays_frozen_snapshot_and_ignores_live_menu_price(): void
    {
        // --- Catalog at order time: a variation priced 7.50. ---
        $item = Item::factory()->create(['price' => self::FROZEN_UNIT_PRICE]);
        $attribute = ItemAttribute::factory()->create(['name' => 'Taille']);
        $variation = ItemVariation::query()->create([
            'item_id'           => $item->id,
            'item_attribute_id' => $attribute->id,
            'name'              => self::FROZEN_VAR_NAME,
            'price'             => self::FROZEN_UNIT_PRICE,
            'status'            => 1,
        ]);

        // --- Order with a hand-crafted, frozen composition_snapshot. ---
        $order = Order::factory()->create([
            'branch_id'          => $this->branch->id,
            'order_type'         => OrderType::POS,
            'source_surface'     => 'pos',
            'payment_status'     => PaymentStatus::PAID,
            'fiscal_sequence_no' => 11,
            'total'              => self::FROZEN_LINE_TOTAL,
        ]);

        $frozenSnapshot = [
            'schema_version' => 1,
            'captured_at'    => '2026-06-03T10:00:00+00:00',
            'lines' => [[
                'variation_id'   => $variation->id,
                'attribute_id'   => $attribute->id,
                'attribute_name' => 'Taille',
                'variation_name' => self::FROZEN_VAR_NAME,
                'quantity'       => self::FROZEN_QUANTITY,
                'unit_price'     => self::FROZEN_UNIT_PRICE,
                'line_total'     => self::FROZEN_LINE_TOTAL,
            ]],
            'extras' => [[
                'extra_id'   => 1,
                'extra_name' => 'Sauce Algerienne',
                'quantity'   => 1,
                'unit_price' => self::FROZEN_EXTRA_PRICE,
                'line_total' => self::FROZEN_EXTRA_PRICE,
            ]],
            'addons' => [],
        ];

        // Created acting as admin so BranchScope (on OrderItem) is bypassed.
        $this->actingAs($this->admin, 'sanctum');
        $order->orderItems()->create([
            'branch_id'            => $this->branch->id,
            'item_id'              => $item->id,
            'quantity'             => self::FROZEN_QUANTITY,
            'price'                => self::FROZEN_UNIT_PRICE,
            'discount'             => 0,
            'tax_name'             => 'TVA',
            'tax_rate'             => '10',
            'tax_type'             => 1,
            'tax_amount'           => 0,
            'item_variation_total' => self::FROZEN_LINE_TOTAL,
            'item_extra_total'     => self::FROZEN_EXTRA_PRICE,
            'total_price'          => self::FROZEN_LINE_TOTAL + self::FROZEN_EXTRA_PRICE,
            'composition_snapshot' => $frozenSnapshot,
        ]);

        // --- Live menu mutates AFTER order creation. ---
        // A recompute-on-read bug for variation lines would read THIS value.
        $variation->price = self::LIVE_MUTATED_PRICE;
        $variation->save();
        $item->price = self::LIVE_MUTATED_PRICE;
        $item->save();

        // --- Read the history detail. ---
        $res = $this->getJson("/api/admin/order-history/show/{$order->id}");
        $res->assertOk();

        $line = $res->json('data.order_items.0.composition_snapshot.lines.0');
        $this->assertIsArray($line, 'Snapshot line not exposed on the detail payload.');

        // HARD: frozen replay — these would all break if read recomputed.
        $this->assertEqualsWithDelta(self::FROZEN_UNIT_PRICE, (float) $line['unit_price'], 1e-6,
            'Snapshot unit_price drifted from the frozen value — read recomputed from live menu.');
        $this->assertEqualsWithDelta(self::FROZEN_LINE_TOTAL, (float) $line['line_total'], 1e-6,
            'Snapshot line_total drifted from the frozen value.');
        $this->assertSame(self::FROZEN_QUANTITY, (int) $line['quantity']);
        $this->assertSame(self::FROZEN_VAR_NAME, $line['variation_name']);

        // EXPLICIT anti-recompute: the live-mutated 999.00 must NOT appear.
        $this->assertNotEqualsWithDelta(self::LIVE_MUTATED_PRICE, (float) $line['unit_price'], 1e-6,
            'P0 NF525: live menu price (999.00) leaked into snapshot unit_price on read.');

        // Extras frozen too.
        $extra = $res->json('data.order_items.0.composition_snapshot.extras.0');
        $this->assertIsArray($extra);
        $this->assertEqualsWithDelta(self::FROZEN_EXTRA_PRICE, (float) $extra['unit_price'], 1e-6);
        $this->assertSame('Sauce Algerienne', $extra['extra_name']);

        // The resolved API shapes derive from the snapshot, not the catalog.
        $this->assertEqualsWithDelta(
            self::FROZEN_UNIT_PRICE,
            (float) $res->json('data.order_items.0.item_variations.0.unit_price'),
            1e-6,
            'item_variations (snapshot-derived) drifted — read re-derived from live menu.'
        );
    }
}
