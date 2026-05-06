<?php

namespace Tests\Feature\Sentinels;

use App\Enums\EventType;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentStatus;
use App\Models\ActionLog;
use App\Models\Branch;
use App\Models\DomainEvent;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * @FK-ID F-VERIFY-09-01 / F-VERIFY-09-10 | @plan docs/audit/plans/PLAN_P13_PAYMENT_STATUS_STATE_MACHINE_2026-05-06.md
 *
 * Sentinel anti-régression pour la state machine paiement câblée dans
 * {@see \App\Services\OrderService::changePaymentStatus}.
 *
 * Trois scénarios :
 *  1. Validation: payment_status invalide -> 422 (régression detector si Rule::in supprimé).
 *  2. Idempotency-Key: 2× POST identique -> exactement 1 ActionLog row + 1 DomainEvent row.
 *  3. Cross-branch: cashier branche A vs commande branche B -> 403, état inchangé.
 *
 * Le test idempotency tourne avec `idempotency.enabled=false` pour exercer
 * la replay protection au niveau service (cache `change_payment_status:*`),
 * sans dépendre du middleware HTTP (déjà couvert par IdempotencyMiddlewareSentinelTest).
 */
class PaymentStatusStateMachineSentinelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();

        // Service-level idempotency exercise: keep HTTP middleware OFF.
        Config::set('idempotency.enabled', false);
    }

    public function test_invalid_payment_status_returns_422(): void
    {
        $branch  = Branch::factory()->create();
        $cashier = User::factory()->create(['branch_id' => $branch->id]);
        $cashier->assignRole('POS Operator');

        $order = Order::factory()->create([
            'user_id'        => $cashier->id,
            'branch_id'      => $branch->id,
            'order_type'     => OrderType::POS,
            'payment_status' => PaymentStatus::UNPAID,
            'status'         => OrderStatus::PENDING,
        ]);

        $response = $this->actingAs($cashier, 'sanctum')
            ->withHeaders(['X-Idempotency-Key' => 'sentinel-pssm-invalid-' . uniqid()])
            ->postJson('/api/admin/pos-order/change-payment-status/' . $order->id, [
                'payment_status' => 999,
            ]);

        $response->assertStatus(422);
        $this->assertSame(
            PaymentStatus::UNPAID,
            (int) Order::withoutGlobalScopes()->findOrFail($order->id)->payment_status,
            'Invalid payment_status MUST NOT mutate the order — Rule::in regression detector.'
        );
    }

    public function test_idempotency_key_replay_yields_one_action_log(): void
    {
        Queue::fake();

        // Force array cache (default in tests). The service-level guard reads
        // `change_payment_status:{branch_id}:{order_id}:{key}` via Cache::get.
        Cache::store(config('cache.default'))->flush();

        $branch  = Branch::factory()->create();
        $cashier = User::factory()->create(['branch_id' => $branch->id]);
        $cashier->assignRole('POS Operator');

        $order = Order::factory()->create([
            'user_id'        => $cashier->id,
            'branch_id'      => $branch->id,
            'order_type'     => OrderType::POS,
            'payment_status' => PaymentStatus::UNPAID,
            'status'         => OrderStatus::PENDING,
            'total'          => 30.00,
        ]);

        $key = 'sentinel-pssm-replay-' . uniqid();

        $first = $this->actingAs($cashier, 'sanctum')
            ->withHeaders(['X-Idempotency-Key' => $key])
            ->postJson('/api/admin/pos-order/change-payment-status/' . $order->id, [
                'payment_status' => PaymentStatus::PAID,
            ]);
        $first->assertStatus(200);

        $second = $this->actingAs($cashier, 'sanctum')
            ->withHeaders(['X-Idempotency-Key' => $key])
            ->postJson('/api/admin/pos-order/change-payment-status/' . $order->id, [
                'payment_status' => PaymentStatus::PAID,
            ]);
        $second->assertStatus(200);

        $this->assertSame(
            1,
            ActionLog::query()->where('resource', 'Commande #' . $order->order_serial_no)->count(),
            'Replay with same X-Idempotency-Key MUST produce exactly one ActionLog row.'
        );

        $this->assertSame(
            1,
            DomainEvent::query()
                ->where('event_type', EventType::ORDER_PAYMENT_STATUS_CHANGED)
                ->where('aggregate_id', $order->id)
                ->count(),
            'Replay MUST produce exactly one domain_events row.'
        );
    }

    public function test_cross_branch_is_rejected_and_state_unchanged(): void
    {
        // Defense-in-depth: BranchScope filters the route binding query first,
        // returning 404 for non-Admin staff attempting to read another branch's
        // order. The service-level abort(403) inside OrderService::changePaymentStatus
        // is the second guard layer (visible when actor bypasses the scope, e.g.
        // Admin user with branch_id=0 hitting the wrong branch via payload).
        // Either status code proves isolation; the invariant is: state unchanged.
        $branchA = Branch::factory()->create();
        $branchB = Branch::factory()->create();

        $cashierA = User::factory()->create(['branch_id' => $branchA->id]);
        $cashierA->assignRole('POS Operator');

        $foreignOrder = Order::factory()->create([
            'user_id'        => $cashierA->id,
            'branch_id'      => $branchB->id,
            'order_type'     => OrderType::POS,
            'payment_status' => PaymentStatus::UNPAID,
            'status'         => OrderStatus::PENDING,
        ]);

        $response = $this->actingAs($cashierA, 'sanctum')
            ->withHeaders(['X-Idempotency-Key' => 'sentinel-pssm-cross-' . uniqid()])
            ->postJson('/api/admin/pos-order/change-payment-status/' . $foreignOrder->id, [
                'payment_status' => PaymentStatus::PAID,
            ]);

        $this->assertContains(
            $response->getStatusCode(),
            [403, 404],
            'Cross-branch attempt must be rejected with 403 (service guard) or 404 (BranchScope).'
        );
        $this->assertSame(
            PaymentStatus::UNPAID,
            (int) Order::withoutGlobalScopes()->findOrFail($foreignOrder->id)->payment_status,
            'Cross-branch attempt MUST leave payment_status unchanged.'
        );
    }
}
