<?php

namespace Tests\Feature;

use App\Enums\OrderType;
use App\Enums\PaymentStatus;
use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * [GOAL-CAISSE-UNIFIED W-HIST 2026-05-30] OrderHistoryController — unified
 * read-only order history at /admin/order-history.
 *
 * Ensures:
 * - Admin (branch_id=0) gets a 200 listing EVERY origin (Borne + Caisse).
 * - The payload carries the NF525 traceability fields exposed by
 *   SimpleOrderResource (fiscal_sequence_no + parent_order_id).
 * - The source_surface filter correctly scopes by origin (Borne='kiosk',
 *   Caisse='pos') — the reliable origin signal for the page's badge/filter.
 * - A user with NONE of pos-orders/online-orders/pos is refused (403).
 */
class OrderHistoryUnifiedTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch;
    protected User $admin;
    protected User $noPerm;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();

        $this->branch = Branch::factory()->create();

        // branch_id=0 admin bypasses BranchScope → sees every origin.
        $this->admin = User::factory()->create([
            'branch_id' => 0,
            'password'  => Hash::make('pwd'),
        ]);
        $this->admin->assignRole('Admin');

        // A user with no role carries none of pos-orders/online-orders/pos.
        $this->noPerm = User::factory()->create([
            'branch_id' => $this->branch->id,
            'password'  => Hash::make('pwd'),
        ]);
    }

    private function makeOrder(string $sourceSurface, int $orderType, int $paymentStatus, ?int $fiscalSeq = null): Order
    {
        return Order::factory()->create([
            'branch_id'          => $this->branch->id,
            'order_type'         => $orderType,
            'source_surface'     => $sourceSurface,
            'payment_status'     => $paymentStatus,
            'fiscal_sequence_no' => $fiscalSeq,
            'total'              => 10.00,
        ]);
    }

    public function test_admin_lists_every_origin(): void
    {
        $this->makeOrder('kiosk', OrderType::TAKEAWAY, PaymentStatus::PENDING_COUNTER);
        $this->makeOrder('pos', OrderType::POS, PaymentStatus::PAID, 42);

        $this->actingAs($this->admin, 'sanctum');
        $res = $this->getJson('/api/admin/order-history?paginate=1&per_page=20');

        $res->assertOk();
        $this->assertGreaterThanOrEqual(2, count($res->json('data')));
    }

    public function test_payload_exposes_nf525_traceability_fields(): void
    {
        $this->makeOrder('pos', OrderType::POS, PaymentStatus::PAID, 7);

        $this->actingAs($this->admin, 'sanctum');
        $res = $this->getJson('/api/admin/order-history?paginate=1&per_page=20');

        $res->assertOk()->assertJsonStructure([
            'data' => [['id', 'fiscal_sequence_no', 'parent_order_id', 'source_surface', 'payment_status']],
        ]);
    }

    public function test_source_surface_filter_scopes_origin(): void
    {
        $this->makeOrder('kiosk', OrderType::TAKEAWAY, PaymentStatus::PENDING_COUNTER);
        $this->makeOrder('kiosk', OrderType::TAKEAWAY, PaymentStatus::PENDING_COUNTER);
        $this->makeOrder('pos', OrderType::POS, PaymentStatus::PAID, 1);

        $this->actingAs($this->admin, 'sanctum');
        $res = $this->getJson('/api/admin/order-history?paginate=1&per_page=20&source_surface=kiosk');

        $res->assertOk();
        $surfaces = collect($res->json('data'))->pluck('source_surface')->unique()->values()->all();
        $this->assertEquals(['kiosk'], $surfaces);
    }

    public function test_user_without_order_permissions_is_forbidden(): void
    {
        $this->actingAs($this->noPerm, 'sanctum');
        $this->getJson('/api/admin/order-history')->assertForbidden();
    }
}
