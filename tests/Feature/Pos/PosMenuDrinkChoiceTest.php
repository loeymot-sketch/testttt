<?php

namespace Tests\Feature\Pos;

use App\Enums\OrderType;
use App\Enums\PosPaymentMethod;
use App\Enums\Status;
use App\Enums\TaxType;
use App\Models\Branch;
use App\Models\Item;
use App\Models\ItemAddon;
use App\Models\ItemCategory;
use App\Models\Tax;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;
use Tests\Feature\Concerns\HasPosQuoteBinding;
use Tests\TestCase;

/**
 * [LOCK_POSWIZARD_KIOSKWIZARD_OWNER8 2026-07-06] W2 — choix de boisson au wizard
 * caisse via le REPLI CATALOGUE (modèle borne) : la boisson choisie voyage en
 * TEXTE (« BOISSON: Hawaï 33cl ») dans l'instruction de l'item — JAMAIS comme
 * ligne facturée.
 *
 * Acceptance LOCK §6 (SSOT backend) :
 *  - devis « Menu Complet » AVANT = APRÈS choix boisson (9,90 € Cayenne :
 *    7,40 + 2,50 menu) — l'instruction est price-inerte
 *  - la commande créée porte l'instruction BOISSON: sur l'order item
 *    (canal lu par le ticket cuisine OrderReceiptEscPosRenderer + le /kds)
 */
class PosMenuDrinkChoiceTest extends TestCase
{
    use RefreshDatabase;
    use HasPosQuoteBinding;

    protected Branch $branch;
    protected User $customer;
    protected User $operator;
    protected Item $cayenne;
    protected ItemAddon $menuAddon;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();

        Config::set('pos.simulation_hardware', true);
        Config::set('fiscal.audit_secret', 'test-fiscal-secret-'.str_repeat('a', 40));

        $this->branch = Branch::factory()->create();
        $this->customer = User::factory()->create(['branch_id' => $this->branch->id, 'password' => Hash::make('password'), 'phone' => '0102030510']);
        $this->customer->assignRole('Customer');
        $this->operator = User::factory()->create(['branch_id' => $this->branch->id, 'password' => Hash::make('password'), 'phone' => '0102030511']);
        $this->operator->assignRole('POS Operator');

        $tax = Tax::factory()->create(['name' => 'TVA 0%', 'code' => 'TVA0', 'type' => TaxType::PERCENTAGE, 'tax_rate' => 0.00, 'status' => Status::ACTIVE]);
        $cat = ItemCategory::factory()->create(['name' => 'Sandwichs']);

        $this->cayenne = Item::factory()->create([
            'item_category_id' => $cat->id,
            'tax_id'           => $tax->id,
            'name'             => 'Cayenne',
            'price'            => 7.40,
            'status'           => Status::ACTIVE,
        ]);

        // Addon formule générique V1 réel : « Menu (Frites + Boisson) » 2,50 €
        $menuItem = Item::factory()->create([
            'item_category_id' => $cat->id,
            'tax_id'           => $tax->id,
            'name'             => 'Menu (Frites + Boisson)',
            'price'            => 2.50,
            'status'           => Status::ACTIVE,
        ]);
        $this->menuAddon = ItemAddon::create([
            'item_id'       => $this->cayenne->id,
            'addon_item_id' => $menuItem->id,
            'role'          => 'menu_component',
        ]);
    }

    /** @return array<string, mixed> */
    private function payload(?string $instruction, array $overrides = []): array
    {
        $itemLine = [
            'item_id'         => $this->cayenne->id,
            'item_price'      => 7.40,
            'quantity'        => 1,
            'total_price'     => 9.90,
            'item_variations' => [],
            'item_extras'     => [],
            'item_addons'     => [
                ['id' => $this->menuAddon->id, 'quantity' => 1, 'role' => 'menu_component'],
            ],
        ];
        if ($instruction !== null) {
            $itemLine['instruction'] = $instruction;
        }

        return array_merge([
            'token'               => null,
            'customer_id'         => $this->customer->id,
            'branch_id'           => $this->branch->id,
            'discount'            => 0,
            'order_type'          => OrderType::TAKEAWAY,
            'is_advance_order'    => 0,
            'source'              => 1,
            'pos_payment_method'  => PosPaymentMethod::CASH,
            'pos_received_amount' => 20.00,
            'items'               => json_encode([$itemLine]),
        ], $overrides);
    }

    private function quoteTotal(array $payload): float
    {
        $data = $this->actingAs($this->operator, 'sanctum')
            ->withHeader('x-api-key', $this->quoteBindingApiKey())
            ->postJson('/api/admin/pos/quote', $payload)
            ->assertOk()
            ->json('data');

        return (float) $data['total_ttc'];
    }

    public function test_menu_complet_quote_total_identical_with_and_without_drink_instruction(): void
    {
        $without = $this->quoteTotal($this->payload(null));
        $with = $this->quoteTotal($this->payload(
            "CAYENNE\nFORMULE: Menu (Frites + Boisson)\nBOISSON: Hawaï 33cl"
        ));

        $this->assertEqualsWithDelta(9.90, $without, 0.001, 'Menu Complet Cayenne = 7,40 + 2,50');
        $this->assertSame($without, $with, 'le choix boisson (instruction) est PRICE-INERTE');
    }

    public function test_order_created_with_drink_instruction_persists_it_and_total_unchanged(): void
    {
        $this->actingAs($this->operator, 'sanctum');
        $instruction = "CAYENNE\nFORMULE: Menu (Frites + Boisson)\nBOISSON: Hawaï 33cl";

        $response = $this->withHeader('x-api-key', config('app.api_key'))
            ->postJson('/api/admin/pos', $this->payloadWithPosQuote($this->operator, $this->payload($instruction)));

        $response->assertStatus(201);
        $orderId = $response->json('data.id');
        $this->assertNotNull($orderId);

        $order = \App\Models\Order::withoutGlobalScopes()->findOrFail($orderId);
        $this->assertEqualsWithDelta(9.90, (float) $order->total, 0.001, 'total 9,90 € inchangé par la boisson');

        $orderItem = $order->orderItems()->withoutGlobalScopes()->firstOrFail();
        $this->assertStringContainsString('BOISSON: Hawaï 33cl', (string) $orderItem->instruction,
            'l\'instruction BOISSON: doit atteindre l\'order item (canal ticket cuisine + KDS)');
    }

    public function test_drink_instruction_cannot_inflate_or_deflate_total_versus_clean_order(): void
    {
        $this->actingAs($this->operator, 'sanctum');

        $clean = $this->withHeader('x-api-key', config('app.api_key'))
            ->postJson('/api/admin/pos', $this->payloadWithPosQuote($this->operator, $this->payload(null)));
        $clean->assertStatus(201);

        $withDrink = $this->withHeader('x-api-key', config('app.api_key'))
            ->postJson('/api/admin/pos', $this->payloadWithPosQuote($this->operator, $this->payload("BOISSON: Capri-Sun")));
        $withDrink->assertStatus(201);

        $totalClean = (float) \App\Models\Order::withoutGlobalScopes()->find($clean->json('data.id'))->total;
        $totalDrink = (float) \App\Models\Order::withoutGlobalScopes()->find($withDrink->json('data.id'))->total;

        $this->assertSame($totalClean, $totalDrink, 'même total avec ou sans BOISSON: (0 €, modèle borne)');
    }
}
