<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Tax;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\User;
use App\Models\Branch;
use App\Models\Order;
use App\Enums\TaxType;
use App\Enums\Status;
use App\Enums\OrderType;
use App\Enums\Source;
use App\Enums\PosPaymentMethod;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Feature\Concerns\HasPosQuoteBinding;
use Tests\Feature\Pos\Traits\SeedsOpenCashDrawerSession;

class PosOrderTaxTest extends TestCase
{
    use RefreshDatabase;
    use HasPosQuoteBinding;
    use SeedsOpenCashDrawerSession;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMinimalSettings();
        $this->seedSpatieRoles();
    }

    private function apiKey(): string
    {
        return config('app.api_key', env('MIX_API_KEY', 'test-api-key'));
    }

    public function test_pos_order_tax_is_calculated_from_db(): void
    {
        // Créer une taxe de 10%
        $tax = Tax::create([
            'name' => 'TVA 10%',
            'code' => 'TVA10',
            'tax_rate' => 10,
            'type' => TaxType::PERCENTAGE,
            'status' => Status::ACTIVE,
        ]);

        // Créer une catégorie d'item
        $category = ItemCategory::create([
            'name' => 'Test Category',
            'slug' => 'test-category',
            'status' => Status::ACTIVE,
        ]);

        // Créer un item avec cette taxe
        $branch = \Database\Factories\BranchFactory::new()->create();
        $item = Item::create([
            'name' => 'Test Item',
            'slug' => 'test-item',
            'price' => 10.00,
            'tax_id' => $tax->id,
            'status' => Status::ACTIVE,
            'item_category_id' => $category->id,
        ]);

        // Créer un admin
        $admin = \Database\Factories\UserFactory::new()->create(['branch_id' => $branch->id]);
        $admin->assignRole('Admin');
        // [Sprint H6 TEST-DEBT-001 2026-05-17] Sprint 1B requires an OPEN cash session for CASH.
        $this->seedOpenSessionFor($admin, $branch);

        // Passer une commande POS
        $payload = [
            'customer_id' => $admin->id,
            'branch_id' => $branch->id,
            'subtotal' => 10.00,
            'total' => 11.00,
            'order_type' => OrderType::TAKEAWAY,
            'is_advance_order' => 0,
            'source' => Source::POS,
            'pos_payment_method' => PosPaymentMethod::CASH,
            'pos_received_amount' => 11.00,
            'items' => json_encode([
                ['item_id' => $item->id, 'quantity' => 1, 'item_variations' => [], 'item_extras' => []]
            ]),
        ];
        // [prod-finale 2026-06-17] idempotency-guarded route requires X-Idempotency-Key (frozen middleware; live UI sends it).
        $response = $this->actingAs($admin)
            ->withHeader('x-api-key', $this->apiKey())
            ->withHeader('X-Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/admin/pos', $this->payloadWithPosQuote($admin, $payload));

        $response->assertStatus(201);
        $data = $response->json();

        // La taxe doit être > 0 (vérifier via total_tax_currency_price ou order_items.tax)
        $totalTax = $data['data']['total_tax_currency_price'] ?? null;
        $itemTax = $data['data']['order_items'][0]['tax_currency_amount'] ?? null;

        // Au moins un des deux doit indiquer une taxe > 0
        $hasTax = (strpos($totalTax ?? '', '0.00') === false && $totalTax !== null) ||
                  (strpos($itemTax ?? '', '0.00') === false && $itemTax !== null);

        $this->assertTrue($hasTax,
            'La taxe doit être > 0 quand une taxe est configurée sur l\'item. ' .
            'total_tax_currency_price: ' . ($totalTax ?? 'null') . ', ' .
            'item_tax: ' . ($itemTax ?? 'null'));
    }

    public function test_payment_status_change_requires_role(): void
    {
        // Créer une taxe
        $tax = Tax::create([
            'name' => 'TVA 10%',
            'code' => 'TVA10',
            'tax_rate' => 10,
            'type' => TaxType::PERCENTAGE,
            'status' => Status::ACTIVE,
        ]);

        // Créer une catégorie d'item
        $category = ItemCategory::create([
            'name' => 'Test Category',
            'slug' => 'test-category',
            'status' => Status::ACTIVE,
        ]);

        // Créer un item et une commande existante
        $branch = \Database\Factories\BranchFactory::new()->create();
        $item = Item::create([
            'name' => 'Test Item',
            'slug' => 'test-item',
            'price' => 10.00,
            'tax_id' => $tax->id,
            'status' => Status::ACTIVE,
            'item_category_id' => $category->id,
        ]);

        // Créer un admin pour créer la commande
        $admin = \Database\Factories\UserFactory::new()->create(['branch_id' => $branch->id]);
        $admin->assignRole('Admin');
        // [Sprint H6 TEST-DEBT-001 2026-05-17] Sprint 1B requires an OPEN cash session for CASH.
        $this->seedOpenSessionFor($admin, $branch);

        // Créer une commande POS
        $payload = [
            'customer_id' => $admin->id,
            'branch_id' => $branch->id,
            'subtotal' => 10.00,
            'total' => 11.00,
            'order_type' => OrderType::TAKEAWAY,
            'is_advance_order' => 0,
            'source' => Source::POS,
            'pos_payment_method' => PosPaymentMethod::CASH,
            'pos_received_amount' => 11.00,
            'items' => json_encode([
                ['item_id' => $item->id, 'quantity' => 1, 'item_variations' => [], 'item_extras' => []]
            ]),
        ];
        // [prod-finale 2026-06-17] idempotency-guarded route requires X-Idempotency-Key (frozen middleware; live UI sends it).
        $response = $this->actingAs($admin)
            ->withHeader('x-api-key', $this->apiKey())
            ->withHeader('X-Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/admin/pos', $this->payloadWithPosQuote($admin, $payload));
        $response->assertStatus(201);
        $orderId = $response->json('data.id');

        // Maintenant tester avec un utilisateur sans rôle
        $user = \Database\Factories\UserFactory::new()->create(['branch_id' => $branch->id]);

        // [prod-finale 2026-06-17] change-payment-status/* is idempotency-guarded (frozen middleware runs
        // BEFORE the controller authz); the 403 is raised inside the controller, so the header is required
        // to reach it — without it the request 422s on the missing key and masks the 403 under test.
        $response2 = $this->actingAs($user)
            ->withHeader('x-api-key', $this->apiKey())
            ->withHeader('X-Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/admin/pos-order/change-payment-status/{$orderId}", [
                'payment_status' => PaymentStatus::UNPAID,
            ]);

        $response2->assertStatus(403);
    }
}
