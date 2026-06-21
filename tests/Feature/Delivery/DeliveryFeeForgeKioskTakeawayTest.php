<?php

namespace Tests\Feature\Delivery;

use App\Enums\Ask;
use App\Enums\OrderType;
use App\Enums\PaymentGateway;
use App\Enums\Source;
use App\Enums\Status;
use App\Enums\TaxType;
use App\Models\Branch;
use App\Models\FrontendOrder;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\KioskMachine;
use App\Models\Tax;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Twin of DeliveryFeeForgePosTest for the KIOSK/web OrderRequest path.
 *
 * A TAKEAWAY ("à emporter") kiosk order has NO delivery concept (no driver,
 * no distance, no address). Yet OrderRequest::prepareForValidation only
 * recomputes/neutralizes `delivery_charge` inside its `if ($isDelivery)`
 * branches — a NON-delivery kiosk order's client-sent `delivery_charge`
 * (validated only `min:0`) passes straight through into the FrontendOrder
 * total and the signed NF525 Z-report.
 */
class DeliveryFeeForgeKioskTakeawayTest extends TestCase
{
    use RefreshDatabase;

    public function test_kiosk_takeaway_order_cannot_carry_a_phantom_delivery_charge(): void
    {
        config(['app.api_key' => 'test-api-key']);
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();

        $branch = Branch::factory()->create();
        $kioskUser = User::factory()->create(['branch_id' => $branch->id]);
        KioskMachine::factory()->create([
            'branch_id' => $branch->id,
            'user_id' => $kioskUser->id,
        ]);

        $tax = Tax::factory()->create([
            'tax_rate' => 0,
            'type' => TaxType::PERCENTAGE,
            'status' => Status::ACTIVE,
        ]);
        $category = ItemCategory::factory()->create(['status' => Status::ACTIVE]);
        $item = Item::factory()->create([
            'item_category_id' => $category->id,
            'tax_id' => $tax->id,
            'price' => 8.00,
            'status' => Status::ACTIVE,
        ]);

        $payload = [
            'branch_id' => $branch->id,
            'order_type' => OrderType::TAKEAWAY,
            'is_advance_order' => Ask::NO,
            'source' => Source::WEB,
            'payment_method' => PaymentGateway::CARD,
            'delivery_charge' => 50.00, // phantom fee on a takeaway order
            'items' => json_encode([[
                'item_id' => $item->id,
                'quantity' => 1,
                'item_variations' => [],
                'item_extras' => [],
            ]]),
        ];

        // Server signs its own quote (this is what the kiosk UI obtains first).
        $quote = $this->actingAs($kioskUser, 'sanctum')
            ->withHeader('x-api-key', 'test-api-key')
            ->postJson('/api/frontend/order/quote', $payload)
            ->assertOk()
            ->json('data');

        $response = $this->actingAs($kioskUser, 'sanctum')
            ->withHeader('x-api-key', 'test-api-key')
            ->withHeader('X-Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/frontend/order', $payload + [
                'quote_token' => $quote['quote_token'],
                'quote_signature' => $quote['signature'],
            ]);

        $this->assertContains($response->status(), [200, 201], $response->getContent());
        $order = FrontendOrder::withoutGlobalScopes()->findOrFail((int) $response->json('data.id'));

        // A takeaway kiosk order must NOT carry a delivery charge.
        $this->assertSame(
            0.0,
            (float) $order->delivery_charge,
            'Phantom delivery_charge entered a takeaway kiosk order fiscal total.'
        );
        $this->assertSame(8.0, (float) $order->total);
    }
}
