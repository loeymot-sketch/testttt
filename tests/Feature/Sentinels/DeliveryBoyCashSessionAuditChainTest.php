<?php

namespace Tests\Feature\Sentinels;

use App\Enums\Role as EnumRole;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\DeliveryBoyCashMovement;
use App\Models\User;
use App\Services\Delivery\DeliveryBoyCashSessionService;
use App\Services\Fiscal\AuditLogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * [V1.0.2 Sub-6.3 — 2026-05-18] Audit chain unification sentinel.
 *
 * Planner H plan Decision Coin #2 : TOUS les events livreur cash MUST écrire
 * sur la même chain `audit_logs` per branch_id via `AuditLogService::write()`.
 * Action namespace : `cash.delivery.session.*` + `cash.delivery.movement.recorded`.
 *
 * Asserts :
 *   - Chaque transition de service produit 1 audit_log row (HMAC chain).
 *   - `verifyChain($branchId)` retourne null après lifecycle complet (chain intacte).
 *   - Payload contient les bonnes clés (session_id, type, amount, direction, etc.).
 *   - Aucune chain forkée (UNIQUE prev_hash défense).
 *   - Reuse de la SAME chain : si on écrit un audit POS avant + après, la
 *     chain reste linéaire — pas de fork POS/livreur.
 */
class DeliveryBoyCashSessionAuditChainTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;
    private User $livreur;
    private DeliveryBoyCashSessionService $service;
    private AuditLogService $auditService;

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
        $this->livreur = User::forceCreate([
            'name'              => 'Livreur Audit',
            'email'             => 'livreur-audit-'.uniqid().'@sentinel.test',
            'username'          => 'livreur_audit_'.uniqid(),
            'password'          => bcrypt('secret-passwd'),
            'branch_id'         => $this->branch->id,
            'email_verified_at' => now(),
            'status'            => 1,
        ]);
        $this->livreur->assignRole(EnumRole::DELIVERY_BOY);

        $this->service = app(DeliveryBoyCashSessionService::class);
        $this->auditService = app(AuditLogService::class);
    }

    public function test_lifecycle_writes_one_audit_row_per_transition(): void
    {
        $countBefore = AuditLog::query()->where('branch_id', $this->branch->id)->count();

        // open → 1 audit row (cash.delivery.session.opened)
        $session = $this->service->openSession($this->branch->id, $this->livreur->id, 30.00, $this->livreur->id);

        // 2 movements → 2 audit rows (cash.delivery.movement.recorded × 2)
        $this->service->recordMovement(
            $session->id,
            DeliveryBoyCashMovement::TYPE_ORDER_COLLECT,
            12.50,
            DeliveryBoyCashMovement::DIRECTION_IN,
            orderId: 999
        );
        $this->service->recordMovement(
            $session->id,
            DeliveryBoyCashMovement::TYPE_CHANGE_GIVEN,
            2.50,
            DeliveryBoyCashMovement::DIRECTION_OUT
        );

        // close → 1 audit row (cash.delivery.session.closed)
        $this->service->closeSession($session->id, 40.00);

        // reconcile → 1 audit row (cash.delivery.session.reconciled)
        $this->service->reconcileSession($session->id);

        $countAfter = AuditLog::query()->where('branch_id', $this->branch->id)->count();
        $this->assertSame(
            $countBefore + 5,
            $countAfter,
            'Lifecycle = 5 audit_log rows (1 open + 2 movements + 1 close + 1 reconcile).'
        );

        // Action names canonical (Planner H plan §7).
        $actions = AuditLog::query()
            ->where('branch_id', $this->branch->id)
            ->orderBy('id')
            ->pluck('action')
            ->toArray();
        $this->assertSame([
            'cash.delivery.session.opened',
            'cash.delivery.movement.recorded',
            'cash.delivery.movement.recorded',
            'cash.delivery.session.closed',
            'cash.delivery.session.reconciled',
        ], $actions, 'Action names must follow `cash.delivery.*` namespace per plan §7.');
    }

    public function test_audit_chain_verifyChain_returns_null_after_lifecycle(): void
    {
        $session = $this->service->openSession($this->branch->id, $this->livreur->id, 25.00, $this->livreur->id);
        $this->service->recordMovement(
            $session->id,
            DeliveryBoyCashMovement::TYPE_ORDER_COLLECT,
            8.00,
            DeliveryBoyCashMovement::DIRECTION_IN
        );
        $this->service->closeSession($session->id, 33.00);
        $this->service->reconcileSession($session->id);

        $tampered = $this->auditService->verifyChain($this->branch->id);
        $this->assertNull(
            $tampered,
            "AuditLogService::verifyChain({$this->branch->id}) must return null — chain HMAC intact after livreur lifecycle."
        );
    }

    public function test_audit_payload_contains_canonical_keys(): void
    {
        $session = $this->service->openSession($this->branch->id, $this->livreur->id, 100.00, $this->livreur->id);
        $movement = $this->service->recordMovement(
            $session->id,
            DeliveryBoyCashMovement::TYPE_ORDER_COLLECT,
            17.50,
            DeliveryBoyCashMovement::DIRECTION_IN,
            orderId: 4242
        );
        $this->assertNotNull($movement);

        $movementLog = AuditLog::query()
            ->where('branch_id', $this->branch->id)
            ->where('action', 'cash.delivery.movement.recorded')
            ->orderBy('id', 'desc')
            ->first();
        $this->assertNotNull($movementLog);
        $payload = is_array($movementLog->payload) ? $movementLog->payload : json_decode((string) $movementLog->payload, true);

        $this->assertSame($session->id, $payload['session_id']);
        $this->assertSame($movement->id, $payload['movement_id']);
        $this->assertSame(4242, $payload['order_id']);
        $this->assertSame(DeliveryBoyCashMovement::TYPE_ORDER_COLLECT, $payload['type']);
        $this->assertSame(DeliveryBoyCashMovement::DIRECTION_IN, $payload['direction']);
        $this->assertEqualsWithDelta(17.50, $payload['amount'], 0.01);

        $this->assertSame('delivery_boy_cash_session', $movementLog->resource);
        $this->assertSame($session->id, (int) $movementLog->resource_id);
    }

    public function test_audit_chain_shared_with_pos_does_not_fork(): void
    {
        // Write a POS-style audit event BEFORE livreur events to prove unified chain.
        $this->auditService->write([
            'branch_id'   => $this->branch->id,
            'user_id'     => $this->livreur->id,
            'action'      => 'cash.session.opened',          // POS action namespace (different from livreur)
            'resource'    => 'cash_drawer_session',
            'resource_id' => 9999,
            'payload'     => ['session_id' => 9999, 'opening_amount' => 100.00],
        ]);

        // Livreur lifecycle on the SAME branch.
        $session = $this->service->openSession($this->branch->id, $this->livreur->id, 50.00, $this->livreur->id);
        $this->service->recordMovement(
            $session->id,
            DeliveryBoyCashMovement::TYPE_ORDER_COLLECT,
            10.00,
            DeliveryBoyCashMovement::DIRECTION_IN
        );

        // Write another POS audit event AFTER livreur events.
        $this->auditService->write([
            'branch_id'   => $this->branch->id,
            'user_id'     => $this->livreur->id,
            'action'      => 'cash.session.closed',
            'resource'    => 'cash_drawer_session',
            'resource_id' => 9999,
            'payload'     => ['session_id' => 9999, 'closing_amount' => 110.00],
        ]);

        // Chain must remain linear (no fork POS/livreur).
        $tampered = $this->auditService->verifyChain($this->branch->id);
        $this->assertNull(
            $tampered,
            'verifyChain must return null — POS + livreur events share the SAME chain per branch_id (Decision Coin #2).'
        );

        // 4 rows : 1 POS open + 1 livreur open + 1 livreur movement + 1 POS close.
        $this->assertSame(4, AuditLog::query()->where('branch_id', $this->branch->id)->count());
    }
}
