<?php

namespace Tests\Feature\Admin;

use App\Models\GatewayOption;
use App\Models\PaymentGateway;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [HEAL dispute-r1 B-R1-19 2026-06-12] /admin/transactions « Mode de
 * paiement » filter feeds on GET /admin/setting/payment-gateway. The SET-01
 * heal gated index with `permission:settings` (secret leak fix) — but the
 * Branch Manager (who legitimately holds `transactions`) then got a 403 +
 * uncaught AxiosError on EVERY /admin/transactions visit.
 *
 * Healed contract (no secret re-leak — SET-01 intent preserved):
 *  - index : permission settings OR transactions; option VALUES (secrets)
 *    are stripped from the payload unless the caller holds `settings`.
 *  - update : permission settings ONLY (unchanged).
 */
class PaymentGatewayIndexBranchManagerAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_branch_manager_with_transactions_can_list_gateways_without_secrets(): void
    {
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
        $this->seedGatewayWithSecret();

        $bm = User::factory()->create(['branch_id' => 1]);
        $bm->assignRole('Branch Manager');
        \Spatie\Permission\Models\Permission::firstOrCreate(['name' => 'transactions', 'guard_name' => 'sanctum']);
        $bm->givePermissionTo('transactions');

        $response = $this->actingAs($bm, 'sanctum')->getJson('/api/admin/setting/payment-gateway');

        $response->assertOk();
        $data = $response->json('data');
        $this->assertNotEmpty($data, 'gateway names must be served for the filter');
        foreach ($data as $gateway) {
            $this->assertSame([], $gateway['options'] ?? [], 'secret option values must NOT leak to non-settings staff');
        }
        $this->assertStringNotContainsString('sk_live_secret_value', $response->getContent());
    }

    public function test_staff_without_settings_or_transactions_still_gets_403(): void
    {
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();

        $chef = User::factory()->create(['branch_id' => 1]);
        $chef->assignRole('Chef');

        $this->actingAs($chef, 'sanctum')
            ->getJson('/api/admin/setting/payment-gateway')
            ->assertForbidden();
    }

    public function test_settings_holder_still_sees_option_values(): void
    {
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
        $this->seedGatewayWithSecret();

        $admin = User::factory()->create(['branch_id' => 0]);
        $admin->assignRole('Admin');
        $admin->givePermissionTo('settings');

        $response = $this->actingAs($admin, 'sanctum')->getJson('/api/admin/setting/payment-gateway');

        $response->assertOk();
        $this->assertStringContainsString('sk_live_secret_value', $response->getContent());
    }

    public function test_branch_manager_cannot_update_gateway_settings(): void
    {
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();

        $bm = User::factory()->create(['branch_id' => 1]);
        $bm->assignRole('Branch Manager');
        \Spatie\Permission\Models\Permission::firstOrCreate(['name' => 'transactions', 'guard_name' => 'sanctum']);
        $bm->givePermissionTo('transactions');

        $this->actingAs($bm, 'sanctum')
            ->putJson('/api/admin/setting/payment-gateway', ['payment_type' => 'stripe'])
            ->assertForbidden();
    }

    private function seedGatewayWithSecret(): PaymentGateway
    {
        $gateway = PaymentGateway::create([
            'name' => 'Stripe',
            'slug' => 'stripe',
            'status' => 5,
        ]);
        GatewayOption::create([
            'model_type' => PaymentGateway::class,
            'model_id' => $gateway->id,
            'option' => 'stripe_secret',
            'value' => 'sk_live_secret_value',
            'type' => 'text',
            'activities' => null,
        ]);

        return $gateway;
    }
}
