<?php

namespace Tests\Feature\OrderPipeline;

use App\Enums\Ask;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentGateway;
use App\Enums\Source;
use App\Enums\Status;
use App\Events\OrderCreated;
use App\Events\OrderStatusChanged;
use App\Events\SendOrderMail;
use App\Events\SendOrderPush;
use App\Events\SendOrderSms;
use App\Models\Allergen;
use App\Models\Branch;
use App\Models\FrontendOrder;
use App\Models\Item;
use App\Models\ItemAttribute;
use App\Models\ItemCategory;
use App\Models\ItemExtra;
use App\Models\ItemVariation;
use App\Models\KioskMachine;
use App\Models\OrderItem;
use App\Models\Tax;
use App\Models\User;
use App\Services\CouponService;
use App\Services\Pricing\PricingRequest;
use App\Services\Pricing\PricingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Smartisan\Settings\Facades\Settings;
use Tests\TestCase;

class KioskFullFlowE2ETest extends TestCase
{
    use RefreshDatabase;

    private Branch $branchA;
    private Branch $branchB;
    private User $kioskUserA;
    private User $kioskUserB;
    private User $chefA;
    private Item $item;
    private ItemVariation $variation;
    private ItemExtra $extra;

    protected function setUp(): void
    {
        parent::setUp();

        // [WG-4 TZ-test-drift V1.0.X 2026-05-19] NOTE: this test was originally
        // listed under the KDS V1.0.X TZ pin cluster, but Carbon::setTestNow()
        // alone CANNOT fix it — `FrontendOrderService.php:263` writes
        // `order_datetime` using `date('Y-m-d H:i:s')` (raw PHP wall-clock,
        // NOT Carbon-aware). Pinning Carbon to a fixed day decouples the
        // controller's wall-clock date from the KDS service's pinned-Carbon
        // bounds → date mismatch → test fails mid-day. The evening-window
        // [22:00, 23:59:59] failure described in V1_0_X_BACKLOG_KDS_TZ_FIX.md
        // is a PRODUCTION bug requiring a heal in FrontendOrderService.php:263
        // (`date()` → `now()->format(...)`). Tracked as V1.0.X-followup.
        // For now: NO pin on this class — relies on real wall-clock for both
        // sides, which is the pre-WG-4 baseline (fails 22:00-23:59:59 Paris,
        // passes otherwise). See reports/audit/wave-g-2026-05-19/WG-4-TZ-TEST-DRIFT/STATUS.md.

        $this->seedMinimalSettings();
        $this->seedSpatieRoles();
        config(['app.api_key' => '123456']);

        Settings::group('order_setup')->set([
            'order_setup_food_preparation_time' => 30,
            'order_setup_schedule_order_slot_duration' => 30,
            'order_setup_delivery' => 5,
            'order_setup_takeaway' => 5,
        ]);

        $this->branchA = Branch::factory()->create();
        $this->branchB = Branch::factory()->create();

        $tax = Tax::create([
            'name' => 'TVA 10',
            'code' => 'TVA10',
            'tax_rate' => 10,
            'type' => 2,
            'status' => 1,
        ]);

        $category = ItemCategory::forceCreate([
            'name' => 'Pipeline Category',
            'slug' => 'pipeline-category',
            'status' => Status::ACTIVE,
        ]);

        $attribute = ItemAttribute::query()->create([
            'name' => 'Taille',
            'item_category_id' => $category->id,
            'is_required' => 0,
            'max_selection' => 1,
        ]);

        $this->item = Item::forceCreate([
            'name' => 'Pipeline Burger',
            'slug' => 'pipeline-burger',
            'price' => 10.00,
            'status' => Status::ACTIVE,
            'item_category_id' => $category->id,
            'tax_id' => $tax->id,
        ]);

        $this->variation = ItemVariation::query()->create([
            'item_id' => $this->item->id,
            'item_attribute_id' => $attribute->id,
            'name' => 'XL',
            'price' => 1.50,
            'status' => Status::ACTIVE,
        ]);

        $this->extra = ItemExtra::query()->create([
            'item_id' => $this->item->id,
            'name' => 'Cheddar',
            'price' => 2.00,
            'status' => Status::ACTIVE,
        ]);

        $arachides = Allergen::forceCreate([
            'code' => 'arachides',
            'name_key' => 'allergens.arachides',
            'sort' => 1,
        ]);
        $gluten = Allergen::forceCreate([
            'code' => 'gluten',
            'name_key' => 'allergens.gluten',
            'sort' => 2,
        ]);
        $this->item->allergens()->sync([
            $arachides->id => ['is_trace' => false],
            $gluten->id => ['is_trace' => false],
        ]);

        $this->kioskUserA = $this->makeKioskUser($this->branchA, 'pipeline_a');
        $this->kioskUserB = $this->makeKioskUser($this->branchB, 'pipeline_b');

        $this->chefA = User::factory()->create([
            'branch_id' => $this->branchA->id,
            'username' => 'chef_pipeline_a',
        ]);
        $this->chefA->assignRole('Chef');
    }

    public function test_kiosk_order_full_flow_to_kds_with_variations_extras_allergens(): void
    {
        Event::fake([
            OrderCreated::class,
            OrderStatusChanged::class,
            SendOrderMail::class,
            SendOrderSms::class,
            SendOrderPush::class,
        ]);

        $payload = $this->kioskPayload();
        $expectedTotal = $this->expectedTotalFor($this->branchA->id, $this->kioskUserA->id, $payload['items']);
        $idempotencyKey = 'kiosk-e2e-key-001';

        $firstResponse = $this->postKioskOrder($this->kioskUserA, $payload, $idempotencyKey);
        $this->assertContains($firstResponse->status(), [200, 201]);

        $orderAId = (int) ($firstResponse->json('data.id') ?? $firstResponse->json('id'));
        $orderA = FrontendOrder::withoutGlobalScopes()->findOrFail($orderAId);
        $orderItemA = OrderItem::query()->where('order_id', $orderAId)->sole();

        $this->assertSame($this->branchA->id, (int) $orderA->branch_id);
        $this->assertEqualsWithDelta($expectedTotal, (float) $orderA->total, 0.0001);
        $this->assertSame(OrderStatus::ACCEPT, (int) $orderA->status);
        $this->assertSame('sans sauce', $orderItemA->instruction);
        $this->assertCount(1, json_decode((string) $orderItemA->item_variations, true));
        $this->assertCount(1, json_decode((string) $orderItemA->item_extras, true));
        $this->assertEqualsCanonicalizing(['arachides', 'gluten'], $orderItemA->allergens_snapshot ?? []);

        $replayResponse = $this->postKioskOrder($this->kioskUserA, $payload, $idempotencyKey);
        $this->assertContains($replayResponse->status(), [200, 201]);
        $this->assertSame($orderAId, (int) ($replayResponse->json('data.id') ?? $replayResponse->json('id')));

        $branchBResponse = $this->postKioskOrder($this->kioskUserB, $payload, $idempotencyKey);
        $branchBCount = FrontendOrder::withoutGlobalScopes()->where('idempotency_key', $idempotencyKey)->count();
        $this->assertContains(
            $branchBResponse->status(),
            [200, 201],
            json_encode(['response' => $branchBResponse->json(), 'count' => $branchBCount])
        );
        $orderBId = (int) ($branchBResponse->json('data.id') ?? $branchBResponse->json('id'));
        $this->assertNotSame($orderAId, $orderBId);

        $this->assertSame(
            2,
            FrontendOrder::withoutGlobalScopes()->where('idempotency_key', $idempotencyKey)->count()
        );

        $kdsResponse = $this
            ->actingAs($this->chefA, 'sanctum')
            ->withHeader('x-api-key', '123456')
            ->getJson('/api/admin/kds-order');

        $kdsResponse->assertOk();
        $orderIds = collect($kdsResponse->json('data') ?? [])->pluck('id')->map(fn ($id) => (int) $id)->all();
        $this->assertContains($orderAId, $orderIds);
        $this->assertNotContains($orderBId, $orderIds);

        $kdsOrder = collect($kdsResponse->json('data'))->firstWhere('id', $orderAId);
        $this->assertEqualsCanonicalizing(
            ['arachides', 'gluten'],
            $kdsOrder['order_items'][0]['allergens_snapshot'] ?? []
        );
    }

    private function makeKioskUser(Branch $branch, string $suffix): User
    {
        $user = User::factory()->create([
            'branch_id' => $branch->id,
            'username' => 'kiosk_' . $suffix,
        ]);

        KioskMachine::create([
            'machine_id' => 'machine-' . $suffix,
            'branch_id' => $branch->id,
            'user_id' => $user->id,
            'username' => 'kiosk-' . $suffix,
            'password' => bcrypt('secret'),
            'is_login' => Ask::NO,
            'status' => Status::ACTIVE,
        ]);

        return $user;
    }

    /**
     * @return array<string, mixed>
     */
    private function kioskPayload(): array
    {
        return [
            'order_type' => OrderType::TAKEAWAY,
            'is_advance_order' => Ask::NO,
            'source' => Source::APP,
            'payment_method' => PaymentGateway::CASH_ON_DELIVERY,
            'items' => json_encode([[
                'item_id' => $this->item->id,
                'quantity' => 2,
                'instruction' => 'sans sauce',
                'item_variations' => [['id' => $this->variation->id]],
                'item_extras' => [['id' => $this->extra->id]],
            ]]),
        ];
    }

    private function postKioskOrder(User $user, array $payload, string $idempotencyKey)
    {
        Sanctum::actingAs($user, ['kiosk:order']);

        // [prod-finale 2026-06-17] Memoize the minted quote per (user, idempotency-key) so a SAME-user
        // SAME-key REPLAY resends the IDENTICAL body (quote_token included). That mirrors the live kiosk
        // offline-queue, which resends the stored body verbatim on retry — NOT a fresh quote. Re-minting a
        // quote on replay would change the payload hash and make the FROZEN idempotency middleware return a
        // 409 payload-conflict instead of replaying the cached 2xx (the bug this test was hitting). A
        // DIFFERENT user/branch still mints its own quote (preserves the cross-branch isolation assertion).
        $memoKey = $user->id . '|' . $idempotencyKey;
        if (! isset($this->kioskQuoteMemo[$memoKey])) {
            $this->kioskQuoteMemo[$memoKey] = $this
                ->withHeader('x-api-key', '123456')
                ->postJson('/api/frontend/order/quote', $payload)
                ->assertOk()
                ->json('data');
        }
        $quote = $this->kioskQuoteMemo[$memoKey];

        return $this
            ->withHeader('x-api-key', '123456')
            ->withHeader('X-Idempotency-Key', $idempotencyKey)
            ->postJson('/api/frontend/order', $payload + [
                'quote_token' => $quote['quote_token'],
                'quote_signature' => $quote['signature'],
            ]);
    }

    /** @var array<string, array<string, mixed>> memoized kiosk quotes keyed by "userId|idempotencyKey" */
    private array $kioskQuoteMemo = [];

    private function expectedTotalFor(int $branchId, int $userId, string $itemsJson): float
    {
        $pricing = (new PricingService())->calculateOrder(
            PricingRequest::forKiosk(
                0,
                $branchId,
                json_decode($itemsJson) ?: [],
                0,
                $userId,
                0.0
            ),
            app(CouponService::class)
        );

        return $pricing->total;
    }
}
