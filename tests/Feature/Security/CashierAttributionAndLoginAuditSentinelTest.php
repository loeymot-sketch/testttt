<?php

namespace Tests\Feature\Security;

use App\Enums\Ask;
use App\Enums\OrderType;
use App\Enums\PosPaymentMethod;
use App\Enums\Source;
use App\Enums\Status;
use App\Enums\TaxType;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Order;
use App\Models\Tax;
use App\Models\User;
use App\Services\Fiscal\AuditLogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Smartisan\Settings\Facades\Settings;
use Tests\Feature\Concerns\HasPosQuoteBinding;
use Tests\TestCase;

/**
 * Sentinel — H2-HEAL-02 (Phase H.1 P1 AMBER + P2 AMBER, 2026-05-24).
 *
 * Closes the NF525 6-year traceability gap caught by Phase H.1 audit:
 *
 *   GAP #1 (orders.creator_id)
 *   --------------------------
 *   Before this heal, `orders.user_id` stored the CUSTOMER (Walking Customer
 *   id=2 for anonymous POS sales), NOT the cashier. `orders.creator_id` was
 *   NULL on every POS-created order. There was no persisted column that
 *   answered "which cashier opened order X?".
 *
 *   GAP #2 (audit_logs delta)
 *   -------------------------
 *   Phase H.1 measurements showed audit_logs delta=0 after POS order create
 *   POSTs and after login/logout events. NF525 requires 6-year tamper-evident
 *   traceability of fiscal events including operator identification — silence
 *   on order create + auth events is non-compliant.
 *
 * Closure (asserted here):
 *   1. POST /api/admin/pos as a cashier sets orders.creator_id = cashier->id
 *      AND appends an `order.created.pos` audit_logs row with cashier_id in
 *      the payload, on the branch's HMAC chain.
 *   2. POST /api/auth/login appends a `user.login` audit_logs row with the
 *      user_id, branch_id, IP, UA on the user's branch chain.
 *   3. POST /api/auth/logout appends a `user.logout` audit_logs row similarly.
 *   4. AuditLogService::verifyChain($branchId) stays GREEN after each write
 *      (the new events extend the chain legitimately — frozen-zone service
 *      itself unchanged, only called via public write() API).
 *
 * @see app/Services/OrderService.php (posOrderStore — creator_id + order.created.pos)
 * @see app/Http/Controllers/Auth/LoginController.php (login + logout audit writes)
 * @see app/Services/Fiscal/AuditLogService.php (FROZEN §7 — public API only)
 * @see CLAUDE.md §8 NF525 Audit Chain
 */
class CashierAttributionAndLoginAuditSentinelTest extends TestCase
{
    use RefreshDatabase;
    use HasPosQuoteBinding;

    protected Branch $branch;
    protected User $cashier;
    protected User $walkingCustomer;
    protected Item $item;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMinimalSettings();
        $this->seedSpatieRoles();

        config([
            'app.api_key' => 'test-api-key',
            'broadcasting.default' => 'log',
            'pricing.use_ssot_service' => true,
            // [H2-HEAL-02] AuditLogService refuses to write an unsigned row
            // when fiscal.audit_secret is empty (RuntimeException). Same 48-char
            // string the existing BL2 audit tests use.
            'fiscal.audit_secret' => str_repeat('a', 48),
        ]);

        Settings::group('order_setup')->set([
            'order_setup_food_preparation_time' => 30,
            'order_setup_schedule_order_slot_duration' => 30,
        ]);

        $this->branch = Branch::forceCreate([
            'name' => 'H2-HEAL-02 Branch',
            'city' => 'Paris',
            'state' => 'IDF',
            'zip_code' => '75000',
            'address' => '1 rue H2-HEAL-02',
            'status' => 1,
        ]);

        $tax = Tax::create([
            'name' => 'TVA 10',
            'code' => 'TVA10H2',
            'tax_rate' => 10,
            'type' => TaxType::PERCENTAGE,
            'status' => Status::ACTIVE,
        ]);

        $category = ItemCategory::forceCreate([
            'name' => 'H2 Cat',
            'slug' => 'h2-cat',
            'status' => Status::ACTIVE,
        ]);

        $this->item = Item::forceCreate([
            'name' => 'H2 Item',
            'slug' => 'h2-item',
            'price' => 10.00,
            'status' => Status::ACTIVE,
            'item_category_id' => $category->id,
            'tax_id' => $tax->id,
        ]);

        // The CASHIER — the actor we expect to find on creator_id +
        // audit_logs payload.cashier_id.
        $this->cashier = \Database\Factories\UserFactory::new()->create([
            'branch_id' => $this->branch->id,
            'phone' => fake()->unique()->numerify('06########'),
            // [H2-HEAL-02] Stable password so the login test can re-authenticate
            // via /api/auth/login with a known value.
            'password' => Hash::make('Cashier2026Heal!'),
        ]);
        $this->cashier->assignRole('Admin');

        // The WALKING CUSTOMER — the user_id we expect on the order row
        // (NOT the cashier). Mirrors WalkInCustomerResolver::resolve output
        // shape (some `name=Walking Customer` user separate from staff).
        $this->walkingCustomer = \Database\Factories\UserFactory::new()->create([
            'branch_id' => $this->branch->id,
            'phone' => fake()->unique()->numerify('06########'),
            'name'  => 'Walking Customer H2',
        ]);
        $this->walkingCustomer->assignRole('Customer');

        // POS CASH path requires an OPEN cash drawer session for the cashier.
        app(\App\Services\Cash\CashDrawerService::class)
            ->openSession($this->branch->id, $this->cashier->id, 100.00);
    }

    /**
     * H2-HEAL-02-A + B — POS order create populates orders.creator_id with
     * the cashier's user id AND appends an order.created.pos audit_logs
     * row carrying the cashier identity. The chain remains intact.
     */
    public function test_pos_order_create_sets_creator_id_and_writes_audit_event(): void
    {
        $payload = [
            // Customer goes here (Walking Customer) — NOT the cashier.
            'customer_id'         => $this->walkingCustomer->id,
            'branch_id'           => $this->branch->id,
            'subtotal'            => 10.00,
            'total'               => 11.00,
            'order_type'          => OrderType::TAKEAWAY,
            'is_advance_order'    => Ask::NO,
            'source'              => Source::POS,
            'pos_payment_method'  => PosPaymentMethod::CASH,
            'pos_received_amount' => 11.00,
            'items' => json_encode([[
                'item_id' => $this->item->id, 'quantity' => 1,
                'item_variations' => [], 'item_extras' => [],
            ]]),
        ];

        $response = $this->actingAs($this->cashier)
            ->withHeader('x-api-key', config('app.api_key'))
            ->postJson('/api/admin/pos', $this->payloadWithPosQuote($this->cashier, $payload));

        $response->assertStatus(201);

        $orderId = (int) $response->json('data.id');
        $this->assertGreaterThan(0, $orderId, 'POST /api/admin/pos must return a persisted order id.');

        $order = Order::withoutGlobalScopes()->findOrFail($orderId);

        // GAP #1 — orders.creator_id closure.
        $this->assertSame(
            (int) $this->cashier->id,
            (int) $order->creator_id,
            'orders.creator_id must equal the cashier (auth()->id()) on POS order create. '
            . 'NF525 6-year traceability: this is the persisted "who opened it" column.'
        );

        // Sanity — user_id still stores the customer, NOT the cashier. We do
        // NOT want this heal to accidentally repurpose user_id.
        $this->assertSame(
            (int) $this->walkingCustomer->id,
            (int) $order->user_id,
            'orders.user_id must remain the CUSTOMER. The heal must not repurpose '
            . 'user_id as a cashier column — creator_id is the dedicated field.'
        );

        // GAP #2 (part a) — audit_logs has the order.created.pos row.
        $audit = AuditLog::where('action', 'order.created.pos')
            ->where('resource_id', $orderId)
            ->where('branch_id', (int) $this->branch->id)
            ->latest('id')
            ->first();

        $this->assertNotNull(
            $audit,
            'audit_logs must contain an order.created.pos entry for the just-created '
            . 'POS order. Phase H.1 measured delta=0 — this sentinel locks it back to 1.'
        );

        $this->assertSame('order', $audit->resource);
        $auditPayload = $audit->payload;
        $this->assertSame(
            (int) $this->cashier->id,
            (int) $auditPayload['cashier_id'],
            'order.created.pos payload must carry cashier_id (the operator) on the HMAC chain.'
        );
        $this->assertSame(
            (string) $this->cashier->name,
            (string) $auditPayload['cashier_name'],
            'order.created.pos payload must carry cashier_name for human-readable forensics.'
        );
        $this->assertSame((int) $orderId, (int) $auditPayload['order_id']);
        $this->assertSame((int) $this->branch->id, (int) $auditPayload['branch_id']);

        // GAP #2 (part b) — chain stays intact after the new write.
        $this->assertNull(
            app(AuditLogService::class)->verifyChain((int) $this->branch->id),
            'NF525 HMAC chain must stay intact after the new order.created.pos write '
            . '— the heal extends the chain legitimately via the FROZEN public write() API.'
        );
    }

    /**
     * H2-HEAL-02-C — POST /api/auth/login appends a user.login audit row
     * with the user_id, branch_id, IP, UA. Chain intact after.
     */
    public function test_login_writes_user_login_audit_event(): void
    {
        // Baseline: no user.login row for this user yet.
        $this->assertSame(
            0,
            AuditLog::where('action', 'user.login')
                ->where('resource_id', (int) $this->cashier->id)
                ->count(),
            'Test fixture must not leak prior user.login rows.'
        );

        $this->withHeader('x-api-key', config('app.api_key'))
            ->postJson('/api/auth/login', [
                'email'    => $this->cashier->email,
                'password' => 'Cashier2026Heal!',
            ])
            ->assertStatus(201);

        $audit = AuditLog::where('action', 'user.login')
            ->where('resource_id', (int) $this->cashier->id)
            ->latest('id')
            ->first();

        $this->assertNotNull(
            $audit,
            'audit_logs must contain a user.login entry after a successful login. '
            . 'Phase H.1 P2 measured delta=0 — this sentinel locks it back to 1.'
        );

        $this->assertSame('user', $audit->resource);
        $this->assertSame((int) $this->cashier->id, (int) $audit->user_id);
        $this->assertSame((int) $this->branch->id, (int) $audit->branch_id);

        $payload = $audit->payload;
        $this->assertSame((int) $this->cashier->id, (int) $payload['user_id']);
        $this->assertSame((string) $this->cashier->email, (string) $payload['user_email']);
        $this->assertSame((int) $this->branch->id, (int) $payload['branch_id']);
        $this->assertArrayHasKey('ip', $payload);
        $this->assertArrayHasKey('user_agent', $payload);

        $this->assertNull(
            app(AuditLogService::class)->verifyChain((int) $this->branch->id),
            'NF525 chain must stay intact after user.login write.'
        );
    }

    /**
     * H2-HEAL-02-C — POST /api/auth/logout appends a user.logout audit row
     * with user identity captured BEFORE the token is destroyed.
     */
    public function test_logout_writes_user_logout_audit_event(): void
    {
        // Acting via sanctum so $request->user() inside logout() resolves and
        // currentAccessToken() returns a deletable PersonalAccessToken.
        $this->actingAs($this->cashier, 'sanctum');

        $this->withHeader('x-api-key', config('app.api_key'))
            ->postJson('/api/auth/logout')
            ->assertStatus(200);

        $audit = AuditLog::where('action', 'user.logout')
            ->where('resource_id', (int) $this->cashier->id)
            ->latest('id')
            ->first();

        $this->assertNotNull(
            $audit,
            'audit_logs must contain a user.logout entry after a successful logout. '
            . 'Phase H.1 P2 measured delta=0 — this sentinel locks it back to 1.'
        );

        $this->assertSame('user', $audit->resource);
        $this->assertSame((int) $this->cashier->id, (int) $audit->user_id);
        $this->assertSame((int) $this->branch->id, (int) $audit->branch_id);

        $payload = $audit->payload;
        $this->assertSame((int) $this->cashier->id, (int) $payload['user_id']);
        $this->assertSame((string) $this->cashier->email, (string) $payload['user_email']);
        $this->assertSame((int) $this->branch->id, (int) $payload['branch_id']);

        $this->assertNull(
            app(AuditLogService::class)->verifyChain((int) $this->branch->id),
            'NF525 chain must stay intact after user.logout write.'
        );
    }

    /**
     * H2-HEAL-02 invariant — the heal must NOT modify the frozen
     * AuditLogService source. We only call its public write() + verifyChain()
     * APIs. This source-level assertion catches a future PR that tries to
     * inline-edit the service to add a "convenience" overload.
     */
    public function test_audit_log_service_remains_frozen(): void
    {
        $source = file_get_contents(
            base_path('app/Services/Fiscal/AuditLogService.php')
        );
        $this->assertNotFalse($source, 'AuditLogService.php must be readable.');

        // The signature we depend on. If a future PR changes write() to
        // positional args, this sentinel fails fast.
        $this->assertMatchesRegularExpression(
            '/public function write\s*\(\s*array \$data\s*\)\s*:\s*AuditLog/',
            $source,
            'AuditLogService::write(array $data): AuditLog is the frozen public '
            . 'API contract H2-HEAL-02 relies on. Do not change the signature.'
        );
    }
}
