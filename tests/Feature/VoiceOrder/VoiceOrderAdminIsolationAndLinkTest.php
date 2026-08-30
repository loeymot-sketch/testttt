<?php

namespace Tests\Feature\VoiceOrder;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\PosPaymentMethod;
use App\Models\ActionLog;
use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use App\Services\VoiceOrder\VoiceOrderTranscriptStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class VoiceOrderAdminIsolationAndLinkTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branchA;
    private Branch $branchB;
    private User $operatorA;
    private User $operatorB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
        Config::set('voice_order.enabled', true);
        Cache::flush();

        $this->branchA = Branch::factory()->create();
        $this->branchB = Branch::factory()->create();
        $this->operatorA = User::factory()->create(['branch_id' => $this->branchA->id]);
        $this->operatorB = User::factory()->create(['branch_id' => $this->branchB->id]);
        $this->operatorA->assignRole('POS Operator');
        $this->operatorB->assignRole('POS Operator');

        app(VoiceOrderTranscriptStore::class)->startCall(
            $this->branchA->id,
            'gw-a',
            ['call_id' => 'call-branch-a-0001', 'caller_number' => '0611111111']
        );
    }

    public function test_admin_snapshot_requires_auth_pos_permission_and_concrete_branch(): void
    {
        $this->getJson('/api/admin/voice-order/snapshot')->assertStatus(401);

        $withoutPermission = User::factory()->create(['branch_id' => $this->branchA->id]);
        $withoutPermission->assignRole('Stuff');
        $this->actingAs($withoutPermission, 'sanctum')->getJson('/api/admin/voice-order/snapshot')->assertStatus(403);

        $admin = User::factory()->create(['branch_id' => 0]);
        $admin->assignRole('Admin');
        $this->actingAs($admin, 'sanctum')->getJson('/api/admin/voice-order/snapshot')->assertStatus(422);

        $this->actingAs($this->operatorA, 'sanctum')
            ->getJson('/api/admin/voice-order/snapshot')
            ->assertOk()
            ->assertJsonPath('data.active_calls.0.call_id', 'call-branch-a-0001');

        $this->actingAs($this->operatorB, 'sanctum')
            ->getJson('/api/admin/voice-order/snapshot')
            ->assertOk()
            ->assertJsonCount(0, 'data.active_calls');
    }

    public function test_link_is_same_branch_phone_deferred_idempotent_and_non_reassignable(): void
    {
        $order = $this->phoneOrder($this->branchA, $this->operatorA);

        $this->actingAs($this->operatorA, 'sanctum')
            ->postJson('/api/admin/voice-order/calls/call-branch-a-0001/link-order', ['order_id' => $order->id])
            ->assertOk()->assertJsonPath('data.idempotent', false);
        $this->actingAs($this->operatorA, 'sanctum')
            ->postJson('/api/admin/voice-order/calls/call-branch-a-0001/link-order', ['order_id' => $order->id])
            ->assertOk()->assertJsonPath('data.idempotent', true);

        $other = $this->phoneOrder($this->branchA, $this->operatorA);
        $this->actingAs($this->operatorA, 'sanctum')
            ->postJson('/api/admin/voice-order/calls/call-branch-a-0001/link-order', ['order_id' => $other->id])
            ->assertStatus(409);

        $this->assertSame(1, ActionLog::query()
            ->where('branch_id', $this->branchA->id)
            ->where('action', VoiceOrderTranscriptStore::ACTION_ORDER_LINK)
            ->count());
    }

    public function test_link_rejects_cross_branch_and_non_phone_orders(): void
    {
        $foreign = $this->phoneOrder($this->branchB, $this->operatorB);
        $this->actingAs($this->operatorA, 'sanctum')
            ->postJson('/api/admin/voice-order/calls/call-branch-a-0001/link-order', ['order_id' => $foreign->id])
            ->assertStatus(404);

        $notPhone = $this->phoneOrder($this->branchA, $this->operatorA, ['source_surface' => 'pos']);
        $this->actingAs($this->operatorA, 'sanctum')
            ->postJson('/api/admin/voice-order/calls/call-branch-a-0001/link-order', ['order_id' => $notPhone->id])
            ->assertStatus(422);
    }

    private function phoneOrder(Branch $branch, User $user, array $overrides = []): Order
    {
        return Order::factory()->create(array_merge([
            'branch_id' => $branch->id,
            'user_id' => $user->id,
            'source_surface' => 'phone',
            'payment_status' => PaymentStatus::PENDING_COUNTER,
            'pos_payment_method' => PosPaymentMethod::COUNTER_DEFERRED,
            'status' => OrderStatus::PREPARING,
        ], $overrides));
    }
}
