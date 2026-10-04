<?php

namespace Tests\Feature;

use App\Enums\Ask;
use App\Enums\OrderType;
use App\Enums\PosPaymentMethod;
use App\Enums\Source;
use App\Enums\Status;
use App\Enums\TaxType;
use App\Models\Branch;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Tax;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QuoteDiscountAuthoritativeTest extends TestCase
{
    use RefreshDatabase;

    public function test_quote_discount_uses_backend_subtotal_not_forged_client_total(): void
    {
        // [AUDIT CAISSE 2026-09-29] Ce banc vérifie que la remise est calculée sur le
        // sous-total SERVEUR, pas sur un total client forgé. Il suppose qu'une remise est
        // acceptée au devis : depuis que le coupe-circuit V1 des remises manuelles
        // (défaut false) s'applique AU DEVIS, il faut l'ouvrir ici — c'est bien le calcul
        // qu'on teste, pas le coupe-circuit (couvert par ManualDiscountDisabledV1SentinelTest).
        config(['pos.manual_discount_enabled' => true]);
        [$operator, $payload] = $this->fixture();

        $payload['subtotal'] = 1000.00;
        $payload['discount'] = 5.00;
        $payload['total'] = 995.00;

        $data = $this->actingAs($operator, 'sanctum')
            ->postJson('/api/admin/pos/quote', $payload)
            ->assertOk()
            ->json('data');

        $this->assertEqualsWithDelta(100.00, $data['subtotal'], 0.001);
        $this->assertEqualsWithDelta(5.00, $data['discount'], 0.001);
        $this->assertEqualsWithDelta(95.00, $data['total_ttc'], 0.001);
    }

    /**
     * @return array{0: User, 1: array<string, mixed>}
     */
    private function fixture(): array
    {
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();

        $branch = Branch::factory()->create();
        $operator = User::factory()->create(['branch_id' => $branch->id]);
        $operator->assignRole('POS Operator');
        $operator->givePermissionTo(['pos', 'pos-discount-up-to-10']);
        $customer = User::factory()->create(['branch_id' => $branch->id]);
        $customer->assignRole('Customer');

        $tax = Tax::factory()->create([
            'tax_rate' => 0,
            'type' => TaxType::PERCENTAGE,
            'status' => Status::ACTIVE,
        ]);
        $category = ItemCategory::factory()->create(['status' => Status::ACTIVE]);
        $item = Item::factory()->create([
            'item_category_id' => $category->id,
            'tax_id' => $tax->id,
            'price' => 100.00,
            'status' => Status::ACTIVE,
        ]);

        return [$operator, [
            'token' => null,
            'customer_id' => $customer->id,
            'branch_id' => $branch->id,
            'subtotal' => 0,
            'discount' => 0,
            'coupon_id' => 0,
            'total' => 0,
            'order_type' => OrderType::TAKEAWAY,
            'is_advance_order' => Ask::NO,
            'source' => Source::POS,
            'pos_payment_method' => PosPaymentMethod::CASH,
            'items' => json_encode([[
                'item_id' => $item->id,
                'quantity' => 1,
                'item_variations' => [],
                'item_extras' => [],
            ]]),
        ]];
    }
}
