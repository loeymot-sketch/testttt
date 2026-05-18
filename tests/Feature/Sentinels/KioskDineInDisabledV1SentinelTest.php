<?php

namespace Tests\Feature\Sentinels;

use App\Enums\Ask;
use App\Enums\OrderType;
use App\Enums\Source;
use App\Enums\Status;
use App\Models\Branch;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\KioskMachine;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Smartisan\Settings\Facades\Settings;
use Tests\TestCase;

/**
 * @FK-ID WAVE5-KIOSK-001
 * @source reports/execution/audit_2026-05-07/FINAL_REPORT_v3_WAVE5_CONSOLIDATED_2026-05-08.md §1.3
 *
 * V1 ships in "à emporter only" mode (memory feedback_v1_dine_in_disabled_2026-05-06).
 * `pos_dine_in_enabled = false` is enforced server-side for the POS path via
 * PosOrderRequest::withValidator (DINING_TABLE rejected). The kiosk path used to
 * bypass *all* order_type restrictions on a kiosk token (OrderRequest:150-152),
 * so a kiosk client could still POST `order_type=KIOSK(25)` ("Sur place" in
 * KioskCartComponent.vue) and create a dine-in order silently. This sentinel
 * locks the matching backend gate on the kiosk path.
 *
 * 4 cases:
 *   1. Dine-in disabled + kiosk token + order_type=KIOSK → 422
 *   2. Dine-in disabled + kiosk token + order_type=DINING_TABLE (defense-in-depth) → 422
 *   3. Dine-in disabled + kiosk token + order_type=TAKEAWAY → success (à emporter allowed)
 *   4. Dine-in enabled  + kiosk token + order_type=KIOSK → success (forward-compat for V1.x)
 */
class KioskDineInDisabledV1SentinelTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;
    private User $kioskUser;
    private Item $item;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedMinimalSettings();
        $this->seedSpatieRoles();
        config(['app.api_key' => 'test-api-key']);
        $this->withHeaders([
            'x-api-key' => 'test-api-key',
            'Accept'    => 'application/json',
        ]);

        $this->branch = Branch::forceCreate([
            'name'     => 'Sentinel Kiosk Branch',
            'city'     => 'Paris',
            'state'    => 'IDF',
            'zip_code' => '75000',
            'address'  => '1 rue Sentinel',
            'status'   => 1,
        ]);
        $this->kioskUser = User::forceCreate([
            'name'      => 'Sentinel Kiosk User',
            'email'     => 'kiosk-sec-001@sentinel.test',
            'username'  => 'kiosk_sec_001',
            'password'  => bcrypt('password'),
            'status'    => Status::ACTIVE,
            'branch_id' => $this->branch->id,
        ]);
        KioskMachine::forceCreate([
            'machine_id' => 'SENTINEL_KIOSK',
            'branch_id'  => $this->branch->id,
            'user_id'    => $this->kioskUser->id,
            'username'   => 'kiosk_sec_001',
            'password'   => bcrypt('password'),
            'status'     => Status::ACTIVE,
            'is_login'   => Ask::NO,
        ]);
        $cat = ItemCategory::forceCreate([
            'name'   => 'Sentinel Cat',
            'slug'   => 'sentinel-cat',
            'status' => Status::ACTIVE,
        ]);
        $this->item = Item::forceCreate([
            'name'             => 'Sentinel Item',
            'slug'             => 'sentinel-item',
            'price'            => 10.00,
            'status'           => Status::ACTIVE,
            'item_category_id' => $cat->id,
        ]);
    }

    private function setDineInEnabled(bool $enabled): void
    {
        // Use the Smartisan facade write-path so cache invalidation runs
        // automatically (see Settings::set() → forgetCacheIfEnabled()).
        Settings::group('pos')->set('pos_dine_in_enabled', $enabled ? 1 : 0);
    }

    private function basePayload(int $orderType): array
    {
        return [
            'branch_id'        => $this->branch->id,
            'subtotal'         => $this->item->price,
            'total'            => $this->item->price,
            'order_type'       => $orderType,
            'is_advance_order' => 0,
            'source'           => Source::WEB,
            'items'            => json_encode([
                ['item_id' => $this->item->id, 'quantity' => 1, 'item_variations' => [], 'item_extras' => []],
            ]),
        ];
    }

    private function withQuote(array $payload): array
    {
        $quote = $this->postJson('/api/frontend/order/quote', $payload);
        if ($quote->status() !== 200) {
            // Quote can't be built (e.g. when validation pre-flight rejects). Caller
            // proceeds without a token; OrderRequest::withValidator runs anyway.
            return $payload;
        }
        $data = $quote->json('data');
        return $payload + [
            'quote_token'     => $data['quote_token'] ?? null,
            'quote_signature' => $data['signature'] ?? null,
        ];
    }

    public function test_dine_in_disabled_kiosk_token_rejects_order_type_kiosk(): void
    {
        $this->setDineInEnabled(false);
        Sanctum::actingAs($this->kioskUser, ['kiosk:order']);

        $resp = $this->postJson('/api/frontend/order', $this->withQuote($this->basePayload(OrderType::KIOSK)));

        $resp->assertStatus(422);
        $resp->assertJsonValidationErrors(['order_type']);
        $this->assertStringContainsString('désactivé en V1', $resp->json('errors.order_type.0') ?? '');
    }

    public function test_dine_in_disabled_kiosk_token_rejects_order_type_dining_table(): void
    {
        // Defense-in-depth: even if a forged kiosk client sends DINING_TABLE
        // (the canonical POS dine-in code), the kiosk-path guard refuses.
        $this->setDineInEnabled(false);
        Sanctum::actingAs($this->kioskUser, ['kiosk:order']);

        $resp = $this->postJson('/api/frontend/order', $this->withQuote($this->basePayload(OrderType::DINING_TABLE)));

        $resp->assertStatus(422);
        $resp->assertJsonValidationErrors(['order_type']);
    }

    public function test_dine_in_disabled_kiosk_token_allows_takeaway(): void
    {
        $this->setDineInEnabled(false);
        Sanctum::actingAs($this->kioskUser, ['kiosk:order']);

        $resp = $this->postJson('/api/frontend/order', $this->withQuote($this->basePayload(OrderType::TAKEAWAY)));

        // 201 = order created; 200 = duplicate idempotency. Either non-422 is acceptable
        // — what we forbid is a 422 from the new dine-in guard on TAKEAWAY.
        $this->assertNotSame(422, $resp->status(), 'TAKEAWAY must remain allowed under V1 dine-in disabled.');
        $this->assertDatabaseHas('orders', [
            'branch_id'  => $this->branch->id,
            'order_type' => OrderType::TAKEAWAY,
        ]);
    }

    public function test_dine_in_enabled_kiosk_token_allows_kiosk_forward_compat(): void
    {
        // Forward compat: when V1.x flips dine_in_enabled=true, kiosk "Sur place"
        // must work again WITHOUT any code change beyond the setting toggle.
        $this->setDineInEnabled(true);
        Sanctum::actingAs($this->kioskUser, ['kiosk:order']);

        $resp = $this->postJson('/api/frontend/order', $this->withQuote($this->basePayload(OrderType::KIOSK)));

        $this->assertNotSame(422, $resp->status(),
            'KIOSK (sur place) must succeed when dine_in is enabled (V1.x rollout path).');
        $this->assertDatabaseHas('orders', [
            'branch_id'  => $this->branch->id,
            'order_type' => OrderType::KIOSK,
        ]);
    }
}
