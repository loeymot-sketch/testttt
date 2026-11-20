<?php

namespace Tests\Feature\Pos;

use App\Enums\OrderType;
use App\Enums\PosPaymentMethod;
use App\Enums\Status;
use App\Enums\TaxType;
use App\Models\Address;
use App\Models\Branch;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Order;
use App\Models\Tax;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;
use Tests\Feature\Concerns\HasPosQuoteBinding;
use Tests\TestCase;

/**
 * [VG-2026-10-21 M4-101 · reste vague 1] « Commande téléphone » + LIVRAISON, refusé au SERVEUR.
 *
 * L'écran caisse refuse cette combinaison depuis 55189537f (posTelephoneEnLivraison.spec.js), mais l'API
 * `POST /api/admin/pos` l'acceptait : une livraison enregistrée comme commande téléphone part dans la file
 * « À encaisser au comptoir », et `counter-collect/purge-phone-today` (client non venu) l'ANNULERAIT alors
 * que le client attend chez lui. Le parcours n'est pas prévu par docs/LIVRAISON.md → 422 explicite.
 */
class PhoneOrderDeliveryRefusedTest extends TestCase
{
    use RefreshDatabase;
    use HasPosQuoteBinding;

    private Branch $branch;
    private User $customer;
    private User $operator;
    private Item $item;
    private Address $address;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
        // La livraison est DÉSACTIVÉE par défaut (coming-soon) : ce test exerce la fonctionnalité derrière son verrou.
        \Smartisan\Settings\Facades\Settings::group('order_setup')->set(['order_setup_delivery' => \App\Enums\Activity::ENABLE]);

        Config::set('pos.simulation_hardware', true);
        Config::set('pos.walkin_route_to_counter', false);
        Config::set('fiscal.audit_secret', 'test-fiscal-secret-' . str_repeat('a', 40));

        $this->branch = Branch::factory()->create(['latitude' => '48.8566', 'longitude' => '2.3522']);

        $this->customer = User::factory()->create([
            'branch_id' => $this->branch->id, 'password' => Hash::make('password'), 'phone' => '0612345678',
        ]);
        $this->customer->assignRole('Customer');

        $this->operator = User::factory()->create([
            'branch_id' => $this->branch->id, 'password' => Hash::make('password'), 'phone' => '0102030401',
        ]);
        $this->operator->assignRole('POS Operator');

        $this->address = Address::query()->create([
            'user_id' => $this->customer->id, 'label' => 'Domicile', 'address' => '10 Rue de Test',
            'latitude' => '48.8606', 'longitude' => '2.3522', 'city' => 'Hénin-Beaumont', 'postal_code' => '62110',
            'geocode_status' => 'OK',
        ]);

        $tax = Tax::factory()->create([
            'name' => 'TVA 0%', 'code' => 'TVA0', 'type' => TaxType::PERCENTAGE, 'tax_rate' => 0.00, 'status' => Status::ACTIVE,
        ]);
        $cat = ItemCategory::factory()->create(['name' => 'Boissons', 'wizard_template' => 'simple', 'has_menu' => false]);
        $this->item = Item::factory()->create([
            'item_category_id' => $cat->id, 'tax_id' => $tax->id, 'name' => 'Eau Plate', 'price' => 1.00, 'status' => Status::ACTIVE,
        ]);
    }

    /** @return array<string, mixed> */
    private function payload(int $orderType, bool $phoneOrder): array
    {
        return [
            'token' => '7',
            'customer_id' => $this->customer->id,
            'branch_id' => $this->branch->id,
            'discount' => 0,
            'order_type' => $orderType,
            'address_id' => $orderType === OrderType::DELIVERY ? $this->address->id : null,
            'is_advance_order' => 0,
            'source' => 1,
            'pos_payment_method' => PosPaymentMethod::COUNTER_DEFERRED,
            'pos_received_amount' => null,
            'phone_order' => $phoneOrder,
            'pos_customer_name' => 'Client Livraison',
            'pos_customer_phone' => '06 12 34 56 78',
            'items' => json_encode([[
                'item_id' => $this->item->id, 'item_price' => 1.00, 'quantity' => 1, 'total_price' => 1.00,
                'item_variations' => [], 'item_extras' => [],
            ]]),
        ];
    }

    private function postPos(array $payload)
    {
        // Exactement ce que fait la caisse : un devis scellé /admin/pos/quote d'abord.
        $payload = $this->payloadWithPosQuote($this->operator, $payload);
        $this->actingAs($this->operator, 'sanctum');

        return $this->withHeader('x-api-key', config('app.api_key'))
            ->withHeader('X-Idempotency-Key', 'phone-liv-' . uniqid())
            ->postJson('/api/admin/pos', $payload);
    }

    /** @test */
    public function phone_order_in_delivery_is_refused_with_a_clear_message_and_creates_nothing(): void
    {
        $before = Order::withoutGlobalScopes()->count();

        $response = $this->postPos($this->payload(OrderType::DELIVERY, true));

        $response->assertStatus(422)->assertJsonValidationErrors(['phone_order']);
        $this->assertStringContainsString(
            'livraison',
            mb_strtolower(implode(' ', (array) $response->json('errors.phone_order'))),
            'Le message doit dire pourquoi : une commande téléphone ne peut pas être une livraison.'
        );
        $this->assertSame($before, Order::withoutGlobalScopes()->count(), 'Aucune commande ne doit être créée.');
    }

    /** @test — non-régression : la commande téléphone À EMPORTER n'est pas touchée par la garde. */
    public function phone_order_takeaway_is_not_refused_by_the_guard(): void
    {
        $response = $this->postPos($this->payload(OrderType::TAKEAWAY, true));

        $this->assertArrayNotHasKey('phone_order', (array) $response->json('errors'), 'La garde ne vise que la livraison.');
    }
}
