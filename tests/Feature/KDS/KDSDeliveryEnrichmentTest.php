<?php

namespace Tests\Feature\KDS;

use App\Enums\Ask;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentStatus;
use App\Enums\Status;
use App\Models\Branch;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Order;
use App\Models\OrderAddress;
use App\Models\Tax;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Sprint 2A DEL-3 — KDS resource exposes delivery address + customer.
 *
 * Background: KDSOrderDetailsResource previously omitted `order_address`
 * and `customer.{name,phone}` entirely. A livreur opening the KDS for a
 * DELIVERY order saw a token and `delivery_time` only — literally not
 * enough information to deliver. This test pins the contract:
 *
 *  - DELIVERY order  → `order_address` populated, `customer.{name,phone}` present.
 *  - DINE_IN order   → `order_address` is null (relation simply not present).
 *  - Customer phone  → exposed verbatim from `users.phone`.
 *  - Eager-load      → the `address` and `user` relations are loaded so
 *                       `whenLoaded` resolves to the populated branch.
 */
class KDSDeliveryEnrichmentTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;
    private User $admin;
    private Tax $tax;
    private Item $item;

    protected function setUp(): void
    {
        parent::setUp();

        // [WG-4 TZ-test-drift V1.0.X 2026-05-19] Pin Carbon::setTestNow to a
        // fixed UTC instant inside the Paris business day (2026-05-18 12:00 UTC
        // = 14:00 Paris CEST) so test fixtures `now()` and service
        // `Carbon::today($appTz)->setTimezone('UTC')` bounds (Wave 3b heal
        // c2613cab0) resolve deterministically. Without the pin, this test
        // fails in the [22:00, 23:59:59] Paris evening window because SQLite
        // string-compares Paris-local fixture against UTC bound literal.
        // See reports/audit/foundation-2026-05-18/failures/V1_0_X_BACKLOG_KDS_TZ_FIX.md.
        Carbon::setTestNow(Carbon::create(2026, 5, 18, 12, 0, 0, 'UTC'));
        CarbonImmutable::setTestNow(CarbonImmutable::create(2026, 5, 18, 12, 0, 0, 'UTC'));

        $this->seedMinimalSettings();
        $this->seedSpatieRoles();
        config(['app.api_key' => '123456']);

        $role = Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'sanctum']);
        Permission::firstOrCreate(['name' => 'kitchen-display-system', 'guard_name' => 'sanctum']);
        $role->givePermissionTo('kitchen-display-system');

        $this->branch = Branch::forceCreate([
            'name' => 'KDS Delivery Branch',
            'email' => 'branch.kds-delivery@example.com',
            'phone' => '0987654321',
            'city' => 'Paris',
            'state' => 'IDF',
            'zip_code' => '75001',
            'address' => '1 rue de Rivoli',
            'status' => Status::ACTIVE,
        ]);

        $this->admin = User::forceCreate([
            'name' => 'KDS Delivery Admin',
            'username' => 'kds_delivery_admin',
            'email' => 'kds-delivery-admin@example.com',
            'phone' => '1122334455',
            'password' => bcrypt('password'),
            'status' => Status::ACTIVE,
            'branch_id' => $this->branch->id,
        ]);
        $this->admin->assignRole($role);

        $this->tax = Tax::forceCreate([
            'name' => 'TVA',
            'code' => 'TVA',
            'tax_rate' => 10,
            'type' => 2,
            'status' => 1,
        ]);

        $category = ItemCategory::forceCreate([
            'name' => 'Burger',
            'slug' => 'burger-delivery',
            'status' => 1,
        ]);

        $this->item = Item::forceCreate([
            'name' => 'Burger Delivery',
            'slug' => 'burger-delivery-1',
            'item_category_id' => $category->id,
            'tax_id' => $this->tax->id,
            'price' => 12,
            'status' => 5,
        ]);
    }

    protected function tearDown(): void
    {
        // [WG-4] Carbon::setTestNow() leaks across tests if not cleared —
        // would silently corrupt unrelated tests sharing the process.
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    /** @test */
    public function delivery_order_payload_includes_address_and_customer_contact(): void
    {
        $customer = User::forceCreate([
            'name' => 'Alice Livreuse',
            'username' => 'alice_livreuse',
            'email' => 'alice@example.com',
            'phone' => '+33612345678',
            'password' => bcrypt('password'),
            'status' => Status::ACTIVE,
            'branch_id' => $this->branch->id,
        ]);

        $order = Order::forceCreate([
            'order_serial_no' => 'KDS-DEL-001',
            'user_id' => $customer->id,
            'branch_id' => $this->branch->id,
            'subtotal' => 12,
            'total' => 12,
            'order_type' => OrderType::DELIVERY,
            'source_surface' => 'delivery',
            'is_advance_order' => Ask::NO,
            'order_datetime' => now(),
            'payment_status' => PaymentStatus::PAID,
            'status' => OrderStatus::ACCEPT,
        ]);

        OrderAddress::forceCreate([
            'order_id' => $order->id,
            'user_id' => $customer->id,
            'label' => 'Home',
            'address' => '12 rue Lafayette, 75009 Paris',
            'apartment' => 'Apt 3B',
            'latitude' => '48.8769',
            'longitude' => '2.3399',
        ]);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->withHeader('x-api-key', '123456')
            ->getJson('/api/admin/kds-order');

        $response->assertStatus(200);

        $payload = $response->json('data');
        $this->assertIsArray($payload);
        $this->assertNotEmpty($payload, 'KDS list should expose at least the seeded delivery order');

        $row = collect($payload)->firstWhere('id', $order->id);
        $this->assertNotNull($row, 'Seeded delivery order must be present in /api/admin/kds-order');

        // Address — must be hydrated, not whenLoaded skipped.
        $this->assertIsArray($row['order_address'] ?? null, 'order_address must be array when DELIVERY order has an OrderAddress row');
        $this->assertSame('12 rue Lafayette, 75009 Paris', $row['order_address']['address']);
        $this->assertSame('Apt 3B', $row['order_address']['apartment']);
        $this->assertSame('Home', $row['order_address']['label']);
        $this->assertSame('48.8769', $row['order_address']['latitude']);
        $this->assertSame('2.3399', $row['order_address']['longitude']);

        // Customer — name + phone so the livreur can call.
        $this->assertIsArray($row['customer'] ?? null, 'customer block must be present');
        $this->assertSame('Alice Livreuse', $row['customer']['name']);
        $this->assertSame('+33612345678', $row['customer']['phone']);

        // Anchor invariants (regression guards on the same row).
        $this->assertSame($order->id, $row['id']);
        $this->assertSame('delivery', $row['source_surface']);
    }

    /** @test */
    public function dine_in_order_payload_omits_address_block(): void
    {
        $customer = User::forceCreate([
            'name' => 'Bob Surplace',
            'username' => 'bob_surplace',
            'email' => 'bob@example.com',
            'phone' => '+33677889900',
            'password' => bcrypt('password'),
            'status' => Status::ACTIVE,
            'branch_id' => $this->branch->id,
        ]);

        $order = Order::forceCreate([
            'order_serial_no' => 'KDS-DIN-001',
            'user_id' => $customer->id,
            'branch_id' => $this->branch->id,
            'subtotal' => 12,
            'total' => 12,
            'order_type' => OrderType::DINING_TABLE,
            'source_surface' => 'pos',
            'is_advance_order' => Ask::NO,
            'order_datetime' => now(),
            'payment_status' => PaymentStatus::PAID,
            'status' => OrderStatus::PREPARING,
        ]);
        // Intentionally NO OrderAddress row — DINE_IN never has one.

        $response = $this->actingAs($this->admin, 'sanctum')
            ->withHeader('x-api-key', '123456')
            ->getJson('/api/admin/kds-order');

        $response->assertStatus(200);
        $payload = $response->json('data');
        $row = collect($payload)->firstWhere('id', $order->id);

        $this->assertNotNull($row);
        // address relation was eager-loaded but the join returned NULL — the
        // resource closure must collapse to null, not throw. The key exists
        // (whenLoaded fires) but its value is null because $this->address is
        // null on a DINE_IN row.
        $this->assertArrayHasKey('order_address', $row);
        $this->assertNull($row['order_address'], 'DINE_IN order with no address must serialise order_address as null');

        // [Sprint 5A Z9-P0-03] GDPR data-minimization: customer.name remains
        // on dine-in (useful for receipts / table callout), but customer.phone
        // is suppressed for non-delivery orders. The chef physically near the
        // dine-in table doesn't need a tel: callback channel.
        $this->assertSame('Bob Surplace', $row['customer']['name'] ?? null);
        $this->assertNull($row['customer']['phone'] ?? null, 'customer.phone must be null on non-DELIVERY orders (Z9-P0-03)');
    }

    /** @test */
    public function eager_loaded_relations_are_present_on_the_underlying_query(): void
    {
        // Smoke test: even with N orders, the resource resolves without N+1.
        $customer = User::forceCreate([
            'name' => 'EagerCheck',
            'username' => 'eager_check',
            'email' => 'eager@example.com',
            'phone' => '+33611112222',
            'password' => bcrypt('password'),
            'status' => Status::ACTIVE,
            'branch_id' => $this->branch->id,
        ]);

        for ($i = 0; $i < 3; $i++) {
            $o = Order::forceCreate([
                'order_serial_no' => 'KDS-EAGER-' . $i,
                'user_id' => $customer->id,
                'branch_id' => $this->branch->id,
                'subtotal' => 10,
                'total' => 10,
                'order_type' => OrderType::DELIVERY,
                'source_surface' => 'delivery',
                'is_advance_order' => Ask::NO,
                'order_datetime' => now(),
                'payment_status' => PaymentStatus::PAID,
                'status' => OrderStatus::ACCEPT,
            ]);
            OrderAddress::forceCreate([
                'order_id' => $o->id,
                'user_id' => $customer->id,
                'label' => 'Home',
                'address' => 'Adresse #' . $i,
                'apartment' => null,
                'latitude' => '48.8',
                'longitude' => '2.3',
            ]);
        }

        $response = $this->actingAs($this->admin, 'sanctum')
            ->withHeader('x-api-key', '123456')
            ->getJson('/api/admin/kds-order');

        $response->assertStatus(200);
        $rows = collect($response->json('data'))->where('source_surface', 'delivery');
        $this->assertGreaterThanOrEqual(3, $rows->count());
        // All 3 must have populated address + customer.
        foreach ($rows as $row) {
            $this->assertNotNull($row['order_address'] ?? null);
            $this->assertSame('EagerCheck', $row['customer']['name'] ?? null);
            $this->assertSame('+33611112222', $row['customer']['phone'] ?? null);
        }
    }
}
