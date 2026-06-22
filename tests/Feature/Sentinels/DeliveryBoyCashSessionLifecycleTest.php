<?php

namespace Tests\Feature\Sentinels;

use App\Enums\Role as EnumRole;
use App\Models\Branch;
use App\Models\DeliveryBoyCashMovement;
use App\Models\DeliveryBoyCashSession;
use App\Models\User;
use App\Services\Delivery\DeliveryBoyCashSessionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * [V1.0.2 Sub-6.3 — 2026-05-18] Lifecycle sentinel — happy path open → collect+collect+change → close → reconcile.
 *
 * Locks the canonical NF525 doorstep cash flow :
 *   1. Livreur opens shift with float (e.g. 50€) → 1 session OPEN.
 *   2. Doorstep deliveries : 2× order_collect (10€ + 20€ in) + 1× change_given (1€ out).
 *   3. Livreur returns to branch, declares physical count (closing_amount = 79€).
 *   4. Manager reconciles → expected = 50 + 10 + 20 − 1 = 79 ; variance = 0.
 *
 * Asserts :
 *   - session.status transitions OPEN → CLOSED → RECONCILED
 *   - 3 movement rows linked + signedAmount() arithmétique correcte
 *   - expected/variance calculated via service (not test math)
 *
 * Anti-régression : si une refonte casse l'arithmétique reconcile / oublie un
 * movement type / inverse une direction → ce test bloque.
 */
class DeliveryBoyCashSessionLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;
    private User $livreur;
    private DeliveryBoyCashSessionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
        Config::set('fiscal.audit_secret', 'test-fiscal-secret-'.str_repeat('a', 40));

        Role::firstOrCreate(
            ['id' => EnumRole::DELIVERY_BOY],
            ['name' => 'Delivery Boy', 'guard_name' => 'sanctum']
        );

        $this->branch = Branch::factory()->create();
        $this->livreur = $this->makeDeliveryBoy($this->branch->id);
        $this->service = app(DeliveryBoyCashSessionService::class);
    }

    private function makeDeliveryBoy(int $branchId): User
    {
        $user = User::forceCreate([
            'name'              => 'Livreur '.uniqid(),
            'email'             => 'livreur-'.uniqid().'@sentinel.test',
            'username'          => 'livreur_'.uniqid(),
            'password'          => bcrypt('secret-passwd'),
            'branch_id'         => $branchId,
            'email_verified_at' => now(),
            'status'            => 1,
        ]);
        $user->assignRole(EnumRole::DELIVERY_BOY);

        return $user->fresh();
    }

    /**
     * [abuse-heal 2026-06-19 livreur FINDING-2] Branch Manager — holds the
     * cash.reconcile.variance.override permission (seeded by seedSpatieRoles), so
     * they can approve an over-threshold delivery-cash variance, exactly as in the
     * POS drawer flow.
     */
    private function makeBranchManager(int $branchId): User
    {
        $user = User::forceCreate([
            'name'              => 'Manager '.uniqid(),
            'email'             => 'manager-'.uniqid().'@sentinel.test',
            'username'          => 'manager_'.uniqid(),
            'password'          => bcrypt('secret-passwd'),
            'branch_id'         => $branchId,
            'email_verified_at' => now(),
            'status'            => 1,
        ]);
        $user->assignRole('Branch Manager');

        return $user->fresh();
    }

    public function test_lifecycle_open_collect_change_close_reconcile_variance_zero(): void
    {
        // 1. Open shift with 50€ float.
        $session = $this->service->openSession($this->branch->id, $this->livreur->id, 50.00, $this->livreur->id);
        $this->assertSame(DeliveryBoyCashSession::STATUS_OPEN, $session->status);
        $this->assertEqualsWithDelta(50.00, (float) $session->opening_amount, 0.01);
        $this->assertSame($this->branch->id, $session->branch_id);
        $this->assertSame($this->livreur->id, $session->delivery_boy_id);

        // 2. Two order_collect movements + 1 change_given.
        $m1 = $this->service->recordMovement(
            $session->id,
            DeliveryBoyCashMovement::TYPE_ORDER_COLLECT,
            10.00,
            DeliveryBoyCashMovement::DIRECTION_IN,
            orderId: null,
            notes: 'order #1 doorstep collect'
        );
        $this->assertNotNull($m1);
        $this->assertSame(DeliveryBoyCashMovement::TYPE_ORDER_COLLECT, $m1->type);
        $this->assertSame(DeliveryBoyCashMovement::DIRECTION_IN, $m1->direction);
        $this->assertEqualsWithDelta(10.00, $m1->signedAmount(), 0.01);

        $m2 = $this->service->recordMovement(
            $session->id,
            DeliveryBoyCashMovement::TYPE_ORDER_COLLECT,
            20.00,
            DeliveryBoyCashMovement::DIRECTION_IN
        );
        $this->assertNotNull($m2);
        $this->assertEqualsWithDelta(20.00, $m2->signedAmount(), 0.01);

        $m3 = $this->service->recordMovement(
            $session->id,
            DeliveryBoyCashMovement::TYPE_CHANGE_GIVEN,
            1.00,
            DeliveryBoyCashMovement::DIRECTION_OUT,
            notes: 'change to customer #2'
        );
        $this->assertNotNull($m3);
        $this->assertSame(DeliveryBoyCashMovement::TYPE_CHANGE_GIVEN, $m3->type);
        $this->assertSame(DeliveryBoyCashMovement::DIRECTION_OUT, $m3->direction);
        $this->assertEqualsWithDelta(-1.00, $m3->signedAmount(), 0.01);

        // Linked + branch isolation correct.
        $this->assertSame(3, DeliveryBoyCashMovement::query()
            ->withoutGlobalScopes()
            ->where('delivery_boy_cash_session_id', $session->id)
            ->count());

        // 3. Close — livreur declares physical count = 79€ (matches expected).
        $closed = $this->service->closeSession($session->id, 79.00);
        $this->assertSame(DeliveryBoyCashSession::STATUS_CLOSED, $closed->status);
        $this->assertEqualsWithDelta(79.00, (float) $closed->closing_amount, 0.01);
        $this->assertNotNull($closed->closed_at);

        // 4. Reconcile — expected = 50 + 10 + 20 − 1 = 79 ; variance = 79 − 79 = 0.
        $reconciled = $this->service->reconcileSession($session->id);
        $this->assertSame(DeliveryBoyCashSession::STATUS_RECONCILED, $reconciled['session']->status);
        $this->assertEqualsWithDelta(79.00, $reconciled['expected'], 0.01, 'expected = float + Σ signed movements');
        $this->assertEqualsWithDelta(0.00, $reconciled['variance'], 0.01, 'variance = closing − expected');

        // 5. Idempotent : second reconcile = no-op.
        $reconciledAgain = $this->service->reconcileSession($session->id);
        $this->assertEqualsWithDelta(0.00, $reconciledAgain['variance'], 0.01);
    }

    public function test_reconcile_with_variance_persists_reason(): void
    {
        // Open 50€ float, single 10€ collect, declare 65€ → variance = 65 − 60 = +5€ (over-count).
        $session = $this->service->openSession($this->branch->id, $this->livreur->id, 50.00, $this->livreur->id);
        $this->service->recordMovement(
            $session->id,
            DeliveryBoyCashMovement::TYPE_ORDER_COLLECT,
            10.00,
            DeliveryBoyCashMovement::DIRECTION_IN
        );
        $this->service->closeSession($session->id, 65.00);

        // [abuse-heal 2026-06-19 livreur FINDING-2] +5€ is OVER the 2.00€ threshold,
        // so the delivery reconcile now mirrors the POS drawer variance gate: a
        // written reason AND a manager-grade actor are required. A Branch Manager
        // (holds cash.reconcile.variance.override) approves — same as the drawer's
        // CashVarianceGateTest::test_reconcile_above_threshold_with_reason_and_manager.
        $manager = $this->makeBranchManager($this->branch->id);
        $this->actingAs($manager);
        $result = $this->service->reconcileSession($session->id, 'tip retenu sur reçu', $manager);
        $this->assertEqualsWithDelta(60.00, $result['expected'], 0.01);
        $this->assertEqualsWithDelta(5.00, $result['variance'], 0.01);
        $this->assertSame('tip retenu sur reçu', $result['session']->variance_reason);
    }

    public function test_recordMovement_rejected_on_closed_session_non_strict_returns_null(): void
    {
        $session = $this->service->openSession($this->branch->id, $this->livreur->id, 20.00, $this->livreur->id);
        $this->service->closeSession($session->id, 20.00);

        $result = $this->service->recordMovement(
            $session->id,
            DeliveryBoyCashMovement::TYPE_ORDER_COLLECT,
            5.00,
            DeliveryBoyCashMovement::DIRECTION_IN,
            strict: false
        );
        $this->assertNull($result, 'best-effort : movement sur session CLOSED retourne null sans throw');
        $this->assertSame(0, DeliveryBoyCashMovement::query()
            ->withoutGlobalScopes()
            ->where('delivery_boy_cash_session_id', $session->id)
            ->count());
    }

    /**
     * Lock the contract for the future DELIVERED hook (Wave 6b-1.4) :
     * when payment_method = CASH_ON_DELIVERY and no livreur shift is OPEN,
     * `recordMovement(..., strict: true)` MUST throw 422 so OrderService
     * can surface LIVREUR_SHIFT_NOT_OPEN to the caller.
     */
    public function test_recordMovement_strict_throws_when_session_not_open(): void
    {
        $session = $this->service->openSession($this->branch->id, $this->livreur->id, 20.00, $this->livreur->id);
        $this->service->closeSession($session->id, 20.00);

        try {
            $this->service->recordMovement(
                $session->id,
                DeliveryBoyCashMovement::TYPE_ORDER_COLLECT,
                5.00,
                DeliveryBoyCashMovement::DIRECTION_IN,
                strict: true
            );
            $this->fail('strict=true on CLOSED session must throw HttpException 422.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode(), 'Strict mode must surface 422.');
            $this->assertStringContainsString('closed', strtolower($e->getMessage()));
        }
    }

    public function test_recordMovement_strict_throws_on_unknown_session(): void
    {
        try {
            $this->service->recordMovement(
                999999,
                DeliveryBoyCashMovement::TYPE_ORDER_COLLECT,
                5.00,
                DeliveryBoyCashMovement::DIRECTION_IN,
                strict: true
            );
            $this->fail('strict=true on unknown session must throw HttpException 404.');
        } catch (HttpException $e) {
            $this->assertSame(404, $e->getStatusCode(), 'Strict mode must surface 404 for missing session.');
        }
    }
}
