<?php

namespace Tests\Feature\Grok;

use App\Models\User;
use App\Services\Pilotage\InterrupteurService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CashierCannotToggleInterrupteurTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMinimalSettings();
        $this->seedSpatieRoles();
    }

    public function test_pos_operator_cannot_put_or_get_interrupteurs(): void
    {
        $cashier = User::factory()->create(['branch_id' => 1]);
        $cashier->assignRole('POS Operator');
        Sanctum::actingAs($cashier, ['*']);

        $this->getJson('/api/admin/observability/interrupteurs')->assertForbidden();
        $this->putJson('/api/admin/observability/interrupteurs/wheel', ['actif' => true])
            ->assertForbidden();
    }

    public function test_admin_round_trip_restores_wheel(): void
    {
        $admin = User::factory()->create(['branch_id' => 0]);
        $admin->assignRole('Admin');
        Sanctum::actingAs($admin, ['*']);

        $service = app(InterrupteurService::class);
        $avant = (bool) $service->valeur('wheel');

        $this->putJson('/api/admin/observability/interrupteurs/wheel', ['actif' => ! $avant])
            ->assertOk();
        $this->putJson('/api/admin/observability/interrupteurs/wheel', ['actif' => $avant])
            ->assertOk();

        $this->assertSame($avant, (bool) $service->valeur('wheel'));
    }
}
