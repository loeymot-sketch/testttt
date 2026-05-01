<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentStatus;
use App\Enums\PosPaymentMethod;
use App\Enums\Source;
use App\Enums\Status;
use App\Enums\TaxType;
use App\Models\Branch;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Order;
use App\Models\Tax;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\HasPosQuoteBinding;
use Tests\TestCase;

/**
 * [P2] POS accepte le mode titre-restaurant (même code numérique que kiosk PaymentGateway::TICKET_RESTAURANT).
 */
class PosTicketRestaurantPaymentTest extends TestCase
{
    use RefreshDatabase;
    use HasPosQuoteBinding;

    private function apiKey(): string
    {
        return config('app.api_key', env('MIX_API_KEY', 'test-api-key'));
    }

    public function test_pos_order_accepts_ticket_restaurant_with_reference_note(): void
    {
        $this->seedMinimalSettings();
        $this->seedSpatieRoles();

        $tax = Tax::create([
            'name' => 'TVA 10%',
            'code' => 'TVA10TR',
            'tax_rate' => 10,
            'type' => TaxType::PERCENTAGE,
            'status' => Status::ACTIVE,
        ]);

        $category = ItemCategory::create([
            'name' => 'Cat TR',
            'slug' => 'cat-tr',
            'status' => Status::ACTIVE,
        ]);

        $branch = Branch::factory()->create();
        $item = Item::create([
            'name' => 'Plat TR',
            'slug' => 'plat-tr',
            'price' => 20.00,
            'tax_id' => $tax->id,
            'status' => Status::ACTIVE,
            'item_category_id' => $category->id,
        ]);

        $cashier = User::factory()->create(['branch_id' => $branch->id]);
        $cashier->assignRole('Admin');

        $payload = [
            'customer_id' => $cashier->id,
            'branch_id' => $branch->id,
            'total' => 22.00,
            'order_type' => OrderType::TAKEAWAY,
            'is_advance_order' => 0,
            'source' => Source::POS,
            'pos_payment_method' => PosPaymentMethod::TICKET_RESTAURANT,
            'pos_payment_note' => 'TR-998877',
            'items' => json_encode([
                ['item_id' => $item->id, 'quantity' => 1, 'item_variations' => [], 'item_extras' => []],
            ]),
        ];

        $response = $this->actingAs($cashier)
            ->withHeader('x-api-key', $this->apiKey())
            ->postJson('/api/admin/pos', $this->payloadWithPosQuote($cashier, $payload));

        $response->assertStatus(201);
        $orderId = (int) ($response->json('data.id') ?? 0);
        $this->assertGreaterThan(0, $orderId);

        $order = Order::query()->findOrFail($orderId);
        $this->assertSame(PosPaymentMethod::TICKET_RESTAURANT, (int) $order->pos_payment_method);
        $this->assertSame('TR-998877', (string) $order->pos_payment_note);
        $this->assertSame(PaymentStatus::PAID, (int) $order->payment_status);
        $this->assertSame(OrderStatus::ACCEPT, (int) $order->status);
    }
}
