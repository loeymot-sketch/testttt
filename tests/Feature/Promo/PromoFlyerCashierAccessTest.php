<?php

namespace Tests\Feature\Promo;

use App\Models\Branch;
use App\Models\User;
use App\Services\Promo\PromoFlyerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * [OWNER 2026-08-13 « ameliore l'acces du caissier »] `PromoFlyerController::store/reprint/revoke`
 * était gardé `coupons_create|settings` (commits a4b9a2b46, a5622d47) — un verrou pensé contre le
 * mint illimité de codes -10%, mais qui a fini par bloquer TOUT LE MONDE sauf l'Admin, y compris
 * le caissier (rôle POS Operator) pour qui le bouton "Ticket promo" du tracker caisse a été
 * construit. Le clic répondait 403.
 *
 * Ce test fige le comportement attendu :
 *   - le caissier (POS Operator) ET le gérant (Branch Manager) peuvent créer / réimprimer /
 *     annuler un ticket promo via la nouvelle permission dédiée `pos-flyer-print` ;
 *   - un plafond quotidien par utilisateur remplace le blocage de rôle qui protégeait contre
 *     le mint illimité (PromoFlyerService::DAILY_CAP_PER_USER) ;
 *   - un rôle qui ne porte NI `pos-flyer-print` NI `coupons_create|settings` reste refusé (403) —
 *     la permission est bien scopée, pas un blanket-open.
 */
class PromoFlyerCashierAccessTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch;
    protected User $cashier;
    protected User $branchManager;
    protected User $chef;
    protected PromoFlyerService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();

        $this->branch = Branch::factory()->create();
        $this->service = app(PromoFlyerService::class);

        $this->cashier = User::factory()->create([
            'branch_id' => $this->branch->id,
            'password'  => Hash::make('pwd'),
        ]);
        $this->cashier->assignRole('POS Operator');

        $this->branchManager = User::factory()->create([
            'branch_id' => $this->branch->id,
            'password'  => Hash::make('pwd'),
        ]);
        $this->branchManager->assignRole('Branch Manager');

        // Chef ne porte ni pos-flyer-print ni coupons_create|settings : sert de
        // témoin négatif — la permission doit rester scopée, pas ouverte à tout admin.
        $this->chef = User::factory()->create([
            'branch_id' => $this->branch->id,
            'password'  => Hash::make('pwd'),
        ]);
        $this->chef->assignRole('Chef');
    }

    private function withApiKey()
    {
        return $this->withHeader('x-api-key', config('app.api_key'));
    }

    /** @test */
    public function test_cashier_can_create_a_promo_flyer(): void
    {
        $this->actingAs($this->cashier, 'sanctum');

        $response = $this->withApiKey()->postJson('/api/admin/promo-flyer', [
            'customer_name' => 'Camille',
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('promo_flyers', [
            'branch_id'     => $this->branch->id,
            'customer_name' => 'Camille',
        ]);
    }

    /** @test */
    public function test_branch_manager_can_create_a_promo_flyer(): void
    {
        $this->actingAs($this->branchManager, 'sanctum');

        $response = $this->withApiKey()->postJson('/api/admin/promo-flyer', [
            'customer_name' => 'Salim',
        ]);

        $response->assertStatus(201);
    }

    /** @test */
    public function test_a_role_without_the_permission_is_still_refused(): void
    {
        $this->actingAs($this->chef, 'sanctum');

        $response = $this->withApiKey()->postJson('/api/admin/promo-flyer', [
            'customer_name' => 'Camille',
        ]);

        $response->assertStatus(403);
    }

    /** @test */
    public function test_cashier_can_reprint_and_revoke(): void
    {
        $flyer = $this->service->create('Camille', (int) $this->branch->id, (int) $this->cashier->id, 'caisse-1');

        $this->actingAs($this->cashier, 'sanctum');

        $this->withApiKey()
            ->postJson("/api/admin/promo-flyer/{$flyer->id}/reprint")
            ->assertStatus(200);

        $this->withApiKey()
            ->postJson("/api/admin/promo-flyer/{$flyer->id}/revoke")
            ->assertStatus(200);
    }

    /**
     * [OWNER 2026-08-13] Le verrou de rôle protégeait contre un mint illimité — en l'ouvrant au
     * caissier, ce risque doit être repris ailleurs : un plafond quotidien par utilisateur.
     */
    /** @test */
    public function test_a_cashier_is_blocked_once_the_daily_cap_is_reached(): void
    {
        for ($i = 0; $i < PromoFlyerService::DAILY_CAP_PER_USER; $i++) {
            $this->service->create('Client' . $i, (int) $this->branch->id, (int) $this->cashier->id, 'caisse-1');
        }

        $this->actingAs($this->cashier, 'sanctum');

        $response = $this->withApiKey()->postJson('/api/admin/promo-flyer', [
            'customer_name' => 'UnDeTrop',
        ]);

        $response->assertStatus(429);
        $this->assertDatabaseMissing('promo_flyers', ['customer_name' => 'UnDeTrop']);
    }

    /**
     * L'Admin (compte de service / secours) ne doit jamais se retrouver bloqué par le plafond
     * pensé pour un usage caissier normal.
     */
    /** @test */
    public function test_admin_is_not_subject_to_the_daily_cap(): void
    {
        $admin = User::factory()->create([
            'branch_id' => 0,
            'password'  => Hash::make('pwd'),
        ]);
        $admin->assignRole('Admin');

        for ($i = 0; $i < PromoFlyerService::DAILY_CAP_PER_USER; $i++) {
            $this->service->create('Client' . $i, (int) $this->branch->id, (int) $admin->id, 'caisse-1');
        }

        $this->actingAs($admin, 'sanctum');

        $response = $this->withApiKey()->postJson('/api/admin/promo-flyer', [
            'customer_name' => 'Encore',
        ]);

        $response->assertStatus(201);
    }

    /** @test */
    public function test_the_daily_cap_resets_the_next_day(): void
    {
        for ($i = 0; $i < PromoFlyerService::DAILY_CAP_PER_USER; $i++) {
            $flyer = $this->service->create('Client' . $i, (int) $this->branch->id, (int) $this->cashier->id, 'caisse-1');
            $flyer->forceFill(['created_at' => now()->subDay()])->save();
        }

        $this->actingAs($this->cashier, 'sanctum');

        $response = $this->withApiKey()->postJson('/api/admin/promo-flyer', [
            'customer_name' => 'Nouveau',
        ]);

        $response->assertStatus(201);
    }
}
