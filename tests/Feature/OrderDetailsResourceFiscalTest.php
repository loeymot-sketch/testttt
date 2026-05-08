<?php

namespace Tests\Feature;

use App\Http\Resources\OrderDetailsResource;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Order;
use App\Services\Fiscal\AuditLogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * [HARDEN-P1-12 / Track 2.1 unblock]
 *
 * `OrderDetailsResource` must surface the per-branch fiscal sequence
 * number (NF525 art. 286-I-3°bis) and the short HMAC signature so the
 * printed receipt (ReceiptComponent.vue) can render them.
 *
 * The two new fields are nullable for back-compat: orders predating
 * F-001 (POS-9.4.1) carry `fiscal_sequence_no = null` and the receipt
 * block stays hidden. This file pins the resource's contract so a
 * future agent cannot drop these fields silently.
 */
class OrderDetailsResourceFiscalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMinimalSettings();
        $this->seedSpatieRoles();
        // Required by AuditLogService::computeHash(); the value itself
        // does not matter for resource-level assertions.
        Config::set('fiscal.audit_secret', 'unit-test-secret-receipt');
    }

    public function test_resource_exposes_fiscal_sequence_no(): void
    {
        $branch = Branch::factory()->create();
        $order  = Order::factory()->create(['branch_id' => $branch->id]);
        // `fiscal_sequence_no` is intentionally not in the model's
        // $fillable (allocated by the Fiscal service, never by user input),
        // so we stamp it the same way the F-001 service does.
        $order->forceFill(['fiscal_sequence_no' => 12345])->saveQuietly();

        $array = (new OrderDetailsResource($order->fresh()))
            ->toArray(request());

        $this->assertArrayHasKey('fiscal_sequence_no', $array);
        $this->assertSame(12345, (int) $array['fiscal_sequence_no']);
    }

    public function test_resource_returns_null_fiscal_when_unallocated(): void
    {
        $branch = Branch::factory()->create();
        $order  = Order::factory()->create([
            'branch_id'          => $branch->id,
            'fiscal_sequence_no' => null,
        ]);

        $array = (new OrderDetailsResource($order))
            ->toArray(request());

        $this->assertArrayHasKey('fiscal_sequence_no', $array);
        $this->assertNull($array['fiscal_sequence_no']);
    }

    public function test_fiscal_signature_short_extracted_from_audit_log(): void
    {
        $branch = Branch::factory()->create();
        $order  = Order::factory()->create(['branch_id' => $branch->id]);
        $order->forceFill(['fiscal_sequence_no' => 7])->saveQuietly();

        // Write a real audit row through the canonical service so we
        // also exercise the on-the-wire shape (resource = 'order',
        // resource_id = order id, current_hash populated).
        $row = app(AuditLogService::class)->write([
            'branch_id'   => (int) $branch->id,
            'action'      => 'order.fiscal_allocated',
            'resource'    => 'order',
            'resource_id' => (int) $order->id,
            'payload'     => [
                'order_id'           => (int) $order->id,
                'fiscal_sequence_no' => 7,
            ],
        ]);

        $this->assertNotEmpty($row->current_hash);
        $expectedShort = substr((string) $row->current_hash, 0, 8);

        $array = (new OrderDetailsResource($order->fresh()))
            ->toArray(request());

        $this->assertArrayHasKey('fiscal_signature_short', $array);
        $this->assertSame(
            $expectedShort,
            $array['fiscal_signature_short'],
            'fiscal_signature_short must be the first 8 chars of the latest audit row HMAC.'
        );
        $this->assertSame(8, strlen((string) $array['fiscal_signature_short']));
    }

    public function test_fiscal_signature_short_null_when_fiscal_unallocated(): void
    {
        // When fiscal_sequence_no is null, the early-return guard MUST
        // skip the audit_logs query — both for performance and because
        // the order isn't yet sealed in the chain.
        $branch = Branch::factory()->create();
        $order  = Order::factory()->create([
            'branch_id'          => $branch->id,
            'fiscal_sequence_no' => null,
        ]);

        $array = (new OrderDetailsResource($order))
            ->toArray(request());

        $this->assertArrayHasKey('fiscal_signature_short', $array);
        $this->assertNull($array['fiscal_signature_short']);
    }

    public function test_fiscal_signature_short_null_when_no_audit_log(): void
    {
        // Distinct branch: order has been allocated a fiscal sequence
        // number but no AuditLog row was written for it yet (race
        // condition window or non-fiscal flows). The resource must
        // degrade gracefully to null instead of crashing the receipt.
        $branch = Branch::factory()->create();
        $order  = Order::factory()->create(['branch_id' => $branch->id]);
        $order->forceFill(['fiscal_sequence_no' => 99])->saveQuietly();

        // Sanity-check the precondition we depend on.
        $this->assertSame(
            0,
            AuditLog::query()
                ->where('resource', 'order')
                ->where('resource_id', $order->id)
                ->count(),
            'Precondition: no audit row should exist for this order.'
        );

        $array = (new OrderDetailsResource($order->fresh()))
            ->toArray(request());

        $this->assertSame(99, (int) $array['fiscal_sequence_no']);
        $this->assertNull($array['fiscal_signature_short']);
    }
}
