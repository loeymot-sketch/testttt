<?php

namespace Tests\Feature\Admin;

use App\Models\Branch;
use App\Models\PaymentTerminal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [Wave F F-2 / Sprint 1C] CRUD coverage for PaymentTerminal admin surface.
 *
 * Verrouille :
 *   - Création + validation (fee_percent range, gateway_type enum)
 *   - Permission gate `settings` sur store/update/destroy
 *   - Branch isolation (index ne retourne que branche courante pour user scoped)
 *   - Destroy = soft-archive (status=5), no hard delete
 *   - Update preserve branch_id when called by branch-scoped user
 */
class PaymentTerminalControllerTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;
    private User $admin;
    private User $operator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedSpatieRoles();
        $this->seedMinimalSettings();

        $this->branch = Branch::factory()->create();

        $this->admin = User::factory()->create(['branch_id' => $this->branch->id]);
        $this->admin->assignRole('Admin');

        $this->operator = User::factory()->create(['branch_id' => $this->branch->id]);
        $this->operator->assignRole('POS Operator');
    }

    public function test_admin_can_create_a_payment_terminal(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')->postJson('/api/admin/payment-terminals', [
            'name'           => 'TPE Cuisine Comptoir',
            'gateway_type'   => 'ingenico',
            'fee_percent'    => 1.5,
            'fee_fixed'      => 0.05,
            'serial_number'  => 'SN12345',
            'status'         => 1,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.name', 'TPE Cuisine Comptoir')
            ->assertJsonPath('data.gateway_type', 'ingenico')
            ->assertJsonPath('data.fee_percent', 1.5)
            ->assertJsonPath('data.fee_fixed', 0.05)
            ->assertJsonPath('data.branch_id', $this->branch->id);

        $this->assertDatabaseHas('payment_terminals', [
            'branch_id'    => $this->branch->id,
            'name'         => 'TPE Cuisine Comptoir',
            'gateway_type' => 'ingenico',
            'status'       => 1,
        ]);
    }

    public function test_validation_rejects_unknown_gateway_type(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')->postJson('/api/admin/payment-terminals', [
            'name'         => 'TPE Bidon',
            'gateway_type' => 'paypal',  // not in enum
            'fee_percent'  => 1.0,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['gateway_type']);
    }

    public function test_validation_rejects_fee_percent_over_100(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')->postJson('/api/admin/payment-terminals', [
            'name'         => 'TPE Greedy',
            'gateway_type' => 'stripe',
            'fee_percent'  => 150.0,  // over max
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['fee_percent']);
    }

    public function test_index_returns_only_terminals_for_the_current_branch(): void
    {
        $otherBranch = Branch::factory()->create();

        PaymentTerminal::query()->create($this->terminalData($this->branch->id, ['name' => 'Visible TPE']));
        PaymentTerminal::query()->create($this->terminalData($otherBranch->id, ['name' => 'Hidden TPE']));

        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/admin/payment-terminals');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Visible TPE');
    }

    public function test_update_changes_fee_percent(): void
    {
        $terminal = PaymentTerminal::query()->create($this->terminalData($this->branch->id, ['fee_percent' => 1.5]));

        $response = $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/admin/payment-terminals/{$terminal->id}", [
                'name'         => $terminal->name,
                'gateway_type' => $terminal->gateway_type,
                'fee_percent'  => 2.25,
                'fee_fixed'    => 0.10,
                'status'       => $terminal->status,
            ]);

        $response->assertOk()
            ->assertJsonPath('data.fee_percent', 2.25)
            ->assertJsonPath('data.fee_fixed', 0.10);

        $this->assertDatabaseHas('payment_terminals', [
            'id'          => $terminal->id,
            'fee_percent' => 2.250,
        ]);
    }

    public function test_destroy_soft_archives_terminal(): void
    {
        $terminal = PaymentTerminal::query()->create($this->terminalData($this->branch->id));

        $response = $this->actingAs($this->admin, 'sanctum')
            ->deleteJson("/api/admin/payment-terminals/{$terminal->id}");

        $response->assertNoContent();

        // Row still exists with status=ARCHIVED (5)
        $this->assertDatabaseHas('payment_terminals', [
            'id'     => $terminal->id,
            'status' => PaymentTerminal::STATUS_ARCHIVED,
        ]);
    }

    public function test_permission_gate_blocks_operator_from_store(): void
    {
        $response = $this->actingAs($this->operator, 'sanctum')->postJson('/api/admin/payment-terminals', [
            'name'         => 'Locked TPE',
            'gateway_type' => 'stripe',
            'fee_percent'  => 1.0,
        ]);

        $response->assertStatus(403);
    }

    public function test_permission_gate_blocks_operator_from_destroy(): void
    {
        $terminal = PaymentTerminal::query()->create($this->terminalData($this->branch->id));

        $response = $this->actingAs($this->operator, 'sanctum')
            ->deleteJson("/api/admin/payment-terminals/{$terminal->id}");

        $response->assertStatus(403);

        $this->assertDatabaseHas('payment_terminals', [
            'id'     => $terminal->id,
            'status' => PaymentTerminal::STATUS_ACTIVE,
        ]);
    }

    public function test_branch_scoped_user_cannot_move_terminal_across_branches(): void
    {
        $otherBranch = Branch::factory()->create();
        $terminal = PaymentTerminal::query()->create($this->terminalData($this->branch->id));

        $response = $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/admin/payment-terminals/{$terminal->id}", [
                'name'         => $terminal->name,
                'gateway_type' => $terminal->gateway_type,
                'fee_percent'  => 1.0,
                'fee_fixed'    => 0,
                'status'       => 1,
                'branch_id'    => $otherBranch->id,  // attempt to move
            ]);

        $response->assertOk();

        // branch_id unchanged
        $this->assertDatabaseHas('payment_terminals', [
            'id'        => $terminal->id,
            'branch_id' => $this->branch->id,
        ]);
    }

    public function test_show_returns_terminal_payload(): void
    {
        $terminal = PaymentTerminal::query()->create($this->terminalData($this->branch->id));

        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/admin/payment-terminals/{$terminal->id}");

        $response->assertOk()
            ->assertJsonPath('data.id', $terminal->id)
            ->assertJsonPath('data.name', $terminal->name);
    }

    private function terminalData(int $branchId, array $overrides = []): array
    {
        return array_merge([
            'branch_id'     => $branchId,
            'name'          => 'TPE Default',
            'gateway_type'  => 'ingenico',
            'fee_percent'   => 1.5,
            'fee_fixed'     => 0.05,
            'serial_number' => 'SN-' . uniqid(),
            'status'        => 1,
        ], $overrides);
    }
}
