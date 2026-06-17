<?php

namespace Tests\Feature\Frontend;

use App\Enums\Ask;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentGateway;
use App\Enums\Source;
use App\Enums\Status;
use App\Models\Branch;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\KioskMachine;
use App\Models\Tax;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Smartisan\Settings\Facades\Settings;
use Tests\TestCase;

/**
 * [iter15-P0-08] /api/frontend/order/* state-mutating endpoints must require
 * the `kiosk:order` Sanctum ability. Enforcement now lives in
 * OrderRequest::authorize() (POST /frontend/order) and the existing
 * PaymentConfirmRequest::authorize() (POST /frontend/order/{id}/payment-confirm).
 *
 * Tokens with `['*']` (web customer login) and `['kiosk:order']` (kiosk login)
 * both satisfy `tokenCan('kiosk:order')`. Tokens with empty abilities or a
 * non-kiosk scope are rejected with 403.
 */
class OrderRouteAbilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (! file_exists(storage_path('installed'))) {
            touch(storage_path('installed'));
        }

        $this->seedMinimalSettings();
        config(['app.api_key' => 'test-api-key']);

        Settings::group('order_setup')->set([
            'order_setup_food_preparation_time' => 30,
            'order_setup_takeaway' => 5,
            'order_setup_delivery' => 5,
        ]);
    }

    /** @test */
    public function test_order_create_blocked_without_kiosk_order_ability_returns_403(): void
    {
        $context = $this->makeContext('no-ability');

        // Token has no abilities at all. tokenCan('kiosk:order') returns false,
        // so OrderRequest::authorize() refuses the request with 403.
        Sanctum::actingAs($context['user'], []);

        // [prod-finale 2026-06-17] idempotency-guarded route requires X-Idempotency-Key (frozen middleware; live UI sends it).
        $response = $this->withHeader('x-api-key', 'test-api-key')
            ->withHeader('X-Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/frontend/order', $this->orderPayload($context['item']));

        $response->assertStatus(403);
    }

    /** @test */
    public function test_order_create_allowed_with_kiosk_order_ability(): void
    {
        $context = $this->makeContext('kiosk-ability');

        Sanctum::actingAs($context['user'], ['kiosk:order']);

        $response = $this->withHeader('x-api-key', 'test-api-key')
            ->withHeader('X-Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/frontend/order', $this->orderPayload($context['item']));

        // The request must clear OrderRequest::authorize(). It may still fail
        // later for unrelated reasons (quote_token missing, validation, etc.)
        // — but it must NOT be 401/403. The thing under test is that the
        // ability gate passes for a legitimately scoped kiosk token.
        $this->assertNotSame(403, $response->status(), 'kiosk:order ability must satisfy authorize(). Body: '.$response->getContent());
        $this->assertNotSame(401, $response->status(), 'Authenticated kiosk token must not 401. Body: '.$response->getContent());
    }

    /** @test */
    public function test_order_create_allowed_with_wildcard_ability_backward_compat(): void
    {
        $context = $this->makeContext('wildcard');

        // Customer web LoginController issues tokens with ['*'] — must still work.
        Sanctum::actingAs($context['user'], ['*']);

        $response = $this->withHeader('x-api-key', 'test-api-key')
            ->withHeader('X-Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/frontend/order', $this->orderPayload($context['item']));

        $this->assertNotSame(403, $response->status(), 'Wildcard token must satisfy kiosk:order ability gate (backward compat). Body: '.$response->getContent());
        $this->assertNotSame(401, $response->status());
    }

    /** @test */
    public function test_payment_confirm_blocked_without_ability(): void
    {
        $context = $this->makeContext('payment-confirm');

        // Insert via raw query (avoid Eloquent observers / domain events on
        // FrontendOrder which can drag in mockery/doctrine autoload chains).
        // Use a fixed primary-key + insert() to dodge insertGetId()'s doctrine
        // schema introspection (problematic on PHP 8.2 in this vendor tree).
        $orderId = 424242;
        DB::table('orders')->insert([
            'id' => $orderId,
            'user_id' => $context['user']->id,
            'branch_id' => $context['branch']->id,
            'order_serial_no' => 'TEST-PC-001',
            'subtotal' => 10,
            'discount' => 0,
            'delivery_charge' => 0,
            'total' => 10,
            'order_type' => OrderType::TAKEAWAY,
            'is_advance_order' => Ask::NO,
            'payment_method' => PaymentGateway::CASH_ON_DELIVERY,
            'status' => OrderStatus::PENDING,
            'source' => Source::APP,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Sanctum::actingAs($context['user'], ['unrelated:scope']);

        $response = $this->withHeader('x-api-key', 'test-api-key')
            ->withHeader('X-Idempotency-Key', (string) Str::uuid())
            ->postJson(sprintf('/api/frontend/order/%d/payment-confirm', $orderId), [
                'transaction_id' => 'TX-TEST-001',
            ]);

        $response->assertStatus(403);
    }

    /**
     * @return array{branch: Branch, item: Item, user: User}
     */
    private function makeContext(string $suffix): array
    {
        $branch = Branch::factory()->create();

        $tax = Tax::create([
            'name' => 'TVA10 '.$suffix,
            'code' => 'TVA10-'.$suffix,
            'tax_rate' => 10,
            'type' => 2,
            'status' => 1,
        ]);

        $category = ItemCategory::forceCreate([
            'name' => 'Cat '.$suffix,
            'slug' => 'cat-'.$suffix,
            'status' => Status::ACTIVE,
        ]);

        $item = Item::forceCreate([
            'name' => 'Burger '.$suffix,
            'slug' => 'burger-'.$suffix,
            'price' => 10.00,
            'status' => Status::ACTIVE,
            'item_category_id' => $category->id,
            'tax_id' => $tax->id,
        ]);

        $user = User::factory()->create([
            'username' => 'user_'.$suffix,
            'branch_id' => $branch->id,
            'status' => Status::ACTIVE,
        ]);

        KioskMachine::factory()->create([
            'machine_id' => 'machine-'.$suffix,
            'branch_id' => $branch->id,
            'user_id' => $user->id,
            'username' => 'machine_user_'.$suffix,
            'password' => bcrypt('secret'),
            'is_login' => Ask::NO,
            'status' => Status::ACTIVE,
        ]);

        return compact('branch', 'item', 'user');
    }

    /**
     * @return array<string, mixed>
     */
    private function orderPayload(Item $item): array
    {
        return [
            'order_type' => OrderType::TAKEAWAY,
            'is_advance_order' => Ask::NO,
            'source' => Source::APP,
            'payment_method' => PaymentGateway::CASH_ON_DELIVERY,
            'items' => json_encode([[
                'item_id' => $item->id,
                'quantity' => 1,
            ]]),
        ];
    }
}
