<?php

namespace Tests\Feature;

use App\Enums\Ask;
use App\Enums\OrderType;
use App\Enums\PosPaymentMethod;
use App\Enums\Status;
use App\Enums\TaxType;
use App\Models\Branch;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Tax;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Feature\Concerns\HasPosQuoteBinding;
use Tests\Feature\Pos\Traits\SeedsOpenCashDrawerSession;
use Tests\TestCase;

/**
 * Sentinelle SSOT : prouve que le POS authentifié recalcule total / sous-total / prix ligne
 * et n’accepte pas des montants client truqués (contrairement à PricingIntegrityTest qui ne
 * couvre que le rejet frontend).
 */
class PosPricingSsotProofTest extends TestCase
{
    use RefreshDatabase;
    use HasPosQuoteBinding;
    use SeedsOpenCashDrawerSession;

    public function test_pos_order_overwrites_client_forged_pricing_with_ssot(): void
    {
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();

        $branch = Branch::factory()->create();

        $posUser = User::factory()->create([
            'branch_id' => $branch->id,
            'email' => 'pos-ssot-proof@test.com',
            'password' => Hash::make('password123'),
        ]);
        $posUser->assignRole('POS Operator');
        $posUser->givePermissionTo('pos');
        // [Sprint H6 TEST-DEBT-001 2026-05-17] Sprint 1B requires an OPEN cash session for CASH.
        $this->seedOpenSessionFor($posUser, $branch);

        $customer = User::factory()->create([
            'branch_id' => $branch->id,
            'email' => 'customer-ssot-proof@test.com',
            'password' => Hash::make('password123'),
        ]);
        $customer->assignRole('Customer');

        $tax = Tax::factory()->create([
            'name' => 'No tax',
            'code' => 'ZERO-SSOT',
            'tax_rate' => 0,
            'type' => TaxType::PERCENTAGE,
            'status' => Status::ACTIVE,
        ]);

        $category = ItemCategory::factory()->create([
            'name' => 'SSOT Proof Category',
            'wizard_template' => 'simple',
            'has_menu' => false,
        ]);

        $item = Item::factory()->create([
            'item_category_id' => $category->id,
            'tax_id' => $tax->id,
            'name' => 'SSOT Proof Item',
            'price' => 10.00,
            'status' => Status::ACTIVE,
        ]);

        $this->actingAs($posUser, 'sanctum');

        $forgedSubtotal = 0.01;
        $forgedTotal = 0.01;
        $serverExpectedTotal = 20.00;

        $payload = [
            'token' => null,
            'customer_id' => $customer->id,
            'branch_id' => $branch->id,
            'subtotal' => $forgedSubtotal,
            'discount' => 0,
            'coupon_id' => 0,
            'total' => $forgedTotal,
            'order_type' => OrderType::TAKEAWAY,
            'is_advance_order' => Ask::NO,
            'source' => 1,
            'pos_payment_method' => PosPaymentMethod::CASH,
            'pos_received_amount' => $serverExpectedTotal,
            'items' => json_encode([
                [
                    'item_id' => $item->id,
                    'item_price' => 0.01,
                    'price' => 0.01,
                    'quantity' => 2,
                    'total_price' => 0.01,
                    'item_variations' => [],
                    'item_extras' => [],
                ],
            ]),
        ];

        $response = $this->postJson('/api/admin/pos', $this->payloadWithPosQuote($posUser, $payload, $serverExpectedTotal));

        $this->assertContains(
            $response->status(),
            [200, 201],
            'La commande POS authentifiée ne doit pas être rejetée en 422 pour des montants truqués ; statut reçu : '.$response->status().' — '.$response->getContent()
        );

        $order = Order::latest()->first();
        $this->assertNotNull($order);

        $this->assertEqualsWithDelta(
            20.00,
            (float) $order->total,
            0.02,
            'Le total persisté doit refléter le prix SSOT (2 × 10), pas le total client truqué.'
        );

        $line = OrderItem::where('order_id', $order->id)->first();
        $this->assertNotNull($line);
        $this->assertEqualsWithDelta(
            10.00,
            (float) $line->price,
            0.01,
            'Le prix unitaire ligne doit venir de la DB (10.00), pas du payload (0.01).'
        );
    }
}
