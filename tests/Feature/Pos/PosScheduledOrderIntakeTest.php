<?php

namespace Tests\Feature\Pos;

use App\Enums\OrderType;
use App\Enums\PosPaymentMethod;
use App\Enums\Status;
use App\Enums\TaxType;
use App\Models\Branch;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Order;
use App\Models\Tax;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;
use Tests\Feature\Concerns\HasPosQuoteBinding;
use Tests\TestCase;

/**
 * [W4-E5 SCHEDULED 2026-07-20] Intake CAISSE/téléphone des commandes programmées.
 *
 * Fondations (commit 1cde5bad7) : colonne additive `orders.scheduled_at`
 * (datetime NULL = ASAP) + SSOT lead cuisine KitchenReleaseRule /
 * config('kds.scheduled_lead_minutes').
 *
 * Cette lane prouve le chemin POS `posOrderStore` :
 *  - sans scheduled_at        → colonne NULL (ASAP), rien ne change ;
 *  - avec T+45 min            → valeur EXACTE persistée en DB ;
 *  - avec T+5 min (< lead 20) → 422 PosOrderRequest (message FR) ;
 *  - avec T+8 jours (> max 7) → 422 garde-fou faute de frappe ;
 *  - le stamp legacy delivery_time "HH:MM - HH:MM" reste posé DANS TOUS LES CAS
 *    (scheduled_at est EN PLUS, aucun consommateur legacy cassé).
 *
 * Harnais miroir de PosWalkinDeferredCreateTest (simulation_hardware ON — pas de
 * tiroir requis ; TVA 0 % item simple sans profil wizard ; quote scellé lié).
 */
class PosScheduledOrderIntakeTest extends TestCase
{
    use RefreshDatabase;
    use HasPosQuoteBinding;

    private const FROZEN_NOW = '2026-07-21 15:00:00';

    protected Branch $branch;
    protected User $customer;
    protected User $operator;
    protected Item $item;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();

        Config::set('pos.simulation_hardware', true);
        Config::set('fiscal.audit_secret', 'test-fiscal-secret-' . str_repeat('a', 40));
        // Lead cuisine déterministe (défaut config = 20, épinglé ici contre une dérive d'env).
        Config::set('kds.scheduled_lead_minutes', 20);

        // Horloge gelée : les bornes min (now+lead) / max (now+7 j) et la valeur
        // persistée deviennent des égalités exactes, pas des à-peu-près.
        $this->travelTo(Carbon::parse(self::FROZEN_NOW));

        $this->branch = Branch::factory()->create();

        $this->customer = User::factory()->create([
            'branch_id' => $this->branch->id,
            'password' => Hash::make('password'),
            'phone' => '0102030400',
        ]);
        $this->customer->assignRole('Customer');

        $this->operator = User::factory()->create([
            'branch_id' => $this->branch->id,
            'password' => Hash::make('password'),
            'phone' => '0102030401',
        ]);
        $this->operator->assignRole('POS Operator');

        $tax = Tax::factory()->create([
            'name' => 'TVA 0%', 'code' => 'TVA0', 'type' => TaxType::PERCENTAGE,
            'tax_rate' => 0.00, 'status' => Status::ACTIVE,
        ]);
        $cat = ItemCategory::factory()->create([
            'name' => 'Boissons', 'wizard_template' => 'simple', 'has_menu' => false,
        ]);
        $this->item = Item::factory()->create([
            'item_category_id' => $cat->id, 'tax_id' => $tax->id,
            'name' => 'Coca-Cola 33cl', 'price' => 1.50, 'status' => Status::ACTIVE,
        ]);
    }

    private function basePayload(array $overrides = []): array
    {
        return array_merge([
            'token' => null,
            'customer_id' => $this->customer->id,
            'branch_id' => $this->branch->id,
            'discount' => 0,
            'order_type' => OrderType::TAKEAWAY,
            'is_advance_order' => 0,
            'source' => 1,
            'pos_payment_method' => PosPaymentMethod::CASH,
            'pos_received_amount' => 10.00,
            'items' => json_encode([[
                'item_id' => $this->item->id,
                'item_price' => 1.50,
                'quantity' => 1,
                'total_price' => 1.50,
                'item_variations' => [],
                'item_extras' => [],
            ]]),
        ], $overrides);
    }

    private function postPosOrder(array $payload): \Illuminate\Testing\TestResponse
    {
        $this->actingAs($this->operator, 'sanctum');

        return $this->withHeader('x-api-key', config('app.api_key'))
            ->postJson('/api/admin/pos', $this->payloadWithPosQuote($this->operator, $payload));
    }

    public function test_pos_order_without_scheduled_at_stays_null_asap_and_stamps_legacy_delivery_time(): void
    {
        $response = $this->postPosOrder($this->basePayload());

        $response->assertStatus(201);
        $order = Order::withoutGlobalScopes()->find($response->json('data.id'));

        $this->assertNull($order->scheduled_at, 'sans scheduled_at la commande est ASAP (colonne NULL)');
        $this->assertMatchesRegularExpression(
            '/^\d{2}:\d{2} - \d{2}:\d{2}$/',
            (string) $order->delivery_time,
            'le stamp legacy delivery_time "HH:MM - HH:MM" reste posé sur le flux ASAP'
        );
    }

    public function test_pos_order_with_scheduled_at_t_plus_45_persists_exact_value_and_keeps_legacy_stamp(): void
    {
        $target = Carbon::parse(self::FROZEN_NOW)->addMinutes(45)->format('Y-m-d H:i:s'); // 2026-07-21 15:45:00

        $response = $this->postPosOrder($this->basePayload(['scheduled_at' => $target]));

        $response->assertStatus(201);
        $order = Order::withoutGlobalScopes()->find($response->json('data.id'));

        $this->assertNotNull($order->scheduled_at);
        $this->assertSame(
            $target,
            $order->scheduled_at->format('Y-m-d H:i:s'),
            'scheduled_at doit être persisté à la valeur EXACTE demandée par l\'opérateur'
        );
        $this->assertMatchesRegularExpression(
            '/^\d{2}:\d{2} - \d{2}:\d{2}$/',
            (string) $order->delivery_time,
            'delivery_time legacy TOUJOURS stampée EN PLUS de scheduled_at (aucun consommateur cassé)'
        );
    }

    public function test_pos_order_with_scheduled_at_t_plus_5_is_rejected_422_below_kitchen_lead(): void
    {
        $tooSoon = Carbon::parse(self::FROZEN_NOW)->addMinutes(5)->format('Y-m-d H:i:s');

        $response = $this->postPosOrder($this->basePayload(['scheduled_at' => $tooSoon]));

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['scheduled_at']);
        $this->assertSame(
            0,
            Order::withoutGlobalScopes()->count(),
            'aucune commande ne doit être créée quand la programmation est sous le lead cuisine'
        );
    }

    public function test_pos_order_with_scheduled_at_beyond_7_days_is_rejected_422(): void
    {
        $tooFar = Carbon::parse(self::FROZEN_NOW)->addDays(8)->format('Y-m-d H:i:s');

        $response = $this->postPosOrder($this->basePayload(['scheduled_at' => $tooFar]));

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['scheduled_at']);
        $this->assertSame(0, Order::withoutGlobalScopes()->count());
    }
}
