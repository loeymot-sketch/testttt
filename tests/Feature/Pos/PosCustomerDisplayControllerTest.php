<?php

namespace Tests\Feature\Pos;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [CUSTOMER-DISPLAY 2026-06-28] Endpoint that refreshes the SAGA pole display.
 * Best-effort: it must never error the POS, and reports enabled/sent state.
 */
class PosCustomerDisplayControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
        $branch = Branch::factory()->create();
        $this->user = User::factory()->create(['branch_id' => $branch->id]);
        $this->user->assignRole('Admin');
    }

    public function test_returns_disabled_when_display_off(): void
    {
        config(['printing.customer_display.enabled' => false]);
        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/admin/pos/customer-display', ['mode' => 'total', 'total' => 12.5])
            ->assertOk()
            ->assertJson(['enabled' => false, 'sent' => false]);
    }

    public function test_sends_total_when_enabled_with_null_transport(): void
    {
        config([
            'printing.customer_display.enabled' => true,
            'printing.customer_display.driver' => 'null',
        ]);
        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/admin/pos/customer-display', ['mode' => 'total', 'total' => 24.20])
            ->assertOk()
            ->assertJson(['enabled' => true, 'sent' => true]);
    }

    public function test_welcome_mode_is_accepted(): void
    {
        config([
            'printing.customer_display.enabled' => true,
            'printing.customer_display.driver' => 'null',
        ]);
        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/admin/pos/customer-display', ['mode' => 'welcome'])
            ->assertOk()
            ->assertJson(['enabled' => true, 'sent' => true]);
    }
}
