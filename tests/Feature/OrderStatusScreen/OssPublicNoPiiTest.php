<?php

namespace Tests\Feature\OrderStatusScreen;

use App\Enums\Ask;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\Status;
use App\Models\Branch;
use App\Models\Order;
use App\Models\OrderAddress;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [GOAL_MGMT_TESTPLAN Wave B — HIST-13 privacy / data-integrity audit]
 *
 * The public Order-Status-Screen (OSS) wall feed is served by
 * `GET /api/frontend/oss-order`
 *   → App\Http\Controllers\Admin\OrderStatusScreenController::publicIndex
 *   → App\Http\Resources\CDSOrderDetailsResource
 *
 * This endpoint is mounted under the public `frontend` group
 * (`installed` + `apiKey` + `localization`, routes/api.php:1244 + :1288) so an
 * unauthenticated TV/wall can poll it WITHOUT a session. Because it is
 * unauthenticated and publicly displayed, it MUST expose only harmless
 * "wall" data — order number / queue number / status — and MUST NOT leak
 * any customer PII.
 *
 * Customer PII lives on the order's owning User (orders.user_id → User:
 * name / phone / email, app/Models/User.php fillable) plus the delivery
 * `orders.address` column. The OSS resource intentionally projects only
 * `id`, `order_serial_no`, `token`, `queue_number`, `order_type`, `status`
 * (CDSOrderDetailsResource::toArray) — none of which is PII.
 *
 * DUAL GUARD:
 *   - GREEN proves the public wall feed carries no customer name / phone /
 *     email / address while still carrying the safe public fields.
 *   - A genuine RED (the exact PII string appearing in the response body)
 *     is a P1 privacy leak on an unauthenticated public endpoint.
 *
 * Convergence filter: `php artisan test --filter=OssPublicNoPii`.
 */
class OssPublicNoPiiTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch;

    /** Known PII values seeded onto the order's customer. */
    private const PII_NAME    = 'Jean Secret';
    private const PII_PHONE   = '0612345678';
    private const PII_EMAIL   = 'jean.secret-pii@example-leak.test';
    private const PII_ADDRESS = '42 Rue Confidentielle, Paris';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
        // Mirror OssCustomerScreenFilterTest: keep the seeded order inside the
        // stale-prune window so it actually reaches the wall feed.
        config(['oss.stale_window_hours' => 8]);
        $this->branch = Branch::factory()->create(['status' => Status::ACTIVE]);
    }

    /**
     * Seed a customer carrying known PII and an order owned by that customer
     * that is eligible for the OSS wall (KIOSK / PREPARING, today, in-window).
     *
     * @return array{0: \App\Models\Order, 1: \App\Models\User}
     */
    private function seedOrderWithPiiCustomer(): array
    {
        // Customer PII lives on the User row (name / phone / email). The
        // delivery address is NOT a User column on this schema — it is stored
        // on the order row (orders.address) + the OrderAddress relation, so
        // the address PII is seeded on the order below.
        $customer = User::factory()->create([
            'name'  => self::PII_NAME,
            'phone' => self::PII_PHONE,
            'email' => self::PII_EMAIL,
        ]);

        $order = Order::factory()->create([
            'branch_id'        => $this->branch->id,
            'user_id'          => $customer->id,
            'order_type'       => OrderType::KIOSK,
            'status'           => OrderStatus::PREPARING,
            'order_datetime'   => now()->subMinutes(5),
            'is_advance_order' => Ask::NO,
            'queue_number'     => '777',
            'token'            => 'OSS-PII-WALL-TOKEN',
        ]);

        // Delivery address PII is stored on the OrderAddress relation
        // (order_addresses table), not on the orders/users rows directly.
        OrderAddress::create([
            'order_id'  => $order->id,
            'user_id'   => $customer->id,
            'label'     => 'Home',
            'address'   => self::PII_ADDRESS,
            'latitude'  => '48.8566',
            'longitude' => '2.3522',
        ]);

        return [$order, $customer];
    }

    /**
     * The public wall feed MUST NOT contain any customer PII string,
     * yet MUST contain the safe public fields (queue/order number + status).
     */
    public function test_public_oss_feed_exposes_no_customer_pii(): void
    {
        [$order, $customer] = $this->seedOrderWithPiiCustomer();

        $response = $this->getJson('/api/frontend/oss-order?branch_id=' . $this->branch->id);

        $response->assertOk();

        // --- HARD: no PII leaks anywhere in the public body ---------------
        $response->assertDontSee(self::PII_NAME, false);
        $response->assertDontSee(self::PII_PHONE, false);
        $response->assertDontSee(self::PII_EMAIL, false);
        $response->assertDontSee(self::PII_ADDRESS, false);

        // The PII string must not be reachable via any nested key either.
        $body = $response->getContent();
        $this->assertStringNotContainsString(self::PII_NAME, $body, 'Customer name leaked on public OSS wall feed.');
        $this->assertStringNotContainsString(self::PII_PHONE, $body, 'Customer phone leaked on public OSS wall feed.');
        $this->assertStringNotContainsString(self::PII_EMAIL, $body, 'Customer email leaked on public OSS wall feed.');
        $this->assertStringNotContainsString(self::PII_ADDRESS, $body, 'Customer/delivery address leaked on public OSS wall feed.');

        // --- HARD: safe public fields ARE present -------------------------
        // Proves the test actually hit the wall feed (not an empty / 401 body)
        // and that the harmless display data is shipped.
        $response->assertSee((string) $order->order_serial_no, false);
        $response->assertSee((string) $order->queue_number, false);
        $response->assertSee((string) $order->status, false);

        // Belt-and-braces: the JSON row exposes ONLY the whitelisted keys.
        $response->assertJsonStructure([
            'data' => [
                ['id', 'order_serial_no', 'token', 'queue_number', 'order_type', 'status'],
            ],
        ]);
        $row = $response->json('data.0');
        $this->assertEqualsCanonicalizing(
            ['id', 'order_serial_no', 'token', 'queue_number', 'order_type', 'status'],
            array_keys($row),
            'Public OSS resource must expose ONLY the safe whitelist — any extra key risks PII drift.',
        );

        // Sanity: the seeded customer genuinely carries the PII we asserted
        // is absent (guards against a false-GREEN from mis-seeded data).
        $this->assertSame(self::PII_NAME, $customer->fresh()->name);
        $this->assertSame(self::PII_PHONE, $customer->fresh()->phone);
    }
}
