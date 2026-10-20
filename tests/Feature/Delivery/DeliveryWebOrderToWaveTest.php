<?php

namespace Tests\Feature\Delivery;

use App\Enums\Ask;
use App\Enums\OrderType;
use App\Enums\PaymentGateway;
use App\Enums\Source;
use App\Enums\Status;
use App\Enums\TaxType;
use App\Models\Address;
use App\Models\Branch;
use App\Models\DeliveryStop;
use App\Models\FrontendOrder;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\OrderAddress;
use App\Models\Tax;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Smartisan\Settings\Facades\Settings;
use Tests\TestCase;

/**
 * Bout en bout : une commande web de livraison entre dans la bonne tournée, au bon prix, avec le créneau
 * choisi par le client — et un créneau invalide est refusé AVANT le paiement.
 *
 * @group delivery
 */
class DeliveryWebOrderToWaveTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;
    private User $customer;
    private Address $address;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMinimalSettings();
        config(['app.api_key' => 'test-api-key']);
        Carbon::setTestNow(Carbon::parse('2026-10-20 19:02:00'));
        Settings::group('order_setup')->set([
            'order_setup_food_preparation_time' => 30, 'order_setup_delivery' => 5, 'order_setup_takeaway' => 5,
        ]);
        $this->branch = Branch::factory()->create(['latitude' => '50.4215667', 'longitude' => '2.9549060']);
        $this->customer = User::factory()->create(['branch_id' => $this->branch->id, 'status' => Status::ACTIVE]);
        $this->address = Address::query()->create([
            'user_id' => $this->customer->id, 'label' => 'Domicile', 'address' => '10 Rue Test, Hénin-Beaumont',
            'latitude' => '50.4300', 'longitude' => '2.9600', 'city' => 'Hénin-Beaumont', 'postal_code' => '62110', 'geocode_status' => 'OK',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function place(array $extra = [])
    {
        $item = $this->activeItem();

        return $this->actingAs($this->customer, 'sanctum')->withHeader('x-api-key', 'test-api-key')
            ->postJson('/api/frontend/order', $extra + [
                'branch_id' => $this->branch->id, 'subtotal' => 10, 'discount' => 0, 'delivery_charge' => 0.01,
                'total' => 1, 'order_type' => (string) OrderType::DELIVERY, 'is_advance_order' => Ask::NO,
                'address_id' => $this->address->id, 'delivery_time' => 'ASAP', 'source' => Source::WEB,
                'payment_method' => PaymentGateway::CASH_ON_DELIVERY,
                'items' => json_encode([['item_id' => $item->id, 'quantity' => 1, 'item_variations' => [], 'item_extras' => []]]),
            ]);
    }

    public function test_asap_web_delivery_is_priced_3_90_and_joins_the_next_wave(): void
    {
        $r = $this->place();
        $this->assertContains($r->status(), [200, 201], $r->getContent());

        $order = FrontendOrder::withoutGlobalScopes()->findOrFail((int) $r->json('data.id'));
        $this->assertSame(3.9, (float) $order->delivery_charge);
        $this->assertSame('home', $order->delivery_tier);
        $this->assertEqualsWithDelta(1.2, (float) $order->delivery_distance_km, 0.6);

        $stop = DeliveryStop::query()->where('order_id', $order->id)->first();
        $this->assertNotNull($stop, 'La commande doit être rattachée à une tournée.');
        $this->assertSame('19:30', $stop->wave_at->format('H:i'));
        $this->assertFalse($stop->slot_fixed);
        $this->assertSame('cash', $stop->collect_method);
        $this->assertSame(1390, $stop->amount_due_cents, '10,00 € + 3,90 € à encaisser à la porte.');
        $this->assertSame('Hénin-Beaumont', OrderAddress::where('order_id', $order->id)->value('city'));
    }

    public function test_customer_chosen_slot_is_kept(): void
    {
        $r = $this->place(['delivery_slot_at' => '2026-10-20 20:30:00']);
        $this->assertContains($r->status(), [200, 201], $r->getContent());

        $stop = DeliveryStop::query()->where('order_id', (int) $r->json('data.id'))->firstOrFail();
        $this->assertSame('20:30', $stop->wave_at->format('H:i'));
        $this->assertTrue($stop->slot_fixed);
    }

    public function test_invalid_slot_is_refused_before_any_order_exists(): void
    {
        foreach (['2026-10-20 19:45:00', '2026-10-20 19:00:00', '2026-10-20 14:00:00'] as $bad) {
            $this->place(['delivery_slot_at' => $bad])->assertStatus(422)->assertJsonValidationErrors('delivery_slot_at');
        }
        $this->assertSame(0, FrontendOrder::withoutGlobalScopes()->count());
        $this->assertSame(0, DeliveryStop::query()->count());
    }

    public function test_slot_on_a_takeaway_order_is_ignored(): void
    {
        $r = $this->place(['order_type' => (string) OrderType::TAKEAWAY, 'delivery_slot_at' => '2026-10-20 20:30:00', 'delivery_tier' => 'home', 'address_id' => null, 'payment_method' => PaymentGateway::CARD]);
        $this->assertContains($r->status(), [200, 201], $r->getContent());
        $this->assertSame(0, DeliveryStop::query()->count());
        $order = FrontendOrder::withoutGlobalScopes()->findOrFail((int) $r->json('data.id'));
        $this->assertNull($order->delivery_tier, 'Un palier de livraison forgé sur une commande à emporter ne doit pas survivre.');
    }

    private function activeItem(): Item
    {
        $tax = Tax::factory()->create([
            'tax_rate' => 0,
            'type' => TaxType::PERCENTAGE,
            'status' => Status::ACTIVE,
        ]);
        $category = ItemCategory::factory()->create([
            'status' => Status::ACTIVE,
        ]);

        return Item::factory()->create([
            'item_category_id' => $category->id,
            'tax_id' => $tax->id,
            'price' => 10.00,
            'status' => Status::ACTIVE,
        ]);
    }
}
