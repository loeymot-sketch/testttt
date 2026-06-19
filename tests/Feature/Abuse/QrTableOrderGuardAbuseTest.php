<?php

namespace Tests\Feature\Abuse;

use App\Enums\Ask;
use App\Enums\OrderType;
use App\Enums\Source;
use App\Enums\Status;
use App\Models\DiningTable;
use App\Models\FrontendOrder;
use Database\Factories\BranchFactory;
use Database\Factories\ItemCategoryFactory;
use Database\Factories\ItemFactory;
use Database\Factories\UserFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Smartisan\Settings\Facades\Settings;
use Tests\TestCase;

/**
 * [abuse-heal 2026-06-19 qr-table-twin]
 *
 * The QR table-order endpoint `POST /api/table/dining-order`
 * (routes/api.php → TableOrderController::store → TableOrderRequest →
 * OrderService::tableOrderStore) is the unguarded TWIN of the POS / kiosk
 * dine-in killswitch.
 *
 * Runtime-confirmed defect (before this heal):
 *   - It was the ONLY order-create path that did NOT validate that the
 *     `dining_table_id` references a real table (rule was numeric-only) — a
 *     POST with `dining_table_id=999999` created an order pointing at a table
 *     that does not exist (HTTP 201).
 *   - It did NOT enforce the V1 dine-in killswitch. The POS path
 *     (PosOrderRequest::withValidator → "Dine-in is disabled for this branch.")
 *     and the kiosk path (OrderRequest::withValidator → "Le service sur place
 *     est désactivé en V1…") both reject DINING_TABLE when
 *     `pos_dine_in_enabled` is false; the QR path let it through (HTTP 201).
 *
 * A QR table order IS a dine-in order, so when dine-in is disabled in V1 it
 * must be blocked exactly like POS + kiosk.
 *
 * NOTE: `seedMinimalSettings()` (TestCase.php) writes `pos_dine_in_enabled=1`
 * in the test env so existing dine-in happy-path tests keep passing. The
 * killswitch scenario below therefore explicitly writes `0`.
 */
class QrTableOrderGuardAbuseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMinimalSettings();
        $this->seedSpatieRoles();
    }

    /**
     * Build a minimal-but-valid QR dining-order payload for a real table.
     */
    private function tablePayload(int $branchId, int $customerId, int $diningTableId, int $itemId, array $overrides = []): array
    {
        return array_merge([
            'order_type' => OrderType::DINING_TABLE,
            'dining_table_id' => $diningTableId,
            'subtotal' => 12.00,
            'total' => 12.00,
            'source' => Source::POS,
            'customer_id' => $customerId,
            'branch_id' => $branchId,
            'is_advance_order' => Ask::NO,
            'items' => json_encode([[
                'item_id' => $itemId,
                'price' => 12.00,
                'quantity' => 1,
            ]]),
        ], $overrides);
    }

    private function postTableOrder(array $payload)
    {
        return $this->withHeader('x-api-key', config('app.api_key', env('MIX_API_KEY', 'test-api-key')))
            ->postJson('/api/table/dining-order', $payload);
    }

    /**
     * ABUSE 1 — non-existent table id must be rejected (422), not created (201).
     * Mirrors the existence-enforcement gap: dining_table_id was numeric-only.
     */
    public function test_table_order_with_nonexistent_table_is_rejected(): void
    {
        // Dine-in ENABLED (test default) so the ONLY thing under test is table existence.
        Settings::group('pos')->set('pos_dine_in_enabled', true);

        $branch = BranchFactory::new()->create();
        $customer = UserFactory::new()->create(['branch_id' => $branch->id]);
        $category = ItemCategoryFactory::new()->create();
        $item = ItemFactory::new()->create([
            'item_category_id' => $category->id,
            'price' => 12.00,
        ]);

        // 999999 — a table id that does not exist (runtime repro value).
        $payload = $this->tablePayload($branch->id, $customer->id, 999999, $item->id);

        $response = $this->postTableOrder($payload);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['dining_table_id']);

        $this->assertSame(
            0,
            FrontendOrder::where('dining_table_id', 999999)->count(),
            'No order may be created against a non-existent dining table.'
        );
    }

    /**
     * ABUSE 2 — dine-in killswitch: when pos_dine_in_enabled is false, a QR
     * table order for a REAL table must still be rejected (parity with the
     * POS + kiosk server-side gate). Was HTTP 201 before this heal.
     */
    public function test_table_order_rejected_when_dine_in_disabled(): void
    {
        // V1 killswitch ON (dine-in disabled). Must explicitly write 0 — the
        // test harness defaults pos_dine_in_enabled to 1.
        Settings::group('pos')->set('pos_dine_in_enabled', false);

        $branch = BranchFactory::new()->create();
        $customer = UserFactory::new()->create(['branch_id' => $branch->id]);
        $table = DiningTable::factory()->create([
            'branch_id' => $branch->id,
            'status' => Status::ACTIVE,
        ]);
        $category = ItemCategoryFactory::new()->create();
        $item = ItemFactory::new()->create([
            'item_category_id' => $category->id,
            'price' => 12.00,
        ]);

        // A perfectly valid, existing table — the ONLY reason to reject is the killswitch.
        $payload = $this->tablePayload($branch->id, $customer->id, $table->id, $item->id);

        $response = $this->postTableOrder($payload);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['order_type']);

        $this->assertSame(
            0,
            FrontendOrder::where('dining_table_id', $table->id)->count(),
            'When dine-in is disabled in V1, a QR table order must be blocked like POS + kiosk.'
        );
    }

    /**
     * CONTROL — dine-in ENABLED + a real existing table → order is accepted.
     * Proves the heal did not break the legitimate dine-in path when it is on.
     */
    public function test_table_order_accepted_when_dine_in_enabled_and_table_valid(): void
    {
        Settings::group('pos')->set('pos_dine_in_enabled', true);

        $branch = BranchFactory::new()->create();
        $customer = UserFactory::new()->create(['branch_id' => $branch->id]);
        $table = DiningTable::factory()->create([
            'branch_id' => $branch->id,
            'status' => Status::ACTIVE,
        ]);
        $category = ItemCategoryFactory::new()->create();
        $item = ItemFactory::new()->create([
            'item_category_id' => $category->id,
            'price' => 12.00,
        ]);

        $payload = $this->tablePayload($branch->id, $customer->id, $table->id, $item->id);

        $response = $this->postTableOrder($payload);

        $this->assertContains(
            $response->status(),
            [200, 201],
            'A valid dine-in QR order on a real table with dine-in enabled must be accepted (control).'
        );

        $this->assertSame(
            1,
            FrontendOrder::where('dining_table_id', $table->id)->count(),
            'The legitimate dine-in order must be persisted exactly once.'
        );
    }
}
